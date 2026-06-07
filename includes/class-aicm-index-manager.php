<?php
/**
 * Index Manager
 *
 * Orchestrates the full content indexing pipeline.
 *
 * ── Data flow ────────────────────────────────────────────────────────────
 *   Admin request  → enqueue_full_reindex()  → seeds aicm_queue
 *   Auto-sync      → enqueue_post()          → adds single post to queue
 *   WP-Cron (5min) → process_queue_batch()  → runs the pipeline per item:
 *                      AICM_Content_Fetcher → AICM_Text_Extractor
 *                      → AICM_Chunker → AICM_Embedder
 *
 * ── Queue states ──────────────────────────────────────────────────────────
 *   pending    → waiting to be processed
 *   processing → claimed by a cron run currently in progress
 *   failed     → attempted MAX_ATTEMPTS times and failed every time
 *   (deleted)  → successfully indexed rows are deleted to keep the table lean
 *
 * ── Concurrency protection ────────────────────────────────────────────────
 * A transient lock with a 4-minute expiry prevents overlapping cron runs.
 * Rows stuck in 'processing' for longer than STALE_MINUTES are reset to
 * 'pending' at the start of each run, recovering from PHP crashes.
 *
 * ── Re-index deduplication ────────────────────────────────────────────────
 * enqueue_full_reindex() uses INSERT ... SELECT with a LEFT JOIN to skip
 * posts that already have a pending or processing queue row. This is done
 * in one SQL statement per post type — far more efficient than looping in PHP.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Index_Manager
 */
class AICM_Index_Manager {

	/** Transient key for the concurrency lock. */
	private const LOCK_TRANSIENT = 'aicm_index_lock';

	/**
	 * Lock expiry in seconds.
	 * Slightly less than the 5-minute cron interval so the lock always
	 * expires before the next cron run — even if release_lock() was never
	 * called due to a PHP crash.
	 */
	private const LOCK_SECONDS = 4 * MINUTE_IN_SECONDS;

	/**
	 * Minutes after which a 'processing' row is considered stale.
	 * Rows older than this are reset to 'pending' for retry.
	 */
	private const STALE_MINUTES = 10;

	/**
	 * Maximum wall-clock seconds to spend in a single cron batch.
	 * Stays well under the typical 30-second PHP max_execution_time.
	 */
	private const MAX_BATCH_SECONDS = 25;

