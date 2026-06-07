<?php
/**
 * Admin Settings Page View
 *
 * Rendered by AICM_Admin::render_settings_page().
 *
 * This is a pure HTML/PHP view file — no business logic.
 * All dynamic data is escaped at the point of output.
 *
 * The form does NOT use wp_options directly on submit. Settings are saved
 * via the REST API (POST aicm/v1/settings) called by admin JS. This keeps
 * validation in one place and allows inline save feedback without a page reload.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Capability check — defence in depth (already checked by AICM_Admin).
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Access denied.', 'ai-chatmate' ) );
}

// Load current settings for pre-filling the form.
$settings = AI_ChatMate::get_setting();

// Determine whether each API key is stored (without decrypting it).
$has_openai    = '' !== (string) get_option( 'aicm_api_key_openai', '' );
$has_anthropic = '' !== (string) get_option( 'aicm_api_key_anthropic', '' );
$has_google    = '' !== (string) get_option( 'aicm_api_key_google', '' );

$active_provider = esc_attr( $settings['active_provider'] ?? 'openai' );
$chat_model      = esc_attr( $settings['chat_model']      ?? 'gpt-4o-mini' );
$embed_model     = esc_attr( $settings['embedding_model'] ?? 'text-embedding-3-small' );
$personality     = esc_attr( $settings['ai_personality']  ?? 'friendly' );
$welcome_msg     = esc_attr( $settings['welcome_message'] ?? '' );
$widget_color    = esc_attr( $settings['widget_color']    ?? '#0073aa' );
$widget_pos      = esc_attr( $settings['widget_position'] ?? 'bottom-right' );
$rate_limit      = (int) ( $settings['rate_limit_msgs']   ?? 20 );
$token_cap       = (int) ( $settings['session_token_cap'] ?? 5000 );
$budget          = (float) ( $settings['monthly_budget']  ?? 0 );
$logging         = ! empty( $settings['logging_enabled'] );
?>
<div class="wrap" id="aicm-settings-page">

	<h1><?php echo esc_html__( 'AI ChatMate — Settings', 'ai-chatmate' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-chatmate&onboarding=1' ) ); ?>" class="button">
			<?php echo esc_html__( 'Re-run setup wizard', 'ai-chatmate' ); ?>
		</a>
	</p>

	<div id="aicm-notice" class="notice" style="display:none;"></div>

	<form id="aicm-settings-form" novalidate>

		<?php
		// WordPress best practice: include a nonce field even though this form
		// submits via REST + JS. The nonce is read from aicmAdmin.nonce in JS.
		wp_nonce_field( 'wp_rest', '_wpnonce_display', false );
		?>

		<!-- ================================================================ -->
		<!-- Section 1: AI Provider & API Keys                                -->
		<!-- ================================================================ -->
		<h2 class="title"><?php echo esc_html__( 'AI Provider & API Keys', 'ai-chatmate' ); ?></h2>
		<p class="description">
			<?php
			echo esc_html__(
				'Your API key is encrypted before being saved and is never exposed to the browser or included in API responses.',
				'ai-chatmate'
			);
			?>
		</p>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="aicm-active-provider">
						<?php echo esc_html__( 'Active Provider', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<select id="aicm-active-provider" name="active_provider">
						<option value="openai" <?php selected( $active_provider, 'openai' ); ?>>
							<?php echo esc_html__( 'OpenAI', 'ai-chatmate' ); ?>
						</option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-api-key-openai">
						<?php echo esc_html__( 'OpenAI API Key', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<input
						type="password"
						id="aicm-api-key-openai"
						name="api_key_openai"
						class="regular-text"
						autocomplete="new-password"
						placeholder="<?php echo $has_openai ? esc_attr__( '••••••••  (key stored)', 'ai-chatmate' ) : esc_attr__( 'sk-...', 'ai-chatmate' ); ?>"
						value=""
					>
					<?php if ( $has_openai ) : ?>
						<button type="button" id="aicm-test-openai" class="button aicm-test-btn" data-provider="openai">
							<?php echo esc_html__( 'Test Connection', 'ai-chatmate' ); ?>
						</button>
						<span id="aicm-test-openai-result" class="aicm-test-result"></span>
					<?php endif; ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to OpenAI API keys page */
							esc_html__( 'Get your API key from %s', 'ai-chatmate' ),
							'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>'
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-chat-model">
						<?php echo esc_html__( 'Chat Model', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<select id="aicm-chat-model" name="chat_model">
						<option value="gpt-4o-mini" <?php selected( $chat_model, 'gpt-4o-mini' ); ?>>
							<?php echo esc_html__( 'gpt-4o-mini — Recommended ($0.15 / 1M input tokens)', 'ai-chatmate' ); ?>
						</option>
						<option value="gpt-4o" <?php selected( $chat_model, 'gpt-4o' ); ?>>
							<?php echo esc_html__( 'gpt-4o — More capable ($2.50 / 1M input tokens)', 'ai-chatmate' ); ?>
						</option>
					</select>
					<p class="description">
						<?php echo esc_html__( 'gpt-4o-mini is the best cost/quality choice for most sites.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-embed-model">
						<?php echo esc_html__( 'Embedding Model', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<select id="aicm-embed-model" name="embedding_model">
						<option value="text-embedding-3-small" <?php selected( $embed_model, 'text-embedding-3-small' ); ?>>
							<?php echo esc_html__( 'text-embedding-3-small — Recommended ($0.02 / 1M tokens)', 'ai-chatmate' ); ?>
						</option>
						<option value="text-embedding-3-large" <?php selected( $embed_model, 'text-embedding-3-large' ); ?>>
							<?php echo esc_html__( 'text-embedding-3-large — Higher accuracy ($0.13 / 1M tokens)', 'ai-chatmate' ); ?>
						</option>
					</select>
				</td>
			</tr>

		</table>

		<hr>

		<!-- ================================================================ -->
		<!-- Section 2: AI Behaviour                                          -->
		<!-- ================================================================ -->
		<h2 class="title"><?php echo esc_html__( 'AI Behaviour', 'ai-chatmate' ); ?></h2>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="aicm-personality">
						<?php echo esc_html__( 'Personality', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<select id="aicm-personality" name="ai_personality">
						<option value="friendly"     <?php selected( $personality, 'friendly' ); ?>>
							<?php echo esc_html__( 'Friendly — Warm and conversational', 'ai-chatmate' ); ?>
						</option>
						<option value="professional" <?php selected( $personality, 'professional' ); ?>>
							<?php echo esc_html__( 'Professional — Formal and concise', 'ai-chatmate' ); ?>
						</option>
						<option value="casual"       <?php selected( $personality, 'casual' ); ?>>
							<?php echo esc_html__( 'Casual — Relaxed and informal', 'ai-chatmate' ); ?>
						</option>
					</select>
				</td>
			</tr>

		</table>

		<hr>

		<!-- ================================================================ -->
		<!-- Section 3: Chat Widget                                           -->
		<!-- ================================================================ -->
		<h2 class="title"><?php echo esc_html__( 'Chat Widget', 'ai-chatmate' ); ?></h2>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="aicm-welcome-msg">
						<?php echo esc_html__( 'Welcome Message', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						id="aicm-welcome-msg"
						name="welcome_message"
						class="large-text"
						value="<?php echo $welcome_msg; ?>"
						maxlength="200"
					>
					<p class="description">
						<?php echo esc_html__( 'The first message shown when a visitor opens the chat.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-widget-color">
						<?php echo esc_html__( 'Brand Colour', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<input
						type="color"
						id="aicm-widget-color"
						name="widget_color"
						value="<?php echo $widget_color; ?>"
					>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-widget-pos">
						<?php echo esc_html__( 'Widget Position', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<select id="aicm-widget-pos" name="widget_position">
						<option value="bottom-right" <?php selected( $widget_pos, 'bottom-right' ); ?>>
							<?php echo esc_html__( 'Bottom Right', 'ai-chatmate' ); ?>
						</option>
						<option value="bottom-left"  <?php selected( $widget_pos, 'bottom-left' ); ?>>
							<?php echo esc_html__( 'Bottom Left', 'ai-chatmate' ); ?>
						</option>
					</select>
				</td>
			</tr>

		</table>

		<hr>

		<!-- ================================================================ -->
		<!-- Section 4: Rate Limiting & Budget                                -->
		<!-- ================================================================ -->
		<h2 class="title"><?php echo esc_html__( 'Rate Limiting & Budget', 'ai-chatmate' ); ?></h2>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="aicm-rate-limit">
						<?php echo esc_html__( 'Max Messages / Minute (per visitor)', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="aicm-rate-limit"
						name="rate_limit_msgs"
						class="small-text"
						value="<?php echo esc_attr( $rate_limit ); ?>"
						min="1"
						max="100"
					>
					<p class="description">
						<?php echo esc_html__( 'Set to 0 to disable rate limiting. Default: 20.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-token-cap">
						<?php echo esc_html__( 'Max Tokens per Session', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="aicm-token-cap"
						name="session_token_cap"
						class="small-text"
						value="<?php echo esc_attr( $token_cap ); ?>"
						min="500"
						max="32000"
					>
					<p class="description">
						<?php echo esc_html__( 'Conversation history is trimmed when this limit is reached. Default: 5000.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-budget">
						<?php echo esc_html__( 'Monthly Budget (USD)', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="aicm-budget"
						name="monthly_budget"
						class="small-text"
						value="<?php echo esc_attr( $budget ); ?>"
						min="0"
						step="0.01"
					>
					<p class="description">
						<?php echo esc_html__( 'Long-term monthly spend tracking shown on the Analytics page.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-daily-budget">
						<?php echo esc_html__( 'Daily Budget (USD)', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="aicm-daily-budget"
						name="daily_budget"
						class="small-text"
						value="<?php echo esc_attr( (float) ( $settings['daily_budget'] ?? 0 ) ); ?>"
						min="0"
						step="0.01"
					>
					<p class="description">
						<?php echo esc_html__( 'Hard kill-switch: when today\'s API spend reaches this amount, the public chat pauses until tomorrow. Set to 0 for unlimited.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="aicm-daily-cap">
						<?php echo esc_html__( 'Max Messages / Day (per visitor)', 'ai-chatmate' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="aicm-daily-cap"
						name="daily_msg_cap"
						class="small-text"
						value="<?php echo esc_attr( (int) ( $settings['daily_msg_cap'] ?? 0 ) ); ?>"
						min="0"
					>
					<p class="description">
						<?php echo esc_html__( 'Hard per-visitor daily ceiling. Set to 0 for unlimited.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>

		</table>

		<hr>

		<!-- ================================================================ -->
		<!-- Section 5: Privacy / GDPR                                        -->
		<!-- ================================================================ -->
		<h2 class="title"><?php echo esc_html__( 'Privacy & GDPR', 'ai-chatmate' ); ?></h2>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<?php echo esc_html__( 'Conversation Logging', 'ai-chatmate' ); ?>
				</th>
				<td>
					<label for="aicm-logging">
						<input
							type="checkbox"
							id="aicm-logging"
							name="logging_enabled"
							value="1"
							<?php checked( $logging ); ?>
						>
						<?php echo esc_html__( 'Enable conversation logging', 'ai-chatmate' ); ?>
					</label>
					<p class="description">
						<?php
						echo esc_html__(
							'Disabled by default. When enabled, conversation turns are saved to the database to power the Analytics dashboard. IP addresses are never logged — only a one-way hash.',
							'ai-chatmate'
						);
						?>
					</p>
				</td>
			</tr>

		</table>

		<p class="submit">
			<button type="submit" id="aicm-save-settings" class="button button-primary">
				<?php echo esc_html__( 'Save Settings', 'ai-chatmate' ); ?>
			</button>
			<span id="aicm-save-status" class="aicm-save-status" aria-live="polite"></span>
		</p>

	</form><!-- #aicm-settings-form -->

</div><!-- .wrap -->

<script>
/**
 * Minimal inline settings form handler.
 *
 * We use inline JS here (rather than an enqueued file) because:
 *  1. This is the ONLY place this JS runs.
 *  2. Enqueueing a separate file for 50 lines of glue code is overkill.
 *  3. It keeps Phase 1 self-contained — no build step needed.
 *
 * All user-visible strings are already escaped above via PHP esc_html__().
 * aicmAdmin is set by wp_add_inline_script() in AICM_Admin::enqueue_assets().
 */
( function () {
	'use strict';

	const form       = document.getElementById( 'aicm-settings-form' );
	const saveBtn    = document.getElementById( 'aicm-save-settings' );
	const saveStatus = document.getElementById( 'aicm-save-status' );
	const notice     = document.getElementById( 'aicm-notice' );

	if ( ! form || ! window.aicmAdmin ) {
		return;
	}

	// -----------------------------------------------------------------
	// Helper: show an admin notice banner.
	// -----------------------------------------------------------------
	function showNotice( message, type ) {
		notice.className = 'notice notice-' + ( type || 'info' ) + ' inline';
		notice.textContent = message;
		notice.style.display = 'block';
		notice.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	// -----------------------------------------------------------------
	// Save settings via REST.
	// -----------------------------------------------------------------
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		saveBtn.disabled  = true;
		saveStatus.textContent = aicmAdmin.i18n.saving;

		const data = {};
		new FormData( form ).forEach( function ( value, key ) {
			// Checkboxes: if present in FormData it is '1' (checked).
			if ( key === 'logging_enabled' ) {
				data[ key ] = true;
			} else {
				data[ key ] = value;
			}
		} );

		// Checkboxes not in FormData are unchecked (false).
		if ( ! ( 'logging_enabled' in data ) ) {
			data.logging_enabled = false;
		}

		fetch( aicmAdmin.restUrl + '/settings', {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   aicmAdmin.nonce,
			},
			body: JSON.stringify( data ),
		} )
		.then( function ( res ) { return res.json(); } )
		.then( function ( json ) {
			if ( json.success ) {
				saveStatus.textContent = aicmAdmin.i18n.saved;
				showNotice( aicmAdmin.i18n.saved, 'success' );
			} else {
				saveStatus.textContent = aicmAdmin.i18n.error;
				showNotice( json.message || aicmAdmin.i18n.error, 'error' );
			}
		} )
		.catch( function () {
			saveStatus.textContent = aicmAdmin.i18n.error;
			showNotice( aicmAdmin.i18n.error, 'error' );
		} )
		.finally( function () {
			saveBtn.disabled = false;
		} );
	} );

	// -----------------------------------------------------------------
	// Test connection button.
	// -----------------------------------------------------------------
	document.querySelectorAll( '.aicm-test-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const provider   = btn.dataset.provider;
			const resultSpan = document.getElementById( 'aicm-test-' + provider + '-result' );

			btn.disabled           = true;
			resultSpan.textContent = aicmAdmin.i18n.testing;
			resultSpan.className   = 'aicm-test-result';

			fetch( aicmAdmin.restUrl + '/test-connection', {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   aicmAdmin.nonce,
				},
				body: JSON.stringify( { provider: provider, api_key: '' } ),
			} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( json.success ) {
					resultSpan.textContent = '✓ ' + json.message + ( json.model ? ' (' + json.model + ')' : '' );
					resultSpan.className   = 'aicm-test-result aicm-test-ok';
				} else {
					resultSpan.textContent = '✗ ' + json.message;
					resultSpan.className   = 'aicm-test-result aicm-test-fail';
				}
			} )
			.catch( function () {
				resultSpan.textContent = '✗ ' + aicmAdmin.i18n.error;
				resultSpan.className   = 'aicm-test-result aicm-test-fail';
			} )
			.finally( function () {
				btn.disabled = false;
			} );
		} );
	} );

} () );
</script>

<style>
.aicm-save-status   { margin-left: 10px; font-style: italic; color: #555; }
.aicm-test-result   { margin-left: 10px; font-weight: 600; }
.aicm-test-ok       { color: #46b450; }
.aicm-test-fail     { color: #dc3232; }
</style>
