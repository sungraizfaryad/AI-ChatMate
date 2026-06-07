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

	<!-- ── Inline styles ─────────────────────────────────────────────────── -->
	<style>
		.aicm-badge-active   { background:#d7f0dd; color:#006505; padding:2px 8px; border-radius:3px; font-size:12px; white-space:nowrap; }
		.aicm-badge-inactive { background:#fce8e8; color:#8c1a1a; padding:2px 8px; border-radius:3px; font-size:12px; white-space:nowrap; }
		#aicm-qa-tbody td    { vertical-align:top; word-break:break-word; }
	</style>
</div>

<script>
( function () {
	'use strict';

	var cfg      = window.aicmAdmin || {};
	var restBase = cfg.restUrl || '';
	var nonce    = cfg.nonce   || '';

	// ── Element references ───────────────────────────────────────────────────
	var form      = document.getElementById( 'aicm-qa-form' );
	var formTitle = document.getElementById( 'aicm-qa-form-title' );
	var idFld     = document.getElementById( 'aicm-qa-id' );
	var qFld      = document.getElementById( 'aicm-qa-question' );
	var aFld      = document.getElementById( 'aicm-qa-answer' );
	var pFld      = document.getElementById( 'aicm-qa-priority' );
	var activeFld = document.getElementById( 'aicm-qa-active' );
	var saveBtn   = document.getElementById( 'aicm-qa-save' );
	var cancelBtn = document.getElementById( 'aicm-qa-cancel' );
	var statusEl  = document.getElementById( 'aicm-qa-status' );
	var addNewBtn = document.getElementById( 'aicm-qa-add-new' );
	var tbody     = document.getElementById( 'aicm-qa-tbody' );
	var emptyEl   = document.getElementById( 'aicm-qa-empty' );

	// ── Utilities ────────────────────────────────────────────────────────────

	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' );
	}

	function setStatus( msg, isError ) {
		statusEl.textContent = msg;
		statusEl.style.color = isError ? '#d63638' : '#008a00';
	}

	function apiFetch( method, url, body ) {
		var opts = {
			method:  method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce,
			},
		};

		if ( body !== undefined ) {
			opts.body = JSON.stringify( body );
		}

		return fetch( url, opts ).then( function ( r ) {
			if ( ! r.ok ) {
				return r.json().then( function ( e ) {
					throw new Error( ( e && e.message ) ? e.message : 'HTTP ' + r.status );
				} );
			}
			return r.json();
		} );
	}

	// ── Form ─────────────────────────────────────────────────────────────────

	function openForm( mode, row ) {
		row = row || {};

		form.style.display    = 'block';
		formTitle.textContent = ( 'edit' === mode )
			? '<?php echo esc_js( __( 'Edit Q&A Pair', 'ai-chatmate' ) ); ?>'
			: '<?php echo esc_js( __( 'Add New Q&A Pair', 'ai-chatmate' ) ); ?>';

		idFld.value      = row.id       ? String( row.id ) : '';
		qFld.value       = row.question || '';
		aFld.value       = row.answer   || '';
		pFld.value       = row.priority || 50;
		activeFld.checked = ( row.is_active !== undefined ) ? row.is_active == '1' : true;

		setStatus( '', false );
		qFld.focus();
		form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function closeForm() {
		form.style.display = 'none';
		idFld.value        = '';
	}

	// ── Table ─────────────────────────────────────────────────────────────────

	function renderRows( items ) {
		tbody.innerHTML = '';

		if ( ! items || 0 === items.length ) {
			emptyEl.style.display = 'block';
			return;
		}

		emptyEl.style.display = 'none';

		items.forEach( function ( row ) {
			var tr      = document.createElement( 'tr' );
			var preview = String( row.answer );

			if ( preview.length > 100 ) {
				preview = preview.slice( 0, 100 ) + '\u2026';
			}

			var badgeClass = row.is_active == '1' ? 'aicm-badge-active' : 'aicm-badge-inactive';
			var badgeLabel = row.is_active == '1'
				? '<?php echo esc_js( __( 'Active', 'ai-chatmate' ) ); ?>'
				: '<?php echo esc_js( __( 'Inactive', 'ai-chatmate' ) ); ?>';

			tr.innerHTML = ''
				+ '<td>' + escHtml( row.question ) + '</td>'
				+ '<td><span style="color:#50575e; font-size:13px;">' + escHtml( preview ) + '</span></td>'
				+ '<td>' + escHtml( row.priority ) + '</td>'
				+ '<td><span class="' + badgeClass + '">' + badgeLabel + '</span></td>'
				+ '<td>' + escHtml( row.match_count ) + '</td>'
				+ '<td>'
				+   '<button type="button" class="button button-small qa-edit-btn">'
				+   '<?php echo esc_js( __( 'Edit', 'ai-chatmate' ) ); ?>'
				+   '</button>&nbsp;'
				+   '<button type="button" class="button button-small button-link-delete qa-del-btn">'
				+   '<?php echo esc_js( __( 'Delete', 'ai-chatmate' ) ); ?>'
				+   '</button>'
				+ '</td>';

			tr.querySelector( '.qa-edit-btn' ).addEventListener( 'click', function () {
				openForm( 'edit', row );
			} );

			tr.querySelector( '.qa-del-btn' ).addEventListener( 'click', function () {
				if ( ! confirm( '<?php echo esc_js( __( 'Delete this Q&A pair? This cannot be undone.', 'ai-chatmate' ) ); ?>' ) ) {
					return;
				}

				apiFetch( 'DELETE', restBase + '/qa/' + row.id )
					.then( function () { loadList(); } )
					.catch( function ( e ) { alert( e.message ); } );
			} );

			tbody.appendChild( tr );
		} );
	}

	function loadList() {
		apiFetch( 'GET', restBase + '/qa' )
			.then( function ( data ) { renderRows( data.items || [] ); } )
			.catch( function ( e ) { console.error( 'AICM QA list error:', e ); } );
	}

	// ── Event listeners ───────────────────────────────────────────────────────

	addNewBtn.addEventListener( 'click', function () {
		openForm( 'add', null );
	} );

	cancelBtn.addEventListener( 'click', closeForm );

	saveBtn.addEventListener( 'click', function () {
		var question = qFld.value.trim();
		var answer   = aFld.value.trim();

		if ( '' === question || '' === answer ) {
			setStatus( '<?php echo esc_js( __( 'Question and answer are required.', 'ai-chatmate' ) ); ?>', true );
			return;
		}

		var id     = idFld.value ? parseInt( idFld.value, 10 ) : 0;
		var method = id > 0 ? 'PUT'  : 'POST';
		var url    = id > 0 ? restBase + '/qa/' + id : restBase + '/qa';

		var body = {
			question:  question,
			answer:    answer,
			priority:  Math.max( 1, Math.min( 100, parseInt( pFld.value, 10 ) || 50 ) ),
			is_active: activeFld.checked,
		};

		saveBtn.disabled = true;
		setStatus( '<?php echo esc_js( __( 'Saving\u2026', 'ai-chatmate' ) ); ?>', false );

		apiFetch( method, url, body )
			.then( function () {
				setStatus( '<?php echo esc_js( __( 'Saved.', 'ai-chatmate' ) ); ?>', false );
				closeForm();
				loadList();
			} )
			.catch( function ( e ) {
				setStatus( e.message, true );
			} )
			.finally( function () {
				saveBtn.disabled = false;
			} );
	} );

	// Initial table load.
	loadList();

} )();
</script>
