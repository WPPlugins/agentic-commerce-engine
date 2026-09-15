/**
 * Rezolve Ai — dynamic (server-rendered) editor blocks.
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
		title: 'Rezolve Ai: IRL scenario cards',
		description: 'Real-life usage scenarios for this product (from Rezolve Ai).',
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
		title: 'Rezolve Ai: Internal links',
		description: 'AI-suggested related product links (from Rezolve Ai).',
		icon: 'admin-links',
		category: 'widgets',
		edit: function () {
			return preview( 'agencoen/internal-links' );
		},
		save: function () {
			return null;
		}
	} );
	blocks.registerBlockType( 'agencoen/highlights', {
		apiVersion: 2,
		title: 'Rezolve Ai: Highlights',
		description: 'Short benefit bullets for this product (from Rezolve Ai).',
		icon: 'list-view',
		category: 'widgets',
		edit: function () {
			return preview( 'agencoen/highlights' );
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'agencoen/product-qa', {
		apiVersion: 2,
		title: 'Rezolve Ai: Product Q&A',
		description: 'Shopper questions and answers for this product (from Rezolve Ai).',
		icon: 'editor-help',
		category: 'widgets',
		edit: function () {
			return preview( 'agencoen/product-qa' );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.serverSideRender );
