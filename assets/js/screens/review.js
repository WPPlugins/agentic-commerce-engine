/**
 * Rezolve Ai: Review screen (v4).
 *
 * Approval queue at catalog scale: pending/applied SegmentedTabs (hash-synced
 * via core.tabs), search, paged lists with lazy before/after diffs and
 * per-content-type previews, approve/dismiss/revert row actions, and a
 * server-driven approve-all batch with progress.
 */
( function () {
	'use strict';

	var ns = window.agencoen || {};
	var core = ns.core;
	if ( ! core ) {
		return;
	}

	var cfg = window.agencoenConfig || {};
	var i18n = cfg.i18n || {};

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}
	function fmt( template ) {
		var out = template;
		for ( var i = 1; i < arguments.length; i++ ) {
			out = out.replace( '%' + i + '$s', arguments[ i ] ).replace( '%s', arguments[ i ] );
		}
		return out;
	}
	function stripTags( s ) {
		return String( s == null ? '' : s ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
	}

	/* ------------------------------------------------------------------ */
	/* Diff + per-type content rendering                                   */
	/* ------------------------------------------------------------------ */

	function renderDiffHtml( diff ) {
		return '<div class="ace-diffs">' + diff.map( function ( row ) {
			var clean = ( 'description' === row.key || 'short_description' === row.key );
			var before = row.before ? core.esc( clean ? stripTags( row.before ) : row.before ) : '<em class="ace-muted">' + core.esc( t( 'emptyValue', '(empty)' ) ) + '</em>';
			var after = row.after ? core.esc( clean ? stripTags( row.after ) : row.after ) : '';
			return '<div class="ace-diff ' + ( row.changed ? 'is-changed' : 'is-same' ) + '">' +
				'<div class="ace-diff-label">' + core.esc( row.label ) + ( row.changed ? '' : ' <span class="ace-diff-tag">' + core.esc( t( 'noChange', 'no change' ) ) + '</span>' ) + '</div>' +
				'<div class="ace-diff-cols"><div class="ace-diff-col ace-diff-before"><div class="ace-diff-cap">' + core.esc( t( 'current', 'Current' ) ) + '</div><div class="ace-diff-text">' + before + '</div></div>' +
				'<div class="ace-diff-col ace-diff-after"><div class="ace-diff-cap">' + core.esc( t( 'aiSuggestion', 'AI suggestion' ) ) + '</div><div class="ace-diff-text">' + after + '</div></div></div></div>';
		} ).join( '' ) + '</div>';
	}

	function renderContentHtml( content ) {
		if ( ! content || 'object' !== typeof content ) {
			return '';
		}
		var html = '';
		var irlRaw = content[ 'irl-scenarios' ];
		if ( irlRaw ) {
			var irl = irlRaw && irlRaw.scenarios ? irlRaw.scenarios : irlRaw;
			if ( Array.isArray( irl ) && irl.length ) {
				html += '<div class="ace-ct-block"><div class="ace-ct-title">' + core.esc( fmt( t( 'irlTitle', 'IRL scenarios (%s)' ), irl.length ) ) + '</div><div class="ace-ct-irl">' +
					irl.slice( 0, 6 ).map( function ( s ) {
						return '<div class="ace-ct-card"><strong>' + core.esc( ( s && s.title ) || '' ) + '</strong><div>' + core.esc( stripTags( ( s && s.description ) || '' ) ) + '</div></div>';
					} ).join( '' ) + '</div></div>';
			}
		}
		var linkRaw = content[ 'internal-links' ];
		if ( linkRaw ) {
			var links = linkRaw && linkRaw.links ? linkRaw.links : linkRaw;
			if ( Array.isArray( links ) && links.length ) {
				html += '<div class="ace-ct-block"><div class="ace-ct-title">' + core.esc( t( 'internalLinks', 'Internal links' ) ) + '</div><ul class="ace-ct-links">' +
					links.map( function ( l ) {
						return '<li>' + core.esc( ( l && ( l.anchorText || l.targetUrl ) ) || '' ) + '</li>';
					} ).join( '' ) + '</ul></div>';
			}
		}
		var sch = content[ 'jsonld-schema' ] || content[ 'json-ld-schema' ];
		if ( sch ) {
			var schStr = 'string' === typeof sch ? sch : JSON.stringify( sch, null, 2 );
			html += '<div class="ace-ct-block"><div class="ace-ct-title">' + core.esc( t( 'jsonldTitle', 'JSON-LD schema' ) ) + '</div><pre class="ace-ct-json">' + core.esc( schStr.slice( 0, 1400 ) ) + '</pre></div>';
		}
		[ 'google-shopping', 'microsoft-shopping', 'agentic-commerce' ].forEach( function ( chKey ) {
			if ( content[ chKey ] ) {
				html += '<div class="ace-ct-block"><div class="ace-ct-title">' + core.esc( fmt( t( 'feedTitle', '%s feed' ), chKey ) ) + '</div><pre class="ace-ct-json">' + core.esc( JSON.stringify( content[ chKey ], null, 2 ).slice( 0, 800 ) ) + '</pre></div>';
			}
		} );
		return html;
	}

	/**
	 * Highlights, Q&A, attributes and alt text, from the server's `extras`, so
	 * the preview matches what Approve will write (same matching, same
	 * "already on the product" check). Every value goes through core.esc.
	 */
	function renderExtrasHtml( extras ) {
		if ( ! extras || 'object' !== typeof extras ) {
			return '';
		}
		var html = '';
		var block = function ( title, body ) {
			return '<div class="ace-ct-block"><div class="ace-ct-title">' + core.esc( title ) + '</div>' + body + '</div>';
		};
		var chip = function ( text, tone ) {
			return '<span class="ace-ct-chip ace-ct-chip--' + tone + '">' + core.esc( text ) + '</span>';
		};

		if ( Array.isArray( extras.highlights ) && extras.highlights.length ) {
			html += block( t( 'highlightsTitle', 'Highlights' ), '<ul class="ace-ct-links">' + extras.highlights.map( function ( h ) {
				return '<li>' + core.esc( h ) + '</li>';
			} ).join( '' ) + '</ul>' );
		}

		if ( Array.isArray( extras.qa ) && extras.qa.length ) {
			html += block( t( 'qaTitle', 'Product Q&A' ), '<dl class="ace-ct-qa">' + extras.qa.map( function ( pair ) {
				return '<dt>' + core.esc( pair.question ) + '</dt><dd>' + core.esc( pair.answer ) + '</dd>';
			} ).join( '' ) + '</dl>' );
		}

		if ( Array.isArray( extras.attributes ) && extras.attributes.length ) {
			html += block( t( 'attributesTitle', 'Product attributes' ), '<table class="ace-ct-table"><tbody>' + extras.attributes.map( function ( a ) {
				var action = 'exists' === a.action ? chip( t( 'attrExists', 'Already on the product, kept' ), 'muted' ) : chip( t( 'attrAdd', 'Will be added' ), 'ok' );
				return '<tr><th scope="row">' + core.esc( a.name ) + '</th><td>' + core.esc( ( a.values || [] ).join( ', ' ) ) + '</td><td>' + action + '</td></tr>';
			} ).join( '' ) + '</tbody></table>' );
		}

		if ( Array.isArray( extras.imageAlt ) && extras.imageAlt.length ) {
			var labels = {
				write: [ t( 'altWrite', 'Will be replaced' ), 'ok' ],
				same: [ t( 'altSame', 'Already matches' ), 'muted' ],
				unmatched: [ t( 'altUnmatched', 'Image no longer on this product, skipped' ), 'warn' ],
			};
			html += block( t( 'imageAltTitle', 'Image alt text' ), '<table class="ace-ct-table ace-ct-alt"><thead><tr><th></th><th>' + core.esc( t( 'currentAlt', 'Current alt text' ) ) + '</th><th>' + core.esc( t( 'proposedAlt', 'Proposed alt text' ) ) + '</th><th></th></tr></thead><tbody>' + extras.imageAlt.map( function ( row ) {
				var label = labels[ row.action ] || labels.write;
				var thumb = row.thumb ? '<img src="' + core.esc( row.thumb ).replace( /"/g, '&quot;' ) + '" alt="" width="48" height="48" loading="lazy">' : '';
				return '<tr><td>' + thumb + '</td><td>' + ( row.current ? core.esc( row.current ) : '<em class="ace-muted">' + core.esc( t( 'emptyValue', '(empty)' ) ) + '</em>' ) + '</td><td>' + core.esc( row.proposed ) + '</td><td>' + chip( label[ 0 ], label[ 1 ] ) + '</td></tr>';
			} ).join( '' ) + '</tbody></table>' );
		}

		return html;
	}

	/* ------------------------------------------------------------------ */
	/* Approve-all batch                                                   */
	/* ------------------------------------------------------------------ */

	function drainServer( action, params, onDone ) {
		var box = $( '#ace-batch' );
		var fill = $( '#ace-batch-fill' );
		var text = $( '#ace-batch-text' );
		var done = 0;
		if ( box ) {
			box.hidden = false;
		}
		function step() {
			return core.post( action, params ).then( function ( d ) {
				done += ( d.processed || 0 );
				var total = done + ( d.remaining || 0 );
				var pctDone = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;
				if ( fill ) {
					fill.style.width = pctDone + '%';
				}
				if ( text ) {
					text.textContent = fmt( t( 'processing', 'Processing %1$s of %2$s…' ), core.num( done ), core.num( total ) );
				}
				if ( d.done || 0 === d.processed ) {
					if ( text ) {
						text.textContent = t( 'done', 'Done.' );
					}
					window.setTimeout( function () {
						if ( box ) {
							box.hidden = true;
						}
						if ( fill ) {
							fill.style.width = '0%';
						}
					}, 1400 );
					if ( onDone ) {
						onDone( done );
					}
					return null;
				}
				return step();
			} ).catch( function ( err ) {
				if ( box ) {
					box.hidden = true;
				}
				core.errorToast( err );
			} );
		}
		return step();
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		var root = $( '#ace-review' );
		if ( ! root ) {
			return; // The WooCommerce dependency state is showing instead.
		}

		var pending = { page: 1, search: '' };
		var applied = { page: 1, loaded: false };
		var list = $( '#ace-review-list' );
		var appliedList = $( '#ace-applied-list' );

		function itemHtml( item ) {
			var chips = ( item.fields || [] ).map( function ( f ) {
				return '<span class="ace-fieldchip">' + core.esc( f ) + '</span>';
			} ).join( '' );
			var score = item.score ? core.chip( 'info', fmt( t( 'scoreLabel', 'Score %s' ), item.score ) ) : '';
			return '<div class="ace-rev-item" data-id="' + item.id + '">' +
				'<div class="ace-rev-head">' +
				'<button type="button" class="ace-rev-toggle" aria-label="' + core.esc( t( 'expand', 'Expand' ) ) + '"><span class="ace-caret">&rsaquo;</span></button>' +
				'<div class="ace-rev-main"><div class="ace-rev-title">' + core.esc( item.name ) + ' ' + score + '</div><div class="ace-rev-chips">' + chips + '</div></div>' +
				'<div class="ace-rev-actions">' +
				( item.editLink ? '<a class="ace-link-sm" href="' + encodeURI( item.editLink ) + '" target="_blank" rel="noopener">' + core.esc( t( 'edit', 'Edit' ) ) + '</a>' : '' ) +
				'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-rev-dismiss">' + core.esc( t( 'dismiss', 'Dismiss' ) ) + '</button>' +
				'<button type="button" class="ace-btn ace-btn-primary ace-btn-sm ace-rev-approve">' + core.esc( t( 'approve', 'Approve' ) ) + '</button>' +
				'</div></div><div class="ace-rev-body" hidden></div></div>';
		}

		// The two tab counts are rendered once by PHP and then owned by this
		// screen. Only the pending one was ever updated, so approving anything
		// left "Applied" reading whatever it said when the page loaded --
		// approve every suggestion and the screen showed "Pending 0 / Applied
		// 0" next to an empty list, which reads as the work having been thrown
		// away. It had not been; a reload showed the real number.
		//
		// Rows are removed from the DOM rather than re-fetched, so the counts
		// are adjusted the same way. A full reload of either list would reset
		// paging and throw away a merchant's "Load more" position.
		function bumpCount( id, delta ) {
			var el = $( id );
			if ( ! el ) {
				return;
			}
			var n = parseInt( String( el.textContent ).replace( /[^0-9]/g, '' ), 10 );
			if ( isNaN( n ) ) {
				return;
			}
			el.textContent = core.num( Math.max( 0, n + delta ) );
		}

		function loadPending( reset ) {
			if ( reset ) {
				pending.page = 1;
				core.skeleton( list, 'text' );
			}
			core.post( 'review_list', { page: pending.page, search: pending.search } ).then( function ( d ) {
				var html = d.items.map( itemHtml ).join( '' );
				if ( 1 === pending.page ) {
					list.innerHTML = d.items.length ? html : '<div class="ace-empty"><p>' + core.esc( t( 'noPending', 'Nothing to review right now.' ) ) + '</p></div>';
				} else {
					list.insertAdjacentHTML( 'beforeend', html );
				}
				$( '#ace-review-more' ).hidden = d.page >= d.totalPages;
				var count = $( '#ace-count-pending' );
				if ( count ) {
					count.textContent = core.num( d.total );
				}
			} ).catch( core.errorToast );
		}

		function loadApplied( reset ) {
			if ( reset ) {
				applied.page = 1;
				core.skeleton( appliedList, 'text' );
			}
			core.post( 'products', { page: applied.page, status: 'applied' } ).then( function ( d ) {
				var html = d.rows.map( function ( row ) {
					return '<div class="ace-rev-item ace-rev-compact" data-id="' + row.id + '"><div class="ace-rev-head">' +
						'<div class="ace-rev-main"><div class="ace-rev-title">' + core.esc( row.name ) + ' ' + core.chip( 'success', t( 'statusApplied', 'Applied' ) ) + '</div></div>' +
						'<div class="ace-rev-actions"><button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-rev-revert">' + core.esc( t( 'revertToOriginal', 'Revert to original' ) ) + '</button></div></div></div>';
				} ).join( '' );
				if ( 1 === applied.page ) {
					appliedList.innerHTML = d.rows.length ? html : '<div class="ace-empty"><p>' + core.esc( t( 'noApplied', 'No applied products yet.' ) ) + '</p></div>';
				} else {
					appliedList.insertAdjacentHTML( 'beforeend', html );
				}
				$( '#ace-applied-more' ).hidden = d.page >= d.totalPages;
				// Authoritative whenever the panel is actually fetched, so any
				// drift the per-row arithmetic could introduce is corrected the
				// moment the merchant opens the tab.
				var appliedCount = $( '#ace-count-applied' );
				if ( appliedCount && 'undefined' !== typeof d.total ) {
					appliedCount.textContent = core.num( d.total );
				}
				applied.loaded = true;
			} ).catch( core.errorToast );
		}

		// SegmentedTabs (hash-synced): lazily load the applied panel.
		core.tabs( root, function ( id ) {
			if ( 'applied' === id && ! applied.loaded ) {
				loadApplied( true );
			}
		} );
		// If the hash restored the applied tab, load it now.
		var appliedPanel = root.querySelector( '[data-panel="applied"]' );
		if ( appliedPanel && ! appliedPanel.hidden && ! applied.loaded ) {
			loadApplied( true );
		}

		var search = $( '#ace-review-search' );
		if ( search ) {
			search.addEventListener( 'input', core.debounce( function () {
				pending.search = search.value.trim();
				loadPending( true );
			}, 350 ) );
		}

		$( '#ace-review-more' ).addEventListener( 'click', function () {
			pending.page++;
			loadPending( false );
		} );
		$( '#ace-applied-more' ).addEventListener( 'click', function () {
			applied.page++;
			loadApplied( false );
		} );

		var approveAll = $( '#ace-approve-all' );
		if ( approveAll ) {
			approveAll.addEventListener( 'click', function () {
				if ( window.confirm( t( 'confirmApproveAll', 'Apply every pending suggestion to your live products?' ) ) ) {
					drainServer( 'approve_batch', { search: pending.search }, function () {
						loadPending( true );
						// The whole queue just moved to applied. Re-fetch rather
						// than count it: a batch can be interrupted, so the
						// server's number is the only one worth showing.
						applied.loaded = false;
						loadApplied( true );
					} );
				}
			} );
		}

		// Delegated row actions.
		root.addEventListener( 'click', function ( e ) {
			var item = e.target.closest ? e.target.closest( '.ace-rev-item' ) : null;
			if ( ! item ) {
				return;
			}
			var id = item.getAttribute( 'data-id' );

			if ( e.target.closest( '.ace-rev-toggle' ) ) {
				var body = $( '.ace-rev-body', item );
				item.classList.toggle( 'is-open' );
				if ( body.hidden ) {
					body.hidden = false;
					if ( ! body.getAttribute( 'data-loaded' ) ) {
						core.skeleton( body, 'text' );
						core.post( 'review_diff', { product_id: id } ).then( function ( d ) {
							body.innerHTML = renderDiffHtml( d.diff || [] ) + renderContentHtml( d.content || {} ) + renderExtrasHtml( d.extras || {} );
							body.setAttribute( 'data-loaded', '1' );
						} ).catch( core.errorToast );
					}
				} else {
					body.hidden = true;
				}
				return;
			}

			var btn = e.target.closest( 'button' );
			if ( ! btn ) {
				return;
			}
			if ( btn.classList.contains( 'ace-rev-approve' ) ) {
				core.setBusy( btn, true, t( 'applying', 'Applying…' ) );
				core.post( 'approve', { product_id: id } ).then( function () {
					core.toast( t( 'applied', 'Applied to your product.' ), 'success' );
					item.parentNode.removeChild( item );
					bumpCount( '#ace-count-pending', -1 );
					bumpCount( '#ace-count-applied', 1 );
				} ).catch( function ( err ) {
					core.setBusy( btn, false );
					core.errorToast( err );
				} );
			} else if ( btn.classList.contains( 'ace-rev-dismiss' ) ) {
				core.setBusy( btn, true );
				core.post( 'dismiss', { product_id: id } ).then( function () {
					core.toast( t( 'dismissed', 'Dismissed.' ), 'info' );
					item.parentNode.removeChild( item );
					// Dismissed leaves the queue without becoming applied.
					bumpCount( '#ace-count-pending', -1 );
				} ).catch( function ( err ) {
					core.setBusy( btn, false );
					core.errorToast( err );
				} );
			} else if ( btn.classList.contains( 'ace-rev-revert' ) ) {
				core.setBusy( btn, true );
				core.post( 'revert', { product_id: id } ).then( function () {
					core.toast( t( 'reverted', 'Reverted to the original.' ), 'success' );
					item.parentNode.removeChild( item );
					// Reverting is the only row action on the applied list.
					bumpCount( '#ace-count-applied', -1 );
				} ).catch( function ( err ) {
					core.setBusy( btn, false );
					core.errorToast( err );
				} );
			}
		} );

		loadPending( true );
	}

	document.addEventListener( 'DOMContentLoaded', init );
} )();
