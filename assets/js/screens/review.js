/**
 * Rezolve Ai: Review screen (v4).
 *
 * Approval queue at catalog scale: pending/applied SegmentedTabs (hash-synced
 * via core.tabs), search, paged lists with lazy before/after diffs and
 * per-content-type previews, measured quality scores with re-score,
 * approve/dismiss/revert row actions, and a server-driven approve-all batch
 * with progress.
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
	/** core.esc leaves quotes alone, which is only safe outside an attribute. */
	function attr( s ) {
		return core.esc( s ).replace( /"/g, '&quot;' );
	}

	/* ------------------------------------------------------------------ */
	/* Quality scores                                                      */
	/*                                                                     */
	/* Every number here is one Rezolve Ai measured, read through the      */
	/* quality_get action. Until 4.17.0 this screen showed "Score 90" for  */
	/* every product, a literal nothing had measured. So nothing below     */
	/* defaults a value: a missing number says "Not scored", and a failed  */
	/* read says it failed rather than passing for "Not scored".           */
	/* ------------------------------------------------------------------ */

	/** Seconds between reads while a re-score is on its way, and when to stop. */
	var RESCORE_POLL_MS = 5000;
	var RESCORE_GIVE_UP_MS = 120000;

	function isScore( v ) {
		return 'number' === typeof v && isFinite( v );
	}

	/** The words the web review queue uses, at the same thresholds. */
	function qualityWord( score ) {
		if ( score >= 90 ) {
			return t( 'qualityExcellent', 'Excellent' );
		}
		if ( score >= 80 ) {
			return t( 'qualityVeryGood', 'Very Good' );
		}
		if ( score >= 70 ) {
			return t( 'qualityGood', 'Good' );
		}
		return t( 'qualityNeedsWork', 'Needs Improvement' );
	}

	function qualityTone( score ) {
		if ( score >= 90 ) {
			return 'excellent';
		}
		if ( score >= 80 ) {
			return 'verygood';
		}
		if ( score >= 70 ) {
			return 'good';
		}
		return 'weak';
	}

	/**
	 * The chip beside a product name. No chip at all until something has been
	 * read, because "Not scored" before the first read would be a guess.
	 */
	function scoreChipHtml( known, overall ) {
		if ( isScore( overall ) ) {
			return core.chip( 'info', fmt( t( 'scoreLabel', 'Score %s' ), overall ) );
		}
		return known ? core.chip( 'neutral', t( 'notScored', 'Not scored' ) ) : '';
	}

	function tileHtml( label, value, emptyText ) {
		var body = isScore( value )
			? '<div class="ace-q-tile-value">' + core.esc( value ) + '%</div><div class="ace-q-tile-word">' + core.esc( qualityWord( value ) ) + '</div>'
			: '<div class="ace-q-tile-empty">' + core.esc( emptyText ) + '</div>';
		return '<div class="ace-q-tile ' + ( isScore( value ) ? 'is-' + qualityTone( value ) : 'is-none' ) + '">' +
			'<div class="ace-q-tile-label">' + core.esc( label ) + '</div>' + body + '</div>';
	}

	function rescoreButtonHtml( group, label, busy ) {
		return '<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-q-rescore" data-key="' + attr( group.key ) + '" data-fields="' + attr( ( group.fields || [] ).join( ',' ) ) + '"' + ( busy ? ' disabled' : '' ) + '>' +
			core.esc( busy ? t( 'qualityScoring', 'Scoring…' ) : label ) + '</button>';
	}

	/** One scored piece of content: its state line, four tiles and reasoning. */
	function qualityGroupHtml( group, busy ) {
		var q = group.quality || {};
		var state = q.state || 'unscored';
		var html = '<div class="ace-q-group"><div class="ace-q-group-title">' + core.esc( group.label || '' ) + '</div>';

		if ( ! group.found ) {
			return html + '<div class="ace-q-banner"><p>' + core.esc( t( 'qualityNotReceived', 'Not scored yet. Rezolve Ai has not received this content for review yet, so there is nothing to score. It is sent automatically, usually within the hour.' ) ) + '</p></div></div>';
		}

		if ( 'unscored' === state ) {
			html += '<div class="ace-q-banner"><p>' + core.esc( t( 'qualityUnscored', 'Not scored yet. Scores usually appear within a few minutes of the content being saved.' ) ) + '</p>' +
				rescoreButtonHtml( group, t( 'qualityScoreNow', 'Score now' ), busy ) + '</div>';
		} else {
			if ( 'stale' === state || 'outdated' === state ) {
				var banner = 'stale' === state
					? t( 'qualityStale', 'This content was edited after it was scored, so these scores may be out of date. A new score is on its way.' )
					: t( 'qualityOutdated', 'These scores were measured under an earlier scoring rubric. An updated score is on its way.' );
				html += '<div class="ace-q-banner is-warning"><p>' + core.esc( banner ) + '</p>' +
					rescoreButtonHtml( group, t( 'qualityRescoreNow', 'Re-score now' ), busy ) + '</div>';
			} else {
				var date = q.scoredAt ? core.fmtDate( q.scoredAt ) : '';
				var line = isScore( q.overall )
					? fmt( t( 'qualityScored', 'Scored %1$s · Overall %2$s' ), date, q.overall )
					: fmt( t( 'qualityScoredOn', 'Scored %s' ), date );
				html += '<div class="ace-q-meta"><p>' + core.esc( line ) + '</p>' +
					rescoreButtonHtml( group, t( 'qualityRescore', 'Re-score' ), busy ) + '</div>';
			}

			var notScored = t( 'notScored', 'Not scored' );
			html += '<div class="ace-q-tiles">' +
				tileHtml( t( 'qualityContent', 'Content Quality' ), q.contentQuality, notScored ) +
				tileHtml( t( 'qualitySeo', 'SEO Score' ), q.seoScore, notScored ) +
				tileHtml( t( 'qualityAi', 'AI Readability' ), q.aiReadability, notScored ) +
				// No voice on the project is a different fact from a voice that
				// was not scored, and the fix for it is different too.
				tileHtml( t( 'qualityBrand', 'Brand Voice' ), q.brandVoice, false === q.brandVoiceConfigured ? t( 'qualityNoVoice', 'No brand voice set' ) : notScored ) +
				'</div>';

			if ( q.reasoning ) {
				html += '<details class="ace-q-why"><summary>' + core.esc( t( 'qualityWhy', 'Why these scores' ) ) + '</summary><p>' + core.esc( q.reasoning ) + '</p></details>';
			}
		}

		if ( group.differentVersion ) {
			html += '<p class="ace-q-note">' + core.esc( t( 'qualityOtherVersion', 'These scores describe a different version of this content.' ) ) + '</p>';
		}
		if ( busy || group.scoringQueued ) {
			html += '<p class="ace-q-note">' + core.esc( t( 'qualityQueued', 'A new score is being measured. This usually takes under a minute.' ) ) + '</p>';
		}
		return html + '</div>';
	}

	/**
	 * The quality section of an expanded product.
	 *
	 * @param {Object|undefined} view    quality_get's view, or undefined while loading.
	 * @param {Object}           waiting Group keys with a re-score in flight.
	 */
	function qualityHtml( view, waiting ) {
		var title = '<div class="ace-ct-title">' + core.esc( t( 'qualityTitle', 'Quality scores' ) ) + '</div>';
		if ( ! view ) {
			return title + '<div class="ace-skeleton ace-skeleton-text" style="width:60%"></div>';
		}

		var notice = '';
		if ( view.offline ) {
			notice = t( 'qualityOffline', 'Connect this site to Rezolve Ai to see up to date quality scores.' );
		} else if ( view.error ) {
			notice = view.known
				? t( 'qualityCached', 'These are the last scores read from Rezolve Ai. They could not be refreshed just now.' )
				: t( 'qualityUnavailable', 'Quality scores could not be loaded just now. Try again in a moment.' );
		}
		var html = title + ( notice ? '<div class="ace-q-banner is-warning"><p>' + core.esc( notice ) + '</p></div>' : '' );

		if ( ! view.known ) {
			return html;
		}
		var groups = view.groups || [];
		if ( ! groups.length ) {
			return html + '<p class="ace-muted">' + core.esc( t( 'qualityNothing', 'There is no content on this product to score.' ) ) + '</p>';
		}
		return html + groups.map( function ( group ) {
			return qualityGroupHtml( group, !! ( waiting && waiting[ group.key ] ) );
		} ).join( '' );
	}

	function findGroup( view, key ) {
		var groups = ( view && view.groups ) || [];
		for ( var i = 0; i < groups.length; i++ ) {
			if ( groups[ i ].key === key ) {
				return groups[ i ];
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Diff + per-type content rendering                                   */
	/* ------------------------------------------------------------------ */

	/** Flat fields with an editor. `short_description` is derived, never edited. */
	var EDIT_FLAT = { title: 1, description: 1, seo_title: 1, seo_description: 1 };

	/**
	 * One section of an expanded product, the unit an editor swaps in and out.
	 * `data-type` is the plugin field name the save action takes.
	 */
	function sectionHtml( type, inner ) {
		return '<div class="ace-edit-section" data-type="' + attr( type ) + '">' + inner + '</div>';
	}

	/** "Edit" on a waiting proposal, "Edit live" on applied content. */
	function editButtonHtml( type, mode ) {
		if ( ! mode ) {
			return '';
		}
		var label = 'live' === mode ? t( 'editLive', 'Edit live' ) : t( 'edit', 'Edit' );
		return '<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-edit-open" data-type="' + attr( type ) + '">' + core.esc( label ) + '</button>';
	}

	function diffRowHtml( row, mode ) {
		var clean = ( 'description' === row.key || 'short_description' === row.key );
		var edit = EDIT_FLAT[ row.key ] ? editButtonHtml( row.key, mode ) : '';
		var empty = '<em class="ace-muted">' + core.esc( t( 'emptyValue', '(empty)' ) ) + '</em>';
		if ( 'live' === mode ) {
			// Applied: what shoppers see now, which is what "Edit live" opens with.
			var live = row.before || row.after;
			return '<div class="ace-diff is-live"><div class="ace-diff-label">' + core.esc( row.label ) + edit + '</div>' +
				'<div class="ace-diff-col ace-diff-live"><div class="ace-diff-cap">' + core.esc( t( 'editLiveCap', 'Live on your store' ) ) + '</div><div class="ace-diff-text">' + ( live ? core.esc( clean ? stripTags( live ) : live ) : empty ) + '</div></div></div>';
		}
		var before = row.before ? core.esc( clean ? stripTags( row.before ) : row.before ) : empty;
		var after = row.after ? core.esc( clean ? stripTags( row.after ) : row.after ) : '';
		return '<div class="ace-diff ' + ( row.changed ? 'is-changed' : 'is-same' ) + '">' +
			'<div class="ace-diff-label">' + core.esc( row.label ) + ( row.changed ? '' : ' <span class="ace-diff-tag">' + core.esc( t( 'noChange', 'no change' ) ) + '</span>' ) + edit + '</div>' +
			'<div class="ace-diff-cols"><div class="ace-diff-col ace-diff-before"><div class="ace-diff-cap">' + core.esc( t( 'current', 'Current' ) ) + '</div><div class="ace-diff-text">' + before + '</div></div>' +
			'<div class="ace-diff-col ace-diff-after"><div class="ace-diff-cap">' + core.esc( t( 'aiSuggestion', 'AI suggestion' ) ) + '</div><div class="ace-diff-text">' + after + '</div></div></div></div>';
	}

	function renderDiffHtml( diff, mode ) {
		return '<div class="ace-diffs">' + diff.map( function ( row ) {
			return sectionHtml( row.key, diffRowHtml( row, mode ) );
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
	function renderExtrasHtml( extras, mode ) {
		if ( ! extras || 'object' !== typeof extras ) {
			return '';
		}
		var html = '';
		var block = function ( title, body, type ) {
			return sectionHtml( type, '<div class="ace-ct-block"><div class="ace-ct-head"><div class="ace-ct-title">' + core.esc( title ) + '</div>' + editButtonHtml( type, mode ) + '</div>' + body + '</div>' );
		};
		var chip = function ( text, tone ) {
			return '<span class="ace-ct-chip ace-ct-chip--' + tone + '">' + core.esc( text ) + '</span>';
		};

		if ( Array.isArray( extras.highlights ) && extras.highlights.length ) {
			html += block( t( 'highlightsTitle', 'Highlights' ), '<ul class="ace-ct-links">' + extras.highlights.map( function ( h ) {
				return '<li>' + core.esc( h ) + '</li>';
			} ).join( '' ) + '</ul>', 'highlights' );
		}

		if ( Array.isArray( extras.qa ) && extras.qa.length ) {
			html += block( t( 'qaTitle', 'Product Q&A' ), '<dl class="ace-ct-qa">' + extras.qa.map( function ( pair ) {
				return '<dt>' + core.esc( pair.question ) + '</dt><dd>' + core.esc( pair.answer ) + '</dd>';
			} ).join( '' ) + '</dl>', 'product_qa' );
		}

		if ( Array.isArray( extras.attributes ) && extras.attributes.length ) {
			html += block( t( 'attributesTitle', 'Product attributes' ), '<table class="ace-ct-table"><tbody>' + extras.attributes.map( function ( a ) {
				var action = chip( t( 'attrAdd', 'Will be added' ), 'ok' );
				if ( 'exists' === a.action ) {
					action = chip( t( 'attrExists', 'Already on the product, kept' ), 'muted' );
				} else if ( 'owned' === a.action ) {
					action = chip( t( 'attrOwned', 'On your store' ), 'ok' );
				}
				return '<tr><th scope="row">' + core.esc( a.name ) + '</th><td>' + core.esc( ( a.values || [] ).join( ', ' ) ) + '</td><td>' + action + '</td></tr>';
			} ).join( '' ) + '</tbody></table>', 'product_attributes' );
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
			} ).join( '' ) + '</tbody></table>', 'image_alt' );
		}

		return html;
	}

	/** The whole expanded body for one product's last read. */
	function bodyHtml( d ) {
		return '<div class="ace-quality"></div>' +
			renderDiffHtml( d.diff || [], d.mode ) +
			sectionHtml( '_content', renderContentHtml( d.content || {} ) ) +
			renderExtrasHtml( d.extras || {}, d.mode );
	}

	/* ------------------------------------------------------------------ */
	/* Editing (4.18.0)                                                    */
	/*                                                                     */
	/* Every value typed here is checked again on the server, which       */
	/* answers with row indexes for anything it refuses, so the counters  */
	/* below are guidance and never the gate. The description uses the    */
	/* classic editor, and every TinyMCE instance is removed with          */
	/* wp.editor.remove() before its DOM goes: a removed node with a live  */
	/* instance leaks it and breaks the next editor opened on that id.     */
	/* ------------------------------------------------------------------ */

	var editorSeq = 0;

	function limit( group, key, fallback ) {
		var l = cfg.editLimits && cfg.editLimits[ group ];
		return l && 'number' === typeof l[ key ] ? l[ key ] : fallback;
	}

	/** Characters as a person counts them, matching the server. */
	function charLen( s ) {
		s = String( s == null ? '' : s );
		return Array.from ? Array.from( s ).length : s.length;
	}

	function hasWpEditor() {
		return !! ( window.wp && window.wp.editor && 'function' === typeof window.wp.editor.initialize );
	}

	/** Remove every classic editor instance inside a node, before the node goes. */
	function removeEditors( container ) {
		if ( ! container ) {
			return;
		}
		Array.prototype.forEach.call( container.querySelectorAll( 'textarea[data-wpeditor]' ), function ( area ) {
			if ( window.wp && window.wp.editor && 'function' === typeof window.wp.editor.remove ) {
				window.wp.editor.remove( area.id );
			}
			area.removeAttribute( 'data-wpeditor' );
		} );
	}

	function fieldHtml( part, value, max, rec, label, multiline ) {
		var common = ' class="ace-input ace-edit-input" data-part="' + attr( part ) + '" data-max="' + attr( max ) + '"' +
			( rec ? ' data-rec="' + attr( rec ) + '"' : '' ) +
			' aria-label="' + attr( label ) + '" placeholder="' + attr( label ) + '"';
		var field = multiline
			? '<textarea rows="3"' + common + '>' + core.esc( value ) + '</textarea>'
			: '<input type="text"' + common + ' value="' + attr( value ) + '">';
		return '<div class="ace-edit-field">' + field + '<span class="ace-edit-count" aria-live="polite"></span></div>';
	}

	function rowToolsHtml( reorder ) {
		var html = '<div class="ace-edit-tools">';
		if ( reorder ) {
			html += '<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-edit-up" aria-label="' + attr( t( 'editMoveUp', 'Move up' ) ) + '" title="' + attr( t( 'editMoveUp', 'Move up' ) ) + '">&uarr;</button>' +
				'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-edit-down" aria-label="' + attr( t( 'editMoveDown', 'Move down' ) ) + '" title="' + attr( t( 'editMoveDown', 'Move down' ) ) + '">&darr;</button>';
		}
		return html + '<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-edit-remove">' + core.esc( t( 'editRemove', 'Remove' ) ) + '</button></div>';
	}

	/** One editable row. Rows are sent in DOM order, so a server index is a DOM index. */
	function rowHtml( type, row ) {
		row = row || {};
		var err = '<div class="ace-edit-rowerr" aria-live="polite"></div>';
		if ( 'highlights' === type ) {
			return '<li class="ace-edit-row">' + fieldHtml( 'text', row.text || '', limit( 'highlights', 'maxChars', 300 ), 0, t( 'editHighlight', 'Highlight' ) ) + rowToolsHtml( true ) + err + '</li>';
		}
		if ( 'product_qa' === type ) {
			return '<li class="ace-edit-row ace-edit-row-qa"><div class="ace-edit-pair">' +
				fieldHtml( 'question', row.question || '', limit( 'qa', 'maxQuestionChars', 300 ), 0, t( 'editQuestion', 'Question' ) ) +
				fieldHtml( 'answer', row.answer || '', limit( 'qa', 'maxAnswerChars', 2000 ), 0, t( 'editAnswer', 'Answer' ), true ) +
				'</div>' + rowToolsHtml( true ) + err + '</li>';
		}
		if ( 'product_attributes' === type ) {
			return '<li class="ace-edit-row" data-source="' + attr( row.source || '' ) + '"><div class="ace-edit-pair ace-edit-pair-inline">' +
				fieldHtml( 'name', row.name || '', limit( 'attributes', 'maxNameChars', 200 ), 0, t( 'editSpecName', 'Name' ) ) +
				fieldHtml( 'value', row.value || '', limit( 'attributes', 'maxValueChars', 200 ), 0, t( 'editSpecValue', 'Value' ) ) +
				'</div>' + rowToolsHtml( false ) + err + '</li>';
		}
		// image_alt: bound to a picture, so no add, remove or reorder.
		var thumb = row.thumb ? '<img src="' + attr( row.thumb ) + '" alt="" width="48" height="48" loading="lazy">' : '<span class="ace-edit-nothumb"></span>';
		var note = '';
		if ( 'unmatched' === row.action ) {
			note = '<p class="ace-edit-note">' + core.esc( t( 'editAltGone', 'This image is no longer on the product, so its alt text is not written.' ) ) + '</p>';
		} else if ( row.current ) {
			note = '<p class="ace-edit-note ace-muted">' + core.esc( t( 'currentAlt', 'Current alt text' ) ) + ': ' + core.esc( row.current ) + '</p>';
		}
		return '<li class="ace-edit-row ace-edit-row-alt" data-url="' + attr( row.imageUrl || '' ) + '">' + thumb + '<div class="ace-edit-pair">' +
			fieldHtml( 'alt', row.proposed || '', limit( 'alt', 'maxChars', 512 ), limit( 'alt', 'recommendedChars', 125 ), t( 'editAltFor', 'Alt text for this image' ) ) +
			note + '</div>' + err + '</li>';
	}

	var ROW_LIMITS = {
		highlights: [ 'highlights', 'maxItems', 10 ],
		product_qa: [ 'qa', 'maxPairs', 20 ],
		product_attributes: [ 'attributes', 'maxRows', 20 ],
	};

	function diffRowFor( d, key ) {
		var rows = d.diff || [];
		for ( var i = 0; i < rows.length; i++ ) {
			if ( rows[ i ].key === key ) {
				return rows[ i ];
			}
		}
		return null;
	}

	/** The inline editor for one section, shaped like what the storefront shows. */
	function editorHtml( type, d, domId ) {
		var live = 'live' === d.mode;
		var extras = d.extras || {};
		var label = '';
		var body = '';
		var rows = [];
		var addLabel = '';

		if ( EDIT_FLAT[ type ] ) {
			var diffRow = diffRowFor( d, type ) || {};
			var value = live ? ( diffRow.before || diffRow.after || '' ) : ( diffRow.after || '' );
			label = diffRow.label || '';
			if ( 'description' === type ) {
				body = '<textarea id="' + attr( domId ) + '" class="ace-edit-wpeditor" rows="14">' + core.esc( value ) + '</textarea>';
			} else if ( 'title' === type ) {
				body = fieldHtml( 'value', value, limit( 'title', 'maxChars', 255 ), limit( 'title', 'recommendedChars', 70 ), label );
			} else if ( 'seo_title' === type ) {
				body = fieldHtml( 'value', value, limit( 'seoTitle', 'maxChars', 255 ), limit( 'seoTitle', 'recommendedChars', 60 ), label );
			} else {
				body = fieldHtml( 'value', value, limit( 'seoDescription', 'maxChars', 1000 ), limit( 'seoDescription', 'recommendedChars', 160 ), label, true );
			}
		} else {
			if ( 'highlights' === type ) {
				label = t( 'highlightsTitle', 'Highlights' );
				addLabel = t( 'editAddHighlight', 'Add a highlight' );
				rows = ( extras.highlights || [] ).map( function ( text ) {
					return { text: text };
				} );
			} else if ( 'product_qa' === type ) {
				label = t( 'qaTitle', 'Product Q&A' );
				addLabel = t( 'editAddQuestion', 'Add a question' );
				rows = extras.qa || [];
			} else if ( 'product_attributes' === type ) {
				label = t( 'attributesTitle', 'Product attributes' );
				addLabel = t( 'editAddSpec', 'Add a specification' );
				rows = extras.attributePairs || [];
			} else {
				label = t( 'imageAltTitle', 'Image alt text' );
				rows = extras.imageAlt || [];
				body = '<p class="ace-edit-note ace-muted">' + core.esc( t( 'editAltHint', 'Leave an image blank to keep its current alt text.' ) ) + '</p>';
			}
			body += '<ol class="ace-edit-rows">' + rows.map( function ( row ) {
				return rowHtml( type, row );
			} ).join( '' ) + '</ol>';
			if ( addLabel ) {
				body += '<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-edit-add">' + core.esc( addLabel ) + '</button>';
			}
		}

		return '<div class="ace-editor" data-type="' + attr( type ) + '">' +
			'<div class="ace-ct-head"><div class="ace-ct-title">' + core.esc( label ) + '</div></div>' +
			( live ? '<p class="ace-edit-livenote">' + core.esc( t( 'editLiveNote', 'This is live on your store. Saving republishes it.' ) ) + '</p>' : '' ) +
			'<div class="ace-edit-errors" role="alert" hidden></div>' +
			body +
			'<div class="ace-edit-actions">' +
			'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-edit-cancel">' + core.esc( t( 'cancel', 'Cancel' ) ) + '</button>' +
			'<button type="button" class="ace-btn ace-btn-primary ace-btn-sm ace-edit-save">' + core.esc( live ? t( 'editSaveLive', 'Save and republish' ) : t( 'editSave', 'Save' ) ) + '</button>' +
			'</div></div>';
	}

	function updateCounter( input ) {
		var box = input.parentNode ? input.parentNode.querySelector( '.ace-edit-count' ) : null;
		if ( ! box ) {
			return;
		}
		var n = charLen( input.value );
		var max = parseInt( input.getAttribute( 'data-max' ), 10 ) || 0;
		var rec = parseInt( input.getAttribute( 'data-rec' ), 10 ) || 0;
		var over = max > 0 && n > max;
		box.textContent = ( rec && ! over )
			? fmt( t( 'editCountRec', '%1$s characters, %2$s recommended' ), core.num( n ), core.num( rec ) )
			: fmt( t( 'editCount', '%1$s of %2$s characters' ), core.num( n ), core.num( max ) );
		box.classList.toggle( 'is-over', over );
		box.classList.toggle( 'is-warn', ! over && rec > 0 && n > rec );
	}

	function updateAddButton( editor ) {
		var type = editor.getAttribute( 'data-type' );
		var add = editor.querySelector( '.ace-edit-add' );
		var cap = ROW_LIMITS[ type ];
		if ( add && cap ) {
			add.disabled = editor.querySelectorAll( '.ace-edit-row' ).length >= limit( cap[ 0 ], cap[ 1 ], cap[ 2 ] );
		}
	}

	/** What the save action receives. Lists travel as JSON: core.post sends flat values only. */
	function readEditorValue( editor, type ) {
		if ( 'description' === type ) {
			var area = editor.querySelector( 'textarea.ace-edit-wpeditor' );
			if ( area && area.getAttribute( 'data-wpeditor' ) && window.wp && window.wp.editor && 'function' === typeof window.wp.editor.getContent ) {
				var html = window.wp.editor.getContent( area.id ) || '';
				// With wpautop on, the editor hands back paragraphs as blank
				// lines. Put the <p> tags back, so the store and Rezolve Ai
				// receive the same HTML the merchant saw.
				return 'function' === typeof window.wp.editor.autop ? window.wp.editor.autop( html ) : html;
			}
			return area ? area.value : '';
		}
		if ( EDIT_FLAT[ type ] ) {
			var input = editor.querySelector( '.ace-edit-input' );
			return input ? input.value : '';
		}
		var part = function ( row, name ) {
			var el = row.querySelector( '[data-part="' + name + '"]' );
			return el ? el.value : '';
		};
		var list = Array.prototype.map.call( editor.querySelectorAll( '.ace-edit-row' ), function ( row ) {
			if ( 'highlights' === type ) {
				return part( row, 'text' );
			}
			if ( 'product_qa' === type ) {
				return { question: part( row, 'question' ), answer: part( row, 'answer' ) };
			}
			if ( 'product_attributes' === type ) {
				var spec = { name: part( row, 'name' ), value: part( row, 'value' ) };
				if ( row.getAttribute( 'data-source' ) ) {
					spec.source = row.getAttribute( 'data-source' );
				}
				return spec;
			}
			return { imageUrl: row.getAttribute( 'data-url' ) || '', alt: part( row, 'alt' ) };
		} );
		return JSON.stringify( list );
	}

	/** Mark what the server refused: rows by index, the rest above the editor. */
	function showEditErrors( editor, errors, message ) {
		Array.prototype.forEach.call( editor.querySelectorAll( '.is-error' ), function ( el ) {
			el.classList.remove( 'is-error' );
			el.removeAttribute( 'aria-invalid' );
		} );
		Array.prototype.forEach.call( editor.querySelectorAll( '.ace-edit-rowerr' ), function ( el ) {
			el.textContent = '';
		} );
		var rows = editor.querySelectorAll( '.ace-edit-row' );
		var general = [];
		( errors || [] ).forEach( function ( error ) {
			var row = 'number' === typeof error.index ? rows[ error.index ] : null;
			if ( ! row ) {
				general.push( error.message );
				var only = editor.querySelector( '.ace-edit-input' );
				if ( only && ! rows.length ) {
					only.classList.add( 'is-error' );
					only.setAttribute( 'aria-invalid', 'true' );
				}
				return;
			}
			row.classList.add( 'is-error' );
			var field = error.part ? row.querySelector( '[data-part="' + attr( error.part ) + '"]' ) : row.querySelector( '.ace-edit-input' );
			if ( field ) {
				field.classList.add( 'is-error' );
				field.setAttribute( 'aria-invalid', 'true' );
			}
			var slot = row.querySelector( '.ace-edit-rowerr' );
			if ( slot ) {
				slot.textContent = ( slot.textContent ? slot.textContent + ' ' : '' ) + error.message;
			}
		} );
		if ( ! ( errors || [] ).length && message ) {
			general.push( message );
		}
		var box = editor.querySelector( '.ace-edit-errors' );
		if ( box ) {
			box.textContent = general.join( ' ' );
			box.hidden = ! general.length;
		}
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

		// Product id => the last quality view read, and => group keys with a
		// re-score in flight ({ before: scoredAt, started: ms }).
		var quality = {};
		var waiting = {};
		var pollTimers = {};

		// Product id => the last review_diff (or save) payload, the one source
		// every section and editor of that product renders from.
		var details = {};

		function hasOpenEditor( item ) {
			return !! item.querySelector( '.ace-edit-section.is-editing' );
		}

		/** Unsaved edits are the merchant's work: ask before a row action drops them. */
		function confirmLeavingEdits( item ) {
			return ! hasOpenEditor( item ) || window.confirm( t( 'editUnsaved', 'This product has edits you have not saved. Continue without them?' ) );
		}

		/** Take a row off the screen, releasing any classic editor inside it first. */
		function dropItem( item ) {
			removeEditors( item );
			if ( item.parentNode ) {
				item.parentNode.removeChild( item );
			}
		}

		/** Re-render every section of one product that is not being edited. */
		function refreshSections( item, id ) {
			var body = $( '.ace-rev-body', item );
			if ( ! body || ! details[ id ] ) {
				return;
			}
			var fresh = document.createElement( 'div' );
			fresh.innerHTML = bodyHtml( details[ id ] );
			Array.prototype.forEach.call( body.querySelectorAll( '.ace-edit-section' ), function ( section ) {
				if ( section.classList.contains( 'is-editing' ) ) {
					return;
				}
				var next = fresh.querySelector( '.ace-edit-section[data-type="' + attr( section.getAttribute( 'data-type' ) ) + '"]' );
				if ( next ) {
					section.parentNode.replaceChild( next, section );
				}
			} );
		}

		function openEditor( section, id ) {
			var d = details[ id ];
			if ( ! d || ! d.mode ) {
				return;
			}
			var type = section.getAttribute( 'data-type' );
			var domId = 'ace-edit-' + String( id ).replace( /[^0-9]/g, '' ) + '-' + ( ++editorSeq );
			section.classList.add( 'is-editing' );
			section.innerHTML = editorHtml( type, d, domId );
			var editor = $( '.ace-editor', section );
			Array.prototype.forEach.call( section.querySelectorAll( '.ace-edit-input' ), updateCounter );
			updateAddButton( editor );
			if ( 'description' === type && hasWpEditor() ) {
				window.wp.editor.initialize( domId, {
					tinymce: {
						wpautop: true,
						toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
						toolbar2: '',
					},
					quicktags: true,
					mediaButtons: false,
				} );
				var area = document.getElementById( domId );
				if ( area ) {
					area.setAttribute( 'data-wpeditor', '1' );
				}
			}
			var first = $( '.ace-edit-input', section );
			if ( first ) {
				first.focus();
			}
		}

		function closeEditor( item, section, id ) {
			removeEditors( section );
			section.classList.remove( 'is-editing' );
			refreshSections( item, id );
		}

		function saveEditor( item, section, id, btn ) {
			var d = details[ id ];
			var editor = $( '.ace-editor', section );
			if ( ! d || ! editor ) {
				return;
			}
			var type = section.getAttribute( 'data-type' );
			core.setBusy( btn, true, t( 'saving', 'Saving…' ) );
			core.post( 'review_save_edit', {
				product_id: id,
				type: type,
				mode: d.mode,
				value: readEditorValue( editor, type ),
			} ).then( function ( res ) {
				details[ id ] = { mode: res.mode, diff: res.diff || [], extras: res.extras || {}, content: res.content || {} };
				closeEditor( item, section, id );
				var message = 'live' === res.mode ? t( 'editSavedLive', 'Saved and republished to your store.' ) : t( 'editSaved', 'Saved. Approving applies your edited version.' );
				if ( res.kept && res.kept.length ) {
					message += ' ' + fmt( t( 'editKeptAttrs', 'Kept as they are, because you added them yourself: %s' ), res.kept.join( ', ' ) );
				}
				core.toast( message, 'success' );
			} ).catch( function ( err ) {
				core.setBusy( btn, false );
				if ( err && Array.isArray( err.errors ) ) {
					showEditErrors( editor, err.errors, err.message );
					core.toast( err.message || t( 'editFix', 'Some of this needs fixing before it can be saved.' ), 'error' );
					return;
				}
				core.errorToast( err );
			} );
		}

		function itemsFor( id ) {
			return Array.prototype.slice.call( root.querySelectorAll( '.ace-rev-item[data-id="' + attr( id ) + '"]' ) );
		}

		function renderQuality( id ) {
			var key = String( id );
			itemsFor( key ).forEach( function ( item ) {
				var section = $( '.ace-quality', item );
				if ( section ) {
					section.innerHTML = qualityHtml( quality[ key ], waiting[ key ] );
				}
			} );
		}

		function applyQuality( view ) {
			var key = String( view.id );
			quality[ key ] = view;
			// A failed read with nothing cached leaves the chip as the list drew
			// it, rather than replacing a real cached score with nothing.
			if ( view.known || ! view.error ) {
				itemsFor( key ).forEach( function ( item ) {
					var chip = $( '.ace-rev-score', item );
					if ( chip ) {
						chip.innerHTML = scoreChipHtml( view.known, view.overall );
					}
				} );
			}
			renderQuality( key );
		}

		function loadQuality( ids ) {
			if ( ! ids.length ) {
				return;
			}
			core.post( 'quality_get', { ids: ids } ).then( function ( d ) {
				( d.products || [] ).forEach( applyQuality );
			} ).catch( function ( err ) {
				ids.forEach( function ( id ) {
					if ( ! quality[ id ] ) {
						quality[ id ] = { id: id, known: false, overall: null, groups: [], error: ( err && err.message ) || true };
					}
					renderQuality( id );
				} );
			} );
		}

		/**
		 * A re-score is settled once its group's scoredAt moves, or abandoned
		 * after RESCORE_GIVE_UP_MS. The score still lands on the platform either
		 * way; this only stops the screen asking.
		 */
		function settleRescores( id, view ) {
			var pending = waiting[ id ] || {};
			Object.keys( pending ).forEach( function ( key ) {
				var group = findGroup( view, key );
				var scoredAt = group && group.quality ? group.quality.scoredAt : null;
				if ( scoredAt && scoredAt !== pending[ key ].before ) {
					delete pending[ key ];
				} else if ( Date.now() - pending[ key ].started > RESCORE_GIVE_UP_MS ) {
					delete pending[ key ];
					core.toast( t( 'qualityTimedOut', 'The new score is taking longer than usual. It will appear here once it is ready.' ), 'info' );
				}
			} );
		}

		function pollRescore( id ) {
			if ( pollTimers[ id ] ) {
				return;
			}
			pollTimers[ id ] = window.setTimeout( function () {
				pollTimers[ id ] = null;
				if ( ! itemsFor( id ).length ) {
					// Approved or dismissed meanwhile: nothing left to update.
					delete waiting[ id ];
					return;
				}
				core.post( 'quality_get', { ids: [ id ] } ).then( function ( d ) {
					var view = ( d.products || [] )[ 0 ];
					if ( view ) {
						settleRescores( id, view );
						applyQuality( view );
					}
				} ).catch( function () {
					/* transient read error; the next tick retries */
				} ).then( function () {
					if ( waiting[ id ] && Object.keys( waiting[ id ] ).length ) {
						pollRescore( id );
					}
				} );
			}, RESCORE_POLL_MS );
		}

		function itemHtml( item ) {
			var chips = ( item.fields || [] ).map( function ( f ) {
				return '<span class="ace-fieldchip">' + core.esc( f ) + '</span>';
			} ).join( '' );
			var q = item.quality || {};
			var score = '<span class="ace-rev-score">' + scoreChipHtml( !! q.known, q.overall ) + '</span>';
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
					quality = {};
					removeEditors( list );
					list.innerHTML = d.items.length ? html : '<div class="ace-empty"><p>' + core.esc( t( 'noPending', 'Nothing to review right now.' ) ) + '</p></div>';
				} else {
					list.insertAdjacentHTML( 'beforeend', html );
				}
				$( '#ace-review-more' ).hidden = d.page >= d.totalPages;
				var count = $( '#ace-count-pending' );
				if ( count ) {
					count.textContent = core.num( d.total );
				}
				// The list painted cached chips; now read what is current.
				loadQuality( d.items.map( function ( item ) {
					return String( item.id );
				} ) );
			} ).catch( core.errorToast );
		}

		function loadApplied( reset ) {
			if ( reset ) {
				applied.page = 1;
				core.skeleton( appliedList, 'text' );
			}
			core.post( 'products', { page: applied.page, status: 'applied' } ).then( function ( d ) {
				// Expandable since 4.18.0, so what is live can be edited in place.
				var html = d.rows.map( function ( row ) {
					return '<div class="ace-rev-item ace-rev-applied" data-id="' + attr( row.id ) + '"><div class="ace-rev-head">' +
						'<button type="button" class="ace-rev-toggle" aria-label="' + attr( t( 'expand', 'Expand' ) ) + '"><span class="ace-caret">&rsaquo;</span></button>' +
						'<div class="ace-rev-main"><div class="ace-rev-title">' + core.esc( row.name ) + ' ' + core.chip( 'success', t( 'statusApplied', 'Applied' ) ) + '</div></div>' +
						'<div class="ace-rev-actions"><button type="button" class="ace-btn ace-btn-secondary ace-btn-sm ace-rev-revert">' + core.esc( t( 'revertToOriginal', 'Revert to original' ) ) + '</button></div></div>' +
						'<div class="ace-rev-body" hidden></div></div>';
				} ).join( '' );
				if ( 1 === applied.page ) {
					removeEditors( appliedList );
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
							details[ id ] = { mode: d.mode || '', diff: d.diff || [], extras: d.extras || {}, content: d.content || {} };
							body.innerHTML = bodyHtml( details[ id ] );
							body.setAttribute( 'data-loaded', '1' );
							renderQuality( id );
							// Applied rows are not in the pending list's quality read.
							if ( item.classList.contains( 'ace-rev-applied' ) && ! quality[ String( id ) ] ) {
								loadQuality( [ String( id ) ] );
							}
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

			/* Editors */
			var section = btn.closest( '.ace-edit-section' );
			var row = btn.closest( '.ace-edit-row' );
			if ( btn.classList.contains( 'ace-edit-open' ) && section ) {
				openEditor( section, id );
				return;
			}
			if ( btn.classList.contains( 'ace-edit-cancel' ) && section ) {
				closeEditor( item, section, id );
				return;
			}
			if ( btn.classList.contains( 'ace-edit-save' ) && section ) {
				saveEditor( item, section, id, btn );
				return;
			}
			if ( btn.classList.contains( 'ace-edit-add' ) && section ) {
				var editor = $( '.ace-editor', section );
				var rows = $( '.ace-edit-rows', section );
				if ( editor && rows ) {
					rows.insertAdjacentHTML( 'beforeend', rowHtml( editor.getAttribute( 'data-type' ), {} ) );
					var added = rows.lastElementChild;
					Array.prototype.forEach.call( added.querySelectorAll( '.ace-edit-input' ), updateCounter );
					updateAddButton( editor );
					var input = $( '.ace-edit-input', added );
					if ( input ) {
						input.focus();
					}
				}
				return;
			}
			if ( row && btn.classList.contains( 'ace-edit-remove' ) ) {
				var owner = btn.closest( '.ace-editor' );
				row.parentNode.removeChild( row );
				if ( owner ) {
					updateAddButton( owner );
				}
				return;
			}
			if ( row && btn.classList.contains( 'ace-edit-up' ) ) {
				if ( row.previousElementSibling ) {
					row.parentNode.insertBefore( row, row.previousElementSibling );
				}
				return;
			}
			if ( row && btn.classList.contains( 'ace-edit-down' ) ) {
				if ( row.nextElementSibling ) {
					row.parentNode.insertBefore( row.nextElementSibling, row );
				}
				return;
			}

			if ( btn.classList.contains( 'ace-rev-approve' ) ) {
				if ( ! confirmLeavingEdits( item ) ) {
					return;
				}
				core.setBusy( btn, true, t( 'applying', 'Applying…' ) );
				core.post( 'approve', { product_id: id } ).then( function () {
					core.toast( t( 'applied', 'Applied to your product.' ), 'success' );
					dropItem( item );
					bumpCount( '#ace-count-pending', -1 );
					bumpCount( '#ace-count-applied', 1 );
				} ).catch( function ( err ) {
					core.setBusy( btn, false );
					core.errorToast( err );
				} );
			} else if ( btn.classList.contains( 'ace-rev-dismiss' ) ) {
				if ( ! confirmLeavingEdits( item ) ) {
					return;
				}
				core.setBusy( btn, true );
				core.post( 'dismiss', { product_id: id } ).then( function () {
					core.toast( t( 'dismissed', 'Dismissed.' ), 'info' );
					dropItem( item );
					// Dismissed leaves the queue without becoming applied.
					bumpCount( '#ace-count-pending', -1 );
				} ).catch( function ( err ) {
					core.setBusy( btn, false );
					core.errorToast( err );
				} );
			} else if ( btn.classList.contains( 'ace-q-rescore' ) ) {
				var groupKey = btn.getAttribute( 'data-key' ) || '';
				var fields = ( btn.getAttribute( 'data-fields' ) || '' ).split( ',' ).filter( function ( f ) {
					return '' !== f;
				} );
				var current = findGroup( quality[ id ], groupKey );
				var before = current && current.quality ? current.quality.scoredAt : null;
				core.setBusy( btn, true, t( 'qualityScoring', 'Scoring…' ) );
				core.post( 'quality_rescore', { product_id: id, fields: fields } ).then( function ( d ) {
					waiting[ id ] = waiting[ id ] || {};
					waiting[ id ][ groupKey ] = { before: before, started: Date.now() };
					if ( d.product ) {
						applyQuality( d.product );
					} else {
						renderQuality( id );
					}
					pollRescore( id );
				} ).catch( function ( err ) {
					core.setBusy( btn, false );
					core.errorToast( err );
				} );
			} else if ( btn.classList.contains( 'ace-rev-revert' ) ) {
				if ( ! confirmLeavingEdits( item ) ) {
					return;
				}
				core.setBusy( btn, true );
				core.post( 'revert', { product_id: id } ).then( function () {
					core.toast( t( 'reverted', 'Reverted to the original.' ), 'success' );
					dropItem( item );
					// Reverting is the only row action on the applied list.
					bumpCount( '#ace-count-applied', -1 );
				} ).catch( function ( err ) {
					core.setBusy( btn, false );
					core.errorToast( err );
				} );
			}
		} );

		// Character counters on every editor field.
		root.addEventListener( 'input', function ( e ) {
			if ( e.target && e.target.classList && e.target.classList.contains( 'ace-edit-input' ) ) {
				updateCounter( e.target );
			}
		} );

		loadPending( true );
	}

	document.addEventListener( 'DOMContentLoaded', init );
} )();
