/**
 * Rezolve Ai: what the Custom fields table shows, as pure functions.
 *
 * A port of src/lib/custom-fields/listing-view.ts, which the web app and the
 * Shopify app use. The platform's test suite runs this file and that one over
 * the same inputs (src/lib/custom-fields/__tests__/listing-view.wp-parity.test.ts),
 * so all three screens order, filter and page a store's fields the same way.
 *
 * Order: fields being rewritten, then fields that could be, then the rest by
 * how many products carry them; left-out and not-used fields last. No
 * dependencies, and no DOM: settings.js does the drawing.
 */
( function ( root ) {
	'use strict';

	var TAB_IDS = [ 'all', 'used', 'rewriting', 'excluded', 'skipped' ];
	var PAGE_SIZE = 25;
	var STATUS_ORDER = { used: 0, excluded: 1, skipped: 2 };

	function counts( fields ) {
		var c = { all: fields.length, used: 0, rewriting: 0, excluded: 0, skipped: 0 };
		fields.forEach( function ( f ) {
			if ( Object.prototype.hasOwnProperty.call( c, f.status ) ) {
				c[ f.status ] += 1;
			}
			if ( 'used' === f.status && f.rewrite ) {
				c.rewriting += 1;
			}
		} );
		return c;
	}

	function rank( f ) {
		if ( 'used' !== f.status ) {
			return 3;
		}
		if ( f.rewrite ) {
			return 0;
		}
		return f.rewritable ? 1 : 2;
	}

	function sort( fields ) {
		return fields.slice().sort( function ( a, b ) {
			return ( STATUS_ORDER[ a.status ] - STATUS_ORDER[ b.status ] ) ||
				( rank( a ) - rank( b ) ) ||
				( b.products - a.products ) ||
				a.label.localeCompare( b.label ) ||
				a.id.localeCompare( b.id );
		} );
	}

	function inTab( f, tab ) {
		if ( 'all' === tab ) {
			return true;
		}
		if ( 'rewriting' === tab ) {
			return 'used' === f.status && !! f.rewrite;
		}
		return f.status === tab;
	}

	/** Case- and accent-insensitive, so "colour" finds "Colour" and "creme" finds "Crème". */
	function fold( text ) {
		return String( text ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
	}

	function filter( fields, view ) {
		var terms = fold( view.query || '' ).split( /\s+/ ).filter( Boolean );
		return sort( fields ).filter( function ( f ) {
			if ( ! inTab( f, view.tab ) ) {
				return false;
			}
			if ( ! terms.length ) {
				return true;
			}
			var haystack = fold( f.label + ' ' + f.kind + ' ' + f.sample );
			return terms.every( function ( term ) {
				return haystack.indexOf( term ) !== -1;
			} );
		} );
	}

	/** An emptied tab (its last field just moved) falls back to All, which is never hidden. */
	function activeTab( tab, c ) {
		return 'all' !== tab && 0 === c[ tab ] ? 'all' : tab;
	}

	function pageOf( rows, page, size ) {
		size = size || PAGE_SIZE;
		var lastPage = Math.max( 0, Math.ceil( rows.length / size ) - 1 );
		var current = Math.min( Math.max( 0, Math.floor( page ) ), lastPage );
		var slice = rows.slice( current * size, current * size + size );
		return {
			rows: slice,
			page: current,
			hasPrevious: current > 0,
			hasNext: current < lastPage,
			from: slice.length ? current * size + 1 : 0,
			to: current * size + slice.length
		};
	}

	/** Only a used or left-out field can change state; a skipped one never had a choice. */
	function isSelectable( f ) {
		return 'skipped' !== f.status;
	}

	function bulkActions( selected ) {
		var leaveOut = 0;
		var useAgain = 0;
		selected.forEach( function ( f ) {
			if ( 'used' === f.status ) {
				leaveOut += 1;
			} else if ( 'excluded' === f.status ) {
				useAgain += 1;
			}
		} );
		return { leaveOut: leaveOut, useAgain: useAgain };
	}

	root.agencoen = root.agencoen || {};
	root.agencoen.customFieldListing = {
		TAB_IDS: TAB_IDS,
		PAGE_SIZE: PAGE_SIZE,
		counts: counts,
		sort: sort,
		filter: filter,
		activeTab: activeTab,
		pageOf: pageOf,
		isSelectable: isSelectable,
		bulkActions: bulkActions
	};
} )( window );
