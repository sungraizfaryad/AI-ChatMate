<?php
/**
 * Auto Sync
 *
 * Hooks into the WordPress post lifecycle to keep the content index
 * current when posts are created, updated, trashed, or permanently deleted.
 *
 * ── Hook overview ─────────────────────────────────────────────────────────
 *  save_post           → queue for re-indexing (published) or remove (un-published)
 *  before_delete_post  → remove from index immediately (permanent delete)
 *  wp_trash_post       → remove from index immediately (trashed)
 *  untrash_post        → queue for re-indexing (restored to publish)
 *
 * ── Indexing is deferred ──────────────────────────────────────────────────
 * save_post and untrash_post do NOT index content immediately — they add
 * a row to aicm_queue and let the 5-minute WP-Cron job do the embedding.
 * This keeps the save_post request fast (no API calls inline) and makes
 * the indexing retryable if the API call fails.
 *
 * ── Removal is immediate ──────────────────────────────────────────────────
 * before_delete_post and wp_trash_post remove the embedding chunks from the
 * database right away — we do not want deleted or trashed content to
 * continue appearing in chatbot answers.
 *
 * ── Guards ────────────────────────────────────────────────────────────────
 * Every callback checks:
 *  - auto_sync setting is enabled.
 *  - The post type is in the admin-configured index_post_types list.
 *  - The post is not an autosave or revision (for save_post).
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Auto_Sync
 */
class AICM_Auto_Sync {

	// ── Registration ─────────────────────────────────────────────────────────

	/**
	 * Register all WordPress post-lifecycle hooks.
	 *
	 * Called from AI_ChatMate::register_hooks() — fires on every request
	 * (frontend, admin, REST, WP-Cron) so hooks are available wherever
	 * WordPress performs post state changes.
	 *
	 * Priority 20 for save_post — runs AFTER all other plugins have finished
	 * saving meta, so get_post_meta() returns the final, complete values.
	 */
	public static function init(): void {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 10, 1 );
		add_action( 'untrash_post', array( __CLASS__, 'on_untrash_post' ), 10, 1 );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────────

	/**
	 * Callback for the `save_post` action.
	 *
	 * Fires after ANY post is saved (new, update, Gutenberg autosave, revision).
	 * We filter carefully to avoid spurious re-indexing:
	 *
	 *  DOING_AUTOSAVE: Gutenberg fires save_post on background autosaves.
	 *   These happen every 60 s and should never trigger an API call.
	 *
	 *  wp_is_post_revision(): WordPress stores each edit as a 'revision' post
	 *   with its own post ID. We only index the canonical parent post.
	 *
	 * Status logic:
	 *  - 'publish'  → queue for (re-)indexing.
	 *  - anything else → remove from index in case the post was previously
	 *    published (e.g. reverted to draft, scheduled, pending review).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object (state AFTER the save).
	 * @param bool    $update  True if updating an existing post; false for new.
	 */
	public static function on_save_post( int $post_id, WP_Post $post, bool $update ): void {
		// Skip Gutenberg background autosaves.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip revision posts.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! self::is_auto_sync_active( $post->post_type ) ) {
			return;
		}

		if ( 'publish' === $post->post_status ) {
			// Enqueue for (re-)indexing via the background queue.
			AICM_Index_Manager::enqueue_post( $post_id, 'index' );
		} else {
			// Post is no longer public — remove from the search index
			// immediately so stale content does not appear in answers.
			AICM_Index_Manager::remove_post_from_index( $post_id );
		}
	}

	/**
	 * Callback for the `before_delete_post` action.
	 *
	 * Fires BEFORE a post is permanently deleted. We remove the chunks here
	 * because after deletion the post no longer exists and any subsequent
	 * lookup by post_id would reference a ghost record.
	 *
	 * WordPress also fires before_delete_post for revisions, attachments,
	 * and menu items — we guard against unrelated post types.
	 *
	 * @param int $post_id Post ID being permanently deleted.
	 */
	public static function on_before_delete_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		// Only handle post types we actually index; skip all others silently.
		$configured_types = (array) AI_ChatMate::get_setting(
			'index_post_types',
			array( 'post', 'page' )
		);

		if ( ! in_array( $post->post_type, $configured_types, true ) ) {
			return;
		}

		// Remove immediately — no auto_sync check needed for deletions.
		// Content must leave the index even if auto_sync is disabled.
		AICM_Index_Manager::remove_post_from_index( $post_id );
	}

	/**
	 * Callback for the `wp_trash_post` action.
	 *
	 * Fires when a post is moved to the Trash. The post still exists in the
	 * database with post_status = 'trash', but it must no longer appear in
	 * chatbot answers.
	 *
	 * @param int $post_id Post ID being trashed.
	 */
	public static function on_trash_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$configured_types = (array) AI_ChatMate::get_setting(
			'index_post_types',
			array( 'post', 'page' )
		);

		if ( ! in_array( $post->post_type, $configured_types, true ) ) {
			return;
		}

		// Remove immediately — same reasoning as on_before_delete_post.
		AICM_Index_Manager::remove_post_from_index( $post_id );
	}

	/**
	 * Callback for the `untrash_post` action.
	 *
	 * Fires when a post is restored from the Trash. At the moment this hook
	 * fires, the post still has status = 'trash' in the database. WordPress
	 * stores the pre-trash status in the '_wp_trash_meta_status' post meta key
	 * so we can determine what status the post will be restored to.
	 *
	 * If the post will be restored to 'publish', we queue it for re-indexing.
	 * If it will be restored to 'draft' or 'pending', we leave it un-indexed.
	 *
	 * @param int $post_id Post ID being restored.
	 */
	public static function on_untrash_post( int $post_id ): void {
		// Read the status the post will be restored to.
		$previous_status = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );

		if ( 'publish' !== $previous_status ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		if ( ! self::is_auto_sync_active( $post->post_type ) ) {
			return;
		}

		AICM_Index_Manager::enqueue_post( $post_id, 'index' );
	}

	// ── Private helper ────────────────────────────────────────────────────────

	/**
	 * Check whether auto-sync is active for the given post type.
	 *
	 * Returns false when:
	 *  - The admin has disabled auto_sync in settings.
	 *  - The post type is not in the configured index_post_types list.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private static function is_auto_sync_active( string $post_type ): bool {
		if ( ! (bool) AI_ChatMate::get_setting( 'auto_sync', true ) ) {
			return false;
		}

		$configured_types = (array) AI_ChatMate::get_setting(
			'index_post_types',
			array( 'post', 'page' )
		);

		return in_array( $post_type, $configured_types, true );
	}
}
