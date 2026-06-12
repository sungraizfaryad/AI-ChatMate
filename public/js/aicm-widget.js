/**
 * Conciera — Chat Widget
 *
 * Handles the floating launcher, chat panel UI, and client-side chat history.
 *
 * Reads from window.aicmChat (set by wp_localize_script):
 *   restUrl        {string}  Base REST URL: e.g. https://example.com/wp-json/aicm/v1
 *   nonce          {string}  aicm_chat_nonce value
 *   restNonce      {string}  wp_rest nonce (sent as X-WP-Nonce)
 *   siteName       {string}  Blog name (shown in header)
 *   welcomeMessage {string}  First message shown on open (empty = skip)
 *   placeholder    {string}  Textarea placeholder text
 *   i18n           {object}  Strings for the history UI
 *
 * ── Chat history ──────────────────────────────────────────────────────────
 * Conversations persist in localStorage under 'aicm_chats_v1', so the chat
 * survives page refreshes and follows the visitor across pages of the same
 * site (localStorage is origin-wide). Each chat record also stores the
 * server-issued session id, so the assistant's conversational memory
 * survives a refresh too. Up to 10 chats / 80 messages each are kept; when
 * localStorage is unavailable (private mode, blocked cookies) everything
 * degrades gracefully to in-memory for the current page view.
 *
 * ── Init timing ───────────────────────────────────────────────────────────
 * This script is enqueued in the footer but the widget markup renders later
 * (wp_footer priority 100), so all DOM work is deferred until the document
 * is ready.
 */
