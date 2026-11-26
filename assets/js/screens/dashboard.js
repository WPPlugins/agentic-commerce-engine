/**
 * Rezolve Ai: Dashboard screen (v4).
 *
 * Boots from the inline window.agencoenBoot payload (localized by Assets):
 * KPI stat row, charts grid (coverage donut, score bars, applied-per-day
 * stacked area, AI bot hits sparkline, Share of Answers preview line,
 * credits gauge), and the quick actions (sync, enrich all).
 */
( function () {
	'use strict';

	var ns = window.agencoen || {};
	var core = ns.core;
	var charts = ns.charts;
	if ( ! core || ! charts ) {
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

	// Five score buckets, low to high, then grey for "Not scored".
	var SCORE_COLORS = [ '#f43f5e', '#f59e0b', '#eab308', '#84cc16', '#10b981', '#9ca3af' ];

	/* ------------------------------------------------------------------ */
	/* KPI stat row                                                        */
	/* ------------------------------------------------------------------ */

	function renderStats( d ) {
		var row = $( '#ace-stat-row' );
		if ( ! row ) {
			return;
		}
		var tiles = [];

		// Local-first: the crawler and article tiles read this site's own tables,
		// so they carry real numbers on a fresh install and lead the row.
		var bots = d.bots || {};
		var botTrend = null;
		if ( bots.prev > 0 ) {
			var botDiff = ( ( bots.total || 0 ) - bots.prev ) / bots.prev * 100;
			botTrend = { dir: botDiff < 0 ? 'down' : 'up', text: core.pct( Math.abs( botDiff ) ) };
		}
		tiles.push( core.statTile( {
			label: t( 'botHits', 'AI bot hits, 7 days' ),
			value: core.num( bots.total || 0 ),
			sub: t( 'vsPrior', 'vs the prior 7 days' ),
			trend: botTrend,
			accent: 'cyan'
		} ) );

		var content = d.content || {};
		tiles.push( core.statTile( {
			label: t( 'articlesPublished', 'Articles published' ),
			value: core.num( content.published || 0 ),
			sub: fmt( t( 'draftsWaiting', '%s drafts waiting' ), core.num( content.drafts || 0 ) ),
			accent: 'violet'
		} ) );

		if ( cfg.hasWoo ) {
			var counts = d.counts || {};
			var total = Math.max( 0, counts.total || 0 );
			var touched = ( counts.applied || 0 ) + ( counts.enriched || 0 ) + ( counts.queued || 0 ) + ( counts.failed || 0 );
			var cover = total > 0 ? Math.round( ( touched / total ) * 100 ) : 0;
			tiles.push( core.statTile( {
				label: t( 'productsOptimized', 'Products optimized' ),
				value: cover + '%',
				sub: fmt( t( 'ofProducts', '%1$s of %2$s products' ), core.num( touched ), core.num( total ) ),
				accent: 'emerald'
			} ) );
		} else {
			tiles.push( core.statTile( {
				label: t( 'productsOptimized', 'Products optimized' ),
				value: t( 'noWoo', 'Not installed' ),
				sub: t( 'noWooSub', 'Product optimization is available with WooCommerce.' ),
				accent: 'emerald'
			} ) );
		}

		// `aeo` is null until a research run has a snapshot. Once it exists, a
		// null rate means Rezolve Ai could not measure the brand (or is too old
		// to send the field): that is n/a, not a prompt to go and set things up.
		var aeo = d.aeo || null;
		if ( aeo && typeof aeo.yourMentionRatePct === 'number' ) {
			tiles.push( core.statTile( {
				label: t( 'yourBrandInAnswers', 'Your brand in AI answers' ),
				value: core.pct( aeo.yourMentionRatePct ),
				sub: t( 'yourBrandSub', 'of AI answers name your brand' ),
				accent: 'amber'
			} ) );
		} else if ( aeo ) {
			tiles.push( core.statTile( {
				label: t( 'yourBrandInAnswers', 'Your brand in AI answers' ),
				value: t( 'notAvailable', 'n/a' ),
				sub: t( 'notMeasuredRun', 'Could not be measured for this run.' ),
				accent: 'amber'
			} ) );
		} else {
			tiles.push( core.statTile( {
				label: t( 'yourBrandInAnswers', 'Your brand in AI answers' ),
				value: t( 'notMeasured', 'Not measured yet' ),
				sub: t( 'sovSetup', 'Run AI search research to measure this.' ),
				accent: 'amber'
			} ) );
		}

		// Credits belong to a Rezolve Ai account; without one there is nothing to show.
		if ( cfg.connected ) {
			var credits = d.credits || {};
			var creditCap = Math.max( credits.limit || 0, ( credits.used || 0 ) + ( credits.balance || 0 ) );
			tiles.push( core.statTile( {
				label: t( 'credits', 'Credits' ),
				value: core.num( credits.balance || 0 ),
				sub: fmt( t( 'creditsUsedOf', '%1$s used of %2$s' ), core.num( credits.used || 0 ), core.num( creditCap ) ),
				accent: 'indigo'
			} ) );
		}

		row.innerHTML = tiles.join( '' );
	}

	/* ------------------------------------------------------------------ */
	/* Charts                                                              */
	/* ------------------------------------------------------------------ */

	function productsUrl() {
		return ( cfg.adminUrl || 'admin.php' ) + '?page=agencoen-products';
	}

	function renderCharts( d ) {
		var ch = d.charts || {};
		var counts = d.counts || {};

		var coverageEl = $( '#ace-chart-coverage' );
		if ( coverageEl ) {
			var total = Math.max( 0, counts.total || 0 );
			var touched = ( counts.applied || 0 ) + ( counts.enriched || 0 ) + ( counts.queued || 0 ) + ( counts.failed || 0 );
			var cover = total > 0 ? Math.round( ( touched / total ) * 100 ) : 0;
			charts.donut(
				coverageEl,
				( ch.coverage || [] ).map( function ( seg ) {
					return { label: seg.label, value: seg.count, color: seg.color, key: seg.key };
				} ),
				{
					centerLabel: { value: cover + '%', label: t( 'optimized', 'optimized' ) },
					onClick: function ( slice ) {
						window.location = productsUrl() + '#status=' + ( slice.key || '' );
					}
				}
			);
		}

		var scoresEl = $( '#ace-chart-scores' );
		if ( scoresEl && ch.scores ) {
			var buckets = ch.scores.buckets || [];
			var hasScores = buckets.some( function ( v ) {
				return v > 0;
			} );
			if ( hasScores ) {
				charts.barsV( scoresEl, {
					labels: ch.scores.labels || [],
					values: buckets
				}, {
					height: 200,
					colors: SCORE_COLORS,
					labelHeader: t( 'scoreRange', 'Score range' ),
					valueLabel: t( 'productsCol', 'Products' )
				} );
			} else {
				scoresEl.innerHTML = '<div class="ace-chart-empty">' + core.esc( t( 'noData', 'No data yet' ) ) + '</div>';
			}
		}

		var activityEl = $( '#ace-chart-activity' );
		if ( activityEl ) {
			var activity = ch.activity || [];
			var hasActivity = activity.some( function ( p ) {
				return p.count > 0;
			} );
			if ( hasActivity ) {
				charts.area( activityEl, {
					series: [ {
						name: t( 'appliedPerDay', 'Products applied per day' ),
						color: charts.palette[ 0 ],
						points: activity.map( function ( p ) {
							return { x: String( p.date ).slice( 5 ), y: p.count };
						} )
					} ]
				}, { height: 220, showLegend: false } );
			} else {
				activityEl.innerHTML = '<div class="ace-chart-empty">' + core.esc( t( 'noData', 'No data yet' ) ) + '</div>';
			}
		}

		var botsEl = $( '#ace-spark-bots' );
		if ( botsEl ) {
			var byDay = ( d.bots && d.bots.byDay ) || [];
			charts.sparkline(
				botsEl,
				byDay.map( function ( p ) {
					return { x: String( p.day ).slice( 5 ), y: p.hits };
				} ),
				{ color: '#06b6d4', height: 48 }
			);
			var totalEl = $( '#ace-bots-total' );
			if ( totalEl ) {
				totalEl.textContent = core.num( ( d.bots && d.bots.total ) || 0 );
			}
		}

		var sovCard = $( '#ace-card-sov' );
		var sovEl = $( '#ace-chart-sov' );
		// Only runs where your brand was measured. A null rate is skipped, never
		// drawn as 0, and the snapshot's any-brand rate is never plotted here.
		var trendPoints = ( ( d.aeo && d.aeo.trend ) || [] ).filter( function ( p ) {
			return p && p.date && typeof p.yourMentionRatePct === 'number' && isFinite( p.yourMentionRatePct );
		} ).map( function ( p ) {
			return { x: core.fmtDate( p.date ), y: p.yourMentionRatePct };
		} );
		if ( sovCard && sovEl ) {
			if ( trendPoints.length ) {
				sovCard.hidden = false;
				charts.line( sovEl, {
					series: [ {
						name: t( 'yourBrandInAnswers', 'Your brand in AI answers' ),
						color: charts.palette[ 0 ],
						points: trendPoints
					} ]
				}, {
					height: 220,
					showLegend: false,
					yMax: 100,
					yFmt: function ( v ) {
						return core.pct( v );
					}
				} );
			} else {
				sovCard.hidden = true;
			}
		}

		var creditsEl = $( '#ace-chart-credits' );
		if ( creditsEl ) {
			var credits = d.credits || {};
			var creditCap = Math.max( credits.limit || 0, ( credits.used || 0 ) + ( credits.balance || 0 ) );
			charts.gauge( creditsEl, {
				value: credits.used || 0,
				max: Math.max( creditCap, 1 ),
				label: t( 'creditsUsed', 'credits used' )
			}, {} );
			var usedNote = $( '#ace-credits-used-note' );
			var capNote = $( '#ace-credits-cap-note' );
			if ( usedNote ) {
				usedNote.textContent = core.num( credits.used || 0 );
			}
			if ( capNote ) {
				capNote.textContent = core.num( creditCap );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Content-type picker (shared modal markup in the view)               */
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
			// A named group, so each checkbox is read with the heading it sits under.
			return '<div class="ace-types-group" role="group" aria-labelledby="ace-types-' + g + '"><div class="ace-types-grouptitle" id="ace-types-' + g + '">' + core.esc( title ) + '</div>' + items.map( function ( type ) {
				var rec = type.recommended ? ' <span class="ace-type-rec">' + core.esc( t( 'recommended', 'Recommended' ) ) + '</span>' : '';
				return '<label class="ace-type-item"><input type="checkbox" class="ace-type-cb" value="' + core.esc( type.key ) + '" data-requires="' + ( type.requires || [] ).join( ',' ) + '"' + ( selected[ type.key ] ? ' checked' : '' ) + '>' +
					'<span class="ace-type-main"><span class="ace-type-label">' + core.esc( type.label ) + rec + '</span>' +
					'<span class="ace-type-desc">' + core.esc( type.description ) + '</span></span></label>';
			} ).join( '' ) + '</div>';
		}

		list.innerHTML = group( 'onpage', t( 'onPage', 'On the product page' ) ) + group( 'catalog', t( 'productData', 'Product data' ) ) + group( 'feed', t( 'feeds', 'Channel feeds' ) );
		var errBox = $( '#ace-modal-error' );
		if ( errBox ) {
			errBox.style.display = 'none';
		}
		// Focus moves into the picker, stays there, and Escape, Cancel or the
		// backdrop put it back on the button that opened it.
		var dlg = core.dialog( modal );

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

		$( '#ace-modal-cancel' ).onclick = dlg.dismiss;
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
			dlg.close();
			onConfirm( chosen );
		};
	}

	/* Server-driven batch loop (enrich_batch) with the shared progress bar. */
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
				var remaining = d.remaining || 0;
				var total = done + remaining;
				var pctDone = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;
				var said = fmt( t( 'processing', 'Processing %1$s of %2$s…' ), core.num( done ), core.num( total ) );
				core.setProgress( fill, pctDone, said );
				if ( text ) {
					text.textContent = said;
				}
				if ( d.done || 0 === d.processed ) {
					if ( text ) {
						text.textContent = t( 'done', 'Done.' );
					}
					window.setTimeout( function () {
						if ( box ) {
							box.hidden = true;
						}
						core.setProgress( fill, 0 );
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
	/* Quick actions                                                       */
	/* ------------------------------------------------------------------ */

	function wireActions() {
		var sync = $( '#ace-sync-now' );
		if ( sync ) {
			sync.addEventListener( 'click', function () {
				core.setBusy( sync, true, t( 'syncing', 'Syncing…' ) );
				core.post( 'sync', {} ).then( function () {
					core.toast( t( 'syncDone', 'Sync complete.' ), 'success' );
					window.setTimeout( function () {
						window.location.reload();
					}, 900 );
				} ).catch( function ( err ) {
					core.setBusy( sync, false );
					core.errorToast( err );
				} );
			} );
		}

		var enrichAll = $( '#ace-enrich-all-dash' );
		if ( enrichAll ) {
			enrichAll.addEventListener( 'click', function () {
				openPicker( t( 'chooseTypes', 'Choose what to enrich' ), function ( types ) {
					drainServer( 'enrich_batch', { content_types: types }, function () {
						window.setTimeout( function () {
							window.location.reload();
						}, 1500 );
					} );
				} );
			} );
		}

		var refresh = $( '#ace-refresh-dash' );
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				core.setBusy( refresh, true );
				core.post( 'refresh_dashboard', { fresh: 1 } ).then( function ( d ) {
					core.setBusy( refresh, false );
					renderStats( d );
					renderCharts( d );
				} ).catch( function ( err ) {
					core.setBusy( refresh, false );
					core.errorToast( err );
				} );
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! $( '#ace-dashboard' ) ) {
			return; // Connect empty-state is showing.
		}
		var boot = window.agencoenBoot;
		if ( boot ) {
			renderStats( boot );
			renderCharts( boot );

			// The boot payload is served cache-only so the page paints without
			// any remote HTTP. When it says a remote block was stale or cold,
			// fill in the fresh numbers post-paint.
			var meta = boot.meta || {};
			if ( cfg.connected && ( meta.creditsStale || meta.aeoCold ) ) {
				core.post( 'refresh_dashboard', {} ).then( function ( d ) {
					renderStats( d );
					renderCharts( d );
				} ).catch( function () {
					// Leave the cached paint in place; the platform is likely
					// unreachable and the server is already backing off.
				} );
			}
		}
		wireActions();
	} );
} )();
