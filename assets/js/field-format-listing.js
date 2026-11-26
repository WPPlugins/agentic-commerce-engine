/**
 * Rezolve Ai: what the Field formats card says about each field, as pure
 * functions (4.28.0).
 *
 * A port of src/lib/field-format/listing-view.ts, which the web app and the
 * Shopify app use. The platform's test suite runs this file and that one over
 * the same inputs (src/lib/field-format/__tests__/listing-view.wp-parity.test.ts),
 * so all three screens describe a field the same way.
 *
 * Every string goes through `tr( key, fallback, values )` so the card can
 * translate it; without one the English fallback is returned, which is what
 * the parity test compares. No dependencies, and no DOM: settings.js draws.
 */
( function ( root ) {
	'use strict';

	function plainTr( key, fallback, values ) {
		var out = fallback;
		( values || [] ).forEach( function ( v, i ) {
			out = out.split( '%' + ( i + 1 ) + '$s' ).join( v ).replace( '%s', v );
		} );
		return out;
	}

	function opts( o ) {
		o = o || {};
		return {
			tr: o.tr || plainTr,
			num: o.num || function ( n ) {
				return Number( n ).toLocaleString( 'en-GB' );
			},
			date: o.date || function ( iso ) {
				return new Date( iso ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' } );
			}
		};
	}

	function draftOf( listing ) {
		var draft = {
			mode: listing.mode,
			locks: { links: listing.locks.links, specs: listing.locks.specs, kept: listing.locks.kept },
			fillEmpty: listing.fillEmpty
		};
		if ( listing.preset ) {
			draft.preset = listing.preset;
		}
		if ( listing.template && listing.template.parts && listing.template.parts.length ) {
			draft.template = listing.template.parts.map( copyPart );
		}
		return draft;
	}

	function copyPart( part ) {
		var out = {};
		Object.keys( part ).forEach( function ( k ) {
			out[ k ] = part[ k ];
		} );
		return out;
	}

	/** The patch that turns the saved choice into the draft, or null when nothing changed. */
	function patchFor( listing, draft ) {
		var patch = { target: listing.target };
		if ( 'custom' === draft.mode ) {
			var parts = draft.template || [];
			var saved = ( listing.template && listing.template.parts ) || [];
			var changed = JSON.stringify( parts ) !== JSON.stringify( saved );
			if ( ( 'custom' !== listing.mode || changed ) && parts.length ) {
				patch.mode = 'custom';
				patch.template = parts;
			}
		} else if ( draft.mode !== listing.mode || ( 'preset' === draft.mode && draft.preset !== listing.preset ) ) {
			patch.mode = draft.mode;
			if ( 'preset' === draft.mode && draft.preset ) {
				patch.preset = draft.preset;
			}
		}
		var locks = {};
		var changed = false;
		[ 'links', 'specs', 'kept' ].forEach( function ( k ) {
			if ( draft.locks[ k ] !== listing.locks[ k ] ) {
				locks[ k ] = draft.locks[ k ];
				changed = true;
			}
		} );
		if ( changed ) {
			patch.locks = locks;
		}
		if ( draft.fillEmpty !== listing.fillEmpty ) {
			patch.fillEmpty = draft.fillEmpty;
		}
		return Object.keys( patch ).length > 1 ? patch : null;
	}

	function kindLine( listing, o ) {
		var tr = opts( o ).tr;
		if ( 'description' === listing.kind ) {
			return tr( 'ffKindDescription', 'Product description' );
		}
		if ( 'destination' === listing.kind ) {
			return tr( 'ffKindDestination', 'Holds content Rezolve Ai places there' );
		}
		return listing.fixedList ? tr( 'ffKindList', 'Custom field with a list of values' ) : tr( 'ffKindField', 'Custom field' );
	}

	/** What a format was learned from, e.g. "812 products, 97% alike". */
	function learnedFromText( listing, o ) {
		var x = opts( o );
		var learned = listing.learned;
		if ( ! learned || 0 === learned.samples ) {
			return { main: x.tr( 'ffNotLearned', 'Not learned yet' ) };
		}
		var products = 1 === learned.samples ?
			x.tr( 'ffProductsOne', '%s product', [ x.num( learned.samples ) ] ) :
			x.tr( 'ffProductsMany', '%s products', [ x.num( learned.samples ) ] );
		if ( 'insufficient' === learned.confidence ) {
			return { main: products, sub: x.tr( 'ffNotAlike', 'Not alike enough to copy' ) };
		}
		return {
			main: x.tr( 'ffAlike', '%1$s, %2$s alike', [ products, String( Math.round( learned.consistency * 100 ) ) + '%' ] ),
			sub: x.tr( 'ffLearnedOn', 'Learned %s', [ x.date( learned.learnedAt ) ] )
		};
	}

	function modeLabel( listing, o ) {
		var tr = opts( o ).tr;
		if ( listing.fixedList ) {
			return tr( 'ffModeList', 'From its list' );
		}
		switch ( listing.mode ) {
			case 'match':
				return tr( 'ffModeMatch', 'Match my store' );
			case 'preset':
				for ( var i = 0; i < listing.presets.length; i++ ) {
					if ( listing.presets[ i ].slug === listing.preset ) {
						return listing.presets[ i ].name;
					}
				}
				return tr( 'ffModePreset', 'Preset' );
			case 'default':
				return tr( 'ffModeDefault', 'Rezolve Ai default' );
			default:
				return tr( 'ffModeCustom', 'Your own template' );
		}
	}

	/** "271 products": how many a fill would write. */
	function fillCount( listing, o ) {
		var x = opts( o );
		var n = listing.fill ? listing.fill.products : 0;
		return 1 === n ? x.tr( 'ffProductsOne', '%s product', [ x.num( n ) ] ) : x.tr( 'ffProductsMany', '%s products', [ x.num( n ) ] );
	}

	/** The estimate shown before filling is turned on. */
	function fillSentence( listing, o ) {
		var x = opts( o );
		var fill = listing.fill;
		if ( ! fill || ! fill.available ) {
			return null;
		}
		var credits = fill.credits.min === fill.credits.max ?
			x.tr( 'ffCredits', '%s credits', [ x.num( fill.credits.min ) ] ) :
			x.tr( 'ffCreditsRange', '%1$s to %2$s credits', [ x.num( fill.credits.min ), x.num( fill.credits.max ) ] );
		return x.tr(
			'ffFillSentence',
			'%1$s is missing on about %2$s of the types that usually have it. Filling them costs about %3$s, charged only as content is generated.',
			[ listing.label, fillCount( listing, o ), credits ]
		);
	}

	/** Which locks mean something for this field. */
	function locksFor( listing, o ) {
		var tr = opts( o ).tr;
		if ( 'single_line' === listing.shape || 'list' === listing.shape || listing.fixedList ) {
			return [];
		}
		var out = [ { key: 'links', label: tr( 'ffLockLinks', 'Links' ), hint: tr( 'ffLockLinksHint', 'Every link in the current text stays, word for word.' ) } ];
		var has = function ( kind ) {
			return listing.parts.some( function ( p ) {
				return p.kind === kind;
			} );
		};
		if ( 'description' === listing.kind || has( 'specs' ) ) {
			out.push( { key: 'specs', label: tr( 'ffLockSpecs', 'Details list' ), hint: tr( 'ffLockSpecsHint', 'The current list of details stays row for row.' ) } );
		}
		if ( has( 'kept' ) ) {
			out.push( { key: 'kept', label: tr( 'ffLockKept', 'Closing sentence' ), hint: tr( 'ffLockKeptHint', 'The sentence your values end with stays as it is.' ) } );
		}
		return out;
	}

	/** What a template part is called in the editor. */
	function templatePartTitle( part, o ) {
		var tr = opts( o ).tr;
		switch ( part.kind ) {
			case 'specs':
				return tr( 'ffPartSpecs', 'Details list' );
			case 'paragraphs':
				return tr( 'ffPartParagraphs', 'Paragraphs' );
			case 'bullets':
				return part.ordered ? tr( 'ffPartNumbered', 'Numbered points' ) : tr( 'ffPartBullets', 'Bullet points' );
			case 'heading':
				return tr( 'ffPartHeading', 'Heading' );
			case 'qa':
				return tr( 'ffPartQa', 'Questions and answers' );
			default:
				return tr( 'ffPartKept', 'Closing sentence' );
		}
	}

	/** The parts a merchant can add to this field's template, in the order offered. */
	function templateKindsFor( listing, parts ) {
		var kinds = [ 'paragraphs', 'bullets', 'heading', 'qa' ];
		var has = function ( kind ) {
			return parts.some( function ( p ) {
				return p.kind === kind;
			} );
		};
		if ( ! has( 'specs' ) ) {
			kinds.unshift( 'specs' );
		}
		if ( listing.template && listing.template.hasKept && ! has( 'kept' ) ) {
			kinds.push( 'kept' );
		}
		return kinds;
	}

	/** A new part with sensible counts. */
	function newTemplatePart( kind ) {
		switch ( kind ) {
			case 'specs':
				return { kind: kind, style: 'label_colon', min: 3, max: 6 };
			case 'paragraphs':
				return { kind: kind, min: 1, max: 2 };
			case 'bullets':
				return { kind: kind, ordered: false, min: 3, max: 6 };
			case 'heading':
				return { kind: kind, level: 3 };
			case 'qa':
				return { kind: kind, style: 'bold_question', min: 3, max: 5 };
			default:
				return { kind: 'kept' };
		}
	}

	/** Where a merchant's own template starts. */
	function starterTemplate( listing ) {
		if ( listing.template && listing.template.parts && listing.template.parts.length ) {
			return listing.template.parts.map( copyPart );
		}
		var parts = [];
		var hasSpecs = listing.parts.some( function ( p ) {
			return 'specs' === p.kind;
		} );
		if ( 'description' === listing.kind || hasSpecs ) {
			parts.push( newTemplatePart( 'specs' ) );
		}
		parts.push( newTemplatePart( 'paragraphs' ) );
		if ( listing.template && listing.template.hasKept ) {
			parts.push( newTemplatePart( 'kept' ) );
		}
		return parts;
	}

	root.agencoen = root.agencoen || {};
	root.agencoen.fieldFormatListing = {
		templatePartTitle: templatePartTitle,
		templateKindsFor: templateKindsFor,
		newTemplatePart: newTemplatePart,
		starterTemplate: starterTemplate,
		draftOf: draftOf,
		patchFor: patchFor,
		kindLine: kindLine,
		learnedFromText: learnedFromText,
		modeLabel: modeLabel,
		fillCount: fillCount,
		fillSentence: fillSentence,
		locksFor: locksFor
	};
} )( window );
