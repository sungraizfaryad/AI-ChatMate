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

	<h1><?php echo esc_html__( 'Conciera — Schema', 'ai-chatmate' ); ?></h1>

	<p class="description">
		<?php
		echo esc_html__(
			'Conciera automatically discovers your post types, taxonomies, and custom fields. This schema is used to build the AI\'s search capabilities. Rescan after adding new post types or custom fields.',
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
