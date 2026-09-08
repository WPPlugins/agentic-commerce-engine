/**
 * Rezolve Ai: Products screen (v4).
 *
 * Paged, searchable, filterable product list with bulk enrichment: status
 * filter tabs, category + sort scoping, row selection with select-all-matching,
 * the content-type picker modal, chunked enrichment (runBatch for explicit
 * selections, a server-driven loop for all-matching), and a backoff poller
 * that live-updates queued rows.
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
	function $all( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
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

	function statusChip( status ) {
		var map = {
			queued: [ 'info', t( 'statusQueued', 'Queued' ) ],
			enriched: [ 'warning', t( 'statusReady', 'Ready to review' ) ],
			applied: [ 'success', t( 'statusApplied', 'Applied' ) ],
			dismissed: [ 'neutral', t( 'statusDismissed', 'Dismissed' ) ],
			failed: [ 'error', t( 'statusFailed', 'Failed' ) ]
		};
		var entry = map[ status ] || [ 'neutral', t( 'statusNone', 'Not enriched' ) ];
		return core.chip( entry[ 0 ], entry[ 1 ] );
	}

	/* ------------------------------------------------------------------ */
	/* Content-type picker                                                 */
	/* ------------------------------------------------------------------ */

	function openPicker( scopeLabel, onConfirm ) {
		var modal = $( '#ace-enrich-modal' );
		var list = $( '#ace-types-list' );
		if ( ! modal || ! list ) {
			onConfirm( cfg.selectedTypes || [] );
			return;
		}
		var types = cfg.contentTypes || [];
		var selected = {};
		( cfg.selectedTypes || [] ).forEach( function ( k ) {
			selected[ k ] = true;
		} );
		var scopeEl = $( '#ace-modal-scope' );
		if ( scopeEl ) {
			scopeEl.textContent = scopeLabel || '';
		}

		function group( g, title ) {
			var items = types.filter( function ( type ) {
				return type.group === g;
			} );
			if ( ! items.length ) {
				return '';
			}
			return '<div class="ace-types-group"><div class="ace-types-grouptitle">' + core.esc( title ) + '</div>' + items.map( function ( type ) {
				var rec = type.recommended ? ' <span class="ace-type-rec">' + core.esc( t( 'recommended', 'Recommended' ) ) + '</span>' : '';
				return '<label class="ace-type-item"><input type="checkbox" class="ace-type-cb" value="' + core.esc( type.key ) + '" data-requires="' + ( type.requires || [] ).join( ',' ) + '"' + ( selected[ type.key ] ? ' checked' : '' ) + '>' +
					'<span class="ace-type-main"><span class="ace-type-label">' + core.esc( type.label ) + rec + '</span>' +
					'<span class="ace-type-desc">' + core.esc( type.description ) + '</span></span></label>';
			} ).join( '' ) + '</div>';
		}

		list.innerHTML = group( 'onpage', t( 'onPage', 'On the product page' ) ) + group( 'feed', t( 'feeds', 'Channel feeds' ) );
		var errBox = $( '#ace-modal-error' );
		if ( errBox ) {
			errBox.style.display = 'none';
		}
		modal.hidden = false;

		$all( '.ace-type-cb', list ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				if ( cb.checked ) {
					( cb.getAttribute( 'data-requires' ) || '' ).split( ',' ).filter( Boolean ).forEach( function ( dep ) {
						var depEl = list.querySelector( '.ace-type-cb[value="' + dep + '"]' );
						if ( depEl ) {
							depEl.checked = true;
						}
					} );
				}
			} );
		} );

		function close() {
			modal.hidden = true;
		}
		$( '#ace-modal-cancel' ).onclick = close;
		$( '#ace-modal-backdrop' ).onclick = close;
		$( '#ace-modal-confirm' ).onclick = function () {
			var chosen = $all( '.ace-type-cb:checked', list ).map( function ( cb ) {
				return cb.value;
			} );
			if ( ! chosen.length ) {
				if ( errBox ) {
					errBox.textContent = t( 'pickAtLeastOne', 'Pick at least one content type.' );
					errBox.style.display = 'block';
				}
				return;
			}
			cfg.selectedTypes = chosen;
			close();
			onConfirm( chosen );
		};
	}

	/* ------------------------------------------------------------------ */
	/* Batch progress helpers                                              */
	/* ------------------------------------------------------------------ */

	function batchUi() {
		return {
			box: $( '#ace-batch' ),
			fill: $( '#ace-batch-fill' ),
			text: $( '#ace-batch-text' )
		};
	}

	function batchProgress( ui, done, total ) {
		var pctDone = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;
		if ( ui.fill ) {
			ui.fill.style.width = pctDone + '%';
		}
		if ( ui.text ) {
			ui.text.textContent = fmt( t( 'processing', 'Processing %1$s of %2$s…' ), core.num( done ), core.num( total ) );
		}
	}

	function batchFinish( ui ) {
		if ( ui.text ) {
			ui.text.textContent = t( 'done', 'Done.' );
		}
		window.setTimeout( function () {
			if ( ui.box ) {
				ui.box.hidden = true;
			}
			if ( ui.fill ) {
				ui.fill.style.width = '0%';
			}
		}, 1400 );
	}

	/** Repeats a server-side batch action until it reports done. */
	function drainServer( action, params, onDone ) {
		var ui = batchUi();
		var done = 0;
		if ( ui.box ) {
			ui.box.hidden = false;
		}
		function step() {
			return core.post( action, params ).then( function ( d ) {
				done += ( d.processed || 0 );
				batchProgress( ui, done, done + ( d.remaining || 0 ) );
				if ( d.done || 0 === d.processed ) {
					batchFinish( ui );
					if ( onDone ) {
						onDone( done );
					}
					return null;
				}
				return step();
			} ).catch( function ( err ) {
				if ( ui.box ) {
					ui.box.hidden = true;
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
		var root = $( '#ace-products' );
		if ( ! root ) {
			return; // The WooCommerce dependency state is showing instead.
		}

		var state = { page: 1, search: '', status: 'all', category: '', orderby: 'recent', totalPages: 1, total: 0 };
		var selectAllMatching = false;
		var tbody = $( '#ace-products-tbody' );

		// Deep link from the dashboard donut (#status=key).
		var hash = ( window.location.hash || '' ).match( /status=([a-z_]+)/ );
		if ( hash ) {
			state.status = hash[ 1 ];
		}

		function selectedIds() {
			return $all( 'input.ace-row-check:checked', tbody ).map( function ( cb ) {
				return cb.getAttribute( 'data-id' );
			} );
		}

		function updateSelbar() {
			var bar = $( '#ace-selbar' );
			if ( ! bar ) {
				return;
			}
			var n = selectedIds().length;
			if ( 0 === n && ! selectAllMatching ) {
				bar.hidden = true;
				return;
			}
			bar.hidden = false;
			$( '#ace-sel-count' ).textContent = selectAllMatching
				? fmt( t( 'selected', '%s selected' ), '~' + core.num( state.total ) )
				: fmt( t( 'selected', '%s selected' ), core.num( n ) );
			var link = $( '#ace-select-all-matching' );
			if ( link ) {
				link.textContent = selectAllMatching
					? t( 'clearAllMatching', 'Keep only the checked rows' )
					: fmt( t( 'selectAllMatching', 'Select all %s matching' ), core.num( state.total ) );
			}
		}

		function updateTabs( counts, total ) {
			$all( '.ace-tab-count[data-count]', root ).forEach( function ( span ) {
				var key = span.getAttribute( 'data-count' );
				span.textContent = 'all' === key ? core.num( total ) : core.num( counts[ key ] || 0 );
			} );
			$all( '#ace-status-tabs .ace-tab', root ).forEach( function ( tab ) {
				tab.classList.toggle( 'is-active', tab.getAttribute( 'data-status' ) === state.status );
			} );
		}

		function rowHtml( row ) {
			return '<tr data-id="' + row.id + '" data-status="' + core.esc( row.status ) + '">' +
				'<td class="ace-col-check"><input type="checkbox" class="ace-row-check" data-id="' + row.id + '"></td>' +
				'<td class="ace-col-img"><img class="ace-thumb" src="' + encodeURI( row.image || '' ) + '" alt="" loading="lazy"></td>' +
				'<td class="ace-prod-name">' + core.esc( row.name ) + '</td>' +
				'<td>' + core.esc( row.sku || '' ) + '</td>' +
				'<td class="ace-status-cell">' + statusChip( row.status ) + '</td>' +
				'<td class="ace-col-score">' + ( row.score ? core.esc( row.score ) : '' ) + '</td></tr>';
		}

		function render( data ) {
			state.page = data.page;
			state.totalPages = data.totalPages || 1;
			state.total = data.total || 0;
			tbody.innerHTML = data.rows.length
				? data.rows.map( rowHtml ).join( '' )
				: '<tr><td colspan="6" class="ace-loading">' + core.esc( t( 'noResults', 'No products match.' ) ) + '</td></tr>';
			$( '#ace-page-info' ).textContent = fmt( t( 'pageOf', 'Page %1$s of %2$s' ), state.page, state.totalPages ) + ' (' + core.num( state.total ) + ')';
			$( '#ace-prev-page' ).disabled = state.page <= 1;
			$( '#ace-next-page' ).disabled = state.page >= state.totalPages;
			var selAll = $( '#ace-select-all' );
			if ( selAll ) {
				selAll.checked = false;
			}
			updateTabs( data.counts || {}, state.total );
			$all( 'input.ace-row-check', tbody ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					selectAllMatching = false;
					updateSelbar();
				} );
			} );
			updateSelbar();
			ensurePolling();
		}

		function load() {
			tbody.innerHTML = '<tr><td colspan="6"><div class="ace-skeleton ace-skeleton-row"></div><div class="ace-skeleton ace-skeleton-row"></div><div class="ace-skeleton ace-skeleton-row"></div></td></tr>';
			core.post( 'products', {
				page: state.page,
				search: state.search,
				status: state.status,
				category: state.category,
				orderby: state.orderby
			} ).then( render ).catch( core.errorToast );
		}

		/* Live status updates for queued rows (backoff after a minute). */
		function queuedIds() {
			return $all( 'tr[data-status="queued"]', tbody ).map( function ( tr ) {
				return tr.getAttribute( 'data-id' );
			} );
		}

		var poller = core.makePoller( function () {
			var ids = queuedIds();
			if ( ! ids.length ) {
				poller.stop();
				return Promise.resolve();
			}
			return core.post( 'poll_status', { ids: ids } ).then( function ( d ) {
				( d.products || [] ).forEach( function ( p ) {
					var tr = $( 'tr[data-id="' + p.id + '"]', tbody );
					if ( ! tr ) {
						return;
					}
					tr.setAttribute( 'data-status', p.status );
					var cell = $( '.ace-status-cell', tr );
					if ( cell ) {
						cell.innerHTML = statusChip( p.status );
					}
					if ( p.score ) {
						var scoreCell = $( '.ace-col-score', tr );
						if ( scoreCell ) {
							scoreCell.textContent = p.score;
						}
					}
				} );
				if ( d.counts ) {
					updateTabs( d.counts, state.total );
				}
			} ).catch( function () {
				/* transient poll error; the next tick retries */
			} );
		}, { interval: cfg.pollInterval || 5000, backoffAfterMs: 90000, maxInterval: 30000 } );

		function ensurePolling() {
			if ( queuedIds().length ) {
				poller.start();
			} else {
				poller.stop();
			}
		}

		/* Wires */
		var search = $( '#ace-search' );
		if ( search ) {
			search.addEventListener( 'input', core.debounce( function () {
				state.search = search.value.trim();
				state.page = 1;
				selectAllMatching = false;
				load();
			}, 350 ) );
		}
		var sort = $( '#ace-sort' );
		if ( sort ) {
			sort.addEventListener( 'change', function () {
				state.orderby = sort.value;
				state.page = 1;
				load();
			} );
		}
		var category = $( '#ace-category' );
		if ( category ) {
			category.addEventListener( 'change', function () {
				state.category = category.value;
				state.page = 1;
				selectAllMatching = false;
				load();
			} );
		}
		$all( '#ace-status-tabs .ace-tab', root ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				state.status = tab.getAttribute( 'data-status' );
				state.page = 1;
				selectAllMatching = false;
				load();
			} );
		} );
		var selAll = $( '#ace-select-all' );
		if ( selAll ) {
			selAll.addEventListener( 'change', function () {
				$all( 'input.ace-row-check', tbody ).forEach( function ( cb ) {
					cb.checked = selAll.checked;
				} );
				selectAllMatching = false;
				updateSelbar();
			} );
		}
		$( '#ace-prev-page' ).addEventListener( 'click', function () {
			if ( state.page > 1 ) {
				state.page--;
				load();
			}
		} );
		$( '#ace-next-page' ).addEventListener( 'click', function () {
			if ( state.page < state.totalPages ) {
				state.page++;
				load();
			}
		} );

		var selMatch = $( '#ace-select-all-matching' );
		if ( selMatch ) {
			selMatch.addEventListener( 'click', function () {
				selectAllMatching = ! selectAllMatching;
				updateSelbar();
			} );
		}
		var clearSel = $( '#ace-clear-sel' );
		if ( clearSel ) {
			clearSel.addEventListener( 'click', function () {
				selectAllMatching = false;
				$all( 'input.ace-row-check', tbody ).forEach( function ( cb ) {
					cb.checked = false;
				} );
				updateSelbar();
			} );
		}

		var enrichSel = $( '#ace-enrich-selected' );
		if ( enrichSel ) {
			enrichSel.addEventListener( 'click', function () {
				if ( selectAllMatching ) {
					openPicker( t( 'chooseTypes', 'Choose what to enrich' ), function ( types ) {
						drainServer( 'enrich_batch', {
							search: state.search,
							category: state.category,
							scope_status: state.status,
							content_types: types
						}, function () {
							selectAllMatching = false;
							updateSelbar();
							load();
						} );
					} );
					return;
				}
				var ids = selectedIds();
				if ( ! ids.length ) {
					return;
				}
				openPicker( fmt( t( 'selected', '%s selected' ), core.num( ids.length ) ), function ( types ) {
					var ui = batchUi();
					if ( ui.box ) {
						ui.box.hidden = false;
					}
					core.setBusy( enrichSel, true, t( 'enriching', 'Sending to AI…' ) );
					core.runBatch( ids, 20, function ( chunk ) {
						return core.post( 'enrich', { ids: chunk, content_types: types } );
					}, function ( done, total ) {
						batchProgress( ui, done, total );
					} ).then( function () {
						batchFinish( ui );
						core.toast( t( 'enrichQueued', 'Queued for enrichment. Results appear as they finish.' ), 'success' );
						core.setBusy( enrichSel, false );
						load();
					} ).catch( function ( err ) {
						if ( ui.box ) {
							ui.box.hidden = true;
						}
						core.setBusy( enrichSel, false );
						core.errorToast( err );
					} );
				} );
			} );
		}
		var enrichAll = $( '#ace-enrich-all' );
		if ( enrichAll ) {
			enrichAll.addEventListener( 'click', function () {
				openPicker( t( 'chooseTypes', 'Choose what to enrich' ), function ( types ) {
					drainServer( 'enrich_batch', {
						search: state.search,
						category: state.category,
						scope_status: state.status,
						content_types: types
					}, function () {
						load();
					} );
				} );
			} );
		}

		load();
	}

	document.addEventListener( 'DOMContentLoaded', init );
} )();
