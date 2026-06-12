<?php
/**
 * Admin Content Indexing View
 *
 * Displays the current indexing status and provides controls to start
 * or stop a full content re-index.
 *
 * How it works:
 *  - PHP renders the initial state from the aicm_index_status option.
 *  - "Start Full Re-index" calls REST POST /index/start, which seeds the
 *    aicm_queue table. The actual embedding work happens in 5-minute
 *    WP-Cron batches — no API calls run inline.
 *  - "Stop Indexing" calls REST POST /index/stop, which clears pending
 *    queue rows. The current cron batch (if any) completes naturally.
 *  - A status poll runs every 5 seconds while indexing is active,
 *    calling GET /index/status to update the progress display.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Access denied.', 'ai-chatmate' ) );
}

// Load the current index status from wp_options.
$index_status = get_option(
	'aicm_index_status',
	array(
		'total_chunks' => 0,
		'pending'      => 0,
		'is_running'   => false,
		'last_indexed' => null,
	)
);

$total_chunks  = (int) ( $index_status['total_chunks'] ?? 0 );
$indexed_posts = (int) ( $index_status['indexed_posts'] ?? 0 );
$pending       = (int) ( $index_status['pending'] ?? 0 );
$is_running    = (bool) ( $index_status['is_running'] ?? false );
$last_indexed  = $index_status['last_indexed'] ?? null;

// Processing mode (frontend = this page drives the queue; background = the
// server drives itself via loopback requests + WP-Cron).
$indexing_mode = (string) AI_ChatMate::get_setting( 'indexing_mode', 'frontend' );

// Initial activity log entries (JS refreshes these live).
$initial_activity = AICM_Index_Manager::get_activity();

// Count failed queue items for the warning display.
global $wpdb;
$failed_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	"SELECT COUNT(*) FROM `{$wpdb->prefix}aicm_queue` WHERE status = 'failed'"
);

// Count the total posts across all configured post types (for context).
$configured_types = (array) AI_ChatMate::get_setting( 'index_post_types', array( 'post', 'page' ) );
$total_posts      = 0;
foreach ( $configured_types as $pt ) {
	$total_posts += AICM_Content_Fetcher::count_published( sanitize_key( (string) $pt ) );
}
?>
<div class="wrap" id="aicm-indexing-page">

	<h1><?php echo esc_html__( 'Conciera — Content Indexing', 'ai-chatmate' ); ?></h1>

	<p class="description">
		<?php
		echo esc_html__(
			'Content indexing converts your posts into AI-searchable embeddings. Click "Start Full Re-index" to build or rebuild the search index. Keep this page open while indexing runs — it processes the queue itself, so it works even on sites with no visitor traffic.',
			'ai-chatmate'
		);
		?>
	</p>

	<?php
	// Tell the admin plainly whether the frontend widget is visible, and if
	// not, exactly why — so there is never a mystery about a missing widget.
	require_once AICM_PLUGIN_DIR . 'public/class-aicm-frontend.php';
	$widget_status = AICM_Frontend::status();

	if ( $widget_status['enabled'] && ! $widget_status['ready'] ) :
		?>
		<div class="notice notice-warning inline" style="margin:12px 0;">
			<p>
				<strong><?php echo esc_html__( 'The chat widget will NOT display on your site yet.', 'ai-chatmate' ); ?></strong>
				<?php
				if ( 'indexing' === $widget_status['reason'] ) {
					echo esc_html__( 'The first content indexing run has not finished. Visitors would get answers from a half-built index, so the widget stays hidden and appears automatically the moment indexing completes.', 'ai-chatmate' );
				} elseif ( 'index_empty' === $widget_status['reason'] ) {
					echo esc_html__( 'Semantic Q&A is enabled and the content index is still empty. Run the indexing below — the widget appears automatically as soon as the index is ready.', 'ai-chatmate' );
				} else {
					echo esc_html__( 'No API key is saved for the active AI provider. Add your API key in Settings, then the widget appears automatically.', 'ai-chatmate' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<!-- ── Status cards ──────────────────────────────────────────────────── -->
	<div class="aicm-cards" style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">

		<!-- Chunks indexed -->
		<div class="aicm-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;min-width:180px;">
			<div style="font-size:28px;font-weight:700;color:#1d2327;" id="aicm-total-chunks">
				<?php echo esc_html( number_format_i18n( $total_chunks ) ); ?>
			</div>
			<div style="color:#646970;margin-top:4px;"><?php echo esc_html__( 'Chunks indexed', 'ai-chatmate' ); ?></div>
		</div>

		<!-- Total posts -->
		<div class="aicm-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;min-width:180px;">
			<div style="font-size:28px;font-weight:700;color:#1d2327;">
				<?php echo esc_html( number_format_i18n( $total_posts ) ); ?>
			</div>
			<div style="color:#646970;margin-top:4px;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: comma-separated list of post type slugs */
						__( 'Published posts (%s)', 'ai-chatmate' ),
						implode( ', ', array_map( 'esc_html', $configured_types ) )
					)
				);
				?>
			</div>
		</div>

		<!-- Posts indexed -->
		<div class="aicm-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;min-width:180px;">
			<div style="font-size:28px;font-weight:700;color:#1d2327;" id="aicm-indexed-posts">
				<?php echo esc_html( number_format_i18n( $indexed_posts ) ); ?>
			</div>
			<div style="color:#646970;margin-top:4px;"><?php echo esc_html__( 'Posts indexed', 'ai-chatmate' ); ?></div>
		</div>

		<!-- Queue pending -->
		<div class="aicm-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;min-width:180px;">
			<div style="font-size:28px;font-weight:700;color:#1d2327;" id="aicm-pending-count">
				<?php echo esc_html( number_format_i18n( $pending ) ); ?>
			</div>
			<div style="color:#646970;margin-top:4px;"><?php echo esc_html__( 'Pending in queue', 'ai-chatmate' ); ?></div>
		</div>

	</div>

	<!-- ── Running status ────────────────────────────────────────────────── -->
	<p id="aicm-running-status" style="margin:0 0 16px;">
		<?php if ( $is_running ) : ?>
			<span class="aicm-badge" style="background:#d63638;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">
				<?php echo esc_html__( 'Indexing in progress…', 'ai-chatmate' ); ?>
			</span>
		<?php elseif ( $last_indexed ) : ?>
			<span class="aicm-badge" style="background:#00a32a;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">
				<?php
				printf(
					/* translators: %s: human-readable time difference */
					esc_html__( 'Last indexed: %s ago', 'ai-chatmate' ),
					esc_html( human_time_diff( strtotime( $last_indexed ), time() ) )
				);
				?>
			</span>
		<?php else : ?>
			<span class="aicm-badge" style="background:#dba617;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">
				<?php echo esc_html__( 'Not yet indexed', 'ai-chatmate' ); ?>
			</span>
		<?php endif; ?>
	</p>

	<!-- ── Failed items warning ──────────────────────────────────────────── -->
	<?php if ( $failed_count > 0 ) : ?>
		<div class="notice notice-warning inline" style="margin-bottom:16px;">
			<p>
				<?php
				printf(
					esc_html(
						/* translators: %d: number of failed queue items */
						_n(
							'%d post failed to index after 3 attempts. Starting a full re-index will retry these posts.',
							'%d posts failed to index after 3 attempts. Starting a full re-index will retry these posts.',
							$failed_count,
							'ai-chatmate'
						)
					),
					(int) $failed_count
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<!-- ── Action message (set by JS) ────────────────────────────────────── -->
	<div id="aicm-index-message" style="display:none;margin-bottom:16px;"></div>

	<!-- ── Scan scope (locked while a run is in progress) ────────────────── -->
	<fieldset class="aicm-option-group" id="aicm-scope-group">
		<legend><strong><?php echo esc_html__( 'What to index', 'ai-chatmate' ); ?></strong></legend>
		<label style="display:block;margin:6px 0;">
			<input type="radio" name="aicm_scan_scope" value="new" checked <?php disabled( $is_running ); ?>>
			<?php echo esc_html__( 'New content only (recommended)', 'ai-chatmate' ); ?>
			<span class="description" style="display:block;margin:2px 0 0 24px;">
				<?php echo esc_html__( 'Skips posts that are already in the index. Edited posts are re-indexed automatically when you save them, so this is the right choice for routine scans — and it never pays for the same content twice.', 'ai-chatmate' ); ?>
			</span>
		</label>
		<label style="display:block;margin:6px 0;">
			<input type="radio" name="aicm_scan_scope" value="all" <?php disabled( $is_running ); ?>>
			<?php echo esc_html__( 'Re-index everything', 'ai-chatmate' ); ?>
			<span class="description" style="display:block;margin:2px 0 0 24px;">
				<?php echo esc_html__( 'Queues every published post again, including content already indexed. Use after changing the embedding model or if the index looks wrong.', 'ai-chatmate' ); ?>
			</span>
		</label>
	</fieldset>

	<!-- ── Current processing mode (configured in Settings → Indexing) ───── -->
	<p class="description" style="margin:0 0 14px;">
		<strong><?php echo esc_html__( 'Processing mode:', 'ai-chatmate' ); ?></strong>
		<?php
		echo esc_html(
			'background' === $indexing_mode
				? __( 'In the background — the server processes the queue on its own; you can close this page.', 'ai-chatmate' )
				: __( 'While this page is open — keep this tab open until indexing finishes.', 'ai-chatmate' )
		);
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-chatmate#indexing' ) ); ?>">
			<?php echo esc_html__( 'Change in Settings', 'ai-chatmate' ); ?>
		</a>
	</p>

	<!-- ── Action buttons ────────────────────────────────────────────────── -->
	<p>
		<button type="button" id="aicm-start-index" class="button button-primary"
			<?php echo $is_running ? 'disabled' : ''; ?>>
			<?php echo esc_html__( 'Start Indexing', 'ai-chatmate' ); ?>
		</button>
		&nbsp;
		<button type="button" id="aicm-stop-index" class="button"
			<?php echo $is_running ? '' : 'style="display:none;"'; ?>>
			<?php echo esc_html__( 'Stop Indexing', 'ai-chatmate' ); ?>
		</button>
		<span id="aicm-index-spinner" class="spinner" style="float:none;vertical-align:middle;<?php echo $is_running ? 'visibility:visible;' : ''; ?>"></span>
	</p>

	<!-- ── Live activity log ─────────────────────────────────────────────── -->
	<div id="aicm-activity-panel" class="aicm-activity-panel" <?php echo ( empty( $initial_activity ) && ! $is_running ) ? 'style="display:none;"' : ''; ?>>
		<h2 class="aicm-activity-title">
			<?php echo esc_html__( 'Indexing activity', 'ai-chatmate' ); ?>
			<span id="aicm-activity-live" class="aicm-activity-live" <?php echo $is_running ? '' : 'style="display:none;"'; ?>>
				<?php echo esc_html__( 'live', 'ai-chatmate' ); ?>
			</span>
		</h2>
		<ul id="aicm-activity-list" class="aicm-activity-list">
			<?php foreach ( $initial_activity as $entry ) : ?>
				<li class="<?php echo $entry['ok'] ? 'aicm-act-ok' : 'aicm-act-fail'; ?>" data-key="<?php echo esc_attr( $entry['post_id'] . '|' . $entry['time'] ); ?>">
					<span class="aicm-act-type"><?php echo esc_html( $entry['type'] ?? '—' ); ?></span>
					<span class="aicm-act-title"><?php echo esc_html( $entry['title'] ); ?></span>
					<span class="aicm-act-status">
						<?php
						if ( 'delete' === $entry['action'] ) {
							echo esc_html__( 'Removed', 'ai-chatmate' );
						} elseif ( $entry['ok'] ) {
							echo esc_html__( 'Indexed ✓', 'ai-chatmate' );
						} else {
							echo esc_html__( 'Failed ✕', 'ai-chatmate' );
						}
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<!-- ── How it works note ─────────────────────────────────────────────── -->
	<div class="notice notice-info inline" style="margin-top:20px;">
		<p>
			<strong><?php echo esc_html__( 'How indexing works:', 'ai-chatmate' ); ?></strong>
			<?php
			echo esc_html__(
				'"Start Indexing" adds your content to a queue, which is then processed in small, retry-safe batches — either by this page or by the server in the background, depending on the mode you choose above. Large sites can take a while on the first run; later runs only pick up new content.',
				'ai-chatmate'
			);
			?>
		</p>
	</div>

</div><!-- .wrap -->
