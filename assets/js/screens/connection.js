/**
 * Connection screen: health check and disconnect.
 *
 * The connect button itself is wired in ace-core.js, which loads on every ACE
 * screen, so pasting a key keeps working wherever the form is rendered.
 *
 * @package Agencoen
 */
( function () {
	'use strict';

	var ns = window.agencoen || {};
	var core = ns.core;
	var cfg = window.agencoenConfig || {};
	var i18n = cfg.i18n || {};

	if ( ! core ) {
		return;
	}

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function $( sel ) {
		return document.querySelector( sel );
	}

	function init() {
		if ( ! $( '#ace-connection' ) ) {
			return;
		}

		var health = $( '#ace-health-check' );
		if ( health ) {
			health.addEventListener( 'click', function () {
				core.setBusy( health, true );
				core.post( 'refresh_dashboard', { fresh: 1 } ).then( function ( d ) {
					core.setBusy( health, false );
					var balance = d.credits ? core.num( d.credits.balance ) : '0';
					core.toast( ( t( 'healthOk', 'Connection healthy. %s credits available.' ) ).replace( '%s', balance ), 'success' );
				} ).catch( function ( err ) {
					core.setBusy( health, false );
					core.errorToast( err );
				} );
			} );
		}

		var disconnect = $( '#ace-disconnect' );
		if ( disconnect ) {
			disconnect.addEventListener( 'click', function () {
				if ( ! window.confirm( t( 'confirmDisconnect', 'Disconnect this site from ACE? Your API key will be removed.' ) ) ) {
					return;
				}
				core.setBusy( disconnect, true );
				core.post( 'disconnect', {} ).then( function () {
					window.location.reload();
				} ).catch( function ( err ) {
					core.setBusy( disconnect, false );
					core.errorToast( err );
				} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', init );
} )();
