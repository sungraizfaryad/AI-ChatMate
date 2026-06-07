<?php
/**
 * Onboarding Wizard View
 *
 * Rendered by AICM_Admin for the top-level page when onboarding is incomplete
 * (or when ?onboarding=1). Pure markup; aicm-wizard.js drives the steps and
 * fills detected data. aicmAdmin (restUrl/nonce/i18n) is localized by AICM_Admin.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Access denied.', 'ai-chatmate' ) );
}

$aicm_steps = array(
	__( 'Welcome', 'ai-chatmate' ),
	__( 'Detect', 'ai-chatmate' ),
	__( 'Content types', 'ai-chatmate' ),
	__( 'Fields', 'ai-chatmate' ),
	__( 'Connect AI', 'ai-chatmate' ),
	__( 'Widget', 'ai-chatmate' ),
);
?>
<div class="wrap" id="aicm-wizard">
	<h1><?php echo esc_html__( 'AI ChatMate — Setup', 'ai-chatmate' ); ?></h1>

	<ol class="aicm-wizard-steps" aria-hidden="true">
		<?php foreach ( $aicm_steps as $aicm_i => $aicm_label ) : ?>
			<li class="aicm-wizard-step<?php echo 0 === $aicm_i ? ' is-active' : ''; ?>" data-step="<?php echo (int) $aicm_i; ?>">
				<span class="aicm-wizard-num"><?php echo (int) $aicm_i + 1; ?></span>
				<span class="aicm-wizard-label"><?php echo esc_html( $aicm_label ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div id="aicm-wizard-notice" class="notice" style="display:none;"></div>

	<!-- 0: Welcome -->
	<section class="aicm-panel is-active" data-panel="0">
		<h2><?php echo esc_html__( 'Welcome', 'ai-chatmate' ); ?></h2>
		<p><?php echo esc_html__( "This assistant searches your own content and answers visitor questions. Nothing leaves your site except the visitor's question and the matched titles. Let's set it up in a few steps.", 'ai-chatmate' ); ?></p>
		<p><button type="button" class="button button-primary" data-aicm-next><?php echo esc_html__( 'Get started', 'ai-chatmate' ); ?></button></p>
	</section>

	<!-- 1: Detect -->
	<section class="aicm-panel" data-panel="1">
		<h2><?php echo esc_html__( 'Detect your site', 'ai-chatmate' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'We scan your post types, taxonomies, and custom fields. Nothing is saved yet.', 'ai-chatmate' ); ?></p>
		<div id="aicm-detect-result" class="aicm-detect-result"></div>
		<p>
			<button type="button" class="button" data-aicm-prev><?php echo esc_html__( 'Back', 'ai-chatmate' ); ?></button>
			<button type="button" class="button button-primary" data-aicm-next disabled id="aicm-detect-next"><?php echo esc_html__( 'Next', 'ai-chatmate' ); ?></button>
		</p>
	</section>

	<!-- 2: Choose types -->
	<section class="aicm-panel" data-panel="2">
		<h2><?php echo esc_html__( 'What should be searchable?', 'ai-chatmate' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Pick the content types visitors should be able to search.', 'ai-chatmate' ); ?></p>
		<div id="aicm-types-list"></div>
		<p>
			<button type="button" class="button" data-aicm-prev><?php echo esc_html__( 'Back', 'ai-chatmate' ); ?></button>
			<button type="button" class="button button-primary" data-aicm-next><?php echo esc_html__( 'Next', 'ai-chatmate' ); ?></button>
		</p>
	</section>

	<!-- 3: Confirm fields -->
	<section class="aicm-panel" data-panel="3">
		<h2><?php echo esc_html__( 'Confirm fields and labels', 'ai-chatmate' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Turn fields off if you do not want them searched, and rename any for clarity.', 'ai-chatmate' ); ?></p>
		<div id="aicm-fields-list"></div>
		<p>
			<button type="button" class="button" data-aicm-prev><?php echo esc_html__( 'Back', 'ai-chatmate' ); ?></button>
			<button type="button" class="button button-primary" data-aicm-next><?php echo esc_html__( 'Next', 'ai-chatmate' ); ?></button>
		</p>
	</section>

	<!-- 4: Connect AI -->
	<section class="aicm-panel" data-panel="4">
		<h2><?php echo esc_html__( 'Connect the AI (optional)', 'ai-chatmate' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Add your OpenAI API key to enable natural-language answers. You can skip this — search still works without a key.', 'ai-chatmate' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="aicm-wiz-key"><?php echo esc_html__( 'OpenAI API Key', 'ai-chatmate' ); ?></label></th>
				<td>
					<input type="password" id="aicm-wiz-key" class="regular-text" autocomplete="new-password" placeholder="sk-...">
					<button type="button" class="button" id="aicm-wiz-test"><?php echo esc_html__( 'Test', 'ai-chatmate' ); ?></button>
					<span id="aicm-wiz-test-result" class="aicm-test-result"></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Semantic Q&A', 'ai-chatmate' ); ?></th>
				<td>
					<label><input type="checkbox" id="aicm-wiz-semantic"> <?php echo esc_html__( 'Enable fuzzy meaning-based answers (costs more; best for blogs and docs)', 'ai-chatmate' ); ?></label>
				</td>
			</tr>
		</table>
		<p>
			<button type="button" class="button" data-aicm-prev><?php echo esc_html__( 'Back', 'ai-chatmate' ); ?></button>
			<button type="button" class="button button-primary" data-aicm-next><?php echo esc_html__( 'Next', 'ai-chatmate' ); ?></button>
		</p>
	</section>

	<!-- 5: Widget + Finish -->
	<section class="aicm-panel" data-panel="5">
		<h2><?php echo esc_html__( 'Place the chat widget', 'ai-chatmate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Show widget', 'ai-chatmate' ); ?></th>
				<td><label><input type="checkbox" id="aicm-wiz-enable"> <?php echo esc_html__( 'Show the floating chat button on the site', 'ai-chatmate' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="aicm-wiz-color"><?php echo esc_html__( 'Brand colour', 'ai-chatmate' ); ?></label></th>
				<td><input type="color" id="aicm-wiz-color" value="#0073aa"></td>
			</tr>
		</table>
		<p>
			<button type="button" class="button" data-aicm-prev><?php echo esc_html__( 'Back', 'ai-chatmate' ); ?></button>
			<button type="button" class="button button-primary" id="aicm-wiz-finish"><?php echo esc_html__( 'Finish setup', 'ai-chatmate' ); ?></button>
			<span id="aicm-wiz-finish-status" class="aicm-save-status" aria-live="polite"></span>
		</p>
	</section>
</div>
