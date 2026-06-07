/**
 * AI ChatMate — Chat Widget
 *
 * Handles the floating launcher and chat panel UI.
 *
 * Reads from window.aicmChat (set by wp_localize_script):
 *   restUrl        {string}  Base REST URL: e.g. https://example.com/wp-json/aicm/v1
 *   nonce          {string}  aicm_chat_nonce value
 *   siteName       {string}  Blog name (shown in header)
 *   welcomeMessage {string}  First message shown on open (empty = skip)
 *   placeholder    {string}  Textarea placeholder text
 *
 * Session ID is stored in sessionStorage so it persists across navigations
 * within the same tab but is cleared when the tab is closed.
 */
( function () {
	'use strict';

	var cfg          = window.aicmChat  || {};
	var restBase     = cfg.restUrl      || '';
	var nonce        = cfg.nonce        || '';
	var welcomeMsg   = cfg.welcomeMessage || '';
	var placeholder  = cfg.placeholder  || 'Ask a question\u2026';

	var SESSION_KEY  = 'aicm_session_id';

	// Session ID — reuse existing one from storage if available.
	var sessionId = sessionStorage.getItem( SESSION_KEY ) || '';

	// State flags.
	var isOpen       = false;
	var isBusy       = false;
	var welcomeShown = false;

	// ── Element references ────────────────────────────────────────────────
	var launcher   = document.getElementById( 'aicm-launcher' );
	var widget     = document.getElementById( 'aicm-widget' );
	var messagesEl = document.getElementById( 'aicm-messages' );
	var inputEl    = document.getElementById( 'aicm-input' );
	var sendBtn    = document.getElementById( 'aicm-send' );
	var closeBtn   = widget ? widget.querySelector( '.aicm-widget__close' ) : null;

	// Bail if the required elements are not in the DOM (e.g. plugin disabled).
	if ( ! launcher || ! widget || ! messagesEl || ! inputEl || ! sendBtn ) {
		return;
	}

	// ── Open / close ─────────────────────────────────────────────────────

	function openWidget() {
		isOpen = true;
		widget.classList.add( 'is-open' );
		widget.setAttribute( 'aria-hidden', 'false' );
		launcher.setAttribute( 'aria-expanded', 'true' );
		inputEl.focus();

		// Show the welcome message the first time the widget is opened.
		if ( ! welcomeShown && '' !== welcomeMsg ) {
			welcomeShown = true;
			appendMessage( welcomeMsg, 'bot', [] );
		}
	}

	function closeWidget() {
		isOpen = false;
		widget.classList.remove( 'is-open' );
		widget.setAttribute( 'aria-hidden', 'true' );
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.focus();
	}

	launcher.addEventListener( 'click', function () {
		isOpen ? closeWidget() : openWidget();
	} );

	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', closeWidget );
	}

	// Close when pressing Escape while the widget is focused.
	widget.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			closeWidget();
		}
	} );

	// ── Send message ──────────────────────────────────────────────────────

	function sendMessage() {
		if ( isBusy ) {
			return;
		}

		var text = inputEl.value.trim();
		if ( '' === text ) {
			return;
		}

		// Enforce client-side max length (REST validates server-side too).
		if ( text.length > 2000 ) {
			text = text.slice( 0, 2000 );
		}

		isBusy            = true;
		inputEl.value     = '';
		inputEl.style.height = 'auto';
		sendBtn.disabled  = true;

		appendMessage( text, 'user', [] );

		var typingEl = appendTyping();

		fetch( restBase + '/chat', {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-AICM-Nonce': nonce,
			},
			body: JSON.stringify( {
				message:    text,
				session_id: sessionId,
			} ),
		} )
		.then( function ( response ) {
			if ( ! response.ok ) {
				// Surface HTTP-level errors (403, 429, 500, etc.).
				return response.json().then( function ( errBody ) {
					throw new Error(
						( errBody && errBody.message )
							? errBody.message
							: 'HTTP ' + response.status
					);
				} );
			}
			return response.json();
		} )
		.then( function ( data ) {
			removeEl( typingEl );

			// Persist session ID for conversation continuity.
			if ( data.session_id ) {
				sessionId = data.session_id;
				sessionStorage.setItem( SESSION_KEY, sessionId );
			}

			var reply   = ( data.reply && '' !== data.reply )
				? data.reply
				: 'Sorry, I could not generate a response. Please try again.';
			var sources = Array.isArray( data.sources ) ? data.sources : [];

			appendMessage( reply, 'bot', sources );
		} )
		.catch( function ( err ) {
			removeEl( typingEl );

			var msg = ( err && err.message && err.message.indexOf( 'HTTP ' ) !== 0 )
				? err.message
				: 'Sorry, I could not connect. Please check your connection and try again.';

			appendMessage( msg, 'bot', [] );
		} )
		.finally( function () {
			isBusy           = false;
			sendBtn.disabled = false;
			inputEl.focus();
		} );
	}

	sendBtn.addEventListener( 'click', sendMessage );

	inputEl.addEventListener( 'keydown', function ( e ) {
		// Enter sends; Shift+Enter inserts a newline.
		if ( 'Enter' === e.key && ! e.shiftKey ) {
			e.preventDefault();
			sendMessage();
		}
	} );

	// Auto-grow the textarea as the user types.
	inputEl.setAttribute( 'placeholder', placeholder );
	inputEl.addEventListener( 'input', function () {
		inputEl.style.height = 'auto';
		inputEl.style.height = Math.min( inputEl.scrollHeight, 120 ) + 'px';
	} );

	// ── DOM helpers ───────────────────────────────────────────────────────

	/**
	 * Append a message bubble to the messages container.
	 *
	 * @param {string}   text    Message text (may contain **bold** markdown).
	 * @param {string}   role    'user' or 'bot'.
	 * @param {Array}    sources Array of {id, title, url} source objects.
	 * @returns {Element}
	 */
	function appendMessage( text, role, sources ) {
		var wrap   = document.createElement( 'div' );
		wrap.className = 'aicm-msg aicm-msg--' + role;

		var bubble = document.createElement( 'div' );
		bubble.className = 'aicm-msg__bubble';
		bubble.innerHTML = formatText( text );
		wrap.appendChild( bubble );

		if ( sources && sources.length > 0 ) {
			var srcEl = document.createElement( 'div' );
			srcEl.className = 'aicm-msg__sources';

			sources.forEach( function ( src ) {
				if ( ! src || ! src.url || ! src.title ) {
					return;
				}
				var a        = document.createElement( 'a' );
				a.href       = escAttr( src.url );
				a.textContent = src.title;
				a.target     = '_blank';
				a.rel        = 'noopener noreferrer';
				srcEl.appendChild( a );
			} );

			if ( srcEl.childNodes.length > 0 ) {
				wrap.appendChild( srcEl );
			}
		}

		messagesEl.appendChild( wrap );
		scrollToBottom();
		return wrap;
	}

	/**
	 * Append an animated "typing…" indicator.
	 *
	 * @returns {Element} The typing row element (pass to removeEl() to dismiss).
	 */
	function appendTyping() {
		var wrap   = document.createElement( 'div' );
		wrap.className = 'aicm-msg aicm-msg--bot aicm-msg--typing';

		var bubble = document.createElement( 'div' );
		bubble.className = 'aicm-msg__bubble';
		bubble.innerHTML = '<span></span><span></span><span></span>';

		wrap.appendChild( bubble );
		messagesEl.appendChild( wrap );
		scrollToBottom();
		return wrap;
	}

	/**
	 * Remove a DOM element if it is still attached.
	 *
	 * @param {Element|null} el
	 */
	function removeEl( el ) {
		if ( el && el.parentNode ) {
			el.parentNode.removeChild( el );
		}
	}

	function scrollToBottom() {
		messagesEl.scrollTop = messagesEl.scrollHeight;
	}

	// ── Text formatting ───────────────────────────────────────────────────

	/**
	 * Convert plain text (with simple markdown) to safe HTML.
	 *
	 * Processing order:
	 *  1. Escape all HTML entities (XSS prevention).
	 *  2. Convert **text** → <strong>text</strong>.
	 *  3. Convert newlines → <br>.
	 *
	 * @param   {string} raw
	 * @returns {string} HTML string, safe to set as innerHTML.
	 */
	function formatText( raw ) {
		var s = escHtml( String( raw ) );

		// Bold: **text**
		s = s.replace( /\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>' );

		// Newlines.
		s = s.replace( /\n/g, '<br>' );

		return s;
	}

	/**
	 * Escape a string for safe use as HTML text content.
	 *
	 * @param   {string} str
	 * @returns {string}
	 */
	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	/**
	 * Escape a string for safe use as an HTML attribute value.
	 *
	 * @param   {string} str
	 * @returns {string}
	 */
	function escAttr( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

} )();
