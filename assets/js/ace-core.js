/**
 * Rezolve Ai: shared admin runtime (v4).
 *
 * Defines window.agencoen.core: the ajax bridge, toasts, formatting helpers,
 * skeletons, SegmentedTabs wiring, stat tiles, chips, a backoff poller, and
 * the chunked batch runner. Every screen script (assets/js/screens/*.js)
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

	function toast( message, type, html ) {
		var el = toastEl();
		el.className = 'ace-toast ace-toast-' + ( type || 'info' );
		if ( html ) {
			el.innerHTML = message;
		} else {
			el.textContent = message;
		}
		el.style.display = 'block';
		window.clearTimeout( el._t );
		el._t = window.setTimeout( function () {
			el.style.display = 'none';
		}, 6000 );
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
			button.disabled = true;
		} else {
			if ( button._label ) {
				button.textContent = button._label;
			}
			button.classList.remove( 'is-busy' );
			button.disabled = false;
		}
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
		el.innerHTML = html;
	}

	/**
	 * Wire a SegmentedTabs bar. `container` is either the .ace-tabs element or
	 * an ancestor containing one. Panels are [data-panel="<tab>"] elements in
	 * the surrounding screen. Syncs location.hash and restores from it.
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
			item.setAttribute( 'role', 'tab' );
			item.addEventListener( 'click', function () {
				activate( item.getAttribute( 'data-tab' ) );
			} );
		} );
		bar.setAttribute( 'role', 'tablist' );

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
		var submit = function () {
			var keyEl = document.getElementById( 'ace-api-key' );
			var baseEl = document.getElementById( 'ace-base-url' );
			var errBox = document.getElementById( 'ace-connect-error' );
			var key = keyEl ? keyEl.value : '';
			if ( ! key.trim() ) {
				if ( errBox ) {
					errBox.textContent = t( 'connectError', 'Could not connect. Check your API key and try again.' );
					errBox.style.display = 'block';
				}
				return;
			}
			if ( errBox ) {
				errBox.style.display = 'none';
			}
			setBusy( btn, true, t( 'connecting', 'Connecting…' ) );
			post( 'connect', { api_key: key, base_url: baseEl ? baseEl.value : '' } )
				.then( function () {
					window.location.reload();
				} )
				.catch( function ( err ) {
					setBusy( btn, false );
					if ( errBox ) {
						errBox.textContent = ( err && err.message ) || t( 'connectError', 'Could not connect. Check your API key and try again.' );
						errBox.style.display = 'block';
					}
				} );
		};
		btn.addEventListener( 'click', submit );
		var keyInput = document.getElementById( 'ace-api-key' );
		if ( keyInput ) {
			keyInput.addEventListener( 'keydown', function ( e ) {
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

	function wireHelp() {
		var tip = document.getElementById( 'ace-help-tip' );
		if ( ! tip ) {
			tip = document.createElement( 'div' );
			tip.id = 'ace-help-tip';
			tip.className = 'ace-chart-tip ace-help-tip';
			tip.style.display = 'none';
			document.body.appendChild( tip );
		}
		document.addEventListener( 'mouseover', function ( e ) {
			var el = e.target.closest ? e.target.closest( '[data-help]' ) : null;
			if ( ! el ) {
				return;
			}
			tip.textContent = el.getAttribute( 'data-help' );
			tip.style.display = 'block';
			var r = el.getBoundingClientRect();
			tip.style.left = Math.max( 8, r.left ) + 'px';
			tip.style.top = ( r.bottom + 8 ) + 'px';
		} );
		document.addEventListener( 'mouseout', function ( e ) {
			if ( e.target.closest && e.target.closest( '[data-help]' ) ) {
				tip.style.display = 'none';
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
		tabs: tabs,
		statTile: statTile,
		chip: chip,
		makePoller: makePoller,
		runBatch: runBatch,
		fmtDate: fmtDate,
		timeAgo: timeAgo
	};
} )();
