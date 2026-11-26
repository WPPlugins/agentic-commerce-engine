/**
 * Rezolve Ai: Settings screen (v4).
 *
 * Saves connection, automation, content publishing, AI crawler tracking, and
 * advanced settings through the agencoen_save_settings action. Only the
 * fields actually rendered on this site are submitted (Woo-less sites omit
 * the commerce controls), so absent controls never clobber saved values.
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

	function checkboxValue( id ) {
		var box = $( '#' + id );
		return box ? ( box.checked ? 'yes' : 'no' ) : null;
	}

	function selectValue( id ) {
		var sel = $( '#' + id );
		return sel ? sel.value : null;
	}

	function collect() {
		var payload = {};
		var bools = {
			auto_sync: 'ace-auto-sync',
			auto_apply: 'ace-auto-apply',
			schema_enabled: 'ace-schema-enabled',
			auto_append: 'ace-auto-append',
			debug_log: 'ace-debug-log',
			article_schema: 'ace-article-schema',
			bots_enabled: 'ace-bots-enabled',
			bots_paths: 'ace-bots-paths',
			bots_report: 'ace-bots-report',
			traffic_enabled: 'ace-traffic-enabled',
			traffic_report: 'ace-traffic-report',
			ab_enabled: 'ace-ab-enabled',
			ab_report: 'ace-ab-report'
		};
		Object.keys( bools ).forEach( function ( field ) {
			var v = checkboxValue( bools[ field ] );
			if ( null !== v ) {
				payload[ field ] = v;
			}
		} );
		var selects = {
			sync_frequency: 'ace-sync-frequency',
			content_status: 'ace-content-status',
			content_format: 'ace-content-format',
			content_author: 'ace-content-author',
			bots_retention: 'ace-bots-retention'
		};
		Object.keys( selects ).forEach( function ( field ) {
			var v = selectValue( selects[ field ] );
			if ( null !== v ) {
				payload[ field ] = v;
			}
		} );
		/*
		 * Placement selects are discovered by attribute rather than listed by
		 * id, so adding a third placeable content type on the platform needs no
		 * change here. The field name matches what save_settings() reads:
		 * placement_<type with dashes as underscores>.
		 */
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ace-placement]' ),
			function ( sel ) {
				var type = sel.getAttribute( 'data-ace-placement' );
				if ( type && sel.value ) {
					payload[ 'placement_' + type.replace( /-/g, '_' ) ] = sel.value;
				}
			}
		);

		/*
		 * Where each content type is written (4.25.0): `block`,
		 * `description:end|start` or `field:<id>`, plus the section heading.
		 * A disabled select (no connection) is not sent, so saving offline
		 * never asks the platform for a change it cannot make.
		 */
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ace-destination]' ),
			function ( sel ) {
				var slug = sel.getAttribute( 'data-ace-destination' );
				if ( ! slug || sel.disabled || ! sel.value ) {
					return;
				}
				payload[ 'destination_' + slug ] = sel.value;
				var heading = $( '[data-ace-destination-heading="' + slug + '"]' );
				if ( heading ) {
					payload[ 'destination_heading_' + slug ] = heading.value.trim();
				}
			}
		);

		var base = $( '#ace-base-url-setting' );
		if ( base && base.value.trim() ) {
			payload.base_url = base.value.trim();
		}
		// Sent even when blank, unlike base_url above: blank is a real choice
		// here ("use the default selector"), and skipping it would make the
		// field impossible to clear once set.
		var abSel = $( '#ace-ab-selector' );
		if ( abSel ) {
			payload.ab_selector = abSel.value.trim();
		}
		return payload;
	}

	/*
	 * Show only the controls for each row's chosen destination: the block's
	 * position for the block, the heading for a description section. Nothing
	 * extra for a field.
	 */
	function initDestinations() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ace-destination-row]' ),
			function ( row ) {
				var sel = row.querySelector( '[data-ace-destination]' );
				if ( ! sel ) {
					return;
				}
				var sync = function () {
					var kind = String( sel.value || 'block' ).split( ':' )[ 0 ];
					Array.prototype.forEach.call( row.querySelectorAll( '[data-ace-when]' ), function ( el ) {
						el.hidden = el.getAttribute( 'data-ace-when' ) !== kind;
					} );
				};
				sel.addEventListener( 'change', sync );
				sync();
			}
		);
	}

	function populateAuthors() {
		var sel = $( '#ace-content-author' );
		if ( ! sel ) {
			return;
		}
		var authors = cfg.authors || [];
		var current = String( ( cfg.settings && cfg.settings.contentAuthor ) || 0 );
		// Keep the server-rendered "publishing user" option, then append users.
		authors.forEach( function ( author ) {
			var opt = document.createElement( 'option' );
			opt.value = String( author.id );
			opt.textContent = author.name;
			sel.appendChild( opt );
		} );
		sel.value = current;
		if ( sel.selectedIndex < 0 ) {
			sel.value = '0';
		}
	}

	/*
	 * Custom fields card. The list comes from the platform, which judges every
	 * field with the same rules the writer uses, so what this card says is used
	 * is exactly what gets used. Every change is sent to the platform and the
	 * table is redrawn from its answer.
	 *
	 * An index table (4.24.1): a tab per status with counts, search, pages of
	 * 25, bulk Leave out / Use again, and a Rewrite switch. Ordering, filtering
	 * and paging come from agencoen.customFieldListing, a port of the rules the
	 * web app and the Shopify app use (a parity test keeps them identical).
	 * The search box is drawn once and never redrawn, so typing keeps focus.
	 */
	function fmt( key, fallback, values ) {
		var out = t( key, fallback );
		( values || [] ).forEach( function ( v, i ) {
			out = out.split( '%' + ( i + 1 ) + '$s' ).join( v ).replace( '%s', v );
		} );
		return out;
	}

	function initCustomFields() {
		var box = $( '#ace-custom-fields' );
		var listing = ns.customFieldListing;
		if ( ! box || ! listing ) {
			return;
		}

		var state = { fields: [], tab: 'all', query: '', page: 0, selected: {}, busy: false };
		var TAB_LABELS = {
			all: t( 'customFieldsTabAll', 'All' ),
			used: t( 'customFieldsTabUsed', 'Used' ),
			rewriting: t( 'customFieldsTabRewriting', 'Rewriting' ),
			excluded: t( 'customFieldsExcluded', 'Left out' ),
			skipped: t( 'customFieldsSkipped', 'Not used' )
		};
		var STATUS_CHIPS = {
			used: [ 'success', t( 'customFieldsTabUsed', 'Used' ) ],
			excluded: [ 'warning', t( 'customFieldsExcluded', 'Left out' ) ],
			skipped: [ 'neutral', t( 'customFieldsSkipped', 'Not used' ) ]
		};

		box.innerHTML =
			'<div class="ace-tabs ace-cf-tabs" role="tablist" aria-label="' + core.esc( t( 'customFieldsTabsLabel', 'Custom field status' ) ) + '" data-cf-tabs></div>' +
			'<div class="ace-toolbar ace-cf-toolbar"><input type="search" class="ace-input ace-search" data-cf-search autocomplete="off" placeholder="' +
				core.esc( t( 'customFieldsSearch', 'Search by field name or content' ) ) + '" aria-label="' + core.esc( t( 'customFieldsSearchLabel', 'Search custom fields' ) ) + '" /></div>' +
			'<div class="ace-selbar" data-cf-selbar hidden></div>' +
			'<div class="ace-cf-frame" id="ace-cf-panel" role="tabpanel"><div class="ace-table-scroll" data-cf-table><p class="ace-help ace-cf-note">' + core.esc( t( 'customFieldsLoading', 'Loading your custom fields…' ) ) + '</p></div>' +
			'<div class="ace-pagination" data-cf-pager hidden></div></div>';

		var tabsEl = $( '[data-cf-tabs]', box );
		var searchEl = $( '[data-cf-search]', box );
		var selbarEl = $( '[data-cf-selbar]', box );
		var tableEl = $( '[data-cf-table]', box );
		var pagerEl = $( '[data-cf-pager]', box );
		var toolbarEl = $( '.ace-cf-toolbar', box );

		function truncate( text, max ) {
			var clean = String( text || '' ).replace( /\s+/g, ' ' ).trim();
			return clean.length > max ? clean.slice( 0, max - 3 ).trim() + '...' : clean;
		}

		function selectedFields() {
			return state.fields.filter( function ( f ) {
				return state.selected[ f.id ] && listing.isSelectable( f );
			} );
		}

		function render() {
			var counts = listing.counts( state.fields );
			var tab = listing.activeTab( state.tab, counts );
			var visible = listing.filter( state.fields, { tab: tab, query: state.query } );
			var view = listing.pageOf( visible, state.page );
			state.page = view.page;
			var empty = ! state.fields.length;

			tabsEl.hidden = empty;
			toolbarEl.hidden = empty;
			tabsEl.innerHTML = listing.TAB_IDS.filter( function ( id ) {
				return 'all' === id || counts[ id ] > 0;
			} ).map( function ( id ) {
				return '<button type="button" role="tab" id="ace-cf-tab-' + id + '" aria-controls="ace-cf-panel" class="ace-tab' + ( id === tab ? ' is-active' : '' ) + '" aria-selected="' + ( id === tab ? 'true' : 'false' ) + '" data-cf-tab="' + id + '">' +
					core.esc( TAB_LABELS[ id ] ) + ' <span class="ace-tab-count">' + core.esc( core.num( counts[ id ] ) ) + '</span></button>';
			} ).join( '' );
			var frameEl = $( '#ace-cf-panel', box );
			if ( frameEl ) {
				frameEl.setAttribute( 'aria-labelledby', 'ace-cf-tab-' + tab );
			}
			core.tabKeys( tabsEl );

			var chosen = selectedFields();
			var bulk = listing.bulkActions( chosen );
			selbarEl.hidden = ! chosen.length;
			selbarEl.innerHTML = chosen.length ?
				'<span>' + core.esc( fmt( 'customFieldsSelected', '%s selected', [ core.num( chosen.length ) ] ) ) + '</span><span class="ace-selbar-spacer"></span>' +
				( bulk.leaveOut ? '<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-cf-bulk="exclude"' + ( state.busy ? ' disabled' : '' ) + '>' + core.esc( fmt( 'customFieldsBulkLeaveOut', 'Leave out %s', [ core.num( bulk.leaveOut ) ] ) ) + '</button>' : '' ) +
				( bulk.useAgain ? '<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-cf-bulk="include"' + ( state.busy ? ' disabled' : '' ) + '>' + core.esc( fmt( 'customFieldsBulkUseAgain', 'Use %s again', [ core.num( bulk.useAgain ) ] ) ) + '</button>' : '' ) +
				'<button type="button" class="ace-link-sm" data-cf-clear>' + core.esc( t( 'customFieldsClear', 'Clear selection' ) ) + '</button>' : '';

			if ( empty ) {
				tableEl.innerHTML = '<p class="ace-help ace-cf-note">' + core.esc( t( 'customFieldsEmpty', 'No custom fields found on your products yet. They appear here after your next catalog sync.' ) ) + '</p>';
				pagerEl.hidden = true;
				return;
			}
			if ( ! visible.length ) {
				tableEl.innerHTML = '<p class="ace-help ace-cf-note">' + core.esc( state.query.trim() ?
					fmt( 'customFieldsNoMatch', 'No fields match "%s".', [ state.query.trim() ] ) :
					t( 'customFieldsNoneHere', 'No fields here yet.' ) ) + '</p>';
				pagerEl.hidden = true;
				return;
			}

			var pageSelectable = view.rows.filter( listing.isSelectable );
			var pageSelected = pageSelectable.filter( function ( f ) {
				return state.selected[ f.id ];
			} ).length;
			var html = '<table class="ace-table ace-cf-table"><thead><tr>' +
				'<th class="ace-col-check"><input type="checkbox" data-cf-select-page aria-label="' + core.esc( t( 'customFieldsSelectPage', 'Select every field on this page' ) ) + '"' +
					( pageSelectable.length && pageSelected === pageSelectable.length ? ' checked' : '' ) + ( pageSelectable.length ? '' : ' disabled' ) + ' /></th>' +
				'<th>' + core.esc( t( 'customFieldsColField', 'Field' ) ) + '</th>' +
				'<th class="num">' + core.esc( t( 'customFieldsColProducts', 'Products' ) ) + '</th>' +
				'<th>' + core.esc( t( 'customFieldsColExample', 'Example' ) ) + '</th>' +
				'<th>' + core.esc( t( 'customFieldsColStatus', 'Status' ) ) + '</th>' +
				'<th>' + core.esc( t( 'customFieldsColRewrite', 'Rewrite' ) ) + '</th>' +
				'<th><span class="screen-reader-text">' + core.esc( t( 'customFieldsColAction', 'Action' ) ) + '</span></th>' +
				'</tr></thead><tbody>';
			view.rows.forEach( function ( f ) {
				var chipDef = STATUS_CHIPS[ f.status ] || STATUS_CHIPS.skipped;
				var example = 'skipped' === f.status ?
					'<span class="ace-muted">' + core.esc( f.reason || '' ) + '</span>' :
					( f.sample ? '<span class="ace-cf-example" translate="no">' + core.esc( truncate( f.sample, 90 ) ) + '</span>' : '<span class="ace-muted">' + core.esc( t( 'customFieldsNoPreview', 'No preview' ) ) + '</span>' );
				var rewrite = '';
				if ( 'used' === f.status && f.rewritable ) {
					rewrite = '<input type="checkbox" role="switch" class="ace-switch" data-cf-rewrite="' + core.esc( f.id ) + '" aria-label="' +
						core.esc( fmt( 'customFieldsRewriteRow', 'Rewrite %s', [ f.label ] ) ) + '"' + ( f.rewrite ? ' checked' : '' ) + ( state.busy ? ' disabled' : '' ) + ' />';
				} else if ( 'used' === f.status ) {
					rewrite = '<span class="ace-muted">' + core.esc( t( 'customFieldsReadOnly', 'Read only' ) ) + '</span>';
				}
				var action = '';
				if ( 'used' === f.status ) {
					action = '<button type="button" class="ace-link-sm" data-cf-intent="exclude" data-cf-id="' + core.esc( f.id ) + '"' + ( state.busy ? ' disabled' : '' ) + '>' + core.esc( t( 'customFieldsLeaveOut', 'Leave out' ) ) + '</button>';
				} else if ( 'excluded' === f.status ) {
					action = '<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-cf-intent="include" data-cf-id="' + core.esc( f.id ) + '"' + ( state.busy ? ' disabled' : '' ) + '>' + core.esc( t( 'customFieldsUseAgain', 'Use again' ) ) + '</button>';
				}
				html += '<tr>' +
					'<td class="ace-col-check"><input type="checkbox" data-cf-select="' + core.esc( f.id ) + '" aria-label="' + core.esc( fmt( 'customFieldsSelectRow', 'Select %s', [ f.label ] ) ) + '"' +
						( state.selected[ f.id ] ? ' checked' : '' ) + ( listing.isSelectable( f ) ? '' : ' disabled' ) + ' /></td>' +
					'<td><strong translate="no">' + core.esc( f.label ) + '</strong><div class="ace-cf-kind">' + core.esc( f.kind || '' ) + '</div></td>' +
					'<td class="num">' + core.esc( core.num( f.products ) ) + '</td>' +
					'<td>' + example + '</td>' +
					'<td>' + core.chip( chipDef[ 0 ], chipDef[ 1 ] ) + '</td>' +
					'<td>' + rewrite + '</td>' +
					'<td class="ace-cf-action">' + action + '</td>' +
					'</tr>';
			} );
			tableEl.innerHTML = html + '</tbody></table>';
			var pageBox = $( '[data-cf-select-page]', tableEl );
			if ( pageBox ) {
				pageBox.indeterminate = pageSelected > 0 && pageSelected < pageSelectable.length;
			}

			pagerEl.hidden = false;
			pagerEl.innerHTML =
				'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-cf-page="-1"' + ( view.hasPrevious ? '' : ' disabled' ) + '>' + core.esc( t( 'previous', 'Previous' ) ) + '</button>' +
				'<span class="ace-muted">' + core.esc( 1 === visible.length ?
					t( 'customFieldsShowingOne', 'Showing 1 field' ) :
					fmt( 'customFieldsShowing', 'Showing %1$s to %2$s of %3$s fields', [ core.num( view.from ), core.num( view.to ), core.num( visible.length ) ] ) ) + '</span>' +
				'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-cf-page="1"' + ( view.hasNext ? '' : ' disabled' ) + '>' + core.esc( t( 'next', 'Next' ) ) + '</button>';
		}

		function outcome( intent, targets, d ) {
			var one = 1 === targets.length ? targets[ 0 ].label : null;
			var count = core.num( targets.length );
			switch ( intent ) {
				case 'exclude':
					return one ?
						fmt( 'customFieldsLeftOutOne', '%s is left out. Rezolve Ai stops using it straight away.', [ one ] ) :
						fmt( 'customFieldsLeftOutMany', '%s fields are left out. Rezolve Ai stops using them straight away.', [ count ] );
				case 'include':
					if ( ! ( d && d.resyncNeeded ) ) {
						return one ? fmt( 'customFieldsUsedAgainOne', '%s is used again.', [ one ] ) : fmt( 'customFieldsUsedAgainMany', '%s fields are used again.', [ count ] );
					}
					if ( d.syncing ) {
						return one ?
							fmt( 'customFieldsSyncingOne', '%s is used again. Your catalog is resyncing, so it is back on every product once that finishes.', [ one ] ) :
							fmt( 'customFieldsSyncingMany', '%s fields are used again. Your catalog is resyncing, so they are back on every product once that finishes.', [ count ] );
					}
					return one ?
						fmt( 'customFieldsLaterOne', '%s is used again. It comes back with your next catalog sync.', [ one ] ) :
						fmt( 'customFieldsLaterMany', '%s fields are used again. They come back with your next catalog sync.', [ count ] );
				case 'rewrite-on':
					return fmt( 'customFieldsRewriteOn', 'Rezolve Ai can now suggest rewrites for %s.', [ one || count ] );
				default:
					return fmt( 'customFieldsRewriteOff', '%s will no longer be rewritten.', [ one || count ] );
			}
		}

		function change( intent, targets ) {
			if ( state.busy || ! targets.length ) {
				return;
			}
			state.busy = true;
			if ( 'exclude' === intent || 'include' === intent ) {
				state.selected = {};
			}
			render();
			core.post( 'custom_fields_update', {
				intent: intent,
				fields: JSON.stringify( targets.map( function ( f ) {
					return { id: f.id, label: f.label };
				} ) )
			} ).then( function ( d ) {
				state.busy = false;
				state.fields = ( d && d.fields ) || state.fields;
				render();
				core.toast( outcome( intent, targets, d ), 'success' );
			} ).catch( function ( err ) {
				state.busy = false;
				render();
				core.errorToast( err );
			} );
		}

		function fieldById( id ) {
			for ( var i = 0; i < state.fields.length; i++ ) {
				if ( state.fields[ i ].id === id ) {
					return state.fields[ i ];
				}
			}
			return null;
		}

		box.addEventListener( 'click', function ( e ) {
			var el = e.target && e.target.closest ? e.target.closest( '[data-cf-tab],[data-cf-intent],[data-cf-bulk],[data-cf-clear],[data-cf-page]' ) : null;
			if ( ! el || el.disabled ) {
				return;
			}
			if ( el.hasAttribute( 'data-cf-tab' ) ) {
				state.tab = el.getAttribute( 'data-cf-tab' );
				state.page = 0;
				// The tabs are drawn again below, which removes the one that
				// was pressed; focus goes to its replacement, not the page.
				render();
				var pressed = $( '[data-cf-tab="' + state.tab + '"]', box );
				if ( pressed ) {
					pressed.focus();
				}
				return;
			} else if ( el.hasAttribute( 'data-cf-page' ) ) {
				state.page += Number( el.getAttribute( 'data-cf-page' ) );
			} else if ( el.hasAttribute( 'data-cf-clear' ) ) {
				state.selected = {};
			} else if ( el.hasAttribute( 'data-cf-bulk' ) ) {
				var bulkIntent = el.getAttribute( 'data-cf-bulk' );
				var wanted = 'exclude' === bulkIntent ? 'used' : 'excluded';
				change( bulkIntent, selectedFields().filter( function ( f ) {
					return f.status === wanted;
				} ) );
				return;
			} else {
				var f = fieldById( el.getAttribute( 'data-cf-id' ) );
				if ( f ) {
					change( el.getAttribute( 'data-cf-intent' ), [ f ] );
				}
				return;
			}
			// Paging and clearing redraw the control that was pressed. Put
			// focus back on its replacement (or, once the selection bar has
			// gone, on the select-all box) rather than losing it to the page.
			var again = el.hasAttribute( 'data-cf-page' )
				? '[data-cf-page="' + el.getAttribute( 'data-cf-page' ) + '"]:not([disabled]), [data-cf-page]:not([disabled])'
				: '[data-cf-select-page]';
			render();
			var target = $( again, box );
			if ( target ) {
				target.focus();
			}
		} );

		box.addEventListener( 'change', function ( e ) {
			var el = e.target;
			if ( ! el || ! el.getAttribute ) {
				return;
			}
			if ( el.hasAttribute( 'data-cf-rewrite' ) ) {
				var f = fieldById( el.getAttribute( 'data-cf-rewrite' ) );
				if ( f && el.checked !== !! f.rewrite ) {
					change( el.checked ? 'rewrite-on' : 'rewrite-off', [ f ] );
				}
				return;
			}
			if ( el.hasAttribute( 'data-cf-select' ) ) {
				if ( el.checked ) {
					state.selected[ el.getAttribute( 'data-cf-select' ) ] = true;
				} else {
					delete state.selected[ el.getAttribute( 'data-cf-select' ) ];
				}
			} else if ( el.hasAttribute( 'data-cf-select-page' ) ) {
				var counts = listing.counts( state.fields );
				var rows = listing.pageOf( listing.filter( state.fields, { tab: listing.activeTab( state.tab, counts ), query: state.query } ), state.page ).rows;
				rows.filter( listing.isSelectable ).forEach( function ( row ) {
					if ( el.checked ) {
						state.selected[ row.id ] = true;
					} else {
						delete state.selected[ row.id ];
					}
				} );
			} else {
				return;
			}
			render();
		} );

		searchEl.addEventListener( 'input', function () {
			state.query = searchEl.value;
			state.page = 0;
			render();
		} );

		core.post( 'custom_fields_list', {} ).then( function ( d ) {
			state.fields = ( d && d.fields ) || [];
			render();
		} ).catch( function ( err ) {
			tableEl.innerHTML = '<p class="ace-help ace-cf-note">' + core.esc( t( 'customFieldsLoadError', 'Your custom fields could not be loaded. Try again in a moment.' ) ) + '</p>';
			core.errorToast( err );
		} );
	}

	/*
	 * Field formats card (4.28.0). How Rezolve Ai writes into each field, learned
	 * from the store's own products, and the merchant's choice for it: match the
	 * store, a preset, or Rezolve Ai's default; which parts to keep exactly as
	 * they are; and whether to fill the field where a product has none. Every
	 * change and the free preview go through the platform, shared with the web
	 * app and the Shopify app. The words come from agencoen.fieldFormatListing,
	 * a port of the platform's (a parity test keeps them identical).
	 */
	function initFieldFormats() {
		var box = $( '#ace-field-formats' );
		var words = ns.fieldFormatListing;
		if ( ! box || ! words ) {
			return;
		}
		var o = {
			tr: function ( key, fallback, values ) {
				return fmt( key, fallback, values );
			},
			num: core.num
		};
		var state = { fields: [], enabled: true, busy: false, open: null, draft: null, detail: null, preview: null, previewError: '', relearnSent: false };
		var modal = $( '#ace-ff-modal' );
		var fillModal = $( '#ace-ff-fill-modal' );
		var dlg = null;
		var fillDlg = null;
		var fillTarget = null;
		var previewTimer = null;
		var previewSeq = 0;

		box.innerHTML = '<div class="ace-ff-head"><button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-ff-relearn>' + core.esc( t( 'ffRelearn', 'Learn again' ) ) + '</button>' +
			'<button type="button" class="ace-link-sm" data-ff-check hidden>' + core.esc( t( 'ffCheckAgain', 'Check again' ) ) + '</button></div>' +
			'<div data-ff-notice></div><div class="ace-table-scroll" data-ff-table><p class="ace-help">' + core.esc( t( 'ffLoading', 'Loading your field formats…' ) ) + '</p></div>';
		var tableEl = $( '[data-ff-table]', box );
		var noticeEl = $( '[data-ff-notice]', box );
		var checkEl = $( '[data-ff-check]', box );

		function byTarget( target ) {
			for ( var i = 0; i < state.fields.length; i++ ) {
				if ( state.fields[ i ].target === target ) {
					return state.fields[ i ];
				}
			}
			return null;
		}

		function replace( field ) {
			state.fields = state.fields.map( function ( f ) {
				return f.target === field.target ? field : f;
			} );
		}

		function render() {
			noticeEl.innerHTML = state.enabled ? '' : '<p class="ace-banner ace-banner-info">' + core.esc( t( 'ffNotOn', 'Field formats are not switched on for this site yet, so content is written in Rezolve Ai\'s own layout for now. What is learned here, and the choices you make, are used as soon as they are.' ) ) + '</p>';
			checkEl.hidden = ! state.relearnSent;
			if ( ! state.fields.length ) {
				tableEl.innerHTML = '<p class="ace-help">' + core.esc( t( 'ffEmpty', 'Nothing learned yet. Formats are learned after your catalog sync, from your own products.' ) ) + '</p>';
				return;
			}
			var html = '<table class="ace-table ace-ff-table"><caption class="screen-reader-text">' + core.esc( t( 'ffCaption', 'How Rezolve Ai writes into each field' ) ) + '</caption><thead><tr>' +
				'<th scope="col">' + core.esc( t( 'ffColField', 'Field' ) ) + '</th>' +
				'<th scope="col">' + core.esc( t( 'ffColLearned', 'Learned from' ) ) + '</th>' +
				'<th scope="col">' + core.esc( t( 'ffColFormat', 'Format' ) ) + '</th>' +
				'<th scope="col">' + core.esc( t( 'ffColFill', 'Fill when empty' ) ) + '</th>' +
				'<th scope="col"><span class="screen-reader-text">' + core.esc( t( 'ffColAction', 'Action' ) ) + '</span></th>' +
				'</tr></thead><tbody>';
			state.fields.forEach( function ( f ) {
				var learned = words.learnedFromText( f, o );
				var fill = '';
				if ( f.fill && f.fill.available ) {
					fill = '<input type="checkbox" role="switch" class="ace-switch" data-ff-fill="' + core.esc( f.target ) + '" aria-label="' +
						core.esc( fmt( 'ffFillRow', 'Fill %s when empty', [ f.label ] ) ) + '"' + ( f.fillEmpty ? ' checked' : '' ) + ( state.busy ? ' disabled' : '' ) + ' />' +
						' <span class="ace-muted">' + core.esc( words.fillCount( f, o ) ) + '</span>';
				} else if ( f.fill ) {
					fill = '<span class="ace-muted">' + core.esc( f.fill.reason || '' ) + '</span>';
				}
				html += '<tr>' +
					'<td><strong translate="no">' + core.esc( f.label ) + '</strong>' + ( words.kindLine( f, o ) === f.label ? '' : '<div class="ace-cf-kind">' + core.esc( words.kindLine( f, o ) ) + '</div>' ) + '</td>' +
					'<td>' + core.esc( learned.main ) + ( learned.sub ? '<div class="ace-muted">' + core.esc( learned.sub ) + '</div>' : '' ) + '</td>' +
					'<td>' + core.chip( 'neutral', words.modeLabel( f, o ) ) + '<div>' + core.esc( f.summary || '' ) + '</div>' + ( f.reason ? '<div class="ace-muted">' + core.esc( f.reason ) + '</div>' : '' ) + '</td>' +
					'<td>' + fill + '</td>' +
					'<td class="ace-cf-action"><button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-ff-open="' + core.esc( f.target ) + '" aria-label="' +
						core.esc( fmt( 'ffChangeRow', 'Change the format of %s', [ f.label ] ) ) + '">' + core.esc( t( 'ffChange', 'Change' ) ) + '</button></td>' +
					'</tr>';
			} );
			tableEl.innerHTML = html + '</tbody></table>';
		}

		function load() {
			return core.post( 'field_formats_list', {} ).then( function ( d ) {
				state.fields = ( d && d.fields ) || [];
				state.enabled = ! d || false !== d.enabled;
				render();
			} ).catch( function ( err ) {
				tableEl.innerHTML = '<p class="ace-help">' + core.esc( t( 'ffLoadError', 'Your field formats could not be loaded. Try again in a moment.' ) ) + '</p>';
				core.errorToast( err );
			} );
		}

		function save( target, patch, done ) {
			if ( state.busy ) {
				return;
			}
			state.busy = true;
			render();
			core.post( 'field_formats_update', { target: target, patch: JSON.stringify( patch ) } ).then( function ( d ) {
				state.busy = false;
				if ( d && d.field && d.field.target ) {
					replace( d.field );
				}
				render();
				core.toast( fmt( 'ffSaved', '%s saved.', [ ( d && d.field && d.field.label ) || '' ] ), 'success' );
				if ( done ) {
					done();
				}
			} ).catch( function ( err ) {
				state.busy = false;
				render();
				core.errorToast( err );
			} );
		}

		/* The dialog for one field ------------------------------------------ */

		function partsHtml( parts ) {
			if ( ! parts || ! parts.length ) {
				return '<p class="ace-help">' + core.esc( t( 'ffOwnLayout', 'Rezolve Ai\'s own layout, sized for the content type it writes.' ) ) + '</p>';
			}
			return '<ol class="ace-ff-parts">' + parts.map( function ( p ) {
				return '<li><strong>' + core.esc( p.title ) + '</strong>' + ( p.detail ? '<span class="ace-muted" translate="no"> ' + core.esc( p.detail ) + '</span>' : '' ) + '</li>';
			} ).join( '' ) + '</ol>';
		}

		function option( value, title, hint, checked ) {
			return '<label class="ace-ff-option"><input type="radio" name="ace-ff-mode" value="' + core.esc( value ) + '"' + ( checked ? ' checked' : '' ) + ' />' +
				'<span><strong>' + core.esc( title ) + '</strong><span class="ace-help">' + core.esc( hint ) + '</span></span></label>';
		}

		/* The merchant's own template (4.28.0): parts in order, each with its settings. */
		var MAX_PARTS = 8;

		function tplSelect( i, prop, label, title, value, options ) {
			return '<label class="ace-ff-tpl-field">' + core.esc( label ) +
				'<select class="ace-select ace-input-sm" data-ff-tpl="' + i + ':' + prop + '" aria-label="' + core.esc( fmt( 'ffTplFieldFor', '%1$s, %2$s', [ label, title ] ) ) + '">' +
				options.map( function ( opt ) {
					return '<option value="' + core.esc( opt[ 0 ] ) + '"' + ( String( value ) === opt[ 0 ] ? ' selected' : '' ) + '>' + core.esc( opt[ 1 ] ) + '</option>';
				} ).join( '' ) + '</select></label>';
		}

		function tplCounts( i, part, title ) {
			var input = function ( prop, label ) {
				return '<label class="ace-ff-tpl-field">' + core.esc( label ) +
					'<input type="number" class="ace-input ace-input-sm" min="1" max="12" step="1" data-ff-tpl="' + i + ':' + prop + '" value="' + core.esc( String( part[ prop ] ) ) +
					'" aria-label="' + core.esc( fmt( 'ffTplFieldFor', '%1$s, %2$s', [ label, title ] ) ) + '" /></label>';
			};
			return input( 'min', t( 'ffTplFewest', 'Fewest' ) ) + input( 'max', t( 'ffTplMost', 'Most' ) );
		}

		function tplSettings( i, part, title ) {
			switch ( part.kind ) {
				case 'specs':
					return tplSelect( i, 'style', t( 'ffTplShownAs', 'Shown as' ), title, part.style, [ [ 'label_colon', t( 'ffTplList', 'A list' ) ], [ 'table', t( 'ffTplTable', 'A table' ) ] ] ) + tplCounts( i, part, title );
				case 'paragraphs':
					return tplCounts( i, part, title );
				case 'bullets':
					return tplSelect( i, 'ordered', t( 'ffTplMarked', 'Marked with' ), title, part.ordered ? '1' : '0', [ [ '0', t( 'ffTplBullets', 'Bullets' ) ], [ '1', t( 'ffTplNumbers', 'Numbers' ) ] ] ) + tplCounts( i, part, title );
				case 'heading':
					return tplSelect( i, 'level', t( 'ffTplSize', 'Size' ), title, part.level, [ [ '2', t( 'ffTplLarge', 'Large' ) ], [ '3', t( 'ffTplSmall', 'Small' ) ] ] ) +
						'<label class="ace-ff-tpl-field ace-ff-tpl-wide">' + core.esc( t( 'ffTplHeadingText', 'Heading text, or leave empty for Rezolve Ai to write one' ) ) +
						'<input type="text" class="ace-input ace-input-sm" maxlength="80" data-ff-tpl="' + i + ':text" value="' + core.esc( part.text || '' ) + '" /></label>';
				case 'qa':
					return tplSelect( i, 'style', t( 'ffTplQuestions', 'Questions shown' ), title, part.style, [
						[ 'bold_question', t( 'ffTplBold', 'In bold' ) ],
						[ 'heading_question', t( 'ffTplHeadings', 'As headings' ) ],
						[ 'q_a_lines', t( 'ffTplLines', 'As Q and A lines' ) ]
					] ) + tplCounts( i, part, title );
				default:
					return '<span class="ace-help">' + core.esc( t( 'ffTplKeptNote', 'The sentence your values end with, kept as your store writes it.' ) ) + '</span>';
			}
		}

		function templateHtml( f, parts ) {
			var kinds = words.templateKindsFor( f, parts );
			var html = '<fieldset class="ace-ff-template"><legend class="ace-h3">' + core.esc( t( 'ffTplTitle', 'Your template' ) ) + '</legend><ol class="ace-ff-tpl">';
			parts.forEach( function ( part, i ) {
				var title = words.templatePartTitle( part, o );
				var last = 'kept' === part.kind;
				var nextKept = parts[ i + 1 ] && 'kept' === parts[ i + 1 ].kind;
				html += '<li class="ace-ff-tpl-part"><div class="ace-ff-tpl-head"><strong>' + core.esc( ( i + 1 ) + '. ' + title ) + '</strong><span>' +
					'<button type="button" class="ace-link-sm" data-ff-tpl-move="' + i + ':-1" aria-label="' + core.esc( fmt( 'ffTplMoveUp', 'Move %s up', [ title ] ) ) + '"' + ( 0 === i || last ? ' disabled' : '' ) + '>' + core.esc( t( 'ffTplUp', 'Up' ) ) + '</button> ' +
					'<button type="button" class="ace-link-sm" data-ff-tpl-move="' + i + ':1" aria-label="' + core.esc( fmt( 'ffTplMoveDown', 'Move %s down', [ title ] ) ) + '"' + ( i === parts.length - 1 || last || nextKept ? ' disabled' : '' ) + '>' + core.esc( t( 'ffTplDown', 'Down' ) ) + '</button> ' +
					'<button type="button" class="ace-link-sm" data-ff-tpl-remove="' + i + '" aria-label="' + core.esc( fmt( 'ffTplRemoveRow', 'Remove %s', [ title ] ) ) + '"' + ( 1 === parts.length ? ' disabled' : '' ) + '>' + core.esc( t( 'ffTplRemove', 'Remove' ) ) + '</button>' +
					'</span></div><div class="ace-ff-tpl-settings">' + tplSettings( i, part, title ) + '</div></li>';
			} );
			html += '</ol>';
			if ( kinds.length && parts.length < MAX_PARTS ) {
				html += '<div class="ace-ff-tpl-add"><label class="ace-ff-tpl-field">' + core.esc( t( 'ffTplAddPart', 'Add a part' ) ) +
					'<select class="ace-select ace-input-sm" data-ff-tpl-kind>' + kinds.map( function ( kind ) {
						return '<option value="' + kind + '">' + core.esc( words.templatePartTitle( { kind: kind }, o ) ) + '</option>';
					} ).join( '' ) + '</select></label>' +
					'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-ff-tpl-add>' + core.esc( t( 'ffTplAdd', 'Add' ) ) + '</button></div>';
			}
			return html + '</fieldset>';
		}

		function setTemplate( next, focusSelector ) {
			state.draft.template = next;
			renderDialog();
			requestPreview();
			var again = focusSelector ? $( focusSelector, modal ) : null;
			if ( again && ! again.disabled ) {
				again.focus();
			}
		}

		function renderDialog() {
			var f = state.open;
			var d = state.draft;
			if ( ! f || ! d ) {
				return;
			}
			var kind = words.kindLine( f, o );
			var html = '<p class="ace-muted">' + core.esc( ( kind === f.label ? '' : kind + '. ' ) + words.learnedFromText( f, o ).main + ( f.learned && f.reason ? '. ' + f.reason : '' ) ) + '</p>';
			var segments = state.detail && state.detail.segments ? state.detail.segments.filter( function ( s ) {
				return s.labels && s.labels.length;
			} ) : [];
			if ( segments.length ) {
				html += '<h3 class="ace-h3">' + core.esc( t( 'ffLabelsTitle', 'Labels by product type' ) ) + '</h3><ul class="ace-ff-labels" translate="no">' + segments.map( function ( s ) {
					return '<li><strong>' + core.esc( s.name ) + '</strong> ' + core.esc( s.labels.join( ', ' ) ) + '</li>';
				} ).join( '' ) + '</ul>';
			}
			if ( f.fixedList ) {
				html += '<p>' + core.esc( t( 'ffFixedList', 'Always one of the values in this field\'s own list, spelled as the list spells it.' ) ) + '</p>';
			} else {
				var picked = 'preset' === d.mode && d.preset ? 'preset:' + d.preset : d.mode;
				html += '<fieldset class="ace-ff-modes"><legend class="ace-h3">' + core.esc( t( 'ffHowTitle', 'How Rezolve Ai writes this field' ) ) + '</legend>' +
					option( 'match', t( 'ffModeMatch', 'Match my store' ), t( 'ffModeMatchHint', 'Written the way your products already are.' ), 'match' === picked ) +
					( f.presets || [] ).filter( function ( p ) {
						return 'rezolve-default' !== p.slug;
					} ).map( function ( p ) {
						return option( 'preset:' + p.slug, p.name, p.description, 'preset:' + p.slug === picked );
					} ).join( '' ) +
					option( 'default', t( 'ffModeDefault', 'Rezolve Ai default' ), t( 'ffModeDefaultHint', 'The layout Rezolve Ai writes when no format is chosen.' ), 'default' === picked ) +
					( f.template && f.template.allowed ? option( 'custom', t( 'ffModeOwn', 'Your own template' ), t( 'ffModeOwnHint', 'Choose the parts and their order yourself.' ), 'custom' === picked ) : '' ) +
					'</fieldset>';
				if ( 'custom' === d.mode && f.template && f.template.allowed ) {
					html += templateHtml( f, d.template || [] );
				}
			}
			var locks = words.locksFor( f, o );
			if ( locks.length ) {
				html += '<fieldset class="ace-ff-locks"><legend class="ace-h3">' + core.esc( t( 'ffKeepTitle', 'Keep exactly as they are' ) ) + '</legend>' + locks.map( function ( l ) {
					return '<label class="ace-ff-option"><input type="checkbox" data-ff-lock="' + l.key + '"' + ( d.locks[ l.key ] ? ' checked' : '' ) + ' />' +
						'<span><strong>' + core.esc( l.label ) + '</strong><span class="ace-help">' + core.esc( l.hint ) + '</span></span></label>';
				} ).join( '' ) + '</fieldset>';
			}
			if ( f.fill && f.fill.available ) {
				html += '<label class="ace-ff-option"><input type="checkbox" data-ff-fill-draft' + ( d.fillEmpty ? ' checked' : '' ) + ' />' +
					'<span><strong>' + core.esc( t( 'ffFillDraft', 'Write this field for products that do not have it' ) ) + '</strong>' +
					( d.fillEmpty ? '<span class="ace-help">' + core.esc( words.fillSentence( f, o ) || '' ) + '</span>' : '' ) + '</span></label>';
			} else if ( f.fill ) {
				html += '<p class="ace-help">' + core.esc( f.fill.reason || '' ) + '</p>';
			}
			html += '<h3 class="ace-h3">' + core.esc( t( 'ffPartsTitle', 'Parts, in order' ) ) + '</h3>' + partsHtml( state.preview ? state.preview.parts : f.parts );
			if ( 'destination' !== f.kind ) {
				html += '<h3 class="ace-h3">' + core.esc( t( 'ffPreviewTitle', 'Preview' ) ) + '</h3><p class="ace-help">' +
					core.esc( t( 'ffPreviewNote', 'Your product\'s own words, laid out in this format. Free: nothing is written or charged.' ) ) + '</p>';
				if ( state.previewError ) {
					html += '<p>' + core.esc( state.previewError ) + '</p>';
				} else if ( state.preview ) {
					var p = state.preview;
					// before and after were cleaned with wp_kses_post on the server.
					html += '<p><strong translate="no">' + core.esc( p.product.title ) + '</strong></p>' +
						'<div class="ace-ff-cap">' + core.esc( t( 'ffNow', 'Now' ) ) + '</div><div class="ace-layout-preview" translate="no">' + p.before + '</div>';
					if ( p.after ) {
						html += '<div class="ace-ff-cap">' + core.esc( t( 'ffInFormat', 'In this format' ) ) + '</div><div class="ace-layout-preview" translate="no">' + p.after.html + '</div>' +
							( p.after.leftOut.length ? '<p>' + core.esc( fmt( 'ffLeftOut', 'Left out in this format: %s.', [ p.after.leftOut.join( ', ' ) ] ) ) + '</p>' : '' ) +
							( p.after.writtenLater.length ? '<p class="ace-help">' + core.esc( fmt( 'ffWrittenLater', 'Written when content is generated: %s.', [ p.after.writtenLater.join( ', ' ) ] ) ) + '</p>' : '' );
					} else {
						html += '<p class="ace-help">' + core.esc( t( 'ffNoLayout', 'Rezolve Ai default has no fixed layout to preview: it follows the content type.' ) ) + '</p>';
					}
				} else {
					html += '<p class="ace-help">' + core.esc( t( 'ffPreviewing', 'Laying out the preview…' ) ) + '</p>';
				}
			}
			$( '#ace-ff-body', modal ).innerHTML = html;
			var saveBtn = $( '#ace-ff-save', modal );
			saveBtn.disabled = state.busy || ! words.patchFor( f, d );
			saveBtn.textContent = d.fillEmpty && ! f.fillEmpty ? t( 'ffSaveAndFill', 'Save and turn on filling' ) : t( 'ffSave', 'Save' );
		}

		function requestPreview() {
			var f = state.open;
			if ( ! f || 'destination' === f.kind ) {
				return;
			}
			clearTimeout( previewTimer );
			var seq = ++previewSeq;
			previewTimer = setTimeout( function () {
				var d = state.draft;
				var patch = { locks: d.locks };
				if ( 'custom' !== d.mode ) {
					patch.mode = d.mode;
				} else if ( d.template && d.template.length ) {
					patch.mode = 'custom';
					patch.template = d.template;
				}
				if ( 'preset' === d.mode && d.preset ) {
					patch.preset = d.preset;
				}
				core.post( 'field_formats_preview', { target: f.target, patch: JSON.stringify( patch ) } ).then( function ( p ) {
					if ( seq !== previewSeq ) {
						return;
					}
					state.preview = p;
					state.previewError = '';
					renderDialog();
				} ).catch( function ( err ) {
					if ( seq !== previewSeq ) {
						return;
					}
					state.preview = null;
					state.previewError = ( err && err.message ) || t( 'ffPreviewError', 'This format could not be previewed.' );
					renderDialog();
				} );
			}, 250 );
		}

		function openField( f ) {
			state.open = f;
			state.draft = words.draftOf( f );
			state.detail = null;
			state.preview = null;
			state.previewError = '';
			$( '#ace-ff-title', modal ).textContent = f.label;
			renderDialog();
			dlg = core.dialog( modal );
			core.post( 'field_formats_detail', { target: f.target } ).then( function ( d ) {
				if ( state.open && d && d.field && d.field.target === state.open.target ) {
					state.detail = d.field;
					renderDialog();
				}
			} ).catch( function () {
				// The labels and examples are extras: the dialog works without them.
			} );
			requestPreview();
		}

		modal.addEventListener( 'change', function ( e ) {
			var el = e.target;
			var d = state.draft;
			if ( ! d ) {
				return;
			}
			if ( el.hasAttribute( 'data-ff-tpl' ) ) {
				var at = el.getAttribute( 'data-ff-tpl' ).split( ':' );
				var i = parseInt( at[ 0 ], 10 );
				var prop = at[ 1 ];
				var parts = ( d.template || [] ).map( function ( p ) {
					return Object.assign( {}, p );
				} );
				var part = parts[ i ];
				if ( ! part ) {
					return;
				}
				if ( 'min' === prop || 'max' === prop ) {
					var n = Math.min( 12, Math.max( 1, Math.round( Number( el.value ) ) || 1 ) );
					part[ prop ] = n;
					if ( 'min' === prop && part.max < n ) {
						part.max = n;
					}
					if ( 'max' === prop && part.min > n ) {
						part.min = n;
					}
				} else if ( 'ordered' === prop ) {
					part.ordered = '1' === el.value;
				} else if ( 'level' === prop ) {
					part.level = '2' === el.value ? 2 : 3;
				} else if ( 'text' === prop ) {
					if ( el.value.trim() ) {
						part.text = el.value.trim().slice( 0, 80 );
					} else {
						delete part.text;
					}
				} else {
					part[ prop ] = el.value;
				}
				setTemplate( parts, '[data-ff-tpl="' + i + ':' + prop + '"]' );
				return;
			}
			if ( 'ace-ff-mode' === el.name ) {
				if ( 0 === el.value.indexOf( 'preset:' ) ) {
					d.mode = 'preset';
					d.preset = el.value.slice( 'preset:'.length );
				} else {
					d.mode = el.value;
					delete d.preset;
					if ( 'custom' === d.mode && ! d.template ) {
						d.template = words.starterTemplate( state.open );
					}
				}
				requestPreview();
			} else if ( el.hasAttribute( 'data-ff-lock' ) ) {
				d.locks[ el.getAttribute( 'data-ff-lock' ) ] = el.checked;
				requestPreview();
			} else if ( el.hasAttribute( 'data-ff-fill-draft' ) ) {
				d.fillEmpty = el.checked;
			}
			renderDialog();
			// Keep focus on the control the merchant just used.
			var again = el.name ? $( 'input[name="' + el.name + '"][value="' + el.value + '"]', modal ) :
				$( '[' + ( el.hasAttribute( 'data-ff-lock' ) ? 'data-ff-lock="' + el.getAttribute( 'data-ff-lock' ) + '"' : 'data-ff-fill-draft' ) + ']', modal );
			if ( again ) {
				again.focus();
			}
		} );

		modal.addEventListener( 'click', function ( e ) {
			var d = state.draft;
			if ( ! d || ! d.template ) {
				return;
			}
			var parts = d.template.slice();
			var mv = e.target.closest( '[data-ff-tpl-move]' );
			if ( mv ) {
				var at = mv.getAttribute( 'data-ff-tpl-move' ).split( ':' );
				var i = parseInt( at[ 0 ], 10 );
				var by = parseInt( at[ 1 ], 10 );
				var moved = parts.splice( i, 1 )[ 0 ];
				parts.splice( i + by, 0, moved );
				setTemplate( parts, '[data-ff-tpl-move="' + ( i + by ) + ':' + by + '"]' );
				return;
			}
			var rm = e.target.closest( '[data-ff-tpl-remove]' );
			if ( rm ) {
				parts.splice( parseInt( rm.getAttribute( 'data-ff-tpl-remove' ), 10 ), 1 );
				setTemplate( parts, '[data-ff-tpl-kind]' );
				return;
			}
			if ( e.target.closest( '[data-ff-tpl-add]' ) ) {
				var kindEl = $( '[data-ff-tpl-kind]', modal );
				var part = words.newTemplatePart( kindEl ? kindEl.value : 'paragraphs' );
				var keptAt = -1;
				parts.forEach( function ( p, j ) {
					if ( 'kept' === p.kind ) {
						keptAt = j;
					}
				} );
				var where = 'kept' !== part.kind && -1 !== keptAt ? keptAt : parts.length;
				parts.splice( where, 0, part );
				setTemplate( parts, '[data-ff-tpl-add]' );
			}
		} );

		$( '#ace-ff-cancel', modal ).addEventListener( 'click', function () {
			if ( dlg ) {
				dlg.dismiss();
			}
		} );
		$( '#ace-ff-save', modal ).addEventListener( 'click', function () {
			var patch = state.open && state.draft ? words.patchFor( state.open, state.draft ) : null;
			if ( ! patch ) {
				return;
			}
			var target = patch.target;
			delete patch.target;
			save( target, patch, function () {
				if ( dlg ) {
					dlg.close();
				}
				state.open = null;
			} );
		} );

		/* Turning on filling from the table, after the estimate ------------- */

		$( '#ace-ff-fill-cancel', fillModal ).addEventListener( 'click', function () {
			if ( fillDlg ) {
				fillDlg.dismiss();
			}
		} );
		$( '#ace-ff-fill-confirm', fillModal ).addEventListener( 'click', function () {
			if ( ! fillTarget ) {
				return;
			}
			save( fillTarget.target, { fillEmpty: true }, function () {
				if ( fillDlg ) {
					fillDlg.close();
				}
			} );
		} );

		box.addEventListener( 'click', function ( e ) {
			var open = e.target.closest( '[data-ff-open]' );
			if ( open ) {
				var f = byTarget( open.getAttribute( 'data-ff-open' ) );
				if ( f ) {
					openField( f );
				}
				return;
			}
			if ( e.target.closest( '[data-ff-relearn]' ) ) {
				core.post( 'field_formats_relearn', {} ).then( function () {
					state.relearnSent = true;
					render();
					core.toast( t( 'ffRelearnSent', 'Learning your formats again. This takes a minute or two.' ), 'success' );
				} ).catch( core.errorToast );
				return;
			}
			if ( e.target.closest( '[data-ff-check]' ) ) {
				load();
			}
		} );

		box.addEventListener( 'change', function ( e ) {
			var el = e.target;
			if ( ! el.hasAttribute( 'data-ff-fill' ) ) {
				return;
			}
			var f = byTarget( el.getAttribute( 'data-ff-fill' ) );
			if ( ! f ) {
				return;
			}
			if ( ! el.checked ) {
				save( f.target, { fillEmpty: false } );
				return;
			}
			// The estimate is accepted first; the switch shows what is saved.
			el.checked = false;
			fillTarget = f;
			$( '#ace-ff-fill-title', fillModal ).textContent = fmt( 'ffFillTitle', 'Fill %s when it is empty?', [ f.label ] );
			$( '#ace-ff-fill-body', fillModal ).innerHTML = '<p>' + core.esc( words.fillSentence( f, o ) || '' ) + '</p>' +
				'<p>' + core.esc( t( 'ffFillReview', 'Each fill is written in this field\'s format, only from what your product data says, and goes to Review first. Nothing reaches your store until you approve it.' ) ) + '</p>' +
				( f.fill && f.fill.checkFacts ? '<p>' + core.esc( t( 'ffFillCheckFacts', 'Because this field tells a story or a meaning, its fills are marked Check the facts in Review.' ) ) + '</p>' : '' );
			fillDlg = core.dialog( fillModal );
		} );

		load();
	}

	/*
	 * ChatGPT product file card (4.34.0). Reads the URL Rezolve Ai serves for
	 * this store through agencoen_openai_feed. Four states, kept apart: not
	 * connected (the PHP renders a link instead and nothing is fetched),
	 * reading, read (a URL, or the platform saying there is none), and failed.
	 * A failed read never shows as "no file": it says so and offers a retry.
	 */
	function initOpenaiFeed() {
		var card = $( '#ace-openai-feed' );
		if ( ! card || '1' !== card.getAttribute( 'data-connected' ) ) {
			return;
		}
		var input = $( '#ace-openai-feed-url', card );
		var copyBtn = $( '#ace-openai-feed-copy', card );
		var status = $( '#ace-openai-feed-status', card );
		if ( ! input || ! copyBtn || ! status ) {
			return;
		}

		function show( url, message ) {
			input.value = url || '';
			copyBtn.disabled = ! url;
			status.textContent = message;
		}

		function load() {
			show( '', t( 'openaiFeedLoading', 'Reading the URL from Rezolve Ai…' ) );
			core.post( 'openai_feed', {} ).then( function ( d ) {
				if ( d && d.url ) {
					show( d.url, t( 'openaiFeedReady', 'Copy this URL into ChatGPT Business Hub. The file lists the products you have synced to Rezolve Ai.' ) );
				} else {
					show( '', t( 'openaiFeedNone', 'Rezolve Ai does not serve a ChatGPT product file for this store.' ) );
				}
			} ).catch( function () {
				show( '', t( 'openaiFeedFailed', 'Could not read your ChatGPT product file URL from Rezolve Ai.' ) + ' ' );
				var retry = document.createElement( 'button' );
				retry.type = 'button';
				retry.className = 'ace-btn ace-btn-secondary ace-btn-sm';
				retry.textContent = t( 'openaiFeedRetry', 'Try again' );
				retry.addEventListener( 'click', load );
				status.appendChild( retry );
			} );
		}

		function copied() {
			core.toast( t( 'openaiFeedCopied', 'ChatGPT product file URL copied.' ), 'success' );
		}

		function copyBySelection() {
			input.focus();
			input.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			if ( ok ) {
				copied();
			} else {
				core.toast( t( 'copyManually', 'The URL is selected. Press Ctrl+C, or Cmd+C on a Mac, to copy it.' ), 'info' );
			}
		}

		copyBtn.addEventListener( 'click', function () {
			var url = input.value;
			if ( ! url ) {
				return;
			}
			if ( window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText ) {
				window.navigator.clipboard.writeText( url ).then( copied, copyBySelection );
			} else {
				copyBySelection();
			}
		} );

		load();
	}

	function init() {
		var root = $( '#ace-settings' );
		if ( ! root ) {
			return; // Commerce screens can render the WooCommerce state instead.
		}

		populateAuthors();
		initOpenaiFeed();
		initCustomFields();
		initFieldFormats();
		initDestinations();

		var save = $( '#ace-save-settings' );
		if ( save ) {
			save.addEventListener( 'click', function () {
				core.setBusy( save, true, t( 'saving', 'Saving…' ) );
				core.post( 'save_settings', collect() ).then( function () {
					core.setBusy( save, false );
					core.toast( t( 'settingsSaved', 'Settings saved.' ), 'success' );
				} ).catch( function ( err ) {
					core.setBusy( save, false );
					core.errorToast( err );
				} );
			} );
		}

		var regen = $( '#ace-feed-regenerate' );
		if ( regen ) {
			regen.addEventListener( 'click', function () {
				core.setBusy( regen, true );
				core.post( 'regenerate_feed_token', {} ).then( function ( d ) {
					core.setBusy( regen, false );
					var urls = ( d && d.urls ) || {};
					Object.keys( urls ).forEach( function ( channel ) {
						var input = $( 'input.ace-select-on-click[data-channel="' + channel + '"]' );
						if ( input ) {
							input.value = urls[ channel ];
						}
					} );
					core.toast( t( 'feedUrlsRegenerated', 'Feed URLs regenerated. Update them wherever they are configured.' ), 'success' );
				} ).catch( function ( err ) {
					core.setBusy( regen, false );
					core.errorToast( err );
				} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', init );
} )();
