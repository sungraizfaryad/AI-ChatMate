/**
 * Conciera — Q&A Manager page handler.
 *
 * Enqueued by AICM_Admin::enqueue_assets() on the Q&A page.
 * Depends on the global `aicmAdmin` (REST URL + nonce) and on `aicmQA.i18n`
 * (translated strings) added via wp_localize_script().
 */
( function () {
	'use strict';

	var cfg      = window.aicmAdmin || {};
	var restBase = cfg.restUrl || '';
	var nonce    = cfg.nonce   || '';
	var i18n     = ( window.aicmQA && aicmQA.i18n ) || {};

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

	if ( ! form || ! window.aicmAdmin ) {
		return;
	}

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
		formTitle.textContent = ( 'edit' === mode ) ? i18n.editPair : i18n.addPair;

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
				preview = preview.slice( 0, 100 ) + '…';
			}

			var badgeClass = row.is_active == '1' ? 'aicm-badge-active' : 'aicm-badge-inactive';
			var badgeLabel = row.is_active == '1' ? i18n.active : i18n.inactive;

			tr.innerHTML = ''
				+ '<td>' + escHtml( row.question ) + '</td>'
				+ '<td><span style="color:#50575e; font-size:13px;">' + escHtml( preview ) + '</span></td>'
				+ '<td>' + escHtml( row.priority ) + '</td>'
				+ '<td><span class="' + badgeClass + '">' + escHtml( badgeLabel ) + '</span></td>'
				+ '<td>' + escHtml( row.match_count ) + '</td>'
				+ '<td>'
				+   '<button type="button" class="button button-small qa-edit-btn">'
				+   escHtml( i18n.edit )
				+   '</button>&nbsp;'
				+   '<button type="button" class="button button-small button-link-delete qa-del-btn">'
				+   escHtml( i18n.delete )
				+   '</button>'
				+ '</td>';

			tr.querySelector( '.qa-edit-btn' ).addEventListener( 'click', function () {
				openForm( 'edit', row );
			} );

			tr.querySelector( '.qa-del-btn' ).addEventListener( 'click', function () {
				if ( ! confirm( i18n.confirmDelete ) ) {
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
			setStatus( i18n.required, true );
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
		setStatus( i18n.saving, false );

		apiFetch( method, url, body )
			.then( function () {
				setStatus( i18n.saved, false );
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

}() );
