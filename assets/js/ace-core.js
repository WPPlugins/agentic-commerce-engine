/**
 * Rezolve Ai: shared admin runtime (v4).
 *
 * Defines window.agencoen.core: the ajax bridge, toasts, formatting helpers,
 * skeletons, modal dialogs, SegmentedTabs wiring, stat tiles, chips, a backoff
 * poller, and the chunked batch runner. Every screen script (assets/js/screens/*.js)
 * builds on this file. Classic IIFE, no modules, no dependencies.
 */
( function () {
	'use strict';

	window.agencoen = window.agencoen || {};

	var cfg = window.agencoenConfig || {};
	var i18n = cfg.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	/* ------------------------------------------------------------------ */
	/* Ajax bridge                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * POST an agencoen_* admin-ajax action. Resolves with the `data` of
	 * wp_send_json_success; rejects with the error object from
	 * wp_send_json_error ({ message, code?, insufficientCredits? }).
	 */
	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', 'agencoen_' + action );
		body.append( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			var v = data[ key ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else if ( v !== undefined && v !== null ) {
				body.append( key, v );
			}
		} );
		return fetch( cfg.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then(
			function ( r ) {
				return r.json().catch( function () {
					throw { message: t( 'genericError', 'Something went wrong. Please try again.' ) };
				} );
			},
			function () {
				throw { message: t( 'genericError', 'Something went wrong. Please try again.' ) };
			}
		).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw ( json && json.data ) ? json.data : { message: t( 'genericError', 'Something went wrong. Please try again.' ) };
			}
			return json.data;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Toasts                                                             */
	/* ------------------------------------------------------------------ */

	function toastEl() {
		var el = document.getElementById( 'ace-toast' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.id = 'ace-toast';
			el.className = 'ace-toast';
			el.style.display = 'none';
			el.setAttribute( 'role', 'status' );
			el.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( el );
		}
		return el;
	}

	/**
	 * Show a toast. Plain messages fade after six seconds (the status role has
	 * already read them out). One carrying a link stays until it is closed:
	 * a link that vanishes on a timer cannot be reached by keyboard in time.
	 */
	function toast( message, type, html ) {
		var el = toastEl();
		el.className = 'ace-toast ace-toast-' + ( type || 'info' );
		if ( html ) {
			el.innerHTML = message + ' <button type="button" class="ace-toast-close" aria-label="' +
				esc( t( 'dismissMessage', 'Dismiss message' ) ) + '">&times;</button>';
			el.querySelector( '.ace-toast-close' ).addEventListener( 'click', function () {
				el.style.display = 'none';
			} );
		} else {
			el.textContent = message;
		}
		el.style.display = 'block';
		window.clearTimeout( el._t );
		if ( ! html ) {
			el._t = window.setTimeout( function () {
				el.style.display = 'none';
			}, 6000 );
		}
	}

	/**
	 * Error toast. Accepts a plain message string or the rejection object
	 * from post() (renders an account link when credits are exhausted).
	 */
	function errorToast( err ) {
		if ( typeof err === 'string' ) {
			toast( err, 'error' );
			return;
		}
		err = err || {};
		if ( err.insufficientCredits && err.accountUrl ) {
			toast(
				esc( err.message || t( 'genericError', 'Something went wrong.' ) ) +
				' <a href="' + encodeURI( err.accountUrl ) + '" target="_blank" rel="noopener">' +
				esc( t( 'accountLink', 'Open your Rezolve Ai account' ) ) + '</a>',
				'error',
				true
			);
		} else {
			toast( err.message || t( 'genericError', 'Something went wrong. Please try again.' ), 'error' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Formatting helpers                                                 */
	/* ------------------------------------------------------------------ */

	function esc( str ) {
		var d = document.createElement( 'div' );
		d.textContent = str == null ? '' : String( str );
		return d.innerHTML;
	}

	function num( n ) {
		var v = Number( n );
		if ( ! isFinite( v ) ) {
			return String( n == null ? 0 : n );
		}
		try {
			return v.toLocaleString();
		} catch ( e ) {
			return String( v );
		}
	}

	/** One-decimal percent string, e.g. 23.46 -> "23.5%". */
	function pct( n ) {
		var v = Number( n );
		if ( ! isFinite( v ) ) {
			v = 0;
		}
		try {
			return v.toLocaleString( undefined, { maximumFractionDigits: 1 } ) + '%';
		} catch ( e ) {
			return ( Math.round( v * 10 ) / 10 ) + '%';
		}
	}

	function debounce( fn, ms ) {
		var timer;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( ctx, args );
			}, ms );
		};
	}

	/** Short local date, e.g. "Jul 16". */
	function fmtDate( iso ) {
		if ( ! iso ) {
			return '';
		}
		var d = new Date( iso );
		if ( isNaN( d.getTime() ) ) {
			return String( iso );
		}
		try {
			return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
		} catch ( e ) {
			return d.toDateString();
		}
	}

	/** Relative time, e.g. "5m ago". Falls back to fmtDate beyond a week. */
	function timeAgo( iso ) {
		if ( ! iso ) {
			return '';
		}
		var d = new Date( iso );
		if ( isNaN( d.getTime() ) ) {
			return String( iso );
		}
		var s = Math.max( 0, Math.floor( ( Date.now() - d.getTime() ) / 1000 ) );
		if ( s < 60 ) {
			return t( 'justNow', 'just now' );
		}
		var mins = Math.floor( s / 60 );
		if ( mins < 60 ) {
			return ( t( 'minutesAgo', '%sm ago' ) ).replace( '%s', mins );
		}
		var hours = Math.floor( mins / 60 );
		if ( hours < 24 ) {
			return ( t( 'hoursAgo', '%sh ago' ) ).replace( '%s', hours );
		}
		var days = Math.floor( hours / 24 );
		if ( days < 7 ) {
			return ( t( 'daysAgo', '%sd ago' ) ).replace( '%s', days );
		}
		return fmtDate( iso );
	}

	/* ------------------------------------------------------------------ */
	/* UI helpers                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Busy state for a button while its request runs.
	 *
	 * A disabled element cannot hold focus, so disabling the button the
	 * merchant just pressed drops keyboard focus to the page body and a screen
	 * reader starts again from the top. The focused button is marked
	 * aria-disabled instead, and the capture listener below swallows its clicks
	 * until the work is done, so it still cannot be pressed twice.
	 */
	function setBusy( button, busy, label ) {
		if ( ! button ) {
			return;
		}
		if ( busy ) {
			button._label = button.textContent;
			if ( label ) {
				button.textContent = label;
			}
			button.classList.add( 'is-busy' );
			button.setAttribute( 'aria-disabled', 'true' );
			if ( document.activeElement !== button ) {
				button.disabled = true;
			}
		} else {
			if ( button._label ) {
				button.textContent = button._label;
			}
			button.classList.remove( 'is-busy' );
			button.removeAttribute( 'aria-disabled' );
			button.disabled = false;
		}
	}

	// A busy button that kept its focus is still enabled; this is what keeps a
	// second press from sending the request again.
	document.addEventListener( 'click', function ( e ) {
		var busy = e.target && e.target.closest ? e.target.closest( '.is-busy[aria-disabled="true"]' ) : null;
		if ( busy ) {
			e.preventDefault();
			e.stopPropagation();
		}
	}, true );

	/**
	 * Mark a container aria-busy while it holds skeleton placeholders, and
	 * clear it the moment the real content replaces them. The screens fill
	 * containers with innerHTML, so watching for the placeholders to go is the
	 * one place this can be done for all of them.
	 */
	function watchBusy( el ) {
		if ( ! el ) {
			return;
		}
		el.setAttribute( 'aria-busy', 'true' );
		if ( el._aceBusy ) {
			el._aceBusy.disconnect();
			el._aceBusy = null;
		}
		if ( ! window.MutationObserver ) {
			return;
		}
		var observer = new window.MutationObserver( function () {
			if ( ! el.querySelector( '.ace-skeleton' ) ) {
				el.removeAttribute( 'aria-busy' );
				observer.disconnect();
				el._aceBusy = null;
			}
		} );
		observer.observe( el, { childList: true, subtree: true } );
		el._aceBusy = observer;
	}

	/**
	 * Fill an element with skeleton placeholders.
	 * kind: 'tiles' | 'chart' | 'table' | 'text'
	 */
	function skeleton( el, kind ) {
		if ( ! el ) {
			return;
		}
		var html = '';
		var i;
		if ( 'tiles' === kind ) {
			html = '<div class="ace-skeleton-grid">';
			for ( i = 0; i < 4; i++ ) {
				html += '<div class="ace-skeleton ace-skeleton-tile"></div>';
			}
			html += '</div>';
		} else if ( 'chart' === kind ) {
			html = '<div class="ace-skeleton ace-skeleton-block" style="height:180px"></div>';
		} else if ( 'table' === kind ) {
			html = '<div class="ace-skeleton ace-skeleton-row" style="height:32px;opacity:.7"></div>';
			for ( i = 0; i < 6; i++ ) {
				html += '<div class="ace-skeleton ace-skeleton-row"></div>';
			}
		} else {
			html = '<div class="ace-skeleton ace-skeleton-text" style="width:82%"></div>' +
				'<div class="ace-skeleton ace-skeleton-text" style="width:64%"></div>' +
				'<div class="ace-skeleton ace-skeleton-text" style="width:73%"></div>';
		}
		el.innerHTML = '<div class="ace-skeleton-wrap" aria-hidden="true">' + html + '</div>' +
			'<span class="screen-reader-text">' + esc( t( 'loading', 'Loading' ) ) + '</span>';
		watchBusy( el );
	}

	/* ------------------------------------------------------------------ */
	/* Dialogs                                                             */
	/* ------------------------------------------------------------------ */

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
		'select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

	/** Elements Tab can reach inside `root`, in document order. */
	function focusables( root ) {
		return Array.prototype.slice.call( root.querySelectorAll( FOCUSABLE ) ).filter( function ( node ) {
			return ! node.closest( '[hidden]' ) && 'none' !== node.style.display;
		} );
	}

	/**
	 * Open a modal: `modal` is the hidden .ace-modal wrapper around a
	 * [role="dialog"] card. Focus moves into the card, Tab and Shift+Tab stay
	 * inside it, Escape and the backdrop close it, and closing puts focus back
	 * on whatever opened it.
	 *
	 * opts: { onClose? } called when the merchant dismisses it.
	 * Returns { close, dismiss }: close after the dialog did its job, dismiss
	 * for Cancel (which also calls onClose).
	 */
	function dialog( modal, opts ) {
		opts = opts || {};
		if ( ! modal ) {
			return { close: function () {}, dismiss: function () {} };
		}
		var card = modal.querySelector( '[role="dialog"]' ) || modal;
		// Reopened while open (a second click on the opener): keep the first
		// opener, which is where focus has to go back to.
		var opener = modal._aceDialog ? modal._aceDialog.opener : document.activeElement;
		if ( modal._aceDialog ) {
			modal._aceDialog.teardown();
		}
		var open = true;

		function onKey( e ) {
			if ( 'Escape' === e.key || 'Esc' === e.key ) {
				e.preventDefault();
				e.stopPropagation();
				close( true );
				return;
			}
			if ( 'Tab' !== e.key ) {
				return;
			}
			var items = focusables( card );
			if ( ! items.length ) {
				e.preventDefault();
				card.focus();
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			var active = document.activeElement;
			if ( e.shiftKey && ( active === first || active === card || ! card.contains( active ) ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && ( active === last || ! card.contains( active ) ) ) {
				e.preventDefault();
				first.focus();
			}
		}

		// Anything that moves focus out while the dialog is open (a click
		// on the page behind, a script) is pulled back in.
		function onFocusIn( e ) {
			if ( open && ! modal.contains( e.target ) ) {
				( focusables( card )[ 0 ] || card ).focus();
			}
		}

		function teardown() {
			open = false;
			modal.hidden = true;
			modal.removeEventListener( 'keydown', onKey );
			document.removeEventListener( 'focusin', onFocusIn, true );
			modal._aceDialog = null;
		}

		function close( dismissed ) {
			if ( ! open ) {
				return;
			}
			teardown();
			if ( opener && opener.focus && document.body.contains( opener ) ) {
				opener.focus();
			}
			if ( dismissed && opts.onClose ) {
				opts.onClose();
			}
		}

		modal.hidden = false;
		modal.addEventListener( 'keydown', onKey );
		document.addEventListener( 'focusin', onFocusIn, true );
		var backdrop = modal.querySelector( '.ace-modal-backdrop' );
		if ( backdrop ) {
			backdrop.onclick = function () {
				close( true );
			};
		}
		if ( ! card.hasAttribute( 'tabindex' ) ) {
			card.setAttribute( 'tabindex', '-1' );
		}
		modal._aceDialog = { opener: opener, teardown: teardown };
		( focusables( card )[ 0 ] || card ).focus();

		return {
			close: function () {
				close( false );
			},
			dismiss: function () {
				close( true );
			}
		};
	}

	/* ------------------------------------------------------------------ */
	/* Batch progress                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Move a batch progress bar. `fill` is the .ace-batch-fill inside the
	 * role="progressbar" .ace-batch-bar; the bar carries the value so a
	 * screen reader can ask for it, and the polite status text beside it says
	 * it as it changes.
	 */
	function setProgress( fill, pct, text ) {
		var value = Math.max( 0, Math.min( 100, Math.round( Number( pct ) || 0 ) ) );
		if ( fill ) {
			fill.style.width = value + '%';
			var bar = fill.parentNode;
			if ( bar && bar.setAttribute ) {
				bar.setAttribute( 'aria-valuenow', String( value ) );
				if ( text ) {
					bar.setAttribute( 'aria-valuetext', text );
				} else {
					bar.removeAttribute( 'aria-valuetext' );
				}
			}
		}
	}

	/** The usable tabs of a tablist, in order. */
	function tabList( bar ) {
		return Array.prototype.slice.call( bar.querySelectorAll( '[role="tab"]' ) ).filter( function ( tab ) {
			return ! tab.disabled && ! tab.hidden;
		} );
	}

	/** Only the selected tab sits in the Tab order; arrows reach the rest. */
	function syncTabStops( bar ) {
		var list = tabList( bar );
		var selected = list.filter( function ( tab ) {
			return 'true' === tab.getAttribute( 'aria-selected' );
		} )[ 0 ] || list[ 0 ];
		list.forEach( function ( tab ) {
			tab.setAttribute( 'tabindex', tab === selected ? '0' : '-1' );
		} );
	}

	/**
	 * Arrow keys for a tablist: Left and Right move to the previous or next
	 * tab and choose it (wrapping), Home and End go to the ends. Safe to call
	 * again after the tabs are re-rendered; the listener is added once.
	 */
	function tabKeys( bar ) {
		if ( ! bar ) {
			return;
		}
		syncTabStops( bar );
		if ( bar._aceTabKeys ) {
			return;
		}
		bar._aceTabKeys = true;
		bar.addEventListener( 'keydown', function ( e ) {
			var list = tabList( bar );
			var i = list.indexOf( e.target );
			if ( i < 0 ) {
				return;
			}
			var next = null;
			if ( 'ArrowRight' === e.key ) {
				next = list[ ( i + 1 ) % list.length ];
			} else if ( 'ArrowLeft' === e.key ) {
				next = list[ ( i - 1 + list.length ) % list.length ];
			} else if ( 'Home' === e.key ) {
				next = list[ 0 ];
			} else if ( 'End' === e.key ) {
				next = list[ list.length - 1 ];
			}
			if ( ! next ) {
				return;
			}
			e.preventDefault();
			next.focus();
			next.click();
			// A filter that re-renders its tabs has just replaced the one
			// that had focus; put focus on its replacement.
			if ( ! bar.contains( document.activeElement ) ) {
				var chosen = bar.querySelector( '[role="tab"][aria-selected="true"]' );
				if ( chosen ) {
					chosen.focus();
				}
			}
			syncTabStops( bar );
		} );
	}

	var tabSeq = 0;

	/**
	 * Wire a SegmentedTabs bar. `container` is either the .ace-tabs element or
	 * an ancestor containing one. Panels are [data-panel="<tab>"] elements in
	 * the surrounding screen. Syncs location.hash and restores from it.
	 *
	 * Each tab controls its panel and each panel is labelled by its tab, so a
	 * screen reader announces where a tab leads and what a panel is.
	 */
	function tabs( container, onChange ) {
		if ( ! container ) {
			return null;
		}
		var bar = container.classList && container.classList.contains( 'ace-tabs' )
			? container
			: container.querySelector( '.ace-tabs' );
		if ( ! bar ) {
			return null;
		}
		var scope = bar.closest( '.agencoen-wrap' ) || ( container === bar ? document : container );
		var items = Array.prototype.slice.call( bar.querySelectorAll( '[data-tab]' ) );
		if ( ! items.length ) {
			return null;
		}
		var uid = bar.id || ( 'ace-tabs-' + ( ++tabSeq ) );

		function activate( id, silent ) {
			var found = false;
			items.forEach( function ( item ) {
				var active = item.getAttribute( 'data-tab' ) === id;
				item.classList.toggle( 'is-active', active );
				item.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				if ( active ) {
					found = true;
				}
			} );
			if ( ! found ) {
				return false;
			}
			syncTabStops( bar );
			Array.prototype.slice.call( scope.querySelectorAll( '[data-panel]' ) ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-panel' ) !== id;
			} );
			if ( ! silent ) {
				try {
					window.history.replaceState( null, '', '#' + id );
				} catch ( e ) {
					/* history API unavailable; hash sync skipped */
				}
				if ( onChange ) {
					onChange( id );
				}
			}
			return true;
		}

		items.forEach( function ( item ) {
			var key = item.getAttribute( 'data-tab' );
			if ( ! item.id ) {
				item.id = uid + '-tab-' + key;
			}
			item.setAttribute( 'role', 'tab' );
			var panel = scope.querySelector( '[data-panel="' + key + '"]' );
			if ( panel ) {
				if ( ! panel.id ) {
					panel.id = uid + '-panel-' + key;
				}
				panel.setAttribute( 'role', 'tabpanel' );
				panel.setAttribute( 'aria-labelledby', item.id );
				item.setAttribute( 'aria-controls', panel.id );
			}
			item.addEventListener( 'click', function () {
				activate( key );
			} );
		} );
		bar.setAttribute( 'role', 'tablist' );
		tabKeys( bar );

		// Restore from the hash, else honor the server-rendered active item.
		var fromHash = ( window.location.hash || '' ).replace( /^#/, '' );
		if ( ! fromHash || ! activate( fromHash, true ) ) {
			var current = bar.querySelector( '.is-active[data-tab]' ) || items[ 0 ];
			activate( current.getAttribute( 'data-tab' ), true );
		}

		return { activate: activate };
	}

	/**
	 * KPI stat tile HTML.
	 * opts: { label, value, sub?, accent?, trend? } with trend { dir: 'up'|'down', text }.
	 */
	function statTile( opts ) {
		opts = opts || {};
		var accent = opts.accent || 'indigo';
		var valueStr = opts.value == null ? '' : String( opts.value );
		var textual = valueStr.length > 9 && ! /^[\d.,%\s]+$/.test( valueStr );
		var html = '<div class="ace-stat" data-accent="' + esc( accent ) + '">';
		html += '<span class="ace-stat-label">' + esc( opts.label || '' ) + '</span>';
		html += '<span class="ace-stat-row">';
		html += '<span class="ace-stat-value' + ( textual ? ' is-text' : '' ) + '">' + esc( valueStr ) + '</span>';
		if ( opts.trend && opts.trend.text ) {
			var dir = 'down' === opts.trend.dir ? 'down' : 'up';
			var arrow = 'down' === dir ? '&#9660;' : '&#9650;';
			html += '<span class="ace-trend ' + dir + '">' + arrow + ' ' + esc( opts.trend.text ) + '</span>';
		}
		html += '</span>';
		if ( opts.sub ) {
			html += '<span class="ace-stat-sub">' + esc( opts.sub ) + '</span>';
		}
		html += '</div>';
		return html;
	}

	/** Tone chip HTML. tone: success|warning|error|info|neutral. */
	function chip( tone, label ) {
		var tones = [ 'success', 'warning', 'error', 'info', 'neutral' ];
		if ( tones.indexOf( tone ) === -1 ) {
			tone = 'neutral';
		}
		return '<span class="ace-chip ace-chip-' + tone + '">' + esc( label ) + '</span>';
	}

	/* ------------------------------------------------------------------ */
	/* Poller + batch runner                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Repeating poller with one in-flight call and interval backoff.
	 * fn returns a Promise. opts: { interval, backoffAfterMs, maxInterval }.
	 */
	function makePoller( fn, opts ) {
		opts = opts || {};
		var base = opts.interval || 5000;
		var current = base;
		var timer = null;
		var running = false;
		var inflight = false;
		var startedAt = 0;

		function schedule() {
			if ( ! running ) {
				return;
			}
			timer = window.setTimeout( tick, current );
		}

		function tick() {
			if ( ! running || inflight ) {
				return;
			}
			inflight = true;
			var done = function () {
				inflight = false;
				if ( ! running ) {
					return;
				}
				if ( opts.backoffAfterMs && ( Date.now() - startedAt ) > opts.backoffAfterMs ) {
					current = Math.min( opts.maxInterval || current * 2, current * 2 );
				}
				schedule();
			};
			Promise.resolve().then( fn ).then( done, done );
		}

		return {
			start: function () {
				if ( running ) {
					return;
				}
				running = true;
				startedAt = Date.now();
				current = base;
				schedule();
			},
			stop: function () {
				running = false;
				if ( timer ) {
					window.clearTimeout( timer );
					timer = null;
				}
			}
		};
	}

	/**
	 * Chunked sequential batch runner. Splits `items` into `batchSize` chunks,
	 * awaits fn(chunk, processedSoFar) for each, reports onProgress(done, total).
	 * Resolves with the number processed.
	 */
	function runBatch( items, batchSize, fn, onProgress ) {
		var list = ( items || [] ).slice();
		var size = Math.max( 1, batchSize || 20 );
		var total = list.length;
		var done = 0;

		function step() {
			if ( ! list.length ) {
				return Promise.resolve( done );
			}
			var chunk = list.splice( 0, size );
			return Promise.resolve( fn( chunk, done ) ).then( function () {
				done += chunk.length;
				if ( onProgress ) {
					onProgress( done, total );
				}
				return step();
			} );
		}
		return step();
	}

	/* ------------------------------------------------------------------ */
	/* Optional-account notice (dismissed per user, never a standing nag)  */
	/* ------------------------------------------------------------------ */

	function wireConnectNotice() {
		var notice = document.getElementById( 'ace-connect-notice' );
		var btn = document.getElementById( 'ace-connect-notice-dismiss' );
		if ( ! notice || ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			notice.parentNode.removeChild( notice );
			post( 'dismiss_connect_notice', {} ).catch( function () {
				// Nothing to recover: it reappears on the next page load.
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* "Nothing is being measured" notice (dismissed per user)             */
	/* ------------------------------------------------------------------ */

	function wireTrafficNotice() {
		var notice = document.getElementById( 'ace-traffic-notice' );
		var btn = document.getElementById( 'ace-traffic-notice-dismiss' );
		if ( ! notice || ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			notice.parentNode.removeChild( notice );
			post( 'dismiss_traffic_notice', {} ).catch( function () {
				// Nothing to recover: it reappears on the next page load.
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard "Start here" card (dismissed per user)                    */
	/* ------------------------------------------------------------------ */

	function wireStartCard() {
		var card = document.getElementById( 'ace-start-card' );
		var btn = document.getElementById( 'ace-start-dismiss' );
		if ( ! card || ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			card.parentNode.removeChild( card );
			post( 'dismiss_start_card', {} ).catch( function () {
				// Nothing to recover: it reappears on the next page load.
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Connect form (rendered by page-connection.php)                      */
	/* ------------------------------------------------------------------ */

	function wireConnect() {
		var btn = document.getElementById( 'ace-connect-btn' );
		if ( ! btn ) {
			return;
		}
		var keyEl = document.getElementById( 'ace-api-key' );
		var errBox = document.getElementById( 'ace-connect-error' );

		// The message is an alert, and the key field says it is the field the
		// message is about, so a screen reader hears both.
		function showError( message ) {
			if ( errBox ) {
				errBox.textContent = message;
				errBox.style.display = 'block';
			}
			if ( keyEl ) {
				keyEl.setAttribute( 'aria-invalid', 'true' );
				if ( errBox ) {
					keyEl.setAttribute( 'aria-describedby', errBox.id );
				}
			}
		}

		function clearError() {
			if ( errBox ) {
				errBox.style.display = 'none';
			}
			if ( keyEl ) {
				keyEl.removeAttribute( 'aria-invalid' );
				keyEl.removeAttribute( 'aria-describedby' );
			}
		}

		var submit = function () {
			var baseEl = document.getElementById( 'ace-base-url' );
			var key = keyEl ? keyEl.value : '';
			if ( ! key.trim() ) {
				showError( t( 'connectError', 'Could not connect. Check your API key and try again.' ) );
				if ( keyEl ) {
					keyEl.focus();
				}
				return;
			}
			clearError();
			setBusy( btn, true, t( 'connecting', 'Connecting…' ) );
			post( 'connect', { api_key: key, base_url: baseEl ? baseEl.value : '' } )
				.then( function () {
					window.location.reload();
				} )
				.catch( function ( err ) {
					setBusy( btn, false );
					showError( ( err && err.message ) || t( 'connectError', 'Could not connect. Check your API key and try again.' ) );
				} );
		};
		btn.addEventListener( 'click', submit );
		if ( keyEl ) {
			keyEl.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					submit();
				}
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Help tooltips (any [data-help] element)                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Help tooltips open on hover, on keyboard focus and on a click or tap,
	 * stay open while the pointer is over the trigger or the tip itself, and
	 * close on Escape. Each trigger is described by a hidden copy of its text,
	 * so a screen reader reads the help with the button whether or not the
	 * tip is showing.
	 */
	var helpSeq = 0;

	function wireHelp() {
		var tip = document.getElementById( 'ace-help-tip' );
		if ( ! tip ) {
			tip = document.createElement( 'div' );
			tip.id = 'ace-help-tip';
			tip.className = 'ace-chart-tip ace-help-tip';
			tip.style.display = 'none';
			// The text is already each trigger's description; this copy is
			// only for the eye.
			tip.setAttribute( 'aria-hidden', 'true' );
			document.body.appendChild( tip );
		}
		var current = null;
		var pinned = false;
		var hideTimer = null;

		Array.prototype.slice.call( document.querySelectorAll( '[data-help]' ) ).forEach( function ( el ) {
			if ( el.getAttribute( 'aria-describedby' ) ) {
				return;
			}
			var desc = document.createElement( 'span' );
			desc.id = 'ace-help-desc-' + ( ++helpSeq );
			desc.hidden = true;
			desc.textContent = el.getAttribute( 'data-help' );
			el.parentNode.insertBefore( desc, el.nextSibling );
			el.setAttribute( 'aria-describedby', desc.id );
		} );

		function show( el ) {
			window.clearTimeout( hideTimer );
			current = el;
			tip.textContent = el.getAttribute( 'data-help' );
			tip.style.display = 'block';
			var r = el.getBoundingClientRect();
			tip.style.left = Math.max( 8, r.left ) + 'px';
			tip.style.top = ( r.bottom + 8 ) + 'px';
		}

		function hide() {
			window.clearTimeout( hideTimer );
			tip.style.display = 'none';
			current = null;
			pinned = false;
		}

		// A short grace period, so the pointer can travel from the trigger
		// onto the tip without it vanishing on the way.
		function hideSoon() {
			if ( pinned ) {
				return;
			}
			window.clearTimeout( hideTimer );
			hideTimer = window.setTimeout( hide, 200 );
		}

		function triggerOf( node ) {
			return node && node.closest ? node.closest( '[data-help]' ) : null;
		}

		document.addEventListener( 'mouseover', function ( e ) {
			var el = triggerOf( e.target );
			if ( el ) {
				show( el );
			} else if ( tip.contains( e.target ) ) {
				window.clearTimeout( hideTimer );
			}
		} );
		document.addEventListener( 'mouseout', function ( e ) {
			var from = triggerOf( e.target ) || ( tip.contains( e.target ) ? tip : null );
			if ( ! from ) {
				return;
			}
			var to = e.relatedTarget;
			if ( to && ( tip.contains( to ) || ( current && current.contains( to ) ) ) ) {
				return;
			}
			hideSoon();
		} );
		document.addEventListener( 'focusin', function ( e ) {
			var el = triggerOf( e.target );
			if ( el ) {
				show( el );
			} else if ( current && ! tip.contains( e.target ) ) {
				hide();
			}
		} );
		document.addEventListener( 'focusout', function ( e ) {
			if ( triggerOf( e.target ) && ! pinned ) {
				hide();
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			var el = triggerOf( e.target );
			if ( el ) {
				e.preventDefault();
				if ( current === el && pinned ) {
					hide();
				} else {
					show( el );
					pinned = true;
				}
				return;
			}
			if ( current && ! tip.contains( e.target ) ) {
				hide();
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( ( 'Escape' === e.key || 'Esc' === e.key ) && 'none' !== tip.style.display ) {
				hide();
			}
		} );
	}

	/**
	 * Placeholders the PHP renders (stat tiles, chart frames) mark their
	 * container busy until the screen script fills it.
	 */
	function wireSkeletons() {
		var seen = [];
		Array.prototype.slice.call( document.querySelectorAll( '.agencoen-wrap .ace-skeleton' ) ).forEach( function ( node ) {
			node.setAttribute( 'aria-hidden', 'true' );
			var box = node.parentNode;
			while ( box && box.classList && box.classList.contains( 'ace-skeleton-wrap' ) ) {
				box = box.parentNode;
			}
			if ( box && seen.indexOf( box ) === -1 ) {
				seen.push( box );
				watchBusy( box );
			}
		} );
	}

	// Read-only inputs (store id, webhook + feed URLs) select their full value
	// on click so they are easy to copy.
	function wireSelectOnClick() {
		document.addEventListener( 'click', function ( e ) {
			var input = e.target.closest && e.target.closest( 'input.ace-select-on-click' );
			if ( input && typeof input.select === 'function' ) {
				input.select();
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		wireConnect();
		wireConnectNotice();
		wireTrafficNotice();
		wireStartCard();
		wireHelp();
		wireSkeletons();
		wireSelectOnClick();
	} );

	/* ------------------------------------------------------------------ */
	/* Export                                                             */
	/* ------------------------------------------------------------------ */

	window.agencoen.core = {
		post: post,
		toast: toast,
		errorToast: errorToast,
		esc: esc,
		num: num,
		pct: pct,
		debounce: debounce,
		setBusy: setBusy,
		skeleton: skeleton,
		dialog: dialog,
		setProgress: setProgress,
		tabs: tabs,
		tabKeys: tabKeys,
		statTile: statTile,
		chip: chip,
		makePoller: makePoller,
		runBatch: runBatch,
		fmtDate: fmtDate,
		timeAgo: timeAgo
	};
} )();
