/**
 * Agentic Commerce Engine — dynamic (server-rendered) editor blocks.
 * No build step: registers directly against the wp.* globals.
 */
( function ( blocks, element, serverSideRender ) {
	'use strict';
	if ( ! blocks || ! element || ! serverSideRender ) {
		return;
	}
	var el = element.createElement;

	function preview( name ) {
		return el( serverSideRender, { block: name } );
	}

	blocks.registerBlockType( 'agencoen/irl', {
		apiVersion: 2,
		title: 'ACE: IRL scenario cards',
		description: 'Real-life usage scenarios for this product (from ACE).',
		icon: 'images-alt2',
		category: 'widgets',
		edit: function () {
			return preview( 'agencoen/irl' );
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'agencoen/internal-links', {
		apiVersion: 2,
		title: 'ACE: Internal links',
		description: 'AI-suggested related product links (from ACE).',
		icon: 'admin-links',
		category: 'widgets',
		edit: function () {
			return preview( 'agencoen/internal-links' );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.serverSideRender );