( function () {
	'use strict';

	function init() {
		var cfg          = window.aicmChat  || {};
		var restBase     = cfg.restUrl      || '';
		var nonce        = cfg.nonce        || '';
		var restNonce    = cfg.restNonce    || '';
		var welcomeMsg   = cfg.welcomeMessage || '';
		var placeholder  = cfg.placeholder  || 'Ask a question…';
		var i18n         = cfg.i18n         || {};

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
		var historyBtn = document.getElementById( 'aicm-history-btn' );
		var newChatBtn = document.getElementById( 'aicm-newchat-btn' );
		var historyEl  = document.getElementById( 'aicm-history' );
		var historyUl  = document.getElementById( 'aicm-history-list' );

		// Bail if the required elements are not in the DOM (e.g. plugin disabled).
		if ( ! launcher || ! widget || ! messagesEl || ! inputEl || ! sendBtn ) {
			return;
		}

		// ── Chat history store (localStorage) ─────────────────────────────────

		var STORE_KEY = 'aicm_chats_v1';
		var MAX_CHATS = 10;
		var MAX_MSGS  = 80;

		function loadStore() {
			try {
				var raw = window.localStorage.getItem( STORE_KEY );
				if ( raw ) {
					var s = JSON.parse( raw );
					if ( s && Array.isArray( s.chats ) ) {
						return s;
					}
				}
			} catch ( e ) { /* unavailable or corrupted — start fresh */ }
			return { active: null, chats: [] };
		}

		// Single in-memory copy; saveStore() mirrors it to localStorage when
		// possible. If storage is blocked, the copy still works for this page.
		var store = loadStore();

		function saveStore() {
			try {
				window.localStorage.setItem( STORE_KEY, JSON.stringify( store ) );
			} catch ( e ) { /* quota/private mode — in-memory only */ }
		}

		function hasUserMessage( chat ) {
			for ( var i = 0; i < chat.messages.length; i++ ) {
				if ( 'u' === chat.messages[ i ].r ) {
					return true;
				}
			}
			return false;
		}

		function createChat() {
			var chat = {
				id:       'c' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 7 ),
				started:  Date.now(),
				session:  '',
				messages: []
			};

			// Drop other EMPTY chats (no user message) so the history list never
			// fills with blank conversations, then cap the total.
			store.chats = store.chats.filter( hasUserMessage );
			store.chats.unshift( chat );
			store.chats  = store.chats.slice( 0, MAX_CHATS );
			store.active = chat.id;
			saveStore();
			return chat;
		}

		function activeChat() {
			for ( var i = 0; i < store.chats.length; i++ ) {
				if ( store.chats[ i ].id === store.active ) {
					return store.chats[ i ];
				}
			}
			return createChat();
		}

		function persistMessage( role, text, sources, options ) {
			var chat   = activeChat();
			var record = {
				r: role,
				t: String( text ),
				s: ( sources || [] ).map( function ( s ) {
					return { t: String( s.title || '' ), u: String( s.url || '' ) };
				} )
			};

			// Store quick-reply options on bot records only; cap at 6.
			if ( options && options.length > 0 ) {
				record.o = options.slice( 0, 6 );
			}

			chat.messages.push( record );

			if ( chat.messages.length > MAX_MSGS ) {
				chat.messages = chat.messages.slice( -MAX_MSGS );
			}

			saveStore();
		}

		// The server-issued conversation id lives on the chat record, so the
		// assistant's memory survives refreshes and page navigation.
		var sessionId = activeChat().session || '';

		function rememberSession( id ) {
			sessionId = id;
			var chat  = activeChat();
			chat.session = id;
			saveStore();
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
			closeHistory();
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
				if ( widget.classList.contains( 'is-history' ) ) {
					closeHistory();
				} else {
					closeWidget();
				}
			}
		} );

		// ── Chat history UI ───────────────────────────────────────────────────

		function openHistory() {
			renderHistoryList();
			widget.classList.add( 'is-history' );
			if ( historyBtn ) {
				historyBtn.setAttribute( 'aria-expanded', 'true' );
			}
		}

		function closeHistory() {
			widget.classList.remove( 'is-history' );
			if ( historyBtn ) {
				historyBtn.setAttribute( 'aria-expanded', 'false' );
			}
		}

		function chatLabel( chat ) {
			for ( var i = 0; i < chat.messages.length; i++ ) {
				if ( 'u' === chat.messages[ i ].r ) {
					var t = chat.messages[ i ].t;
					return t.length > 60 ? t.slice( 0, 60 ) + '…' : t;
				}
			}
			return i18n.newConversation || 'New conversation';
		}

		// All nodes are built with createElement/textContent — stored text is
		// never injected as HTML, so a crafted message cannot script-inject.
		function renderHistoryList() {
			if ( ! historyUl ) {
				return;
			}

			historyUl.innerHTML = '';

			var listed = store.chats.filter( hasUserMessage );

			if ( 0 === listed.length ) {
				var empty = document.createElement( 'li' );
				empty.className   = 'aicm-history-empty';
				empty.textContent = i18n.noHistory || 'No previous conversations yet.';
				historyUl.appendChild( empty );
				return;
			}

			listed.forEach( function ( chat ) {
				var li  = document.createElement( 'li' );
				var btn = document.createElement( 'button' );
				btn.type      = 'button';
				btn.className = 'aicm-history-item' + ( chat.id === store.active ? ' is-current' : '' );

				var label = document.createElement( 'span' );
				label.className   = 'aicm-history-item__label';
				label.textContent = chatLabel( chat );

				var meta = document.createElement( 'span' );
				meta.className   = 'aicm-history-item__meta';
				meta.textContent = new Date( chat.started ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } )
					+ ( chat.id === store.active && i18n.current ? ' · ' + i18n.current : '' );

				btn.appendChild( label );
				btn.appendChild( meta );
				btn.addEventListener( 'click', function () {
					switchChat( chat.id );
				} );

				li.appendChild( btn );
				historyUl.appendChild( li );
			} );
		}

		function renderChat( chat ) {
			messagesEl.innerHTML = '';
			welcomeShown         = chat.messages.length > 0;

			chat.messages.forEach( function ( m ) {
				appendMessage(
					m.t,
					'u' === m.r ? 'user' : 'bot',
					( m.s || [] ).map( function ( s ) {
						return { title: s.t, url: s.u };
					} )
				);
			} );

			// Restore chips if the last message is a bot message with saved options.
			var msgs = chat.messages;
			if ( msgs.length > 0 ) {
				var last = msgs[ msgs.length - 1 ];
				if ( 'b' === last.r && Array.isArray( last.o ) && last.o.length > 0 ) {
					renderChips( last.o );
				}
			}
		}

		function switchChat( id ) {
			if ( isBusy || id === store.active ) {
				closeHistory();
				return;
			}

			store.active = id;
			saveStore();

			var chat  = activeChat();
			sessionId = chat.session || '';
			renderChat( chat );
			closeHistory();
			inputEl.focus();
		}

		function startNewChat() {
			if ( isBusy ) {
				return;
			}

			// If the current chat is still empty, just stay on it.
			if ( ! hasUserMessage( activeChat() ) ) {
				closeHistory();
				inputEl.focus();
				return;
			}

			createChat();
			sessionId            = '';
			messagesEl.innerHTML = '';
			welcomeShown         = false;
			closeHistory();

			if ( isOpen && '' !== welcomeMsg ) {
				welcomeShown = true;
				appendMessage( welcomeMsg, 'bot', [] );
			}

			inputEl.focus();
		}

		if ( historyBtn ) {
			historyBtn.addEventListener( 'click', function () {
				widget.classList.contains( 'is-history' ) ? closeHistory() : openHistory();
			} );
		}

		if ( newChatBtn ) {
			newChatBtn.addEventListener( 'click', startNewChat );
		}

		// ── Send message ──────────────────────────────────────────────────────

		/**
		 * Send a message, either typed by the user or triggered by a chip tap.
		 *
		 * @param {string} [overrideText]  When non-empty, use this text instead of
		 *                                 reading the input element. Input element
		 *                                 handling (clear, height reset) is skipped.
		 */
		function sendMessage( overrideText ) {
			if ( isBusy ) {
				return;
			}

			// Remove any stale quick-reply chips before sending — typed or tapped.
			var existingChips = messagesEl.querySelector( '.aicm-chips' );
			if ( existingChips ) {
				removeEl( existingChips );
			}

			var fromChip = ( 'string' === typeof overrideText && '' !== overrideText.trim() );
			var text;

			if ( fromChip ) {
				text = overrideText.trim();
			} else {
				text = inputEl.value.trim();
			}

			if ( '' === text ) {
				return;
			}

			// A send always happens in the conversation view.
			closeHistory();

			// Enforce client-side max length (REST validates server-side too).
			if ( text.length > 2000 ) {
				text = text.slice( 0, 2000 );
			}

			isBusy           = true;
			sendBtn.disabled = true;

			// Only manipulate the input element when the text came from it.
			if ( ! fromChip ) {
				inputEl.value        = '';
				inputEl.style.height = 'auto';
			}

			appendMessage( text, 'user', [] );
			persistMessage( 'u', text, [] );

			var typingEl = appendTyping();

			// X-WP-Nonce keeps the REST request authenticated as the SAME user
			// the page was rendered for (uid 0 for visitors, real uid for
			// logged-in users). Without it WordPress downgrades the request to
			// logged-out, and the user-bound X-AICM-Nonce can never verify for
			// logged-in visitors.
			var headers = {
				'Content-Type': 'application/json',
				'X-AICM-Nonce': nonce,
			};
			if ( restNonce ) {
				headers['X-WP-Nonce'] = restNonce;
			}

			fetch( restBase + '/chat', {
				method:  'POST',
				headers: headers,
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
					rememberSession( data.session_id );
				}

				var reply   = ( data.reply && '' !== data.reply )
					? data.reply
					: 'Sorry, I could not generate a response. Please try again.';
				var sources = Array.isArray( data.sources ) ? data.sources : [];
				var options = ( Array.isArray( data.options ) && data.options.length > 0 )
					? data.options.slice( 0, 6 )
					: [];

				appendMessage( reply, 'bot', sources );
				persistMessage( 'b', reply, sources, options );

				// Render quick-reply chips when the server sent options.
				if ( options.length > 0 ) {
					renderChips( options );
				}
			} )
			.catch( function ( err ) {
				removeEl( typingEl );

				var msg = ( err && err.message && err.message.indexOf( 'HTTP ' ) !== 0 )
					? err.message
					: 'Sorry, I could not connect. Please check your connection and try again.';

				appendMessage( msg, 'bot', [] );
				persistMessage( 'b', msg, [] );
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

		// ── Restore the active conversation from a previous page/visit ───────
		var restored = activeChat();
		if ( restored.messages.length > 0 ) {
			renderChat( restored );
		}

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

		/**
		 * Render a row of tappable quick-reply chips below the last message.
		 * There is at most one chips row at a time; call renderChips() removes any
		 * previous one automatically.
		 *
		 * @param {string[]} options  Up to 6 short option strings (already sliced).
		 */
		function renderChips( options ) {
			// Remove any existing chips row first.
			var old = messagesEl.querySelector( '.aicm-chips' );
			if ( old ) {
				removeEl( old );
			}

			var row = document.createElement( 'div' );
			row.className = 'aicm-chips';

			options.forEach( function ( label ) {
				var btn       = document.createElement( 'button' );
				btn.type      = 'button';
				btn.className = 'aicm-chip';
				// textContent only — never innerHTML, prevents XSS from server data.
				btn.textContent = label;

				btn.addEventListener( 'click', function () {
					if ( isBusy ) {
						return;
					}
					// Chips row is removed inside sendMessage() before the send.
					sendMessage( label );
				} );

				row.appendChild( btn );
			} );

			messagesEl.appendChild( row );
			scrollToBottom();
		}

		// ── Text formatting ───────────────────────────────────────────────────

		/**
		 * Convert plain text (with simple markdown) to safe HTML.
		 *
		 * @param   {string} raw
		 * @returns {string} HTML string, safe to set as innerHTML.
		 */
		function formatText( raw ) {
			var s = String( raw );

			// The UI shows clickable buttons for every source below the
			// message, so links inside the text are unwanted (and raw markdown
			// renders as noise). Collapse markdown links to their bold label
			// and drop bare URLs entirely. Done BEFORE escaping; only the
			// label text survives, and it gets escaped below like everything
			// else.
			s = s.replace( /\[([^\]\n]+)\]\((?:[^)\s]+)\)/g, '**$1**' );
			s = s.replace( /https?:\/\/[^\s)\]]+/g, '' );

			s = escHtml( s );

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
	}

	// Run init() once the DOM is ready. The widget markup is rendered after
	// this script in the footer, so we must wait for DOMContentLoaded; if the
	// document is already parsed (script loaded late/async), run immediately.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

}() );