	/**
	 * Maximum number of attempts before a queue item is permanently failed.
	 */
	private const MAX_ATTEMPTS = 3;

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Seed the indexing queue for a full re-index of all configured post types.
	 *
	 * Uses INSERT ... SELECT per post type to add all published posts that
	 * do not already have a pending or processing queue row. One SQL statement
	 * per post type — no PHP loops over all post IDs.
	 *
	 * 'failed' rows are cleared first so those posts get a fresh retry.
	 * 'pending' and 'processing' rows are left untouched (deduplication).
	 *
	 * @return int Total number of posts newly added to the queue.
	 */
	public static function enqueue_full_reindex(): int {
		global $wpdb;

		$table       = $wpdb->prefix . 'aicm_queue';
		$posts_table = $wpdb->prefix . 'posts';
		$now         = current_time( 'mysql' );
		$total       = 0;

		$configured_types = (array) AI_ChatMate::get_setting(
			'index_post_types',
			array( 'post', 'page' )
		);

		if ( empty( $configured_types ) ) {
			return 0;
		}

		// Clear all 'failed' rows so every post gets a fresh retry on a
		// full re-index (user explicitly requested a complete rebuild).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM `{$table}` WHERE status = 'failed'" );

		foreach ( $configured_types as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type ) {
				continue;
			}

			// INSERT all published posts of this type that are not already queued.
			// The LEFT JOIN + NULL check efficiently prevents duplicate rows without
			// requiring a UNIQUE constraint on the queue table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `{$table}` (post_id, action, status, attempts, created_at)
					SELECT p.ID, 'index', 'pending', 0, %s
					FROM `{$posts_table}` p
					LEFT JOIN `{$table}` q
						ON  q.post_id = p.ID
						AND q.status IN ('pending', 'processing')
					WHERE p.post_type   = %s
					  AND p.post_status = 'publish'
					  AND q.id IS NULL",
					$now,
					$post_type
				)
			);

			$total += (int) $wpdb->rows_affected;
		}

		self::update_status();

		return $total;
	}

	/**
	 * Add a single post to the indexing queue.
	 *
	 * Any existing 'pending' or 'failed' row for this post is removed before
	 * the new row is inserted, preventing duplicate entries.
	 * A 'processing' row (currently being worked on by cron) is left alone —
	 * the next cron run will pick up the new row after the current one finishes.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $action  'index' to embed the post, 'delete' to remove it.
	 */
	public static function enqueue_post( int $post_id, string $action = 'index' ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_queue';
		$now   = current_time( 'mysql' );

		// Remove stale pending/failed rows to prevent duplicates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE post_id = %d AND status IN ('pending', 'failed')",
				$post_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'post_id'    => $post_id,
				'action'     => $action,
				'status'     => 'pending',
				'attempts'   => 0,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Remove a post from the search index immediately (synchronous).
	 *
	 * Used when a post is permanently deleted or trashed. We remove it
	 * immediately (not via the queue) so deleted content stops being served
	 * by the chatbot as soon as possible.
	 *
	 * Also cancels any pending queue entry for this post so cron does not
	 * try to re-index something that no longer exists.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public static function remove_post_from_index( int $post_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_queue';

		// Cancel pending/failed queue entries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE post_id = %d AND status IN ('pending', 'failed')",
				$post_id
			)
		);

		// Delete the embedding chunks immediately.
		AICM_Embedder::delete_post_chunks( $post_id );

		self::update_status();
	}

	/**
	 * Process a batch of pending queue items.
	 *
	 * Called by the `aicm_process_index_queue` WP-Cron action every 5 minutes.
	 *
	 * Processing steps:
	 *  1. Acquire concurrency lock — exit if another cron run is active.
	 *  2. Reset stale 'processing' rows (from a previous crashed run).
	 *  3. Fetch up to batch_size pending rows (oldest first).
	 *  4. Mark them 'processing' to block other cron runs from picking them up.
	 *  5. Process each item, honouring the per-batch time limit.
	 *  6. On success: delete the completed row.
	 *     On failure: increment attempts. After MAX_ATTEMPTS, mark 'failed'.
	 *  7. Update the index status option and release the lock.
	 */
	public static function process_queue_batch(): void {
		global $wpdb;

		// ── Step 1: acquire concurrency lock ──────────────────────────────
		if ( ! self::acquire_lock() ) {
			return;
		}

		$table = $wpdb->prefix . 'aicm_queue';

		// Respect admin's batch_size setting, capped at 50 per cron run
		// regardless of the setting — guards against excessive API usage.
		$batch_size = max( 1, min( 50, (int) AI_ChatMate::get_setting( 'batch_size', 10 ) ) );

		// ── Step 2: reset stale 'processing' rows ─────────────────────────
		// Rows stuck in 'processing' longer than STALE_MINUTES are from a
		// crashed cron run. Reset them so this run can pick them up.
		$stale_cutoff = gmdate(
			'Y-m-d H:i:s',
			time() - ( self::STALE_MINUTES * MINUTE_IN_SECONDS )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'pending' WHERE status = 'processing' AND created_at < %s",
				$stale_cutoff
			)
		);

		// ── Step 3: fetch pending rows ────────────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
				$batch_size
			)
		);

		if ( empty( $rows ) ) {
			// Queue is empty — all done.
			self::mark_not_running();
			self::update_status();
			self::release_lock();
			return;
		}

		// Mark in progress for admin UI.
		self::mark_running();

		// ── Step 4: claim rows by marking 'processing' ────────────────────
		$row_ids      = array_map( static fn( object $r ): int => (int) $r->id, $rows );
		$placeholders = implode( ', ', array_fill( 0, count( $row_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'processing' WHERE id IN ({$placeholders})",
				...$row_ids
			)
		);

		// ── Step 5: load provider once for the whole batch ────────────────
		$provider   = self::get_provider();
		$start_time = microtime( true );

		// ── Step 6: process each item ─────────────────────────────────────
		foreach ( $rows as $row ) {
			// Check per-batch time limit before starting each item.
			if ( microtime( true ) - $start_time > self::MAX_BATCH_SECONDS ) {
				// Return this item to 'pending' so the next cron run processes it.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array( 'status' => 'pending' ),
					array( 'id' => (int) $row->id ),
					array( '%s' ),
					array( '%d' )
				);
				continue;
			}

			$success = self::process_queue_item( $row, $provider );

			if ( $success ) {
				// Delete completed row — keeps the queue table compact.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );

			} else {
				$attempts = (int) $row->attempts + 1;

				if ( $attempts >= self::MAX_ATTEMPTS ) {
					// Permanently failed — preserve for admin inspection.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						array(
							'status'       => 'failed',
							'attempts'     => $attempts,
							'processed_at' => current_time( 'mysql' ),
						),
						array( 'id' => (int) $row->id ),
						array( '%s', '%d', '%s' ),
						array( '%d' )
					);
				} else {
					// Return to 'pending' for retry on the next cron run.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						array(
							'status'   => 'pending',
							'attempts' => $attempts,
						),
						array( 'id' => (int) $row->id ),
						array( '%s', '%d' ),
						array( '%d' )
					);
				}
			}
		}

		// ── Step 7: check if queue is now empty ───────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE status IN ('pending', 'processing')"
		);

		if ( 0 === $remaining ) {
			self::mark_not_running();
		}

		self::update_status();
		self::release_lock();
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Run the full indexing pipeline for one queue item.
	 *
	 * For 'delete' action: removes chunks immediately (always succeeds).
	 * For 'index' action:  Fetcher → Extractor → Chunker → Embedder.
	 *
	 * If the post no longer exists or has been un-published, any stale
	 * chunks are cleaned up and the item is treated as a success
	 * (nothing to index, but no error either).
	 *
	 * @param object                 $row      Row from the aicm_queue table.
	 * @param AICM_LLM_Provider|null $provider Provider instance; null if not configured.
	 * @return bool True = success (delete queue row), false = failure (retry or fail).
	 */
	private static function process_queue_item( object $row, ?AICM_LLM_Provider $provider ): bool {
		$post_id = (int) $row->post_id;
		$action  = (string) $row->action;

		// ── Delete action ──────────────────────────────────────────────────
		if ( 'delete' === $action ) {
			AICM_Embedder::delete_post_chunks( $post_id );
			return true; // Deletion always succeeds.
		}

		// ── Index action ───────────────────────────────────────────────────
		if ( null === $provider ) {
			// No API key configured — cannot embed anything.
			// Return false so the item stays in the queue and will be
			// retried once the admin adds an API key.
			return false;
		}

		// Fetch post data. Returns null when the post no longer exists,
		// is not published, or its type was removed from index_post_types.
		$post_data = AICM_Content_Fetcher::get_post_data( $post_id );

		if ( null === $post_data ) {
			// Nothing to index — clean up any stale chunks and mark success.
			AICM_Embedder::delete_post_chunks( $post_id );
			return true;
		}

		// Extract clean text from post + meta.
		$text = AICM_Text_Extractor::extract( $post_data['post'], $post_data['meta'] );

		// Split into chunks.
		$chunks = AICM_Chunker::chunk( $text );

		// Generate embeddings and write to aicm_chunks.
		return AICM_Embedder::embed_post(
			$post_id,
			$post_data['post_type'],
			$post_data['post']->post_title,
			$chunks,
			$provider
		);
	}

	/**
	 * Load the configured AI provider instance.
	 *
	 * Returns null when no API key is stored for the active provider —
	 * prevents wasted instantiation when the plugin is not yet configured.
	 *
	 * @return AICM_LLM_Provider|null
	 */
	private static function get_provider(): ?AICM_LLM_Provider {
		$active     = (string) AI_ChatMate::get_setting( 'active_provider', 'openai' );
		$option_key = "aicm_api_key_{$active}";

		// No key stored — bail immediately.
		if ( '' === (string) get_option( $option_key, '' ) ) {
			return null;
		}

		if ( 'openai' === $active ) {
			require_once AICM_PLUGIN_DIR . 'includes/providers/class-aicm-openai-provider.php';
			return new AICM_OpenAI_Provider();
		}

		// Anthropic and Google providers will be added in future phases.
		return null;
	}

	/**
	 * Recount chunks and pending items; write results to aicm_index_status.
	 *
	 * Called after every batch and after enqueue/remove operations to keep
	 * the admin UI accurate.
	 */
	private static function update_status(): void {
		global $wpdb;

		$chunks_table = $wpdb->prefix . 'aicm_chunks';
		$queue_table  = $wpdb->prefix . 'aicm_queue';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$chunks_table}`" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$queue_table}` WHERE status IN ('pending', 'processing')"
		);

		$status                 = get_option( 'aicm_index_status', array() );
		$status['total_chunks'] = $total_chunks;
		$status['pending']      = $pending_count;

		update_option( 'aicm_index_status', $status );
	}

	/**
	 * Set the is_running flag in aicm_index_status (for admin UI feedback).
	 */
	private static function mark_running(): void {
		$status               = get_option( 'aicm_index_status', array() );
		$status['is_running'] = true;
		update_option( 'aicm_index_status', $status );
	}

	/**
	 * Clear the is_running flag and record the completion timestamp.
	 */
	private static function mark_not_running(): void {
		$status                 = get_option( 'aicm_index_status', array() );
		$status['is_running']   = false;
		$status['last_indexed'] = current_time( 'mysql' );
		update_option( 'aicm_index_status', $status );
	}

	/**
	 * Acquire the processing concurrency lock.
	 *
	 * The lock is a transient with a 4-minute expiry. If it already exists,
	 * another cron run is still active and we should not start a new one.
	 * The expiry acts as a dead-man switch — if PHP crashes without calling
	 * release_lock(), the lock auto-expires and the next cron run can proceed.
	 *
	 * @return bool True if the lock was successfully acquired.
	 */
	private static function acquire_lock(): bool {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS );
		return true;
	}

	/**
	 * Release the processing concurrency lock.
	 */
	private static function release_lock(): void {
		delete_transient( self::LOCK_TRANSIENT );
	}
}
