<?php
/**
 * Admin Schema View
 *
 * Displays the auto-discovered site schema and allows the admin to
 * trigger a manual re-scan.
 *
 * The schema data is loaded from the cache (wp_options) — not re-computed
 * on every page load. The "Rescan Now" button calls the REST API.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Access denied.', 'ai-chatmate' ) );
}

require_once AICM_PLUGIN_DIR . 'includes/schema/class-aicm-schema-cache.php';

$schema         = AICM_Schema_Cache::get();
$last_generated = AICM_Schema_Cache::last_generated_at();
?>
<div class="wrap" id="aicm-schema-page">

	<h1><?php echo esc_html__( 'AI ChatMate — Schema', 'ai-chatmate' ); ?></h1>

	<p class="description">
		<?php
		echo esc_html__(
			'AI ChatMate automatically discovers your post types, taxonomies, and custom fields. This schema is used to build the AI\'s search capabilities. Rescan after adding new post types or custom fields.',
			'ai-chatmate'
		);
		?>
	</p>

	<!-- Status bar -->
	<div class="aicm-schema-status-bar">
		<?php if ( $last_generated ) : ?>
			<span class="aicm-badge aicm-badge-ok">
				<?php
				printf(
					/* translators: %s: human-readable time ago */
					esc_html__( 'Last scanned: %s ago', 'ai-chatmate' ),
					esc_html( human_time_diff( strtotime( $last_generated ), time() ) )
				);
				?>
			</span>
		<?php else : ?>
			<span class="aicm-badge aicm-badge-warn">
				<?php echo esc_html__( 'Not yet scanned', 'ai-chatmate' ); ?>
			</span>
		<?php endif; ?>

		<!-- Detected plugins -->
		<?php if ( $schema ) : ?>
			<?php if ( ! empty( $schema['has_acf'] ) ) : ?>
				<span class="aicm-badge aicm-badge-plugin">ACF</span>
			<?php endif; ?>
			<?php if ( ! empty( $schema['has_metabox'] ) ) : ?>
				<span class="aicm-badge aicm-badge-plugin">MetaBox</span>
			<?php endif; ?>
			<?php if ( ! empty( $schema['has_woocommerce'] ) ) : ?>
				<span class="aicm-badge aicm-badge-plugin">WooCommerce</span>
			<?php endif; ?>
		<?php endif; ?>

		<button type="button" id="aicm-rescan-btn" class="button button-primary" style="margin-left:12px;">
			<?php echo esc_html__( 'Rescan Now', 'ai-chatmate' ); ?>
		</button>
		<span id="aicm-rescan-status" style="margin-left:10px; font-style:italic; color:#555;" aria-live="polite"></span>
	</div>

	<hr>

	<?php if ( ! $schema ) : ?>
		<!-- No schema yet -->
		<div class="notice notice-info inline">
			<p>
				<?php
				echo esc_html__(
					'No schema discovered yet. Click "Rescan Now" to scan your site, or wait for the automatic weekly scan.',
					'ai-chatmate'
				);
				?>
			</p>
		</div>

	<?php else : ?>

		<!-- Post type cards -->
		<?php if ( ! empty( $schema['post_types'] ) ) : ?>
			<div id="aicm-schema-grid">
				<?php foreach ( $schema['post_types'] as $pt_name => $pt_data ) : ?>
					<?php
					$is_search = in_array( $pt_name, $schema['search_enabled_types'] ?? array(), true );
					$count     = (int) ( $pt_data['count'] ?? 0 );
					?>
					<div class="aicm-pt-card">
						<div class="aicm-pt-card-header">
							<strong><?php echo esc_html( $pt_data['label'] ?? $pt_name ); ?></strong>
							<code><?php echo esc_html( $pt_name ); ?></code>
							<span class="aicm-pt-count">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of posts */
										_n( '%d post', '%d posts', $count, 'ai-chatmate' ),
										$count
									)
								);
								?>
							</span>
							<?php if ( $is_search ) : ?>
								<span class="aicm-badge aicm-badge-search" title="<?php echo esc_attr__( 'This post type has enough structured fields to support Smart Search mode.', 'ai-chatmate' ); ?>">
									<?php echo esc_html__( 'Search', 'ai-chatmate' ); ?>
								</span>
							<?php endif; ?>
							<span class="aicm-badge aicm-badge-rag" title="<?php echo esc_attr__( 'This post type is indexed for Q&A / RAG mode.', 'ai-chatmate' ); ?>">
								<?php echo esc_html__( 'Q&amp;A', 'ai-chatmate' ); ?>
							</span>
						</div>

						<!-- Taxonomies -->
						<?php if ( ! empty( $pt_data['taxonomies'] ) ) : ?>
							<div class="aicm-pt-section">
								<h4><?php echo esc_html__( 'Taxonomies', 'ai-chatmate' ); ?></h4>
								<table class="widefat striped aicm-inner-table">
									<thead>
										<tr>
											<th><?php echo esc_html__( 'Taxonomy', 'ai-chatmate' ); ?></th>
											<th><?php echo esc_html__( 'Slug', 'ai-chatmate' ); ?></th>
											<th><?php echo esc_html__( 'Terms (sample)', 'ai-chatmate' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $pt_data['taxonomies'] as $tax_name => $tax_data ) : ?>
											<tr>
												<td><?php echo esc_html( $tax_data['label'] ?? $tax_name ); ?></td>
												<td><code><?php echo esc_html( $tax_name ); ?></code></td>
												<td>
													<?php
													$terms  = $tax_data['terms'] ?? array();
													$sample = array_slice( $terms, 0, 8 );
													$trunc  = ! empty( $tax_data['truncated'] );
													echo esc_html( implode( ', ', $sample ) );
													if ( $trunc || count( $terms ) > 8 ) {
														printf(
															'<em> … +%d more</em>',
															(int) ( ( $tax_data['term_count'] ?? count( $terms ) ) - count( $sample ) )
														);
													}
													?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>

						<!-- Meta fields -->
						<?php if ( ! empty( $pt_data['meta_fields'] ) ) : ?>
							<div class="aicm-pt-section">
								<h4><?php echo esc_html__( 'Custom Fields', 'ai-chatmate' ); ?></h4>
								<table class="widefat striped aicm-inner-table">
									<thead>
										<tr>
											<th><?php echo esc_html__( 'Label', 'ai-chatmate' ); ?></th>
											<th><?php echo esc_html__( 'Key', 'ai-chatmate' ); ?></th>
											<th><?php echo esc_html__( 'Type', 'ai-chatmate' ); ?></th>
											<th><?php echo esc_html__( 'Source', 'ai-chatmate' ); ?></th>
											<th><?php echo esc_html__( 'Range / Choices', 'ai-chatmate' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $pt_data['meta_fields'] as $field_key => $field_info ) : ?>
											<tr>
												<td><?php echo esc_html( $field_info['label'] ?? $field_key ); ?></td>
												<td><code><?php echo esc_html( $field_key ); ?></code></td>
												<td>
													<span class="aicm-type-badge aicm-type-<?php echo esc_attr( $field_info['type'] ?? 'text' ); ?>">
														<?php echo esc_html( $field_info['type'] ?? 'text' ); ?>
													</span>
												</td>
												<td>
													<span class="aicm-source-badge">
														<?php echo esc_html( $field_info['source'] ?? '—' ); ?>
													</span>
												</td>
												<td>
													<?php
													if ( isset( $field_info['min'], $field_info['max'] ) ) {
														printf(
															'%s – %s',
															esc_html( number_format_i18n( $field_info['min'] ) ),
															esc_html( number_format_i18n( $field_info['max'] ) )
														);
													} elseif ( ! empty( $field_info['choices'] ) ) {
														echo esc_html( implode( ', ', array_slice( $field_info['choices'], 0, 5 ) ) );
														if ( count( $field_info['choices'] ) > 5 ) {
															echo esc_html( ' …' );
														}
													} else {
														echo '—';
													}
													?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php else : ?>
							<p class="aicm-no-fields">
								<?php echo esc_html__( 'No custom fields detected for this post type.', 'ai-chatmate' ); ?>
							</p>
						<?php endif; ?>

					</div><!-- .aicm-pt-card -->
				<?php endforeach; ?>
			</div><!-- #aicm-schema-grid -->
		<?php endif; ?>

	<?php endif; ?>

