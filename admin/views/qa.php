<?php
/**
 * Q&A Manager Admin Page
 *
 * Provides a CRUD interface for admin-managed Q&A pairs stored in aicm_qa.
 * All read/write operations are performed via the REST API using inline JS;
 * the aicmAdmin object (restUrl, nonce) is injected by AICM_Admin::enqueue_assets().
 *
 * ── What this page does ──────────────────────────────────────────────────────
 *  - Lists all Q&A pairs (question, answer preview, priority, status, match count).
 *  - "Add New" button reveals an inline form to create a pair.
 *  - "Edit" row action loads the pair into the form for update.
 *  - "Delete" row action removes the pair after confirmation.
 *  - All CRUD goes through the aicm/v1/qa REST endpoints (admin-nonce protected).
 *  - After each CRUD operation the table refreshes via GET /qa.
 *
 * ── Embedding notice ─────────────────────────────────────────────────────────
 * When a pair is saved (new or question changed), the backend immediately
 * embeds the question and stores the vector. The pair will not match user
 * queries until the embedding is available — which happens inline on save,
 * assuming an API key is configured.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-chatmate' ) );
}
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Q&amp;A Manager', 'ai-chatmate' ); ?></h1>
	<hr class="wp-header-end">

	<p style="max-width:760px; margin-top:12px;">
		<?php
		esc_html_e(
			'Add custom Q&A pairs that are matched against user messages before AI retrieval. When a user\'s question closely matches a stored question (cosine similarity ≥ 0.92), the configured answer is returned instantly — zero API cost, guaranteed accuracy.',
			'ai-chatmate'
		);
		?>
	</p>

	<?php if ( '' === (string) get_option( 'aicm_api_key_openai', '' ) ) : ?>
	<div class="notice notice-warning inline" style="max-width:760px;">
		<p>
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to the Settings page */
					__( 'No API key is configured. Q&A pairs cannot be embedded for matching until an API key is added on the <a href="%s">Settings page</a>.', 'ai-chatmate' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( admin_url( 'admin.php?page=ai-chatmate' ) )
			);
			?>
		</p>
	</div>
	<?php endif; ?>

	<!-- ── Add / Edit form ───────────────────────────────────────────────── -->
	<div id="aicm-qa-form" style="display:none; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px 4px; margin:20px 0; max-width:760px;">
		<h2 id="aicm-qa-form-title" style="margin-top:0;"><?php esc_html_e( 'Add New Q&amp;A Pair', 'ai-chatmate' ); ?></h2>
		<input type="hidden" id="aicm-qa-id" value="">

		<table class="form-table" role="presentation" style="max-width:100%;">
			<tr>
				<th scope="row" style="width:160px;">
					<label for="aicm-qa-question"><?php esc_html_e( 'Question', 'ai-chatmate' ); ?></label>
				</th>
				<td>
					<textarea
						id="aicm-qa-question"
						rows="3"
						class="large-text"
						placeholder="<?php esc_attr_e( 'e.g. What are your opening hours?', 'ai-chatmate' ); ?>"
					></textarea>
					<p class="description">
						<?php esc_html_e( 'Write the question naturally, as a visitor might type it. The matching is semantic — close paraphrases will also trigger this answer.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="aicm-qa-answer"><?php esc_html_e( 'Answer', 'ai-chatmate' ); ?></label>
				</th>
				<td>
					<textarea
						id="aicm-qa-answer"
						rows="6"
						class="large-text"
						placeholder="<?php esc_attr_e( 'Enter the exact answer to return\u2026', 'ai-chatmate' ); ?>"
					></textarea>
					<p class="description">
						<?php esc_html_e( 'Plain text. Supports **bold** markdown and line breaks.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="aicm-qa-priority"><?php esc_html_e( 'Priority', 'ai-chatmate' ); ?></label>
				</th>
				<td>
					<input type="number" id="aicm-qa-priority" value="50" min="1" max="100" class="small-text">
					<p class="description">
						<?php esc_html_e( 'Lower number = higher priority (1 is matched first when two pairs are equally similar). Range: 1–100.', 'ai-chatmate' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'ai-chatmate' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="aicm-qa-active" checked>
						<?php esc_html_e( 'Active — include this pair in matching', 'ai-chatmate' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<p class="submit" style="padding-top:0;">
			<button type="button" id="aicm-qa-save" class="button button-primary">
				<?php esc_html_e( 'Save Q&amp;A Pair', 'ai-chatmate' ); ?>
			</button>
			<button type="button" id="aicm-qa-cancel" class="button" style="margin-left:4px;">
				<?php esc_html_e( 'Cancel', 'ai-chatmate' ); ?>
			</button>
			<span id="aicm-qa-status" style="margin-left:12px; font-size:13px;"></span>
		</p>
	</div>

	<!-- ── List header ───────────────────────────────────────────────────── -->
	<div style="display:flex; align-items:center; justify-content:space-between; margin:20px 0 10px; max-width:100%;">
		<h2 style="margin:0;"><?php esc_html_e( 'All Q&amp;A Pairs', 'ai-chatmate' ); ?></h2>
		<button type="button" id="aicm-qa-add-new" class="button button-primary">
			<?php esc_html_e( '+ Add New', 'ai-chatmate' ); ?>
		</button>
	</div>

	<!-- ── Table ─────────────────────────────────────────────────────────── -->
	<table class="wp-list-table widefat fixed striped" style="max-width:100%;">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Question', 'ai-chatmate' ); ?></th>
				<th scope="col" style="width:220px;"><?php esc_html_e( 'Answer preview', 'ai-chatmate' ); ?></th>
				<th scope="col" style="width:80px;"><?php esc_html_e( 'Priority', 'ai-chatmate' ); ?></th>
				<th scope="col" style="width:90px;"><?php esc_html_e( 'Status', 'ai-chatmate' ); ?></th>
				<th scope="col" style="width:80px;"><?php esc_html_e( 'Matches', 'ai-chatmate' ); ?></th>
				<th scope="col" style="width:160px;"><?php esc_html_e( 'Actions', 'ai-chatmate' ); ?></th>
			</tr>
		</thead>
		<tbody id="aicm-qa-tbody">
			<!-- Populated by JavaScript on load and after each mutation. -->
		</tbody>
	</table>

	<p id="aicm-qa-empty" style="display:none; padding:12px 0; color:#50575e;">
		<?php esc_html_e( 'No Q&amp;A pairs yet. Click &ldquo;+ Add New&rdquo; to create your first one.', 'ai-chatmate' ); ?>
	</p>

</div>
