/**
 * Conciera — Content Indexing page handler.
 *
 * Enqueued by AICM_Admin::enqueue_assets() on the Indexing page.
 * Depends on the global `aicmAdmin` (REST URL + nonce) and on `aicmIndexing`
 * ({ isRunning, mode, i18n }) added via wp_localize_script().
 *
 * ── Processing modes (chosen in Settings → Indexing, locked per run) ──────
 * frontend  — this page drives the queue: it POSTs /index/process in a loop,
 *             each call processing one time-boxed batch server-side.
 * background — the server drives itself via secret-key loopback requests;
 *             this page only POLLs /index/status for live progress.
 *
 * ── Stall watchdog ────────────────────────────────────────────────────────
 * The background loopback chain is sequential, so a single failed request
 * kills it (and WP-Cron may not fire on quiet sites). While a run is active
 * we track the pending count; if it has not moved for STALL_AFTER_MS the
 * watchdog tells the admin and automatically POSTs /index/process — which
 * processes one batch AND re-arms the loopback chain server-side. The admin
 * is never left staring at a frozen number with no explanation.
 */
( function () {
	'use strict';

	const cfg       = window.aicmIndexing || {};
	const i18n      = cfg.i18n || {};
	const restBase  = aicmAdmin.restUrl;
	const nonce     = aicmAdmin.nonce;

	let mode        = ( 'background' === cfg.mode ) ? 'background' : 'frontend';

	const startBtn  = document.getElementById( 'aicm-start-index' );
	const stopBtn   = document.getElementById( 'aicm-stop-index' );
	const spinner   = document.getElementById( 'aicm-index-spinner' );
	const msgBox    = document.getElementById( 'aicm-index-message' );
	const statusEl  = document.getElementById( 'aicm-running-status' );
	const chunksEl  = document.getElementById( 'aicm-total-chunks' );
	const postsEl   = document.getElementById( 'aicm-indexed-posts' );
	const pendingEl = document.getElementById( 'aicm-pending-count' );
	const actPanel  = document.getElementById( 'aicm-activity-panel' );
	const actList   = document.getElementById( 'aicm-activity-list' );
	const actLive   = document.getElementById( 'aicm-activity-live' );
	const scopeEls  = document.querySelectorAll( 'input[name="aicm_scan_scope"]' );

	if ( ! startBtn || ! window.aicmAdmin ) {
		return;
	}

	// Set when the admin clicks Stop; loops check it between iterations.
	let stopped  = false;
	// Guards against two concurrent loops (e.g. double-click on Start).
	let looping  = false;

	// ── Stall watchdog state ──────────────────────────────────────────────
	const STALL_AFTER_MS = 45000; // No progress for 45 s → consider stalled.
	let lastPending      = null;
	let lastProgressAt   = Date.now();
	let kicking          = false;

	// ── Messaging helpers ─────────────────────────────────────────────────
	function showMessage( text, type ) {
		msgBox.innerHTML   = '<div class="notice notice-' + type + ' inline"><p>' + escHtml( text ) + '</p></div>';
		msgBox.style.display = 'block';
	}

	function clearMessage() {
		msgBox.style.display = 'none';
	}

	function escHtml( str ) {
		return String( str ).replace( /&/g, '&amp;' )
					.replace( /</g, '&lt;' )
					.replace( />/g, '&gt;' )
					.replace( /"/g, '&quot;' );
	}

	// ── UI state ──────────────────────────────────────────────────────────
	function setRunning( running ) {
		startBtn.disabled        = running;
		stopBtn.style.display    = running ? '' : 'none';
		spinner.style.visibility = running ? 'visible' : 'hidden';
		if ( actLive ) {
			actLive.style.display = running ? '' : 'none';
		}
		// Options are chosen BEFORE starting and locked during the run.
		scopeEls.forEach( function ( el ) {
			el.disabled = running;
		} );
	}

	function applyStatus( status ) {
		if ( chunksEl )  chunksEl.textContent  = Number( status.total_chunks  || 0 ).toLocaleString();
		if ( postsEl )   postsEl.textContent   = Number( status.indexed_posts || 0 ).toLocaleString();
		if ( pendingEl ) pendingEl.textContent  = Number( status.pending       || 0 ).toLocaleString();

		// Keep the local mode in sync (it can be changed in Settings).
		if ( status.mode ) {
			mode = ( 'background' === status.mode ) ? 'background' : 'frontend';
		}

		setRunning( !! status.is_running );

		if ( status.is_running ) {
			statusEl.innerHTML = '<span class="aicm-badge" style="background:#d63638;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">' + escHtml( i18n.inProgress ) + '</span>';
		} else if ( ! status.is_running && status.last_indexed ) {
			statusEl.innerHTML = '<span class="aicm-badge" style="background:#00a32a;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">' + escHtml( i18n.complete ) + '</span>';
		}

		renderActivity( status.activity );
		watchdog( status );
	}

	// ── Stall watchdog ────────────────────────────────────────────────────
	function watchdog( status ) {
		const pending = Number( status.pending || 0 );

		if ( ! status.is_running || pending < 1 || stopped ) {
			lastPending = null;
			return;
		}

		if ( null === lastPending || pending !== lastPending ) {
			// Progress happened — reset the timer and clear any stall notice.
			if ( null !== lastPending && pending !== lastPending && kicking ) {
				kicking = false;
				showMessage( i18n.resumed, 'success' );
			}
			lastPending    = pending;
			lastProgressAt = Date.now();
			return;
		}

		if ( Date.now() - lastProgressAt > STALL_AFTER_MS && ! kicking ) {
			// No movement for too long: tell the admin and kick the queue.
			// /index/process runs one batch synchronously AND re-arms the
			// background loopback chain server-side.
			kicking = true;
			showMessage( i18n.stalledKicking, 'warning' );

			api( 'POST', '/index/process' )
			.then( function ( data ) {
				if ( data && data.status ) {
					applyStatus( data.status );
				}
				lastProgressAt = Date.now(); // Give the kick time to show effect.
			} )
			.catch( function () {
				kicking = false;
				showMessage( i18n.stalledFailed, 'error' );
			} );
		}
	}

	// ── Live activity log (append-only, no full re-render) ───────────────
	// New entries slide in at the top; existing rows are left untouched so
	// the list reads like a calm, professional feed instead of blinking.
	function renderActivity( activity ) {
		if ( ! actList || ! Array.isArray( activity ) || 0 === activity.length ) {
			return;
		}

		if ( actPanel ) {
			actPanel.style.display = '';
		}

		const existing = new Set();
		actList.querySelectorAll( 'li[data-key]' ).forEach( function ( li ) {
			existing.add( li.dataset.key );
		} );

		// activity is newest-first; iterate oldest-first so rows are
		// prepended in the right order and end up newest on top.
		for ( let i = activity.length - 1; i >= 0; i-- ) {
			const e   = activity[ i ];
			const key = ( e.post_id || '' ) + '|' + ( e.time || '' );

			if ( existing.has( key ) ) {
				continue;
			}
			existing.add( key );

			const ok    = !! e.ok;
			const label = ( 'delete' === e.action )
				? i18n.actRemoved
				: ( ok ? i18n.actIndexed : i18n.actFailed );

			const li      = document.createElement( 'li' );
			li.className  = ok ? 'aicm-act-ok' : 'aicm-act-fail';
			li.dataset.key = key;
			li.innerHTML  = ''
				+ '<span class="aicm-act-type">' + escHtml( e.type || '—' ) + '</span>'
				+ '<span class="aicm-act-title">' + escHtml( e.title || ( '#' + e.post_id ) ) + '</span>'
				+ '<span class="aicm-act-status">' + escHtml( label ) + '</span>';

			actList.insertBefore( li, actList.firstChild );
		}

		// Trim to the server-side cap so the DOM never grows unbounded.
		while ( actList.children.length > 30 ) {
			actList.removeChild( actList.lastChild );
		}
	}

	// ── REST helper ───────────────────────────────────────────────────────
	function api( method, path, body ) {
		const opts = {
			method:  method,
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
		};
		if ( body !== undefined ) {
			opts.body = JSON.stringify( body );
		}
		return fetch( restBase + path, opts ).then( function ( r ) { return r.json(); } );
	}

	// ── Frontend mode: drive loop ─────────────────────────────────────────
	function driveQueue() {
		if ( looping ) {
			return;
		}
		looping = true;

		function step() {
			if ( stopped ) {
				looping = false;
				return;
			}

			api( 'POST', '/index/process' )
			.then( function ( data ) {
				const status = ( data && data.status ) ? data.status : {};
				applyStatus( status );

				if ( ! stopped && Number( status.pending || 0 ) > 0 ) {
					setTimeout( step, 800 );
				} else {
					looping = false;
					if ( ! stopped ) {
						showMessage( i18n.finished, 'success' );
					}
				}
			} )
			.catch( function () {
				// Transient network blip — retry after a longer pause rather
				// than aborting a long-running index over one failed call.
				if ( ! stopped ) {
					setTimeout( step, 3000 );
				} else {
					looping = false;
				}
			} );
		}

		step();
	}

	// ── Background mode: status polling (the server does the work) ───────
	function pollStatus() {
		if ( looping ) {
			return;
		}
		looping = true;

		function tick() {
			if ( stopped ) {
				looping = false;
				return;
			}

			api( 'GET', '/index/status' )
			.then( function ( status ) {
				applyStatus( status );

				if ( ! stopped && Number( status.pending || 0 ) > 0 ) {
					setTimeout( tick, 4000 );
				} else {
					looping = false;
					if ( ! stopped && status && ! status.is_running ) {
						showMessage( i18n.finished, 'success' );
					}
				}
			} )
			.catch( function () {
				if ( ! stopped ) {
					setTimeout( tick, 6000 );
				} else {
					looping = false;
				}
			} );
		}

		tick();
	}

	// ── Start indexing ────────────────────────────────────────────────────
	startBtn.addEventListener( 'click', function () {
		startBtn.disabled = true;
		stopped           = false;
		lastPending       = null;
		lastProgressAt    = Date.now();
		setRunning( true );
		clearMessage();

		const scopeEl = document.querySelector( 'input[name="aicm_scan_scope"]:checked' );
		const scope   = ( scopeEl && 'all' === scopeEl.value ) ? 'all' : 'new';

		api( 'POST', '/index/start', { scope: scope } )
		.then( function ( data ) {
			if ( data.success ) {
				if ( data.mode ) {
					mode = ( 'background' === data.mode ) ? 'background' : 'frontend';
				}

				// pending counts BOTH newly queued rows and rows left over
				// from an earlier interrupted run — work exists if it is > 0.
				if ( 0 === Number( data.pending || 0 ) ) {
					showMessage( data.message, 'info' );
					setRunning( false );
					return;
				}

				applyStatus( { is_running: true, pending: data.pending, total_chunks: ( chunksEl ? parseInt( chunksEl.textContent.replace( /\D/g, '' ), 10 ) : 0 ), indexed_posts: ( postsEl ? parseInt( postsEl.textContent.replace( /\D/g, '' ), 10 ) : 0 ) } );

				if ( 'background' === mode ) {
					showMessage( i18n.startedBackground, 'success' );
					pollStatus();
				} else {
					showMessage( data.message, 'success' );
					driveQueue();
				}
			} else {
				showMessage( data.message || i18n.couldNotStart, 'error' );
				setRunning( false );
			}
		} )
		.catch( function () {
			showMessage( i18n.requestFailed, 'error' );
			setRunning( false );
		} );
	} );

	// ── Stop indexing ─────────────────────────────────────────────────────
	stopBtn.addEventListener( 'click', function () {
		stopped = true;
		api( 'POST', '/index/stop' )
		.then( function () {
			setRunning( false );
			showMessage( i18n.stopped, 'info' );
			if ( pendingEl ) pendingEl.textContent = '0';
		} )
		.catch( function () {
			showMessage( i18n.couldNotStop, 'error' );
		} );
	} );

	// ── Resume on load if a queue is outstanding ──────────────────────────
	// frontend mode: resume driving (the previous tab may have been closed).
	// background mode: watch — the server is doing the work; the watchdog
	// revives it automatically if it has stalled.
	if ( cfg.isRunning ) {
		setRunning( true );
		if ( 'background' === mode ) {
			pollStatus();
		} else {
			driveQueue();
		}
	}

}() );