</div><!-- .wrap -->

<script>
( function () {
	'use strict';

	const btn    = document.getElementById( 'aicm-rescan-btn' );
	const status = document.getElementById( 'aicm-rescan-status' );

	if ( ! btn || ! window.aicmAdmin ) return;

	btn.addEventListener( 'click', function () {
		btn.disabled      = true;
		status.textContent = '<?php echo esc_js( __( 'Scanning…', 'ai-chatmate' ) ); ?>';

		fetch( aicmAdmin.restUrl + '/schema/rescan', {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   aicmAdmin.nonce,
			},
		} )
		.then( function ( res ) { return res.json(); } )
		.then( function ( json ) {
			if ( json.success ) {
				status.textContent = '<?php echo esc_js( __( 'Done! Reloading…', 'ai-chatmate' ) ); ?>';
				setTimeout( function () { window.location.reload(); }, 800 );
			} else {
				status.textContent = json.message || '<?php echo esc_js( __( 'Error. Please try again.', 'ai-chatmate' ) ); ?>';
				btn.disabled = false;
			}
		} )
		.catch( function () {
			status.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'ai-chatmate' ) ); ?>';
			btn.disabled = false;
		} );
	} );
} () );
</script>

<style>
/* Schema page styles — scoped to the schema page wrapper */
#aicm-schema-page .aicm-schema-status-bar { margin: 16px 0; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }

