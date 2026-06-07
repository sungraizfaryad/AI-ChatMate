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

$total_chunks = (int) ( $index_status['total_chunks'] ?? 0 );
$pending      = (int) ( $index_status['pending']      ?? 0 );
$is_running   = (bool) ( $index_status['is_running']  ?? false );
$last_indexed = $index_status['last_indexed'] ?? null;

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

	<h1><?php echo esc_html__( 'AI ChatMate — Content Indexing', 'ai-chatmate' ); ?></h1>

	<p class="description">
		<?php
		echo esc_html__(
			'Content indexing converts your posts into AI-searchable embeddings. Click "Start Full Re-index" to build or rebuild the search index. Indexing runs in the background — this page updates automatically while it is active.',
			'ai-chatmate'
		);
		?>
	</p>

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
					/* translators: %d: number of failed queue items */
					esc_html(
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

	<!-- ── Action buttons ────────────────────────────────────────────────── -->
	<p>
		<button type="button" id="aicm-start-index" class="button button-primary"
			<?php echo $is_running ? 'disabled' : ''; ?>>
			<?php echo esc_html__( 'Start Full Re-index', 'ai-chatmate' ); ?>
		</button>
		&nbsp;
		<button type="button" id="aicm-stop-index" class="button"
			<?php echo $is_running ? '' : 'style="display:none;"'; ?>>
			<?php echo esc_html__( 'Stop Indexing', 'ai-chatmate' ); ?>
		</button>
		<span id="aicm-index-spinner" class="spinner" style="float:none;vertical-align:middle;<?php echo $is_running ? 'visibility:visible;' : ''; ?>"></span>
	</p>

	<!-- ── How it works note ─────────────────────────────────────────────── -->
	<div class="notice notice-info inline" style="margin-top:20px;">
		<p>
			<strong><?php echo esc_html__( 'How indexing works:', 'ai-chatmate' ); ?></strong>
			<?php
			echo esc_html__(
				'"Start Full Re-index" adds all your posts to a background queue. WordPress processes them in small batches every 5 minutes using WP-Cron. This keeps your server load low and makes the process retryable. Large sites may take 30–60 minutes to fully index.',
				'ai-chatmate'
			);
			?>
		</p>
	</div>

</div><!-- .wrap -->

<script>
( function () {
	'use strict';

	const restBase  = aicmAdmin.restUrl;
	const nonce     = aicmAdmin.nonce;

	const startBtn  = document.getElementById( 'aicm-start-index' );
	const stopBtn   = document.getElementById( 'aicm-stop-index' );
	const spinner   = document.getElementById( 'aicm-index-spinner' );
	const msgBox    = document.getElementById( 'aicm-index-message' );
	const statusEl  = document.getElementById( 'aicm-running-status' );
	const chunksEl  = document.getElementById( 'aicm-total-chunks' );
	const pendingEl = document.getElementById( 'aicm-pending-count' );

	let pollTimer = null;

	// ── Show a message to the admin ──────────────────────────────────────
	function showMessage( text, type ) {
		msgBox.innerHTML   = '<div class="notice notice-' + type + ' inline"><p>' + escHtml( text ) + '</p></div>';
		msgBox.style.display = 'block';
	}

	function escHtml( str ) {
		return str.replace( /&/g, '&amp;' )
		          .replace( /</g, '&lt;' )
		          .replace( />/g, '&gt;' )
		          .replace( /"/g, '&quot;' );
	}

	// ── Set the UI to "running" or "idle" state ──────────────────────────
	function setRunning( running ) {
		startBtn.disabled             = running;
		stopBtn.style.display         = running ? '' : 'none';
		spinner.style.visibility      = running ? 'visible' : 'hidden';
	}

	// ── Update the status display from a status object ───────────────────
	function applyStatus( status ) {
		if ( chunksEl )  chunksEl.textContent  = Number( status.total_chunks || 0 ).toLocaleString();
		if ( pendingEl ) pendingEl.textContent  = Number( status.pending      || 0 ).toLocaleString();

		setRunning( !! status.is_running );

		if ( status.is_running ) {
			statusEl.innerHTML = '<span class="aicm-badge" style="background:#d63638;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;"><?php echo esc_js( __( 'Indexing in progress…', 'ai-chatmate' ) ); ?></span>';
		} else if ( ! status.is_running && status.last_indexed ) {
			statusEl.innerHTML = '<span class="aicm-badge" style="background:#00a32a;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;"><?php echo esc_js( __( 'Indexing complete', 'ai-chatmate' ) ); ?></span>';
		}
	}

	// ── Poll GET /index/status every 5 seconds while running ─────────────
	function startPolling() {
		stopPolling();
		pollTimer = setInterval( function () {
			fetch( restBase + '/index/status', {
				headers: { 'X-WP-Nonce': nonce }
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				applyStatus( data );
				if ( ! data.is_running ) {
					stopPolling();
				}
			} )
			.catch( function () { /* silent — transient network blip */ } );
		}, 5000 );
	}

	function stopPolling() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	// ── Start Full Re-index ───────────────────────────────────────────────
	startBtn.addEventListener( 'click', function () {
		startBtn.disabled = true;
		setRunning( true );
		msgBox.style.display = 'none';

		fetch( restBase + '/index/start', {
			method:  'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( data ) {
			if ( data.success ) {
				showMessage( data.message, 'success' );
				applyStatus( { is_running: true, pending: data.queued, total_chunks: ( chunksEl ? parseInt( chunksEl.textContent.replace( /\D/g, '' ), 10 ) : 0 ) } );
				startPolling();
			} else {
				showMessage( data.message || '<?php echo esc_js( __( 'Could not start indexing. Please try again.', 'ai-chatmate' ) ); ?>', 'error' );
				setRunning( false );
			}
		} )
		.catch( function () {
			showMessage( '<?php echo esc_js( __( 'Request failed. Please check your connection and try again.', 'ai-chatmate' ) ); ?>', 'error' );
			setRunning( false );
		} );
	} );

	// ── Stop Indexing ─────────────────────────────────────────────────────
	stopBtn.addEventListener( 'click', function () {
		stopPolling();
		fetch( restBase + '/index/stop', {
			method:  'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function () {
			setRunning( false );
			showMessage( '<?php echo esc_js( __( 'Indexing stopped. Pending items have been cleared.', 'ai-chatmate' ) ); ?>', 'info' );
			if ( pendingEl ) pendingEl.textContent = '0';
		} )
		.catch( function () {
			showMessage( '<?php echo esc_js( __( 'Could not stop indexing. Please try again.', 'ai-chatmate' ) ); ?>', 'error' );
		} );
	} );

	// ── Auto-start polling if page loads while running ────────────────────
	<?php if ( $is_running ) : ?>
	startPolling();
	<?php endif; ?>

} )();
</script>
