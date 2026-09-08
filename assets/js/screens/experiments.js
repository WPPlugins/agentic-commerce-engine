/**
 * Experiments screen: the list, a per-experiment cockpit, and a five-step
 * create wizard.
 *
 * Hydrates admin/views/page-experiments.php over admin-ajax via
 * window.agencoen.core. Vanilla JS, no framework.
 *
 * THE ONE FORMATTING RULE THAT MATTERS
 * A result the platform will not stand behind arrives with a null point and a
 * null interval, and it must render as its reason with NO number. Zero percent
 * and "we could not measure this" are the same pixel otherwise, and only one of
 * them means the merchant should act. `lift()` returns words for a null, never
 * "0%".
 *
 * NOTHING HERE DECIDES ANYTHING. Whether a design is legal, whether the traffic
 * can support it, what a result means and how it is worded are all answered by
 * the platform, from one copy table shared with the web app and the Shopify
 * app. This file asks and formats.
 */
( function () {
	'use strict';

	function boot() {
		var agencoen = window.agencoen || {};
		var core = agencoen.core;
		var charts = agencoen.charts;
		var root = document.getElementById( 'ace-experiments' );
		if ( ! core || ! root ) { return; }

		var byId = function ( id ) { return document.getElementById( id ); };
		var esc = core.esc;

		/*
		 * Translation, the same way every other screen does it. This screen was
		 * the only one that hardcoded its English, so nothing on it could be
		 * translated through the .pot even though the rest of the plugin could.
		 */
		var i18n = ( window.agencoenConfig || {} ).i18n || {};
		function t( key, fallback ) { return i18n[ key ] || fallback; }

		/* Goals the platform can actually measure, per design. */
		var GOALS = {
			visitor_split: [
				{ id: 'checkout', label: 'Reached checkout' },
				{ id: 'revenue_per_visitor', label: 'Revenue per shopper' }
			],
			product_holdout: [
				{ id: 'sessions', label: 'Visits' },
				{ id: 'clicks', label: 'Shopping clicks' },
				{ id: 'ai_citations', label: 'AI citations' }
			]
		};

		var STATUS = {
			draft: { label: 'Not started', tone: 'info' },
			running: { label: 'Running', tone: 'success' },
			paused: { label: 'Paused', tone: 'warning' },
			released: { label: 'Control released', tone: 'info' },
			complete: { label: 'Finished', tone: 'neutral' },
			abandoned: { label: 'Discarded', tone: 'neutral' }
		};

		/**
		 * Health states, verbatim from the platform's `HealthState` union.
		 * `broken` is its word for a failed check; there is no `fail`. `unknown`
		 * is a GAP and takes the neutral tone precisely so it cannot read as a
		 * tick -- a check that has not run has not passed.
		 */
		var HEALTH_TONE = {
			ok: 'success',
			warn: 'warning',
			broken: 'error',
			unknown: 'neutral'
		};
		var HEALTH_WORD = {
			ok: 'Fine',
			warn: 'Needs attention',
			broken: 'Broken',
			unknown: 'Not checked yet'
		};

		/* Which lifecycle actions a status allows. Mirrors the platform table. */
		/**
		 * Banner tone per decision, verbatim from the platform's union.
		 *
		 * `critical` is the platform's word and is NOT a banner tone here, so it
		 * is mapped explicitly to `error`. An unmapped verdict falling through
		 * to neutral is exactly how a BROKEN health check once rendered in the
		 * same grey as one that passed, on two of the three surfaces.
		 */
		var DECISION_TONE = {
			success: 'success',
			warning: 'warning',
			critical: 'error',
			info: 'info',
			neutral: 'neutral'
		};

		/** Step states, verbatim from the platform's `StageState` union. */
		var STEP_TONE = {
			done: 'success',
			active: 'info',
			blocked: 'warning',
			pending: 'neutral'
		};
		var STEP_WORD = {
			done: 'Done',
			active: 'Now',
			blocked: 'Needs you',
			pending: 'Later'
		};

		var ACTIONS = {
			draft: [ 'start', 'abandon' ],
			running: [ 'pause', 'stop', 'abandon' ],
			paused: [ 'resume', 'stop', 'abandon' ],
			released: [ 'abandon' ]
		};
		var ACTION_LABEL = {
			start: 'Start now',
			pause: 'Pause',
			resume: 'Resume',
			stop: 'Stop',
			abandon: 'Discard'
		};

		function statusChip( status ) {
			return STATUS[ status ] || { label: status || 'Unknown', tone: 'neutral' };
		}

		/**
		 * A change as a percentage, or words.
		 *
		 * Never "0%" for a null: see the header. This is the single most
		 * important line in this file.
		 */
		function lift( point ) {
			if ( typeof point !== 'number' || ! isFinite( point ) ) {
				return 'Not readable yet';
			}
			var pct = Math.round( point * 1000 ) / 10;
			return ( pct > 0 ? '+' : '' ) + pct + '%';
		}

		function interval( lo, hi ) {
			if ( typeof lo !== 'number' || typeof hi !== 'number' ) { return null; }
			return lift( lo ) + ' to ' + lift( hi );
		}

		/**
		 * Minor units to a readable amount, in the MERCHANT's currency.
		 *
		 * The currency comes from the payload rather than from a site setting:
		 * it is whatever the storefront actually reported alongside the orders,
		 * and printing a different symbol over the same number would be a claim
		 * about money that nothing measured.
		 */
		function money( minor, currency ) {
			if ( typeof minor !== 'number' || ! isFinite( minor ) ) { return 'n/a'; }
			try {
				return ( minor / 100 ).toLocaleString( undefined, {
					style: 'currency',
					currency: currency || 'USD',
					maximumFractionDigits: 0
				} );
			} catch ( e ) {
				return ( Math.round( minor / 100 ) ) + ' ' + ( currency || '' );
			}
		}

		function rate( value ) {
			if ( typeof value !== 'number' || ! isFinite( value ) ) { return 'n/a'; }
			return ( Math.round( value * 1000 ) / 10 ) + '%';
		}

		/* ================================================================== */
		/* The list                                                           */
		/* ================================================================== */

		var listEl = byId( 'ace-exp-list' );

		function renderList( experiments ) {
			if ( ! experiments.length ) {
				listEl.innerHTML = '<div class="ace-empty">' +
					esc( 'No experiments yet. An experiment writes a second version of your product copy, shows it to some visitors and not others, and tells you which one sold more.' ) +
					'</div>';
				return;
			}

			var rank = function ( s ) {
				return s === 'running' ? 0 : s === 'paused' ? 1 : s === 'draft' ? 2 : 3;
			};
			experiments.sort( function ( a, b ) { return rank( a.status ) - rank( b.status ); } );

			var rows = experiments.map( function ( e ) {
				var chip = statusChip( e.status );
				var design = ( e.designs || [] ).indexOf( 'visitor_split' ) !== -1 ? 'By shopper' : 'By product';
				// A draft has no day count. "Day 0" would read as a running
				// test that has found nothing.
				var day = e.daysRunning === null || e.daysRunning === undefined
					? 'Not started'
					: 'Day ' + core.num( e.daysRunning );
				var products = ( e.treatedUnits || 0 ) + ( e.controlUnits || 0 );
				return '<tr>' +
					'<td><a href="#" class="ace-exp-open" data-id="' + esc( e.id ) + '">' + esc( e.name ) + '</a></td>' +
					'<td>' + core.chip( chip.tone, chip.label ) + '</td>' +
					'<td>' + esc( design ) + '</td>' +
					'<td>' + core.num( products ) + '</td>' +
					'<td>' + esc( day ) + '</td>' +
					'</tr>';
			} ).join( '' );

			listEl.innerHTML = '<table class="ace-table"><thead><tr>' +
				'<th>Name</th><th>Status</th><th>Split</th><th>Products</th><th>Progress</th>' +
				'</tr></thead><tbody>' + rows + '</tbody></table>';
		}

		function loadList( fresh ) {
			core.skeleton( listEl, 'table' );
			core.post( 'experiments_list', { fresh: fresh ? 1 : '' } )
				.then( function ( payload ) {
					renderList( ( payload && payload.experiments ) || [] );
				} )
				.catch( function ( err ) {
					listEl.innerHTML = '<div class="ace-empty">' +
						esc( ( err && err.message ) || 'Could not load your experiments.' ) + '</div>';
				} );
		}

		/* ================================================================== */
		/* The cockpit                                                        */
		/* ================================================================== */

		var detailEl = byId( 'ace-exp-detail' );
		var openId = null;

		function panel( name ) {
			Array.prototype.forEach.call( root.querySelectorAll( '.ace-panel' ), function ( el ) {
				el.hidden = el.getAttribute( 'data-panel' ) !== name;
			} );
		}

		function renderDetail( d ) {
			var e = d.experiment || {};
			var chip = statusChip( e.status );
			var cov = d.coverage || {};
			var html = '';

			html += '<h2>' + esc( e.name || '' ) + ' ' + core.chip( chip.tone, chip.label ) + '</h2>';
			html += '<p>' + esc( ( d.designSummaries || [] ).join( ' ' ) ) + '</p>';

			/*
			 * WHAT TO DO, FIRST.
			 *
			 * This is the health panel's conclusion when a check is broken: the
			 * platform's `decideExperiment` reads health BEFORE it reads any
			 * interval, so a merchant who reads only this line has still been
			 * told the thing that would have changed their mind. Every word and
			 * every button label comes from the platform, shared with the web
			 * app and the Shopify app, so all three cannot phrase a refusal
			 * differently and let the softest one through.
			 */
			var decision = d.decision;
			if ( decision ) {
				var tone = DECISION_TONE[ decision.tone ] || 'info';
				html += '<div class="ace-banner ace-banner-' + tone + '">';
				html += '<strong>' + esc( decision.headline ) + '</strong>';
				html += '<p>' + esc( decision.detail ) + '</p>';
				// The number, only when the verdict rests on one. A "keep
				// waiting" verdict has earned no figure, and printing the
				// current best guess beside it would be read as the answer.
				if ( decision.basis && typeof decision.basis.point === 'number' ) {
					// "between X to Y" is not English. `interval()` joins with
					// "to", which is right on its own and wrong after "between",
					// so the pair is built here rather than reused.
					var lo = decision.basis.lo;
					var hi = decision.basis.hi;
					var band = ( typeof lo === 'number' && typeof hi === 'number' )
						? t( 'likelyBetween', 'likely between' ) + ' ' + lift( lo ) + ' ' +
							t( 'andBetween', 'and' ) + ' ' + lift( hi )
						: '';
					html += '<p><strong>' + esc( lift( decision.basis.point ) ) + '</strong>' +
						( band ? ' ' + esc( band ) : '' ) + '</p>';
				}
				if ( decision.action && decision.action.kind === 'apply_winner' && decision.arm ) {
					html += '<p><button type="button" class="ace-btn ace-btn-primary ace-exp-apply" data-arm="' +
						esc( decision.arm ) + '" data-confirm="' + esc( decision.action.confirm || '' ) + '">' +
						esc( decision.action.label ) + '</button></p>';
				}
				html += '</div>';
			}

			/* Five steps, so "where is this and what is holding it up" needs no
			   reading. A blocked step names the thing to go and do. */
			var stage = d.stage;
			if ( stage && stage.steps && stage.steps.length ) {
				html += '<h3>' + esc( t( 'progress', 'Progress' ) ) + '</h3>';
				html += '<table class="ace-table"><tbody>' + stage.steps.map( function ( s ) {
					var stepTone = STEP_TONE[ s.state ] || 'neutral';
					var stepWord = t( 'step_' + s.state, STEP_WORD[ s.state ] || s.state );
					return '<tr><td>' + esc( s.label ) + '</td><td>' + core.chip( stepTone, stepWord ) +
						'</td><td>' + esc( s.detail ) + '</td></tr>';
				} ).join( '' ) + '</tbody></table>';
			} else if ( cov.enrolledProducts > 0 && cov.liveProducts < cov.enrolledProducts ) {
				html += '<p>' + esc( 'A second version is live on ' + core.num( cov.liveProducts ) +
					' of ' + core.num( cov.enrolledProducts ) + ' products.' ) + '</p>';
			}

			var actions = ACTIONS[ e.status ] || [];
			if ( actions.length ) {
				html += '<div class="ace-toolbar-actions">' + actions.map( function ( a ) {
					// Start is disabled until something is genuinely live. The
					// platform refuses it too; this is so the button explains
					// itself rather than failing after a click.
					var notReady = a === 'start' && ! ( cov.liveProducts > 0 );
					return '<button type="button" class="ace-btn ace-btn-sm ace-exp-act" data-act="' + a + '"' +
						( notReady ? ' disabled title="' + esc( 'No product in this test has a second version live yet.' ) + '"' : '' ) +
						'>' + esc( ACTION_LABEL[ a ] ) + '</button>';
				} ).join( ' ' ) + '</div>';
			}

			/* The drawn half. Containers only: `drawDetailCharts` fills them
			   after this HTML is in the DOM, because the kit measures the
			   container's width to size itself. */
			html += '<h3>' + esc( t( 'howEachVersion', 'How each version is doing' ) ) + '</h3>';
			html += '<div class="ace-chart" id="ace-exp-trend"><div class="ace-skeleton-chart"></div></div>';

			html += '<h3>' + esc( t( 'whenWillIKnow', 'When will I know?' ) ) + '</h3>';
			html += '<p>' + esc( ( d.projection && d.projection.detail ) ||
				t( 'projectionPending', 'Once both versions have been seen by enough shoppers, this shows the range narrowing and projects the day it becomes readable.' ) ) + '</p>';
			html += '<div class="ace-chart" id="ace-exp-band"></div>';

			html += '<h3>' + esc( t( 'whereDropOff', 'Where shoppers drop off' ) ) + '</h3>';
			html += '<div class="ace-chart" id="ace-exp-funnel"></div>';
			/* A step this site cannot count is left out of the chart rather than
			   drawn as a zero, the same way the money panel below is absent
			   rather than showing 0 when order value is not reported. Without
			   this sentence an absent row looks like a chart that lost a step. */
			if ( d.funnels && d.funnels.length &&
				( d.funnels[ 0 ].unmeasured || [] ).indexOf( 'carted' ) !== -1 ) {
				html += '<p class="ace-card-sub">' + esc( t( 'basketNotCounted',
					'The basket step is not shown because this site does not report it. Rezolve Ai 4.14.0 and later count it, and it appears here on its own once every version has been seen adding to a basket.' ) ) + '</p>';
			}

			html += '<h3>' + esc( t( 'measuredProperly', 'Is this being measured properly?' ) ) + '</h3>';
			html += '<div class="ace-grid-2">';
			html += '<div class="ace-chart" id="ace-exp-split"></div>';
			html += '<div><div class="ace-chart" id="ace-exp-coverage"></div>';
			html += '<p class="ace-card-sub" id="ace-exp-coverage-note" hidden></p></div>';
			html += '</div>';
			if ( d.split && d.split.unattributedVisitors > 0 ) {
				html += '<p>' + esc( core.num( d.split.unattributedVisitors ) + ' ' +
					t( 'unattributed', 'shoppers could not be tied to this test because another split was running at the same time, so they are left out rather than guessed at.' ) ) + '</p>';
			}

			/* Money, and only when the platform sent it. `money` is null unless
			   revenue was genuinely observed, so a missing panel here means
			   "your store does not report order value", not "you earned zero". */
			if ( d.money && d.money.arms && d.money.arms.length ) {
				html += '<h3>' + esc( t( 'whatItIsWorth', 'What each version is worth' ) ) + '</h3>';
				html += '<p>' + esc( t( 'whatItIsWorthSub', 'Revenue divided by shoppers, counted from your own store. Only shown because real order values were reported.' ) ) + '</p>';
				/* `annualValue`, NOT `projectedAnnualGainMinor`. The latter is
				   gains only and is kept for installs older than this one;
				   reading it here would drop every loss avoided, which is
				   usually the larger half of what testing is worth to a shop.
				   The sentence arrives in the payload with a {0} where the
				   amount goes, so this screen, the Shopify app and the web
				   cannot word the same figure differently. */
				if ( d.money.annualValue && typeof d.money.annualValue.minor === 'number' ) {
					var av = d.money.annualValue;
					html += '<p>' + core.chip(
						av.kind === 'gain_won' ? 'success' : 'info',
						String( av.sentence || '{0}' ).replace( '{0}', money( av.minor, d.money.currency ) )
					) + '</p>';
					if ( av.ruleOutSentence ) {
						html += '<p class="ace-muted">' + esc(
							String( av.ruleOutSentence ).replace( '{0}', money( av.loMinor, d.money.currency ) )
						) + '</p>';
					}
				}
				html += '<div class="ace-chart" id="ace-exp-money"></div>';
				html += '<table class="ace-table"><thead><tr><th>' + esc( t( 'version', 'Version' ) ) +
					'</th><th>' + esc( t( 'shoppers', 'Shoppers' ) ) +
					'</th><th>' + esc( t( 'revenue', 'Revenue' ) ) +
					'</th><th>' + esc( t( 'perShopper', 'Per shopper' ) ) + '</th></tr></thead><tbody>' +
					d.money.arms.map( function ( a ) {
						return '<tr><td>' + esc( a.armLabel || ( 'Version ' + String( a.arm ).toUpperCase() ) ) +
							'</td><td>' + core.num( a.visitors ) +
							'</td><td>' + esc( money( a.revenueMinor, d.money.currency ) ) +
							'</td><td>' + esc( a.revenuePerVisitor === null || a.revenuePerVisitor === undefined
								? '\u2014'
								: money( a.revenuePerVisitor, d.money.currency ) ) + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
			}

			/* HEALTH DETAIL. The banner already carried the verdict; this is the
			   working, kept below it so the five checks stay auditable. */
			var checks = ( d.health && d.health.checks ) || [];
			if ( checks.length ) {
				html += '<h3>' + esc( 'Can this be trusted?' ) + '</h3>';
				/* The trust summary heads the section: it answers the question
				   the heading asks, which the individual checks only answer
				   between them. `warning`, never `error`, for a low band --
				   low trust is a prompt to look at the setup before acting, not
				   a statement that something failed. `health` owns failure, and
				   giving both the same red would make a quiet concern read as
				   an outage. Guarded because an old plugin may be talking to a
				   platform that predates the field, or the other way round. */
				if ( d.trust && d.trust.headline ) {
					var trustTone = d.trust.band === 'high' ? 'success'
						: d.trust.band === 'low' ? 'warning'
						: d.trust.band === 'moderate' ? 'info' : 'neutral';
					html += '<p>' + core.chip( trustTone, d.trust.headline ) + '</p>';
					if ( d.trust.detail ) {
						html += '<p class="ace-muted">' + esc( d.trust.detail ) + '</p>';
					}
				}
				html += '<table class="ace-table"><tbody>' + checks.map( function ( c ) {
					// `broken` is the platform's word (assessHealth). `fail` is not
					// a state it can ever emit, and `critical` is not a chip tone --
					// together they put a BROKEN check in neutral grey, which is the
					// one rendering this file's header forbids.
					var tone = HEALTH_TONE[ c.state ] || 'neutral';
					var label = HEALTH_WORD[ c.state ] || c.state;
					return '<tr><td>' + esc( c.label ) + '</td><td>' + core.chip( tone, label ) +
						'</td><td>' + esc( c.detail ) + '</td></tr>';
				} ).join( '' ) + '</tbody></table>';
			}

			var exposures = d.exposures || [];
			if ( exposures.length ) {
				html += '<h3>' + esc( 'What each version was shown to' ) + '</h3>';
				html += '<table class="ace-table"><thead><tr><th>Version</th><th>Shoppers</th><th>Bought</th><th>Rate</th></tr></thead><tbody>' +
					exposures.map( function ( x ) {
						return '<tr><td>' + esc( x.armLabel || ( 'Version ' + String( x.arm ).toUpperCase() ) ) +
							'</td><td>' + core.num( x.visitors ) + '</td><td>' + core.num( x.converters ) +
							'</td><td>' + esc( rate( x.rate ) ) + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
			}

			var results = d.results || [];
			html += '<h3>' + esc( t( 'whatItFound', 'What it found' ) ) + '</h3>';
			if ( ! results.length ) {
				html += '<p>' + esc( t( 'nothingComputed', 'Nothing has been computed yet. Results are worked out overnight, once there is enough traffic for a comparison to mean anything.' ) ) + '</p>';
			} else {
				/* Plotted separately from the table: a result with no interval
				   cannot be a whisker. It still appears below, with its reason. */
				html += '<div class="ace-chart" id="ace-exp-forest"></div>';
				html += '<table class="ace-table"><thead><tr><th>' + esc( t( 'version', 'Version' ) ) +
					'</th><th>' + esc( t( 'change', 'Change' ) ) +
					'</th><th>' + esc( t( 'range', 'Range' ) ) +
					'</th><th>' + esc( t( 'reading', 'Reading' ) ) + '</th></tr></thead><tbody>' +
					results.map( function ( r ) {
						var range = interval( r.lo, r.hi );
						return '<tr><td>' + esc( r.armLabel || ( r.arm ? 'Version ' + String( r.arm ).toUpperCase() : 'Test group' ) ) +
							// A refusal renders as its reason and NO number.
							'</td><td>' + esc( lift( r.point ) ) +
							'</td><td>' + esc( range || 'n/a' ) +
							'</td><td>' + esc( r.refusal || r.claimChip || '' ) + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
			}

			/*
			 * The five words this feature cannot avoid, explained.
			 *
			 * Shipped by the platform rather than written here, so this screen
			 * and the two other surfaces cannot end up explaining a confidence
			 * interval three different ways, with the shortest and most
			 * confident one winning. Last on the page: reference material, not
			 * a finding.
			 */
			var glossary = d.glossary || [];
			if ( glossary.length ) {
				html += '<details class="ace-card"><summary class="ace-card-title">' +
					esc( t( 'whatWordsMean', 'What these words mean' ) ) + '</summary>';
				html += '<table class="ace-table"><tbody>' + glossary.map( function ( g ) {
					return '<tr><td>' + esc( g.term ) + '</td><td>' + esc( g.plain ) + '</td></tr>';
				} ).join( '' ) + '</tbody></table></details>';
			}

			detailEl.innerHTML = html;
			drawDetailCharts( d );
		}

		/* ------------------------------------------------------------------ */
		/* The drawn half                                                      */
		/* ------------------------------------------------------------------ */

		/**
		 * Every chart on the cockpit, from the payload the platform already
		 * shaped. Nothing here computes a rate, an interval or a verdict: those
		 * live in src/lib/experiments/, shared with the web app and the Shopify
		 * app, precisely so this file cannot quietly disagree with them.
		 *
		 * A container with nothing to draw is LEFT ALONE rather than handed an
		 * empty axis. `renderEmpty` in the kit says "no data yet"; an axis with
		 * no line on it says the measurement came back as nothing, which is the
		 * one thing this feature exists not to say.
		 */
		function drawDetailCharts( d ) {
			if ( ! charts ) { return; }

			var exposures = d.exposures || [];
			function armLabel( arm ) {
				for ( var i = 0; i < exposures.length; i++ ) {
					if ( exposures[ i ].arm === arm ) {
						return exposures[ i ].armLabel || ( 'Version ' + String( arm ).toUpperCase() );
					}
				}
				return 'Version ' + String( arm ).toUpperCase();
			}
			function pctFmt( v ) {
				if ( typeof v !== 'number' || ! isFinite( v ) ) { return 'n/a'; }
				return ( Math.round( v * 10 ) / 10 ) + '%';
			}

			/* 1. Trend. Cumulative, not daily: a few hundred shoppers a day
			      makes the daily rate swing on noise alone, and a merchant
			      watching that swing will stop a healthy test. */
			var trendEl = byId( 'ace-exp-trend' );
			var timeline = d.timeline || {};
			var visitor = timeline.visitor;
			var holdout = timeline.holdout;
			if ( trendEl && visitor && visitor.hasTraffic ) {
				charts.line( trendEl, {
					series: ( visitor.arms || [] ).map( function ( a, i ) {
						return {
							name: armLabel( a.arm ),
							color: charts.palette[ i % charts.palette.length ],
							points: ( visitor.dates || [] ).map( function ( x, di ) {
								var v = a.cumulativeRate[ di ];
								return { x: x, y: ( v === null || v === undefined ) ? null : v * 100 };
							} )
						};
					} )
				}, { height: 240, yFmt: pctFmt } );
			} else if ( trendEl && holdout && holdout.hasTraffic ) {
				charts.line( trendEl, {
					series: ( holdout.series || [] ).map( function ( s, i ) {
						return {
							name: s.arm === 'treated'
								? t( 'newContent', 'New content' )
								: t( 'leftUnchanged', 'Left unchanged' ),
							color: charts.palette[ i % charts.palette.length ],
							points: ( holdout.dates || [] ).map( function ( x, di ) {
								return { x: x, y: s.values[ di ] };
							} )
						};
					} )
				}, { height: 240 } );
			}

			/* 2. When will I know. The band narrowing IS the argument: while it
			      touches zero the versions cannot be told apart. */
			var bandEl = byId( 'ace-exp-band' );
			var traj = d.trajectory;
			var arm = null;
			if ( traj && traj.arms ) {
				for ( var i = 0; i < traj.arms.length; i++ ) {
					var hasPoint = traj.arms[ i ].points.some( function ( p ) { return p.point !== null; } );
					if ( hasPoint ) { arm = traj.arms[ i ]; break; }
				}
			}
			if ( bandEl && arm ) {
				charts.band( bandEl, {
					keys: traj.dates,
					line: arm.points.map( function ( p ) {
						return { x: p.date, y: p.point === null ? null : p.point * 100 };
					} ),
					band: arm.points.map( function ( p ) {
						return {
							x: p.date,
							lo: p.lo === null ? null : p.lo * 100,
							hi: p.hi === null ? null : p.hi * 100
						};
					} )
				}, {
					height: 240,
					yFmt: pctFmt,
					valueLabel: t( 'differenceLabel', 'Against your original copy' )
				} );
			}

			/* 3. Where shoppers drop off. */
			var funnelEl = byId( 'ace-exp-funnel' );
			var funnels = d.funnels || [];
			var anyFunnel = funnels.some( function ( f ) {
				return f.stages && f.stages.length && f.stages[ 0 ].visitors > 0;
			} );
			if ( funnelEl && anyFunnel ) {
				charts.funnel( funnelEl, funnels.map( function ( f, i ) {
					return {
						name: armLabel( f.arm ),
						color: charts.palette[ i % charts.palette.length ],
						stages: f.stages.map( function ( s ) {
							return { label: s.label, value: s.visitors };
						} )
					};
				} ), { height: 240 } );
			}

			/* 4. Is the split even, and how much of the test is actually live. */
			var splitEl = byId( 'ace-exp-split' );
			var split = d.split || { arms: [] };
			if ( splitEl && split.arms.length ) {
				charts.barsH( splitEl, split.arms.map( function ( a, i ) {
					return {
						label: a.armLabel || ( 'Version ' + String( a.arm ).toUpperCase() ),
						value: a.observed,
						color: charts.palette[ i % charts.palette.length ]
					};
				} ), { height: Math.max( 120, split.arms.length * 42 ) } );
			}

			/* `ring`, not `gauge`. The gauge renderer colours a HIGH fraction
			   red because it was built for a usage meter, where filling up is
			   the bad news. Coverage is the opposite: live on all of what you
			   enrolled is the good outcome, and `ring` greens as it fills. */
			var coverageEl = byId( 'ace-exp-coverage' );
			var cov = d.coverage || {};
			if ( coverageEl && cov.enrolledProducts > 0 ) {
				/* The label sits INSIDE a 140px circle, so it has room for about
				   ten characters. "% of products live" overflowed it at both
				   ends and rendered as "% OF PRODUCTS LIV". The count belongs
				   under the chart, where there is a whole line for it. */
				charts.ring( coverageEl, {
					value: Math.round( ( cov.liveProducts / cov.enrolledProducts ) * 100 ),
					label: t( 'percentLive', '% live' ),
					size: 140
				} );
				var covNote = byId( 'ace-exp-coverage-note' );
				if ( covNote ) {
					covNote.textContent = core.num( cov.liveProducts ) + ' ' +
						t( 'ofProductsLive', 'of' ) + ' ' + core.num( cov.enrolledProducts ) + ' ' +
						t( 'productsLive', 'products have a second version live' );
					covNote.hidden = false;
				}
			}

			/* Money over time, on the same date axis as everything else. */
			var moneyEl = byId( 'ace-exp-money' );
			if ( moneyEl && d.money && d.money.arms && d.money.arms.length && visitor ) {
				charts.line( moneyEl, {
					series: d.money.arms.map( function ( a, i ) {
						return {
							name: a.armLabel || ( 'Version ' + String( a.arm ).toUpperCase() ),
							color: charts.palette[ i % charts.palette.length ],
							points: ( visitor.dates || [] ).map( function ( x, di ) {
								var v = a.series[ di ];
								return { x: x, y: ( v === null || v === undefined ) ? null : v / 100 };
							} )
						};
					} )
				}, {
					height: 220,
					yFmt: function ( v ) { return money( v * 100, d.money.currency ); }
				} );
			}

			/* 5. The results, as whiskers. A row still touching zero has not
			      separated the versions, which a table of numbers hides. */
			var forestEl = byId( 'ace-exp-forest' );
			if ( forestEl ) {
				var plottable = ( d.results || [] ).filter( function ( r ) {
					return typeof r.point === 'number' && typeof r.lo === 'number' && typeof r.hi === 'number';
				} );
				if ( plottable.length ) {
					charts.forest( forestEl, plottable.map( function ( r ) {
						return {
							label: r.armLabel || ( r.arm ? 'Version ' + String( r.arm ).toUpperCase() : 'Test group' ),
							estimate: r.point * 100,
							lo: r.lo * 100,
							hi: r.hi * 100,
							conclusive: !! r.conclusive,
							detail: r.claimChip || ''
						};
					} ), { height: 200, xFmt: pctFmt } );
				}
			}
		}

		function openDetail( id, fresh ) {
			openId = id;
			panel( 'detail' );
			core.skeleton( detailEl, 'table' );
			core.post( 'experiment_get', { experimentId: id, fresh: fresh ? 1 : '' } )
				.then( renderDetail )
				.catch( function ( err ) {
					detailEl.innerHTML = '<div class="ace-empty">' +
						esc( ( err && err.message ) || 'Could not load this experiment.' ) + '</div>';
				} );
		}

		/* ================================================================== */
		/* The wizard                                                         */
		/* ================================================================== */

		var form = byId( 'ace-exp-form' );
		var verdictEl = byId( 'ace-exp-verdict' );
		var launchBtn = byId( 'ace-exp-launch' );
		var checkBtn = byId( 'ace-exp-check' );
		var slowEl = byId( 'ace-exp-accept-slow' );

		function currentType() {
			var picked = form.querySelector( 'input[name="type"]:checked' );
			return picked ? picked.value : 'visitor_split';
		}

		function fillGoals() {
			var select = byId( 'ace-exp-goal' );
			select.innerHTML = ( GOALS[ currentType() ] || [] ).map( function ( g ) {
				return '<option value="' + esc( g.id ) + '">' + esc( g.label ) + '</option>';
			} ).join( '' );
		}

		function fillCategories() {
			var select = byId( 'ace-exp-category' );
			core.post( 'experiment_subjects', {} ).then( function ( payload ) {
				var cats = ( payload && payload.categories ) || [];
				var total = ( payload && payload.ids ) ? payload.ids.length : 0;
				select.innerHTML = '<option value="">' +
					esc( 'Everything (' + core.num( total ) + ' products)' ) + '</option>' +
					cats.map( function ( c ) {
						return '<option value="' + esc( c.name ) + '">' +
							esc( c.name + ' (' + core.num( c.count ) + ')' ) + '</option>';
					} ).join( '' );
			} ).catch( function () {
				select.innerHTML = '<option value="">' + esc( 'Everything' ) + '</option>';
			} );
		}

		function designPayload() {
			return {
				type: currentType(),
				category: byId( 'ace-exp-category' ).value,
				levels: byId( 'ace-exp-levels' ).value,
				days: byId( 'ace-exp-days' ).value
			};
		}

		function showVerdict( report ) {
			var verdict = report && report.report ? report.report.verdict : null;
			var tone = verdict === 'impossible' ? 'error'
				: verdict === 'slow' ? 'warning'
				: verdict === 'ready' ? 'success' : 'info';
			verdictEl.hidden = false;
			verdictEl.className = 'ace-banner ace-banner-' + tone;
			verdictEl.innerHTML = '<strong>' + esc( report.headline || '' ) + '</strong>' +
				'<p>' + esc( report.detail || '' ) + '</p>';

			// Only "impossible" blocks. "Slow" is the merchant's call, and the
			// checkbox is how they make it.
			launchBtn.disabled = verdict === 'impossible';
			slowEl.hidden = verdict !== 'slow';

			drawSizing( report );
		}

		/**
		 * The picture that makes the version-count trade visible.
		 *
		 * More versions feels like more learning and is actually less: the same
		 * shoppers are cut into more pieces, so every comparison gets weaker and
		 * the wait gets longer. That is invisible in prose and obvious in a
		 * curve. The curve itself is computed by the platform, with the same
		 * function that produced the verdict above, so this file cannot promise
		 * a sensitivity the estimator will not deliver.
		 */
		function drawSizing( report ) {
			var el = byId( 'ace-exp-sizing' );
			if ( ! el || ! charts ) { return; }
			var points = ( report && report.sensitivity ) || [];
			var note = byId( 'ace-exp-sizing-note' );
			if ( ! points.length ) {
				el.innerHTML = '';
				el.hidden = true;
				if ( note ) { note.hidden = true; }
				return;
			}
			el.hidden = false;
			if ( note ) { note.hidden = false; }
			charts.line( el, {
				series: [ {
					name: ( report.arms || 2 ) + ' ' + t( 'versions', 'versions' ),
					color: charts.palette[ 0 ],
					points: points.map( function ( s ) {
						return {
							x: String( s.days ),
							// A refusal is a gap: at that length this design
							// resolves nothing, which is not the same as
							// resolving everything.
							y: ( s.mde === null || s.mde === undefined ) ? null : Math.min( 200, s.mde * 100 )
						};
					} )
				} ]
			}, {
				height: 180,
				showLegend: false,
				yFmt: function ( v ) {
					return ( Math.round( v * 10 ) / 10 ) + '%';
				}
			} );
		}

		function runPreflight() {
			core.setBusy( checkBtn, true );
			core.post( 'experiment_preflight', designPayload() )
				.then( function ( report ) {
					core.setBusy( checkBtn, false );
					showVerdict( report );
				} )
				.catch( function ( err ) {
					core.setBusy( checkBtn, false );
					var message = ( err && err.message ) ||
						t( 'preflightFailed', 'Could not check that design.' );

					/*
					 * A REFUSAL BELONGS ON THE PAGE, NOT IN A TOAST.
					 *
					 * This used to be a toast alone. A store whose products have
					 * not reached Rezolve Ai gets "No products matched that selection.",
					 * which is the correct and useful answer -- and it appeared
					 * for a few seconds beside a button the merchant was still
					 * looking away from, then vanished. Clicking "Check this
					 * design" and having the page do nothing at all is the
					 * behaviour that gets reported as "the button is broken".
					 *
					 * The verdict banner is already here and already hidden, so
					 * the refusal goes in it and stays there. The toast stays
					 * too: it is what catches the eye.
					 */
					verdictEl.hidden = false;
					verdictEl.className = 'ace-banner ace-banner-error';
					verdictEl.innerHTML = '<strong>' +
						esc( t( 'preflightRefused', 'This design cannot be checked yet' ) ) +
						'</strong><p>' + esc( message ) + '</p>';
					launchBtn.disabled = true;
					slowEl.hidden = true;
					drawSizing( null );

					core.errorToast( message );
				} );
		}

		function launch() {
			var payload = designPayload();
			payload.name = byId( 'ace-exp-name' ).value;
			payload.goal = byId( 'ace-exp-goal' ).value;
			var slow = form.querySelector( 'input[name="acceptSlow"]' );
			payload.acceptSlow = slow && slow.checked ? 1 : '';

			core.setBusy( launchBtn, true );
			core.post( 'experiment_create', payload )
				.then( function ( created ) {
					core.setBusy( launchBtn, false );
					core.toast( 'Your versions are being written. Approve them in Review to start the test.' );
					loadList( true );
					if ( created && created.experimentId ) {
						openDetail( created.experimentId, true );
					}
				} )
				.catch( function ( err ) {
					core.setBusy( launchBtn, false );
					// The platform's refusals are the merchant's answer, not an
					// internal error, so the message travels through unchanged.
					core.errorToast( ( err && err.message ) || 'Could not create that experiment.' );
				} );
		}

		/* ================================================================== */
		/* History: finished tests, and what testing has been worth           */
		/* ================================================================== */

		var historyEl = byId( 'ace-exp-history' );
		var facetsEl = byId( 'ace-exp-history-facets' );
		var valueEl = byId( 'ace-exp-value' );
		var historyVerdict = '';
		var historySearch = '';
		var historyPage = 0;
		var historyLoaded = false;

		/*
		 * `core.chip` takes ( tone, label ) and knows five tones:
		 * success, warning, error, info, neutral. The payload speaks the shared
		 * vocabulary, which uses `critical` where this uses `error`. Mapping it
		 * here rather than passing it through is deliberate: an unknown tone
		 * falls back to `neutral`, so a BROKEN result would render in the same
		 * quiet grey as one that passed. That exact bug shipped once already.
		 */
		function toneToChip( tone ) {
			if ( tone === 'critical' ) { return 'error'; }
			if ( tone === 'success' || tone === 'warning' || tone === 'info' ) { return tone; }
			return 'neutral';
		}

		function renderValue( portfolio ) {
			if ( ! valueEl ) { return; }
			var banked = ( portfolio && portfolio.banked ) || [];
			var counts = ( portfolio && portfolio.counts ) || {};

			/* Absent, never zero. A row of zeroes reads as "testing earned you
			   nothing", which is the opposite of "you have not finished a test
			   yet", and those are opposite statements about a shop. */
			if ( ! banked.length || ! counts.concluded ) {
				valueEl.innerHTML = '';
				return;
			}

			var primary = banked[ 0 ];
			var html = '<div class="ace-card">';
			html += '<h3>' + esc( t( 'worthHeading', 'What testing has been worth' ) ) + '</h3>';
			html += '<p>' + esc(
				t( 'worthSub', 'From' ) + ' ' + core.num( counts.concluded ) + ' ' +
				t( 'worthSubTail', 'finished tests, counted as each one was decided.' )
			) + '</p>';
			html += '<div class="ace-stat-grid">';
			html += core.statTile( {
				label: t( 'bankedAYear', 'A year, from decisions taken' ),
				value: money( primary.totalMinor, primary.currency ),
				accent: 'indigo'
			} );
			html += core.statTile( {
				label: t( 'wonByKeeping', 'Won by keeping a better version' ),
				value: money( primary.gainsWonMinor, primary.currency ),
				// Only indigo, emerald, violet, cyan and amber have a rule in
				// admin.css. Anything else silently renders with no accent.
				accent: 'emerald'
			} );
			html += core.statTile( {
				label: t( 'keptByNotShipping', 'Kept by not shipping a worse one' ),
				value: money( primary.lossesAvoidedMinor, primary.currency ),
				accent: 'cyan'
			} );
			html += '</div>';
			html += '<p class="ace-muted">' + esc(
				t( 'atLeast', 'At least' ) + ' ' + money( primary.ruleOutMinor, primary.currency ) + ' ' +
				t( 'onTheCautiousEnd', 'a year on the cautious end of every range.' )
			) + '</p>';

			/* A second currency gets its own line. Converting it would make this
			   figure depend on today's exchange rate. */
			for ( var i = 1; i < banked.length; i++ ) {
				html += '<p class="ace-muted">' + esc(
					t( 'plus', 'Plus' ) + ' ' + money( banked[ i ].totalMinor, banked[ i ].currency ) +
					' ' + t( 'aYearIn', 'a year in' ) + ' ' + banked[ i ].currency + '.'
				) + '</p>';
			}

			/* Running tests are a FORECAST about tests that have not finished,
			   so they are stated separately and never added in. */
			var pipeline = ( portfolio && portfolio.pipeline ) || [];
			if ( pipeline.length ) {
				html += '<p class="ace-muted">' + esc(
					t( 'runningWouldAdd', 'Tests still running would add about' ) + ' ' +
					money( pipeline[ 0 ].totalMinor, pipeline[ 0 ].currency ) + ' ' +
					t( 'ifEstimateHolds', 'a year if their current estimate holds. That is not counted above, because they have not finished.' )
				) + '</p>';
			}
			html += '</div>';
			valueEl.innerHTML = html;
		}

		function renderFacets( payload ) {
			if ( ! facetsEl ) { return; }
			var facets = ( payload && payload.facets ) || [];
			if ( ! facets.length ) {
				facetsEl.innerHTML = '';
				return;
			}
			var html = '<div class="ace-filter-row">';
			html += '<button type="button" class="ace-btn ace-btn-sm ace-hist-facet' +
				( historyVerdict === '' ? ' is-active' : '' ) +
				'" data-verdict="">' + esc( t( 'allTests', 'All' ) ) +
				' (' + core.num( payload.total || 0 ) + ')</button>';
			facets.forEach( function ( f ) {
				html += '<button type="button" class="ace-btn ace-btn-sm ace-hist-facet' +
					( historyVerdict === f.verdict ? ' is-active' : '' ) +
					'" data-verdict="' + esc( f.verdict ) + '">' +
					esc( f.label ) + ' (' + core.num( f.count ) + ')</button>';
			} );
			html += '</div>';
			facetsEl.innerHTML = html;
		}

		function renderHistory( payload ) {
			if ( ! historyEl ) { return; }
			var rows = ( payload && payload.rows ) || [];

			if ( ! rows.length ) {
				historyEl.innerHTML = '<div class="ace-empty">' + esc(
					historyVerdict || historySearch
						? t( 'noTestsMatch', 'No tests match that.' )
						: t( 'noHistoryYet', 'Nothing here yet. Finished tests and what they found will collect here.' )
				) + '</div>';
				return;
			}

			var body = rows.map( function ( r ) {
				var o = r.outcome;
				var verdictCell = o
					? core.chip( toneToChip( o.tone ), o.label )
					: core.chip( 'neutral', t( 'stillRunning', 'Running' ) );

				/* A withheld number and a measured nothing must never share a
				   cell. `lift` is null whenever the claim gate refused. */
				var liftCell = esc( lift( o ? o.lift : null ) );

				var worthCell = ( o && o.value )
					? esc( money( o.value.minor, o.value.currency ) + ' ' +
						( o.value.kind === 'gain_won'
							? t( 'won', 'won' )
							: t( 'notLost', 'not lost' ) ) )
					: '\u2014';

				var noteId = 'ace-hist-note-' + esc( r.id );
				var note = '<details><summary>' +
					esc( o && o.notes ? t( 'editNote', 'Edit what you learned' ) : t( 'addNote', 'Add what you learned' ) ) +
					'</summary>' +
					'<textarea class="ace-input ace-hist-note" id="' + noteId + '" rows="3" maxlength="2000">' +
					esc( ( o && o.notes ) || '' ) + '</textarea>' +
					'<button type="button" class="ace-btn ace-btn-sm ace-hist-save" data-id="' + esc( r.id ) +
					'" data-note="' + noteId + '">' + esc( t( 'saveNote', 'Save' ) ) + '</button>' +
					'</details>';

				return '<tr>' +
					'<td><a href="#" class="ace-exp-open" data-id="' + esc( r.id ) + '">' + esc( r.name ) + '</a>' +
					( o && o.plain ? '<br /><span class="ace-muted">' + esc( o.plain ) + '</span>' : '' ) +
					note +
					'</td>' +
					'<td>' + verdictCell + '</td>' +
					'<td>' + liftCell + '</td>' +
					'<td>' + worthCell + '</td>' +
					'<td>' + esc( o ? core.fmtDate( o.decidedAt ) : '\u2014' ) + '</td>' +
					'</tr>';
			} ).join( '' );

			var html = '<table class="ace-table"><thead><tr>' +
				'<th>' + esc( t( 'test', 'Test' ) ) + '</th>' +
				'<th>' + esc( t( 'outcome', 'Outcome' ) ) + '</th>' +
				'<th>' + esc( t( 'change', 'Change' ) ) + '</th>' +
				'<th>' + esc( t( 'worth', 'Worth' ) ) + '</th>' +
				'<th>' + esc( t( 'decided', 'Decided' ) ) + '</th>' +
				'</tr></thead><tbody>' + body + '</tbody></table>';

			var pages = Math.ceil( ( payload.total || 0 ) / ( payload.pageSize || 25 ) );
			if ( pages > 1 ) {
				html += '<div class="ace-filter-row">';
				if ( historyPage > 0 ) {
					html += '<button type="button" class="ace-btn ace-btn-sm ace-hist-page" data-page="' +
						( historyPage - 1 ) + '">' + esc( t( 'previous', 'Previous' ) ) + '</button>';
				}
				html += '<span class="ace-muted">' + esc(
					t( 'page', 'Page' ) + ' ' + ( historyPage + 1 ) + ' / ' + pages
				) + '</span>';
				if ( historyPage + 1 < pages ) {
					html += '<button type="button" class="ace-btn ace-btn-sm ace-hist-page" data-page="' +
						( historyPage + 1 ) + '">' + esc( t( 'next', 'Next' ) ) + '</button>';
				}
				html += '</div>';
			}

			historyEl.innerHTML = html;
		}

		function loadHistory() {
			if ( ! historyEl ) { return; }
			historyLoaded = true;
			core.skeleton( historyEl, 'table' );
			core.post( 'experiment_history', {
				verdict: historyVerdict,
				q: historySearch,
				page: historyPage
			} )
				.then( function ( payload ) {
					renderFacets( payload );
					renderHistory( payload );
				} )
				.catch( function ( err ) {
					historyEl.innerHTML = '<div class="ace-empty">' +
						esc( ( err && err.message ) || t( 'noHistory', 'Could not load your test history.' ) ) +
						'</div>';
				} );

			/* The portfolio total degrades on its own: losing it must not cost
			   the merchant the history they came for. */
			core.post( 'experiment_value', {} )
				.then( renderValue )
				.catch( function () {
					if ( valueEl ) { valueEl.innerHTML = ''; }
				} );
		}

		/* ================================================================== */
		/* Wiring                                                             */
		/* ================================================================== */

		/* `core.tabs( container, onChange )`. The history is loaded on FIRST
		   view rather than at boot: most visits to this screen are about a
		   running test, and paying for two extra requests every time to fill a
		   tab nobody opened is the wrong default. */
		core.tabs( root, function ( tab ) {
			if ( tab === 'history' && ! historyLoaded ) { loadHistory(); }
		} );

		/* `core.tabs` fires `onChange` only on a CLICK: the hash restore it does
		   at boot passes `silent`, deliberately, so that arriving on a tab does
		   not look like choosing it. That means a bookmark or a reload landing
		   on #history would show an empty panel forever. Asking the DOM which
		   panel it actually left visible is the one answer that cannot drift
		   from what `core.tabs` decided. */
		var historyPanel = root.querySelector( '[data-panel="history"]' );
		if ( historyPanel && ! historyPanel.hidden && ! historyLoaded ) {
			loadHistory();
		}

		root.addEventListener( 'click', function ( event ) {
			var open = event.target.closest( '.ace-exp-open' );
			if ( open ) {
				event.preventDefault();
				openDetail( open.getAttribute( 'data-id' ), false );
				return;
			}

			/* History: filter by verdict. */
			var facet = event.target.closest( '.ace-hist-facet' );
			if ( facet ) {
				var next = facet.getAttribute( 'data-verdict' ) || '';
				historyVerdict = next === historyVerdict ? '' : next;
				historyPage = 0;
				loadHistory();
				return;
			}

			/* History: page. */
			var pageBtn = event.target.closest( '.ace-hist-page' );
			if ( pageBtn ) {
				historyPage = parseInt( pageBtn.getAttribute( 'data-page' ), 10 ) || 0;
				loadHistory();
				return;
			}

			/*
			 * History: save what the merchant learned.
			 *
			 * The row is NOT re-rendered on success. Reloading the table would
			 * collapse the `<details>` they are typing in and scroll the page,
			 * which reads as the note having been lost. A toast says it saved
			 * and the text they can see is already what was sent.
			 */
			var saveNote = event.target.closest( '.ace-hist-save' );
			if ( saveNote ) {
				var field = byId( saveNote.getAttribute( 'data-note' ) );
				if ( ! field ) { return; }
				core.setBusy( saveNote, true );
				core.post( 'experiment_note', {
					experimentId: saveNote.getAttribute( 'data-id' ),
					notes: field.value
				} )
					.then( function () {
						core.setBusy( saveNote, false );
						core.toast( t( 'noteSaved', 'Saved.' ) );
					} )
					.catch( function ( err ) {
						core.setBusy( saveNote, false );
						core.errorToast( ( err && err.message ) || t( 'noteNotSaved', 'Could not save that note.' ) );
					} );
				return;
			}

			/*
			 * Keep the winning version for everybody.
			 *
			 * The confirmation text comes from the platform's decision, not from
			 * here, so all three surfaces warn with the same words. The platform
			 * also RECOMPUTES the verdict and refuses if the evidence has moved,
			 * which is what makes it safe to leave this tab open overnight.
			 */
			var apply = event.target.closest( '.ace-exp-apply' );
			if ( apply && openId ) {
				var arm = apply.getAttribute( 'data-arm' );
				var confirmText = apply.getAttribute( 'data-confirm' ) ||
					t( 'applyWinnerConfirm', 'This finishes the test and makes the winning version the copy every shopper sees. It cannot be resumed afterwards.' );
				if ( ! window.confirm( confirmText ) ) { return; }
				core.setBusy( apply, true );
				core.post( 'experiment_apply_winner', { experimentId: openId, arm: arm } )
					.then( function () {
						core.setBusy( apply, false );
						core.toast( t( 'applyWinnerDone', 'The winning version is on its way to your store.' ) );
						openDetail( openId, true );
						loadList( true );
					} )
					.catch( function ( err ) {
						core.setBusy( apply, false );
						core.errorToast( ( err && err.message ) || t( 'didNotGoThrough', 'That did not go through.' ) );
					} );
				return;
			}

			var act = event.target.closest( '.ace-exp-act' );
			if ( act && openId ) {
				var action = act.getAttribute( 'data-act' );
				if ( action === 'stop' || action === 'abandon' ) {
					var warning = action === 'stop'
						? 'Stop this experiment? This closes the measurement window for good. Splitting stops, everyone sees your normal copy, and results already computed stay on this page.'
						: 'Discard this experiment?';
					if ( ! window.confirm( warning ) ) { return; }
				}
				core.setBusy( act, true );
				core.post( 'experiment_action', { experimentId: openId, experimentAction: action } )
					.then( function () {
						core.setBusy( act, false );
						openDetail( openId, true );
						loadList( true );
					} )
					.catch( function ( err ) {
						core.setBusy( act, false );
						core.errorToast( ( err && err.message ) || 'That did not go through.' );
					} );
			}
		} );

		byId( 'ace-exp-refresh' ).addEventListener( 'click', function () { loadList( true ); } );
		byId( 'ace-exp-back' ).addEventListener( 'click', function () {
			openId = null;
			panel( 'list' );
		} );
		checkBtn.addEventListener( 'click', runPreflight );
		launchBtn.addEventListener( 'click', launch );
		Array.prototype.forEach.call( form.querySelectorAll( 'input[name="type"]' ), function ( el ) {
			el.addEventListener( 'change', function () {
				fillGoals();
				// The design changed, so the verdict is about a test that no
				// longer exists. Clearing it is the honest move; leaving a green
				// tick against a different design is not.
				verdictEl.hidden = true;
				launchBtn.disabled = true;
			} );
		} );

		/* Debounced through the shared helper rather than firing on every
		   keystroke: each search is a round trip to the platform. */
		var historySearchEl = byId( 'ace-exp-history-search' );
		if ( historySearchEl ) {
			historySearchEl.addEventListener( 'input', core.debounce( function () {
				historySearch = historySearchEl.value || '';
				historyPage = 0;
				loadHistory();
			}, 350 ) );
		}

		fillGoals();
		fillCategories();
		loadList( false );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