#aicm-schema-page .aicm-badge {
	display: inline-block; padding: 3px 8px; border-radius: 3px;
	font-size: 12px; font-weight: 600; line-height: 1.4;
}
.aicm-badge-ok     { background: #d4edda; color: #155724; }
.aicm-badge-warn   { background: #fff3cd; color: #856404; }
.aicm-badge-plugin { background: #cce5ff; color: #004085; }
.aicm-badge-search { background: #d1ecf1; color: #0c5460; }
.aicm-badge-rag    { background: #e2d9f3; color: #4a1d96; }

#aicm-schema-grid { display: flex; flex-direction: column; gap: 24px; margin-top: 20px; }

.aicm-pt-card {
	background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 16px 20px;
	box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.aicm-pt-card-header {
	display: flex; align-items: center; flex-wrap: wrap;
	gap: 8px; margin-bottom: 12px;
}
.aicm-pt-card-header strong { font-size: 15px; }
.aicm-pt-card-header code   { background: #f0f0f1; padding: 2px 6px; }
.aicm-pt-count               { color: #666; font-size: 13px; }

.aicm-pt-section       { margin-top: 14px; }
.aicm-pt-section h4    { margin: 0 0 8px; font-size: 13px; text-transform: uppercase; color: #666; letter-spacing: .5px; }
.aicm-inner-table      { font-size: 13px; }
.aicm-inner-table th   { font-weight: 600; }
.aicm-no-fields        { color: #888; font-style: italic; margin: 8px 0 0; font-size: 13px; }

.aicm-type-badge {
	display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 11px; font-weight: 700; text-transform: uppercase;
}
.aicm-type-numeric  { background: #d4edda; color: #155724; }
.aicm-type-boolean  { background: #fff3cd; color: #856404; }
.aicm-type-date     { background: #cce5ff; color: #004085; }
.aicm-type-text     { background: #f0f0f1; color: #555; }

.aicm-source-badge  { font-size: 12px; color: #888; font-style: italic; }
</style>
