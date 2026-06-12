<?php
/**
 * Admin Analytics View
 *
 * Displays API cost history, index health, and (when logging is enabled)
 * conversation statistics.
 *
 * Data sources:
 *  aicm_monthly_usage  (wp_options) — per-month USD cost accumulated by
 *                                     AICM_Conversation_Handler::track_usage().
 *  aicm_index_status   (wp_options) — chunk count, last indexed time, etc.
 *  aicm_chunks         (table)      — live chunk count via COUNT(*).
 *  aicm_queue          (table)      — failed-item count.
 *  aicm_logs           (table)      — conversation stats (only if logging on).
 *
 * No JavaScript required — this page is entirely server-rendered.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Access denied.', 'ai-chatmate' ) );
}

global $wpdb;

// ── Monthly cost history ───────────────────────────────────────────────────
$monthly_usage = (array) get_option( 'aicm_monthly_usage', array() );

// Sort descending by month key (YYYY-MM) so newest is first.
krsort( $monthly_usage );

// We show at most 12 months of history.
$usage_display = array_slice( $monthly_usage, 0, 12, true );

// Current month key and cost.
$current_month   = gmdate( 'Y-m' );
$this_month_cost = (float) ( $monthly_usage[ $current_month ] ?? 0.0 );

// The largest monthly cost — used to scale the bar chart proportionally.
$max_cost = ! empty( $usage_display )
	? max( array_values( $usage_display ) )
	: 0.0;

// ── Budget ────────────────────────────────────────────────────────────────
$monthly_budget = (float) AI_ChatMate::get_setting( 'monthly_budget', 0.0 );
$budget_set     = $monthly_budget > 0.0;
$budget_pct     = ( $budget_set && $monthly_budget > 0 )
	? min( 100, round( ( $this_month_cost / $monthly_budget ) * 100, 1 ) )
	: 0.0;
$over_budget    = $budget_set && $this_month_cost >= $monthly_budget;

// ── Index stats ────────────────────────────────────────────────────────────
$index_status = (array) get_option(
	'aicm_index_status',
	array(
		'total_chunks' => 0,
		'pending'      => 0,
		'is_running'   => false,
		'last_indexed' => null,
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$total_chunks = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM `{$wpdb->prefix}aicm_chunks`"
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$failed_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM `{$wpdb->prefix}aicm_queue` WHERE status = 'failed'"
);

$last_indexed       = $index_status['last_indexed'] ?? null;
$last_indexed_human = $last_indexed
	? human_time_diff( (int) strtotime( $last_indexed ), time() )
	: null;

// ── Conversation log stats (only when logging is enabled) ──────────────────
$logging_enabled = (bool) AI_ChatMate::get_setting( 'logging_enabled', false );
$log_stats       = array(
	'sessions_this_month' => 0,
	'messages_this_month' => 0,
	'avg_response_ms'     => 0,
	'total_sessions_ever' => 0,
);

if ( $logging_enabled ) {
	$month_start = gmdate( 'Y-m-01 00:00:00' );
	$log_table   = $wpdb->prefix . 'aicm_logs';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$log_stats['sessions_this_month'] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT session_id) FROM `{$log_table}` WHERE created_at >= %s",
			$month_start
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$log_stats['messages_this_month'] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM `{$log_table}` WHERE role = 'user' AND created_at >= %s",
			$month_start
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$log_stats['avg_response_ms'] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT AVG(response_ms) FROM `{$log_table}` WHERE response_ms > 0 AND created_at >= %s",
			$month_start
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$log_stats['total_sessions_ever'] = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT session_id) FROM `{$log_table}`"
	);
}
?>
<div class="wrap" id="aicm-analytics-page">

	<h1><?php echo esc_html__( 'Conciera — Analytics', 'ai-chatmate' ); ?></h1>

	<?php if ( $over_budget ) : ?>
		<div class="notice notice-error inline" style="margin-bottom:20px;">
			<p>
				<strong><?php echo esc_html__( 'Monthly budget reached.', 'ai-chatmate' ); ?></strong>
				<?php
				printf(
					/* translators: 1: current cost, 2: budget limit */
					esc_html__( 'This month\'s API cost ($%1$s) has reached the $%2$s budget. The chat widget is paused until next month.', 'ai-chatmate' ),
					esc_html( number_format( $this_month_cost, 4 ) ),
					esc_html( number_format( $monthly_budget, 2 ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<!-- ── Summary cards ──────────────────────────────────────────────────── -->
	<div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">

		<!-- This month cost -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;min-width:180px;">
			<div style="font-size:26px;font-weight:700;color:#1d2327;">
				$<?php echo esc_html( number_format( $this_month_cost, 4 ) ); ?>
			</div>
			<div style="color:#646970;margin-top:4px;">
				<?php echo esc_html__( 'API cost this month', 'ai-chatmate' ); ?>
			</div>
			<?php if ( $budget_set ) : ?>
				<div style="margin-top:8px;">
					<div style="background:#f0f0f1;border-radius:3px;height:6px;overflow:hidden;">
						<div style="background:<?php echo $over_budget ? '#d63638' : '#00a32a'; ?>;height:100%;width:<?php echo esc_attr( $budget_pct ); ?>%;transition:width .3s;"></div>
					</div>
					<div style="font-size:11px;color:#646970;margin-top:4px;">
						<?php
						printf(
							/* translators: 1: percentage, 2: budget amount */
							esc_html__( '%1$s%% of $%2$s budget', 'ai-chatmate' ),
							esc_html( $budget_pct ),
							esc_html( number_format( $monthly_budget, 2 ) )
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Indexed chunks -->
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;min-width:180px;">
			<div style="font-size:26px;font-weight:700;color:#1d2327;">
				<?php echo esc_html( number_format_i18n( $total_chunks ) ); ?>
			</div>
			<div style="color:#646970;margin-top:4px;">
				<?php echo esc_html__( 'Chunks indexed', 'ai-chatmate' ); ?>
			</div>
			<?php if ( $last_indexed_human ) : ?>
				<div style="font-size:11px;color:#646970;margin-top:6px;">
					<?php
					printf(
						/* translators: %s: time difference */
						esc_html__( 'Last indexed: %s ago', 'ai-chatmate' ),
						esc_html( $last_indexed_human )
					);
					?>
				</div>
			<?php else : ?>
				<div style="font-size:11px;color:#dba617;margin-top:6px;">
					<?php echo esc_html__( 'Not yet indexed', 'ai-chatmate' ); ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Failed items -->
		<?php if ( $failed_count > 0 ) : ?>
			<div style="background:#fff;border:1px solid #d63638;border-radius:4px;padding:20px 24px;min-width:180px;">
				<div style="font-size:26px;font-weight:700;color:#d63638;">
					<?php echo esc_html( number_format_i18n( $failed_count ) ); ?>
				</div>
				<div style="color:#646970;margin-top:4px;">
					<?php echo esc_html__( 'Failed index items', 'ai-chatmate' ); ?>
				</div>
				<div style="font-size:11px;color:#646970;margin-top:6px;">
					<?php
					echo esc_html__(
						'Start a full re-index to retry.',
						'ai-chatmate'
					);
					?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $logging_enabled ) : ?>
			<!-- Chat sessions this month -->
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;min-width:180px;">
				<div style="font-size:26px;font-weight:700;color:#1d2327;">
					<?php echo esc_html( number_format_i18n( $log_stats['sessions_this_month'] ) ); ?>
				</div>
				<div style="color:#646970;margin-top:4px;">
					<?php echo esc_html__( 'Chat sessions this month', 'ai-chatmate' ); ?>
				</div>
				<div style="font-size:11px;color:#646970;margin-top:6px;">
					<?php
					printf(
						/* translators: %s: number of messages */
						esc_html__( '%s user messages', 'ai-chatmate' ),
						esc_html( number_format_i18n( $log_stats['messages_this_month'] ) )
					);
					?>
				</div>
			</div>
		<?php endif; ?>

	</div><!-- /cards -->

	<!-- ── Monthly cost history ───────────────────────────────────────────── -->
	<h2><?php echo esc_html__( 'Monthly API Cost', 'ai-chatmate' ); ?></h2>

	<?php if ( empty( $usage_display ) ) : ?>
		<p class="description">
			<?php echo esc_html__( 'No cost data yet. Data is recorded as visitors use the chat widget.', 'ai-chatmate' ); ?>
		</p>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped" style="max-width:600px;">
			<thead>
				<tr>
					<th style="width:40%;"><?php echo esc_html__( 'Month', 'ai-chatmate' ); ?></th>
					<th style="width:25%;"><?php echo esc_html__( 'Cost (USD)', 'ai-chatmate' ); ?></th>
					<th style="width:35%;"><?php echo esc_html__( 'Relative', 'ai-chatmate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $usage_display as $month_key => $cost ) :
					$cost       = (float) $cost;
					$bar_pct    = ( $max_cost > 0 ) ? round( ( $cost / $max_cost ) * 100, 1 ) : 0;
					$is_current = ( $month_key === $current_month );

					// Parse YYYY-MM into a localised month name.
					$ts          = mktime( 0, 0, 0, (int) substr( $month_key, 5, 2 ), 1, (int) substr( $month_key, 0, 4 ) );
					$month_label = date_i18n( 'F Y', $ts );
					?>
					<tr<?php echo $is_current ? ' style="font-weight:600;"' : ''; ?>>
						<td>
							<?php echo esc_html( $month_label ); ?>
							<?php if ( $is_current ) : ?>
								<span style="font-size:10px;background:#0073aa;color:#fff;padding:1px 6px;border-radius:3px;margin-left:4px;font-weight:400;">
									<?php echo esc_html__( 'current', 'ai-chatmate' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td>$<?php echo esc_html( number_format( $cost, 4 ) ); ?></td>
						<td>
							<div style="background:#f0f0f1;border-radius:3px;height:8px;overflow:hidden;max-width:140px;">
								<div style="background:#0073aa;height:100%;width:<?php echo esc_attr( $bar_pct ); ?>%;"></div>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th><?php echo esc_html__( 'All-time total', 'ai-chatmate' ); ?></th>
					<th colspan="2">
						$<?php echo esc_html( number_format( array_sum( array_values( $monthly_usage ) ), 4 ) ); ?>
					</th>
				</tr>
			</tfoot>
		</table>

	<?php endif; ?>

	<!-- ── Log-based stats ────────────────────────────────────────────────── -->
	<?php if ( $logging_enabled && $log_stats['total_sessions_ever'] > 0 ) : ?>

		<h2 style="margin-top:30px;">
			<?php echo esc_html__( 'Conversation Statistics', 'ai-chatmate' ); ?>
		</h2>

		<table class="form-table" role="presentation" style="max-width:480px;">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Total sessions (all time)', 'ai-chatmate' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $log_stats['total_sessions_ever'] ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Sessions this month', 'ai-chatmate' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $log_stats['sessions_this_month'] ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'User messages this month', 'ai-chatmate' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $log_stats['messages_this_month'] ) ); ?></td>
			</tr>
			<?php if ( $log_stats['avg_response_ms'] > 0 ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Avg. response time (this month)', 'ai-chatmate' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: %s: milliseconds */
							esc_html__( '%s ms', 'ai-chatmate' ),
							esc_html( number_format_i18n( $log_stats['avg_response_ms'] ) )
						);
						?>
					</td>
				</tr>
			<?php endif; ?>
		</table>

	<?php elseif ( ! $logging_enabled ) : ?>

		<div class="notice notice-info inline" style="margin-top:24px;max-width:640px;">
			<p>
				<strong><?php echo esc_html__( 'Conversation logging is disabled.', 'ai-chatmate' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to Settings page */
					esc_html__( 'Enable conversation logging in %s to track chat volume, session counts, and response times. IP addresses are never logged — only anonymous session IDs.', 'ai-chatmate' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=ai-chatmate' ) ) . '">'
					. esc_html__( 'Settings', 'ai-chatmate' )
					. '</a>'
				);
				?>
			</p>
		</div>

	<?php endif; ?>

	<!-- ── Shortcode reference ────────────────────────────────────────────── -->
	<h2 style="margin-top:30px;">
		<?php echo esc_html__( 'Embedding the Widget', 'ai-chatmate' ); ?>
	</h2>

	<p class="description">
		<?php
		echo esc_html__(
			'The floating chat button appears automatically on every page of your site. To ensure the widget loads on a specific page (e.g. when using a page builder that strips footer scripts), add the shortcode below anywhere on that page.',
			'ai-chatmate'
		);
		?>
	</p>

	<p>
		<code style="font-size:14px;padding:4px 10px;background:#f0f0f1;border-radius:3px;">[ai_chatmate]</code>
	</p>

	<!-- ── Downloadable chat logs ─────────────────────────────────────────── -->
	<h2 style="margin-top:30px;">
		<?php echo esc_html__( 'Chat Log Files', 'ai-chatmate' ); ?>
	</h2>

	<?php $aicm_log_files = AICM_Chat_Log::list_files(); ?>

	<?php if ( ! AICM_Chat_Log::is_enabled() && empty( $aicm_log_files ) ) : ?>
		<p class="description">
			<?php echo esc_html__( 'File logging is disabled. Enable "Downloadable Chat Logs" in Settings → Privacy to record each chat exchange to a downloadable daily file.', 'ai-chatmate' ); ?>
		</p>
	<?php elseif ( empty( $aicm_log_files ) ) : ?>
		<p class="description">
			<?php echo esc_html__( 'No log files yet — they appear here after the first logged conversation.', 'ai-chatmate' ); ?>
		</p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:560px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Day', 'ai-chatmate' ); ?></th>
					<th><?php echo esc_html__( 'Size', 'ai-chatmate' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $aicm_log_files as $aicm_log ) : ?>
					<tr>
						<td><?php echo esc_html( str_replace( '.jsonl', '', $aicm_log['file'] ) ); ?></td>
						<td><?php echo esc_html( size_format( $aicm_log['size'] ) ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aicm_download_log&file=' . rawurlencode( $aicm_log['file'] ) ), 'aicm_download_log' ) ); ?>">
								<?php echo esc_html__( 'Download', 'ai-chatmate' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php echo esc_html__( 'One JSON line per chat exchange. Files older than 30 days are removed automatically.', 'ai-chatmate' ); ?>
		</p>
	<?php endif; ?>

</div><!-- .wrap -->
