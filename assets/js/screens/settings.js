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

	function init() {
		var root = $( '#ace-settings' );
		if ( ! root ) {
			return; // Commerce screens can render the WooCommerce state instead.
		}

		populateAuthors();

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
