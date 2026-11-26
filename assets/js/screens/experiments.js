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

		/*
		 * What the create form may offer: the content each split can test, the
		 * versions and the goals, from the platform (`experiment_options`).
		 *
		 * This file used to carry its own goal list and test only the
		 * description. The copy went stale on merchants' servers: it offered
		 * "AI citations", which the platform refuses on every project, and never
		 * offered orders or revenue. Null until loaded, and null again if the
		 * read fails, in which case the form says so and offers nothing.
		 */
		var OPTIONS = null;

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

		/** core.esc leaves quotes alone, which is only safe outside an attribute. */
		function attr( s ) {
			return esc( s ).replace( /"/g, '&quot;' );
		}

		/** The learning note's label, naming its test. A function replacer keeps "$&" in a name literal. */
		function noteLabel( name ) {
			var value = String( name == null ? '' : name );
			return t( 'noteLabel', 'What you learned from %s' ).replace( '%s', function () {
				return value;
			} );
		}

		/*
		 * PROGRESS: where a test is, as a caption and a bar, from the platform's
		 * `progress` ("Writing 12 of 40", "3 of 40 reviewed", "Day 10 of 90").
		 *
		 * Local helpers, not `core` ones: a screen calling a core function that
		 * an older cached ace-core.js lacks blanks the whole page. The colour
		 * comes from the step state through STEP_TONE, the map the stepper
		 * already uses, so there is no new vocabulary to keep in step with the
		 * platform. A progress with no fraction draws NO bar, never an empty one:
		 * a bar at zero for "waiting on your review" reads as "nothing happened".
		 */
		var FILL_CLASS = {
			success: ' is-success',
			warning: ' is-warning',
			info: '',
			neutral: ''
		};

		function progressBar( p, label ) {
			if ( ! p || typeof p.fraction !== 'number' || ! isFinite( p.fraction ) ) { return ''; }
			var pct = Math.round( Math.min( 1, Math.max( 0, p.fraction ) ) * 1000 ) / 10;
			var fill = FILL_CLASS[ STEP_TONE[ p.state ] || 'neutral' ] || '';
			return '<div class="ace-batch-bar ace-exp-progress-bar" role="progressbar" aria-label="' + attr( label ) +
				'" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.round( pct ) +
				'" aria-valuetext="' + attr( p.label ) + '">' +
				'<span class="ace-batch-fill' + fill + '" style="width:' + pct + '%"></span></div>';
		}

		/**
		 * The list's Progress cell, or '' so the caller falls back to the day
		 * label it always showed (an older platform, a failed read, a finished
		 * test). Needs the merchant: the caption is a chip.
		 */
		function progressBlock( p, label ) {
			if ( ! p || typeof p.label !== 'string' || ! p.label ) { return ''; }
			var caption = p.state === 'blocked'
				? core.chip( STEP_TONE[ p.state ] || 'neutral', p.label )
				: '<span class="ace-exp-progress-caption">' + esc( p.label ) + '</span>';
			return '<div class="ace-exp-progress">' + caption + progressBar( p, label ) + '</div>';
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

		/**
		 * A result row's name. A row with no arm is either the whole test or, when
		 * the test was read out per content type, one content type. Naming both
		 * "Test group" printed the same label on every row of a breakdown.
		 */
		function rowLabel( r ) {
			if ( r.armLabel ) { return r.armLabel; }
			if ( r.arm ) { return 'Version ' + String( r.arm ).toUpperCase(); }
			return r.contentTypeLabel || 'Test group';
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
					'<td>' + ( progressBlock( e.progress, ( e.name || '' ) + ': ' + t( 'progress', 'Progress' ) ) || esc( day ) ) + '</td>' +
					'</tr>';
			} ).join( '' );

			listEl.innerHTML = '<table class="ace-table"><caption class="screen-reader-text">' + esc( t( 'yourTests', 'Your tests' ) ) + '</caption><thead><tr>' +
				'<th scope="col">Name</th><th scope="col">Status</th><th scope="col">Split</th><th scope="col">Products</th><th scope="col">Progress</th>' +
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
			// A product split has one version, its new content; never "a second version".
			var byShopper = isByShopper( e );
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
				// The bar goes INSIDE the current step, under its own sentence, and
				// only when the progress names that step: under another step's
				// words it would be a second answer on one page.
				var progress = d.progress;
				html += '<table class="ace-table"><caption class="screen-reader-text">' + esc( t( 'progress', 'Progress' ) ) + '</caption>' +
					'<thead><tr><th scope="col">' + esc( t( 'colStep', 'Step' ) ) + '</th><th scope="col">' + esc( t( 'colState', 'State' ) ) +
					'</th><th scope="col">' + esc( t( 'colDetail', 'Detail' ) ) + '</th></tr></thead><tbody>' + stage.steps.map( function ( s, i ) {
					var stepTone = STEP_TONE[ s.state ] || 'neutral';
					var stepWord = t( 'step_' + s.state, STEP_WORD[ s.state ] || s.state );
					var bar = ( i === stage.currentIndex && progress && progress.step === s.key )
						? progressBar( progress, s.label + ': ' + t( 'progress', 'Progress' ) )
						: '';
					return '<tr><th scope="row">' + esc( s.label ) + '</th><td>' + core.chip( stepTone, stepWord ) +
						'</td><td>' + esc( s.detail ) +
						( bar
							? '<div class="ace-exp-progress">' + bar +
								'<span class="ace-exp-progress-caption">' + esc( progress.label ) + '</span></div>'
							: '' ) +
						'</td></tr>';
				} ).join( '' ) + '</tbody></table>';
			} else if ( cov.enrolledProducts > 0 && cov.liveProducts < cov.enrolledProducts ) {
				html += '<p>' + esc( ( byShopper ? 'A second version' : 'The new content' ) + ' is live on ' +
					core.num( cov.liveProducts ) + ' of ' + core.num( cov.enrolledProducts ) + ' products.' ) + '</p>';
			}

			var actions = ACTIONS[ e.status ] || [];
			if ( actions.length ) {
				html += '<div class="ace-toolbar-actions">' + actions.map( function ( a ) {
					// Start is disabled until something is genuinely live. The
					// platform refuses it too; this is so the button explains
					// itself rather than failing after a click.
					var notReady = a === 'start' && ! ( cov.liveProducts > 0 );
					return '<button type="button" class="ace-btn ace-btn-sm ace-exp-act" data-act="' + a + '"' +
						( notReady ? ' disabled title="' + esc( byShopper
							? 'No product in this test has a second version live yet.'
							: 'No product in this test has its new content live yet.' ) + '"' : '' ) +
						'>' + esc( ACTION_LABEL[ a ] ) + '</button>';
				} ).join( ' ' ) + '</div>';
			}

			/* The drawn half. Containers only: `drawDetailCharts` fills them
			   after this HTML is in the DOM, because the kit measures the
			   container's width to size itself. */
			if ( byShopper ) {
				html += '<h3>' + esc( t( 'howEachVersion', 'How each version is doing' ) ) + '</h3>';
			} else {
				// A product split's chart says which days are baseline; the hint
				// is filled with the chart, from the same payload.
				html += '<h3 id="ace-exp-trend-title">' + esc( trendTitle( ( d.timeline || {} ).holdout ) ) + '</h3>';
				html += '<p class="description" id="ace-exp-trend-hint"></p>';
			}
			html += '<div class="ace-chart" id="ace-exp-trend"><div class="ace-skeleton-chart"></div></div>';

			html += '<h3>' + esc( t( 'whenWillIKnow', 'When will I know?' ) ) + '</h3>';
			if ( byShopper ) {
				html += '<p>' + esc( ( d.projection && d.projection.detail ) ||
					t( 'projectionPending', 'Once both versions have been seen by enough shoppers, this shows the range narrowing and projects the day it becomes readable.' ) ) + '</p>';
				html += '<div class="ace-chart" id="ace-exp-band"></div>';
			} else {
				html += '<p><strong>' + esc( productSplitReadinessTitle( e ) ) + '</strong></p>';
				html += '<p>' + esc( t( 'productSplitReadiness', 'This test compares the products with new content against the held-back products, so it is read once its planned days are done rather than as shoppers arrive. There is no range to draw while it runs.' ) ) + '</p>';
			}

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
			if ( ! byShopper ) {
				html += '<p>' + esc( t( 'productSplitGroups', 'Products were put into the two groups at random when the test was set up, so there is no shopper split to check. Every shopper sees the same content on a given product.' ) ) + '</p>';
			}
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
				html += '<table class="ace-table"><thead><tr><th scope="col">' + esc( t( 'version', 'Version' ) ) +
					'</th><th scope="col">' + esc( t( 'shoppers', 'Shoppers' ) ) +
					'</th><th scope="col">' + esc( t( 'revenue', 'Revenue' ) ) +
					'</th><th scope="col">' + esc( t( 'perShopper', 'Per shopper' ) ) + '</th></tr></thead><tbody>' +
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
				html += '<table class="ace-table"><caption class="screen-reader-text">' + esc( t( 'checksCaption', 'Measurement checks' ) ) + '</caption>' +
					'<thead><tr><th scope="col">' + esc( t( 'colCheck', 'Check' ) ) + '</th><th scope="col">' + esc( t( 'colState', 'State' ) ) +
					'</th><th scope="col">' + esc( t( 'colDetail', 'Detail' ) ) + '</th></tr></thead><tbody>' + checks.map( function ( c ) {
					// `broken` is the platform's word (assessHealth). `fail` is not
					// a state it can ever emit, and `critical` is not a chip tone --
					// together they put a BROKEN check in neutral grey, which is the
					// one rendering this file's header forbids.
					var tone = HEALTH_TONE[ c.state ] || 'neutral';
					var label = HEALTH_WORD[ c.state ] || c.state;
					return '<tr><th scope="row">' + esc( c.label ) + '</th><td>' + core.chip( tone, label ) +
						'</td><td>' + esc( c.detail ) + '</td></tr>';
				} ).join( '' ) + '</tbody></table>';
			}

			var exposures = d.exposures || [];
			if ( exposures.length ) {
				html += '<h3>' + esc( 'What each version was shown to' ) + '</h3>';
				html += '<table class="ace-table"><thead><tr><th scope="col">Version</th><th scope="col">Shoppers</th><th scope="col">Bought</th><th scope="col">Rate</th></tr></thead><tbody>' +
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
				html += '<table class="ace-table"><thead><tr><th scope="col">' + esc( t( 'version', 'Version' ) ) +
					'</th><th scope="col">' + esc( t( 'change', 'Change' ) ) +
					'</th><th scope="col">' + esc( t( 'range', 'Range' ) ) +
					'</th><th scope="col">' + esc( t( 'reading', 'Reading' ) ) + '</th></tr></thead><tbody>' +
					results.map( function ( r ) {
						var range = interval( r.lo, r.hi );
						return '<tr><td>' + esc( rowLabel( r ) ) +
							// A refusal renders as its reason and NO number.
							'</td><td>' + esc( lift( r.point ) ) +
							'</td><td>' + esc( range || 'n/a' ) +
							'</td><td>' + esc( r.refusal || r.claimChip || '' ) + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
			}

			/*
			 * The secondary goals: cart adds or orders beside a split read on
			 * visits. Context for the result above, never a result of its own.
			 * Absent from an older platform, so nothing is drawn; null when the
			 * platform could not read them, which is said rather than drawn as
			 * an empty table that would read as "nothing moved".
			 */
			if ( d.alsoMeasured === null ) {
				html += '<h3>' + esc( t( 'alsoMeasured', 'Also measured' ) ) + '</h3>' +
					'<p>' + esc( t( 'alsoMeasuredUnread', 'These could not be read just now. Reload the page to try again.' ) ) + '</p>';
			} else if ( d.alsoMeasured && d.alsoMeasured.length ) {
				html += '<h3>' + esc( t( 'alsoMeasured', 'Also measured' ) ) + '</h3>' +
					'<p class="description">' + esc( t( 'alsoMeasuredIntro', 'The other goals you asked to watch, on the same products and the same days. They do not decide the result above.' ) ) + '</p>';
				html += '<table class="ace-table"><thead><tr><th scope="col">' + esc( t( 'colGoal', 'Goal' ) ) +
					'</th><th scope="col">' + esc( t( 'change', 'Change' ) ) +
					'</th><th scope="col">' + esc( t( 'rangeOrReason', 'Range or reason' ) ) + '</th></tr></thead><tbody>' +
					d.alsoMeasured.map( function ( m ) {
						// A line without a number shows its reason, never a 0%.
						var measured = typeof m.point === 'number' && isFinite( m.point );
						var reading = measured
							? [ interval( m.lo, m.hi ), m.claimChip ].filter( Boolean ).join( '. ' )
							: ( m.refusal || '' );
						return '<tr><th scope="row">' + esc( m.goalLabel || m.goal ) +
							'</th><td>' + esc( measured ? lift( m.point ) : t( 'noFigure', 'No figure' ) ) +
							'</td><td>' + esc( reading ) + '</td></tr>';
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
				html += '<table class="ace-table"><caption class="screen-reader-text">' + esc( t( 'whatWordsMean', 'What these words mean' ) ) + '</caption>' +
					'<thead><tr><th scope="col">' + esc( t( 'colTerm', 'Term' ) ) + '</th><th scope="col">' + esc( t( 'colMeaning', 'Meaning' ) ) +
					'</th></tr></thead><tbody>' + glossary.map( function ( g ) {
					return '<tr><th scope="row">' + esc( g.term ) + '</th><td>' + esc( g.plain ) + '</td></tr>';
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
		/* Split by shopper, against split by product. A payload naming no design
		   keeps the shopper wording this screen has always used. */
		function isByShopper( e ) {
			var designs = e.designs || [];
			return ! designs.length || designs.indexOf( 'visitor_split' ) !== -1;
		}

		/* "When will I know?" for a product split: the stage stepper's own
		   countdown, since no shopper range is ever drawn for one. The same
		   words as the platform's product-split-copy.ts. */
		function productSplitReadinessTitle( e ) {
			if ( e.status === 'complete' || e.status === 'released' || e.status === 'abandoned' ) {
				return t( 'testClosed', 'This test is closed' );
			}
			if ( e.daysRunning === null || e.daysRunning === undefined ) {
				return t( 'notCountingYet', 'Not counting yet' );
			}
			if ( e.daysRemaining === null || e.daysRemaining === undefined ) {
				return t( 'nothingToEstimate', 'Nothing to estimate yet' );
			}
			if ( e.daysRemaining === 0 ) {
				return t( 'plannedDaysDone', 'The planned days are done' );
			}
			return e.daysRemaining === 1
				? t( 'aboutOneDayToGo', 'About 1 day to go' )
				: t( 'aboutDaysToGo', 'About %s days to go' ).replace( '%s', core.num( e.daysRemaining ) );
		}

		/** "28 Oct" for a calendar day key. UTC, so a day key never shifts by a time zone. */
		/* What a product split's chart counts: the goal the test is measured
		   on, so the lines and the verdict agree. The label is the goal's,
		   sent by the API like the goal list; an ACE without `metric` drew
		   visits. */
		function trendMetricLabel( holdout ) {
			var metric = holdout && holdout.metric;
			return ( metric && metric.label ) || t( 'trendVisits', 'Visits' );
		}

		function trendTitle( holdout ) {
			var label = trendMetricLabel( holdout );
			// "Shopping impressions" reads lower case mid-sentence; "AI crawler pulls" keeps its capitals.
			var phrase = /^[A-Z]{2}/.test( label ) ? label : label.charAt( 0 ).toLowerCase() + label.slice( 1 );
			return t( 'trendTitleFor', 'Average %s per product, in each group' ).replace( '%s', phrase );
		}

		function utcDay( key ) {
			var dt = new Date( key + 'T00:00:00Z' );
			if ( isNaN( dt.getTime() ) ) { return key; }
			try {
				return dt.toLocaleDateString( undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' } );
			} catch ( e ) {
				return key;
			}
		}

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
				/* Per product, with the start day on the axis (cockpit.ts). The
				   window opens four weeks before the start and the rollup trails
				   a day, so the morning after a start every point is baseline;
				   and the groups differ in size, so totals drew the bigger one
				   ahead. An older ACE without `perProduct` still gets totals. */
				var view = holdout.perProduct || null;
				var ex = d.experiment || {};
				var groupName = function ( arm ) {
					var label = arm === 'treated' ? t( 'newContent', 'New content' ) : t( 'leftUnchanged', 'Left unchanged' );
					if ( ! view ) { return label; }
					var n = arm === 'treated' ? ( ex.treatedUnits || 0 ) : ( ex.controlUnits || 0 );
					var count = n === 1
						? t( 'oneProduct', '1 product' )
						: t( 'nProducts', '%s products' ).replace( '%s', core.num( n ) );
					return label + ' (' + count + ')';
				};
				var dates = view ? view.dates : ( holdout.dates || [] );
				var cut = view ? view.cutDate : null;
				var metricLabel = trendMetricLabel( holdout );
				var hintEl = byId( 'ace-exp-trend-hint' );
				var titleEl = byId( 'ace-exp-trend-title' );
				if ( titleEl && ! view ) {
					titleEl.textContent = t( 'trendTotals', 'Total visits to each group' );
				}
				if ( hintEl ) {
					hintEl.textContent = ! view
						? ''
						: ! cut
						? t( 'trendNoStartFor', '%s in each group, averaged per product so the two groups compare fairly.' ).replace( '%s', metricLabel )
						: view.daysSince === 0
							? t( 'trendStartedOn', 'The test started on %s.' ).replace( '%s', utcDay( cut ) ) + ' ' +
								t( 'trendBaselineOnly', 'Every day on this chart so far is from before it, when both groups still had the same content. That is the baseline the test is measured against. Days with the new content appear here as they are counted, about a day behind.' )
							: t( 'trendShaded', 'Shaded days are from before the test, when both groups had the same content.' ) + ' ' +
								t( 'trendFromDay', 'From %s, one group has the new content.' ).replace( '%s', utcDay( cut ) ) + ' ' +
								t( 'trendHowToRead', 'Watch whether the gap between the lines changes after that day. A gap that was already there before the test is not a result.' );
				}
				charts.line( trendEl, {
					series: ( view ? view.series : ( holdout.series || [] ) ).map( function ( s, i ) {
						return {
							name: groupName( s.arm ),
							color: charts.palette[ i % charts.palette.length ],
							points: dates.map( function ( x, di ) {
								return { x: x, y: s.values[ di ] };
							} )
						};
					} )
				}, {
					height: 240,
					xFmt: utcDay,
					baseline: cut ? {
						until: cut,
						label: t( 'trendBefore', 'Before the test' ),
						afterLabel: t( 'trendRunning', 'Test running' )
					} : null
				} );
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
			var ex = d.experiment || {};
			if ( splitEl && ! isByShopper( ex ) && ( ex.treatedUnits || ex.controlUnits ) ) {
				/* A product split splits products, not shoppers: its two groups. */
				charts.barsH( splitEl, [
					{ label: t( 'newContent', 'New content' ), value: ex.treatedUnits || 0, color: charts.palette[ 0 ] },
					{ label: t( 'leftUnchanged', 'Left unchanged' ), value: ex.controlUnits || 0, color: charts.palette[ 1 ] }
				], { height: 120 } );
			} else if ( splitEl && split.arms.length ) {
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
						( isByShopper( d.experiment || {} )
							? t( 'productsLive', 'products have a second version live' )
							: t( 'productsNewContentLive', 'products have their new content live' ) );
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
							label: rowLabel( r ),
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

		/** Content type ids the merchant has ticked, in form order. */
		function checkedContent() {
			return Array.prototype.map.call(
				form.querySelectorAll( 'input[name="contentTypes"]:checked' ),
				function ( el ) { return el.value; }
			);
		}

		/** The platform's factor entries for the chosen split. */
		function factorsForType() {
			return ( OPTIONS && OPTIONS.factors && OPTIONS.factors[ currentType() ] ) || [];
		}

		function fillGoals() {
			var select = byId( 'ace-exp-goal' );
			var detail = byId( 'ace-exp-goal-detail' );
			var previous = select.value;
			var goals = ( ( OPTIONS && OPTIONS.goals ) || [] ).filter( function ( g ) {
				return ! g.guardrailOnly && ( g.types || [] ).indexOf( currentType() ) !== -1;
			} );
			select.innerHTML = goals.map( function ( g ) {
				return '<option value="' + esc( g.id ) + '">' + esc( g.label ) + '</option>';
			} ).join( '' );
			// Keep the merchant's choice when the new split can still measure it.
			if ( goals.some( function ( g ) { return g.id === previous; } ) ) {
				select.value = previous;
			}
			var picked = goals.filter( function ( g ) { return g.id === select.value; } )[ 0 ];
			if ( detail ) { detail.textContent = picked ? picked.detail : ''; }
			fillSecondaryGoals();
		}

		/**
		 * The goals this split can watch beside the headline, as ticks.
		 *
		 * From the platform's `secondaryFor`, which only a platform that reads
		 * them out sends, so an older one offers none. The headline is left
		 * out: it is already measured. A tick survives a change of split or
		 * headline wherever it is still offered.
		 */
		function fillSecondaryGoals() {
			var el = byId( 'ace-exp-secondary' );
			if ( ! el ) { return; }
			var kept = checkedSecondaryGoals();
			var headline = byId( 'ace-exp-goal' ).value;
			var goals = ( ( OPTIONS && OPTIONS.goals ) || [] ).filter( function ( g ) {
				return g.id !== headline && ( g.secondaryFor || [] ).indexOf( currentType() ) !== -1;
			} );
			if ( ! goals.length ) {
				el.innerHTML = '';
				return;
			}
			el.innerHTML = '<p><strong>' + esc( t( 'alsoMeasure', 'Also measure' ) ) + '</strong></p>' +
				'<p class="description">' + esc( currentType() === 'product_holdout'
					? t( 'alsoMeasureProduct', 'Read out beside the main goal, on the same products and the same days. Shown on the experiment page, and never what decides the result.' )
					: t( 'alsoMeasureShopper', 'Shown for each version on the experiment page, and never what decides the result.' ) ) + '</p>' +
				goals.map( function ( g ) {
					return '<label class="ace-label">' +
						'<input type="checkbox" name="guardrailGoals" value="' + esc( g.id ) + '"' +
						( kept.indexOf( g.id ) !== -1 ? ' checked' : '' ) + ' /> ' +
						'<span><strong>' + esc( g.label ) + '</strong><br />' +
						'<span class="description">' + esc( g.detail || '' ) + '</span></span>' +
						'</label>';
				} ).join( '' );
		}

		function checkedSecondaryGoals() {
			return Array.prototype.map.call(
				form.querySelectorAll( 'input[name="guardrailGoals"]:checked' ),
				function ( el ) { return el.value; }
			);
		}

		/**
		 * How many pieces of content one design may carry, from the platform.
		 *
		 * The number is enforced by the schema behind the power check and the
		 * launch, so a form that does not know it lets a merchant tick past it
		 * and get "Request body failed validation" back, which names neither
		 * the limit nor the tick that crossed it. The fallback is for a plugin
		 * running against a platform from before the cap was sent; a merchant
		 * does not update a plugin because we shipped one.
		 */
		function factorCap() {
			var max = ( OPTIONS && parseInt( OPTIONS.maxFactors, 10 ) ) || 4;
			var reason = ( OPTIONS && OPTIONS.maxFactorsReason ) ||
				'A test compares every combination, so one experiment covers at most ' + max +
				' pieces of content. Take one out to test this instead.';
			return { max: max, reason: reason };
		}

		/**
		 * Content checkboxes for the chosen split. Unavailable content is drawn
		 * disabled with its reason rather than left out: a choice that silently
		 * vanishes tells a merchant nothing.
		 */
		function fillContent() {
			var el = byId( 'ace-exp-content' );
			var note = byId( 'ace-exp-content-note' );
			if ( ! el ) { return; }
			var kept = checkedContent();
			if ( ! kept.length ) { kept = [ 'product-description' ]; }
			el.innerHTML = factorsForType().map( function ( f ) {
				var on = f.available && kept.indexOf( f.contentTypeId ) !== -1;
				return '<label class="ace-label">' +
					'<input type="checkbox" name="contentTypes" value="' + esc( f.contentTypeId ) + '"' +
					( on ? ' checked' : '' ) + ( f.available ? '' : ' disabled' ) + ' /> ' +
					'<span><strong>' + esc( f.label ) + '</strong><br />' +
					'<span class="description">' + esc( f.available ? f.summary : ( f.unavailableReason || '' ) ) + '</span></span>' +
					'</label>';
			} ).join( '' );
			if ( note ) { note.hidden = currentType() !== 'product_holdout'; }
			var hold = byId( 'ace-exp-holdout' );
			if ( hold ) { hold.hidden = currentType() !== 'product_holdout'; }
			syncContentCap();
			syncVersions();
		}

		/**
		 * Stop AT the cap rather than one past it.
		 *
		 * Updated in place on every tick rather than by redrawing the list,
		 * which would move the box the merchant just clicked. A box already in
		 * the design stays live so it can be taken out again, and the ones now
		 * out of reach keep their label and swap their summary for the reason,
		 * exactly as content this split cannot use does.
		 */
		function syncContentCap() {
			var el = byId( 'ace-exp-content' );
			if ( ! el ) { return; }
			var cap = factorCap();
			var chosen = checkedContent();
			var summaries = {};
			factorsForType().forEach( function ( f ) { summaries[ f.contentTypeId ] = f; } );
			Array.prototype.forEach.call(
				el.querySelectorAll( 'input[name="contentTypes"]' ),
				function ( input ) {
					var f = summaries[ input.value ];
					// Content this split cannot use already says why. Leave it.
					if ( ! f || ! f.available ) { return; }
					var atCap = chosen.length >= cap.max && chosen.indexOf( input.value ) === -1;
					input.disabled = atCap;
					var note = input.parentNode.querySelector( '.description' );
					if ( note ) { note.textContent = atCap ? cap.reason : ( f.summary || '' ); }
				}
			);
		}

		/**
		 * Versions only apply to content the platform can write more than one
		 * way, and a single version is only a test when products are held back.
		 */
		function syncVersions() {
			var select = byId( 'ace-exp-levels' );
			if ( ! select ) { return; }
			var angled = factorsForType().some( function ( f ) {
				return f.angleTestable && checkedContent().indexOf( f.contentTypeId ) !== -1;
			} );
			var one = select.querySelector( 'option[value="1"]' );
			var shopper = currentType() === 'visitor_split';
			if ( one ) {
				one.disabled = shopper;
				one.hidden = shopper;
			}
			if ( shopper && select.value === '1' ) { select.value = '2'; }
			select.disabled = ! angled;
		}

		function clearVerdict() {
			// The design changed, so the verdict is about a test that no longer
			// exists. Clearing it is the honest move; leaving a green tick
			// against a different design is not.
			verdictEl.hidden = true;
			launchBtn.disabled = true;
		}

		function loadOptions() {
			core.post( 'experiment_options', {} )
				.then( function ( payload ) {
					OPTIONS = payload || null;
					fillContent();
					fillGoals();
				} )
				.catch( function ( err ) {
					OPTIONS = null;
					fillContent();
					fillGoals();
					verdictEl.hidden = false;
					verdictEl.className = 'ace-banner ace-banner-error';
					verdictEl.innerHTML = '<strong>' +
						esc( t( 'optionsFailed', 'Could not load what can be tested' ) ) +
						'</strong><p>' + esc( ( err && err.message ) || '' ) + '</p>';
					checkBtn.disabled = true;
					launchBtn.disabled = true;
				} );
		}

		/*
		 * WHICH PRODUCTS: five filters, hand-picking, and a cohort described in
		 * words, all producing ONE selection spec.
		 *
		 * THE IDS NEVER REACH THIS BROWSER. The spec is a couple of hundred bytes;
		 * the platform resolves it for the count, again for the power check and
		 * once more for the create. This screen runs on a merchant's own server,
		 * often behind a slow link, and five thousand ids crossing admin-ajax on
		 * every checkbox would be both the latency and a payload the page could
		 * edit into a different test.
		 *
		 * EVERY HELPER BELOW IS LOCAL. A screen calling a `core` function that an
		 * older cached ace-core.js lacks blanks the whole page, and CI never sees
		 * it because none of this is bundled.
		 */
		var FACET_KEYS = [ 'categories', 'collections', 'productTypes', 'vendors', 'tags' ];
		var FACET_LABELS = {
			categories: t( 'facetCategories', 'Categories' ),
			collections: t( 'facetCollections', 'Collections' ),
			productTypes: t( 'facetProductTypes', 'Product type' ),
			vendors: t( 'facetVendors', 'Brand' ),
			tags: t( 'facetTags', 'Tags' )
		};
		/** Past this, a hand-picked list is really a filter nobody wrote down. */
		var MAX_PICKED = 500;
		/** The selection, as the merchant has built it so far. */
		var SELECTION = { facets: {}, includeIds: [], excludeIds: [], everything: false, groupId: '' };
		var FACETS = null;
		var GROUPS = [];

		/*
		 * core.post is a bare fetch with NO timeout, so a cohort suggestion --
		 * two model round trips behind a route that allows ninety seconds -- would
		 * otherwise look hung forever with nothing on screen.
		 */
		function withTimeout( promise, ms ) {
			return new Promise( function ( resolve, reject ) {
				var done = false;
				var timer = setTimeout( function () {
					if ( done ) { return; }
					done = true;
					reject( new Error( t(
						'suggestTimedOut',
						'That took longer than expected. Try a shorter description, or pick by category instead.'
					) ) );
				}, ms );
				promise.then( function ( value ) {
					if ( done ) { return; }
					done = true;
					clearTimeout( timer );
					resolve( value );
				}, function ( error ) {
					if ( done ) { return; }
					done = true;
					clearTimeout( timer );
					reject( error );
				} );
			} );
		}

		/**
		 * One filter as a <details> with a search box and checkboxes.
		 *
		 * <details>, not a popover: there is no CSS for one here, and it is the
		 * native WP admin idiom. The change handler is delegated on the container
		 * because the whole block is redrawn when the facets load.
		 */
		function facetBlock( key, list ) {
			var label = FACET_LABELS[ key ] || key;
			var options = ( list && list.options ) || [];
			var picked = SELECTION.facets[ key ] || [];
			var body;

			if ( options.length === 0 ) {
				// "Nothing stored yet" and "this store has none" are DIFFERENT facts,
				// and rendering the first as the second is a failed read dressed up
				// as one. `synced` is how the platform tells them apart.
				body = '<p class="description">' + esc(
					list && list.synced
						? t( 'facetEmpty', 'This catalogue records none of these.' )
						: t( 'facetUnsynced', 'Nothing stored yet. These appear once your catalogue has been read.' )
				) + '</p>';
			} else {
				body = options.map( function ( option ) {
					var on = picked.indexOf( option.name ) !== -1;
					return '<label class="ace-label"><input type="checkbox" data-facet="' + attr( key ) +
						'" value="' + attr( option.name ) + '"' + ( on ? ' checked' : '' ) + ' /> <span>' +
						esc( option.name ) + ' (' + core.num( option.count ) + ')</span></label>';
				} ).join( '' );
			}

			return '<details class="ace-exp-facet"><summary>' + esc( label ) +
				( picked.length ? ' (' + core.num( picked.length ) + ')' : '' ) +
				'</summary>' + body + '</details>';
		}

		function fillFacets() {
			var host = byId( 'ace-exp-facets' );
			if ( ! host ) { return; }
			if ( ! FACETS ) {
				host.innerHTML = '<p class="description">' +
					esc( t( 'facetsFailed', 'Could not read your catalogue filters.' ) ) + '</p>';
				return;
			}
			host.innerHTML = FACET_KEYS.map( function ( key ) {
				return facetBlock( key, FACETS[ key ] );
			} ).join( '' );
			host.hidden = SELECTION.everything;
		}

		function loadFacets() {
			core.post( 'experiment_facets', {} ).then( function ( payload ) {
				FACETS = ( payload && payload.facets ) || null;
				var total = ( payload && payload.ids ) ? payload.ids.length : 0;
				var everythingLabel = byId( 'ace-exp-everything-label' );
				if ( everythingLabel && total ) {
					everythingLabel.textContent = t( 'everyProduct', 'Every product' ) +
						' (' + core.num( total ) + ')';
				}
				fillFacets();
			} ).catch( function () {
				FACETS = null;
				fillFacets();
			} );
		}

		function loadGroups() {
			core.post( 'experiment_groups', {} ).then( function ( payload ) {
				GROUPS = ( payload && payload.groups ) || [];
				var row = byId( 'ace-exp-groups-row' );
				var select = byId( 'ace-exp-group' );
				if ( ! row || ! select ) { return; }
				if ( GROUPS.length === 0 ) {
					row.hidden = true;
					return;
				}
				row.hidden = false;
				select.innerHTML = '<option value="">' + esc( t( 'groupNone', 'None' ) ) + '</option>' +
					GROUPS.map( function ( group ) {
						// The count is a CACHE, so it is shown with the day it was
						// taken or not at all. A bare stale number is how a merchant
						// starts a test over a cohort that no longer exists.
						var note = ( group.lastCount !== null && group.lastCountedAt )
							? ' (' + core.num( group.lastCount ) + ' ' +
								t( 'groupAsOf', 'products as of' ) + ' ' +
								core.fmtDate( group.lastCountedAt ) + ')'
							: ' (' + t( 'groupUncounted', 'not counted yet' ) + ')';
						return '<option value="' + attr( group.id ) + '">' +
							esc( group.name ) + esc( note ) + '</option>';
					} ).join( '' );
			} ).catch( function () {
				GROUPS = [];
			} );
		}

		/** The selection, in the shape the platform's parser reads. */
		function selectionPayload() {
			var payload = {
				selectionVersion: 1,
				everything: SELECTION.everything ? 1 : '',
				groupId: SELECTION.groupId || '',
				includeIds: SELECTION.includeIds.slice( 0, MAX_PICKED ),
				excludeIds: SELECTION.excludeIds.slice( 0, MAX_PICKED )
			};
			FACET_KEYS.forEach( function ( key ) {
				payload[ 'facet_' + key ] = ( SELECTION.facets[ key ] || [] ).slice();
			} );
			return payload;
		}

		function selectionIsEmpty() {
			if ( SELECTION.everything ) { return false; }
			var any = SELECTION.includeIds.length > 0;
			FACET_KEYS.forEach( function ( key ) {
				if ( ( SELECTION.facets[ key ] || [] ).length > 0 ) { any = true; }
			} );
			return ! any;
		}

		/*
		 * The count, from the platform.
		 *
		 * NEVER added up here. Filters overlap and narrow each other, so summing
		 * "Boots (120)" and "Acme (80)" gives 200 for a selection that may come to
		 * nine. The counts beside each box say how big that filter is; only the
		 * platform can say how big the intersection is.
		 */
		var refreshCount = core.debounce( function () {
			var summary = byId( 'ace-exp-subject-summary' );
			if ( ! summary ) { return; }
			if ( selectionIsEmpty() ) {
				summary.textContent = t( 'noProductsChosen', 'No products chosen yet.' );
				return;
			}
			var body = selectionPayload();
			body.type = currentType();
			core.post( 'experiment_resolve', body ).then( function ( answer ) {
				var text = ( answer && answer.sentence ) || '';
				var holdout = answer && answer.holdout;
				if ( holdout && currentType() === 'product_holdout' ) {
					text += ' ' + ( holdout.ok
						? ( typeof holdout.half === 'number' && holdout.half !== holdout.approx
							? t( 'holdoutBetween', 'Between about %1$s and %2$s of them will be held back unchanged as the control group, depending on how many you choose to hold back.' )
								.replace( '%1$s', core.num( holdout.approx ) ).replace( '%2$s', core.num( holdout.half ) )
							: t( 'holdoutAbout', 'About' ) + ' ' + core.num( holdout.approx ) + ' ' +
								t( 'holdoutHeld', 'of them will be held back unchanged as the control group.' ) )
						: t( 'holdoutTooFew', 'That is too few for a control group.' ) + ' ' +
							t( 'holdoutNeed', 'A product split needs about' ) + ' ' +
							core.num( holdout.minimum ) + ' ' + t( 'holdoutProducts', 'products.' ) );
				}
				// A filter is not a live segment: the products are frozen into the
				// test when it starts. Without this a merchant reading "Categories:
				// Boots" would reasonably assume the opposite.
				text += ' ' + t(
					'selectionFreezes',
					'Fixed when you start the test. Products added to these filters later will not join it.'
				);
				summary.textContent = text;
			} ).catch( function ( err ) {
				// The previous number is REPLACED by an error here rather than by a
				// zero: a failed read is not an empty catalogue.
				summary.textContent = ( err && err.message ) ||
					t( 'countFailed', 'Could not count these products.' );
			} );
		}, 400 );

		/** Everything that changes the selection goes through here. */
		function selectionChanged() {
			refreshCount();
			clearVerdict();
		}

		function suggestCohort() {
			var input = byId( 'ace-exp-cohort' );
			var out = byId( 'ace-exp-cohort-result' );
			var btn = byId( 'ace-exp-cohort-go' );
			if ( ! input || ! out ) { return; }
			var brief = ( input.value || '' ).trim();
			if ( ! brief ) { return; }

			core.setBusy( btn, true );
			out.innerHTML = '<p class="description">' +
				esc( t( 'cohortWorking', 'Looking through your catalogue...' ) ) + '</p>';

			withTimeout(
				core.post( 'experiment_suggest', { brief: brief, type: currentType(), arms: 2 } ),
				75000
			).then( function ( result ) {
				core.setBusy( btn, false );
				if ( ! result || ! result.ok ) {
					// The platform's own wording, unchanged. This is the screen where
					// a merchant learns their catalogue has nothing matching what they
					// described, and a surface that softened it would be believed.
					out.innerHTML = '<p class="description">' +
						esc( ( result && result.message ) || t( 'cohortNone', 'Nothing matched that.' ) ) +
						'</p>';
					return;
				}
				var products = result.products || [];
				out.innerHTML = '<p>' + esc( result.reason || '' ) + '</p>' +
					( result.underpowered
						? '<p class="description">' + esc(
							t( 'cohortSmall', 'That is smaller than a test usually needs. Take it, and the design check will have the last word.' )
						) + '</p>'
						: '' ) +
					products.map( function ( product ) {
						return '<label class="ace-label"><input type="checkbox" data-cohort="1" value="' +
							attr( product.productId ) + '" checked /> <span>' +
							esc( product.title || product.productId ) + '</span></label>';
					} ).join( '' ) +
					'<p><button type="button" class="button button-primary" id="ace-exp-cohort-use">' +
					esc( t( 'cohortUse', 'Use these products' ) ) + '</button></p>';
			} ).catch( function ( err ) {
				core.setBusy( btn, false );
				out.innerHTML = '<p class="description">' +
					esc( ( err && err.message ) || t( 'cohortFailed', 'Could not suggest products just now.' ) ) +
					'</p>';
			} );
		}

		function acceptCohort() {
			var out = byId( 'ace-exp-cohort-result' );
			if ( ! out ) { return; }
			var picked = Array.prototype.filter.call(
				out.querySelectorAll( 'input[data-cohort]' ),
				function ( el ) { return el.checked; }
			).map( function ( el ) { return el.value; } );
			if ( picked.length === 0 ) { return; }

			// An accepted proposal REPLACES the filters. Layering it on top would
			// produce a count that is neither the filter's nor the suggestion's,
			// with nothing on screen saying which part came from where.
			SELECTION.facets = {};
			SELECTION.everything = false;
			SELECTION.groupId = '';
			SELECTION.includeIds = picked.slice( 0, MAX_PICKED );
			SELECTION.excludeIds = [];
			fillFacets();
			selectionChanged();
			core.toast( t( 'cohortApplied', 'Using the products you picked.' ) );
		}

		function saveGroup() {
			var nameEl = byId( 'ace-exp-group-name' );
			var btn = byId( 'ace-exp-group-save' );
			if ( ! nameEl ) { return; }
			var name = ( nameEl.value || '' ).trim();
			if ( ! name || selectionIsEmpty() ) { return; }
			var body = selectionPayload();
			body.name = name;
			core.setBusy( btn, true );
			core.post( 'experiment_group_save', body ).then( function () {
				core.setBusy( btn, false );
				nameEl.value = '';
				core.toast( t( 'groupSaved', 'Saved.' ) );
				loadGroups();
			} ).catch( function ( err ) {
				core.setBusy( btn, false );
				core.errorToast( ( err && err.message ) || t( 'groupSaveFailed', 'Could not save that group.' ) );
			} );
		}

		/*
		 * HAND-PICKING. Products come from the platform's own product page, in
		 * `products.product_id` -- NOT a WordPress post id and not a WooCommerce
		 * product id. The platform keys experiment units and review items by
		 * that id, and a selection carrying anything else produces a test whose
		 * units match no content at all, with nothing to say so.
		 */
		function togglePicked( productId, on ) {
			var include = SELECTION.includeIds;
			var exclude = SELECTION.excludeIds;
			var atInclude = include.indexOf( productId );
			var atExclude = exclude.indexOf( productId );
			if ( on ) {
				if ( atExclude !== -1 ) { exclude.splice( atExclude, 1 ); }
				if ( atInclude === -1 && include.length < MAX_PICKED ) { include.push( productId ); }
			} else if ( atInclude !== -1 ) {
				// Only here because it was hand-picked: taking it out of the
				// pick says what the merchant meant, rather than saying both
				// "always this" and "never this" at once.
				include.splice( atInclude, 1 );
			} else if ( atExclude === -1 ) {
				exclude.push( productId );
			}
			selectionChanged();
		}

		function searchProducts( term ) {
			var host = byId( 'ace-exp-picker-list' );
			if ( ! host ) { return; }
			if ( ! term ) {
				host.innerHTML = '<p class="description">' +
					esc( t( 'pickerHint', 'Search to add a product the filters missed.' ) ) + '</p>';
				return;
			}
			core.skeleton( host, 'table' );
			core.post( 'experiment_products', { q: term, page: 0 } ).then( function ( payload ) {
				var products = ( payload && payload.products ) || [];
				if ( products.length === 0 ) {
					host.innerHTML = '<p class="description">' +
						esc( t( 'pickerNone', 'Nothing matched that search.' ) ) + '</p>';
					return;
				}
				host.innerHTML = products.map( function ( product ) {
					var on = SELECTION.includeIds.indexOf( product.productId ) !== -1;
					return '<label class="ace-label"><input type="checkbox" data-pick="1" value="' +
						attr( product.productId ) + '"' + ( on ? ' checked' : '' ) + ' /> <span>' +
						esc( product.title || product.productId ) + '</span></label>';
				} ).join( '' );
			} ).catch( function ( err ) {
				host.innerHTML = '<p class="description">' +
					esc( ( err && err.message ) || t( 'pickerFailed', 'Could not search products.' ) ) +
					'</p>';
			} );
		}

		function designPayload() {
			var payload = selectionPayload();
			payload.type = currentType();
			payload.contentTypes = checkedContent();
			// An empty array posts nothing at all, which the PHP would read as a
			// script from before 4.19.0 and test the description. This says the
			// choice was made, even when the choice was nothing.
			payload.contentChosen = 1;
			payload.levels = byId( 'ace-exp-levels' ).value;
			// Sent to the power check too: a product split is sized against
			// the goal it will measure, and orders are far rarer than visits.
			payload.goal = byId( 'ace-exp-goal' ).value;
			payload.guardrailGoals = checkedSecondaryGoals();
			payload.days = byId( 'ace-exp-days' ).value;
			var share = form.querySelector( 'input[name="holdoutFraction"]:checked' );
			if ( share && currentType() === 'product_holdout' ) { payload.holdoutFraction = share.value; }
			return payload;
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
				/* NOT "Running": a row with no ledger entry may be a draft,
				   paused or discarded test. statusLabel/statusTone come from
				   the platform so this screen, the Shopify app and the web app
				   describe the same row with the same word. */
				var verdictCell = o
					? core.chip( toneToChip( o.tone ), o.label )
					: core.chip( toneToChip( r.statusTone ), r.statusLabel || t( 'stillRunning', 'Running' ) );

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
					'<textarea class="ace-input ace-hist-note" id="' + noteId + '" rows="3" maxlength="2000" aria-label="' +
					attr( noteLabel( r.name ) ) + '">' +
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

			var html = '<table class="ace-table"><caption class="screen-reader-text">' + esc( t( 'historyCaption', 'Finished tests' ) ) + '</caption><thead><tr>' +
				'<th scope="col">' + esc( t( 'test', 'Test' ) ) + '</th>' +
				'<th scope="col">' + esc( t( 'outcome', 'Outcome' ) ) + '</th>' +
				'<th scope="col">' + esc( t( 'change', 'Change' ) ) + '</th>' +
				'<th scope="col">' + esc( t( 'worth', 'Worth' ) ) + '</th>' +
				'<th scope="col">' + esc( t( 'decided', 'Decided' ) ) + '</th>' +
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
				fillContent();
				fillGoals();
				clearVerdict();
			} );
		} );
		// Delegated, because the checkboxes are redrawn on every split change.
		byId( 'ace-exp-content' ).addEventListener( 'change', function () {
			syncContentCap();
			syncVersions();
			clearVerdict();
		} );
		byId( 'ace-exp-goal' ).addEventListener( 'change', function () {
			fillGoals();
			clearVerdict();
		} );

		/*
		 * The product selection.
		 *
		 * EVERY ONE OF THESE CLEARS THE VERDICT. A green tick left standing
		 * against a different set of products is the exact failure
		 * `clearVerdict` exists to prevent, and until this release the only
		 * things that could change the products were a single dropdown.
		 */
		var everythingEl = byId( 'ace-exp-everything' );
		if ( everythingEl ) {
			everythingEl.addEventListener( 'change', function () {
				SELECTION.everything = everythingEl.checked;
				// Filtering is off under "every product", so the block is
				// hidden rather than left as controls that do nothing.
				fillFacets();
				selectionChanged();
			} );
		}

		// Delegated: the whole facet block is redrawn when the lists load.
		var facetHost = byId( 'ace-exp-facets' );
		if ( facetHost ) {
			facetHost.addEventListener( 'change', function ( event ) {
				var el = event.target;
				if ( ! el || ! el.getAttribute || ! el.getAttribute( 'data-facet' ) ) { return; }
				var key = el.getAttribute( 'data-facet' );
				var list = SELECTION.facets[ key ] || [];
				var at = list.indexOf( el.value );
				if ( el.checked && at === -1 ) {
					list.push( el.value );
				} else if ( ! el.checked && at !== -1 ) {
					list.splice( at, 1 );
				}
				SELECTION.facets[ key ] = list;
				// Picking a filter by hand ends the claim that this selection
				// is a saved group: it is the merchant's own copy now.
				SELECTION.groupId = '';
				var groupSelect = byId( 'ace-exp-group' );
				if ( groupSelect ) { groupSelect.value = ''; }
				selectionChanged();
			} );
		}

		var groupSelectEl = byId( 'ace-exp-group' );
		if ( groupSelectEl ) {
			groupSelectEl.addEventListener( 'change', function () {
				var chosen = null;
				for ( var i = 0; i < GROUPS.length; i++ ) {
					if ( GROUPS[ i ].id === groupSelectEl.value ) { chosen = GROUPS[ i ]; }
				}
				if ( ! chosen ) {
					SELECTION.groupId = '';
					selectionChanged();
					return;
				}
				// COPIED into the selection, never pointed at. The merchant may
				// edit it freely from here, and editing the group next month
				// cannot change a test started today.
				var spec = chosen.selection || {};
				SELECTION.facets = {};
				FACET_KEYS.forEach( function ( key ) {
					SELECTION.facets[ key ] = ( ( spec.facets || {} )[ key ] || [] ).slice();
				} );
				SELECTION.everything = spec.mode === 'all';
				SELECTION.includeIds = ( spec.includeIds || [] ).slice();
				SELECTION.excludeIds = ( spec.excludeIds || [] ).slice();
				SELECTION.groupId = chosen.id;
				if ( everythingEl ) { everythingEl.checked = SELECTION.everything; }
				fillFacets();
				selectionChanged();
			} );
		}

		var cohortGo = byId( 'ace-exp-cohort-go' );
		if ( cohortGo ) { cohortGo.addEventListener( 'click', suggestCohort ); }

		// Delegated: the Use button is drawn with the proposal.
		var cohortOut = byId( 'ace-exp-cohort-result' );
		if ( cohortOut ) {
			cohortOut.addEventListener( 'click', function ( event ) {
				if ( event.target && event.target.id === 'ace-exp-cohort-use' ) { acceptCohort(); }
			} );
		}

		var groupSaveBtn = byId( 'ace-exp-group-save' );
		if ( groupSaveBtn ) { groupSaveBtn.addEventListener( 'click', saveGroup ); }

		var pickerSearch = byId( 'ace-exp-picker-search' );
		if ( pickerSearch ) {
			pickerSearch.addEventListener( 'input', core.debounce( function () {
				searchProducts( pickerSearch.value || '' );
			}, 350 ) );
		}

		var pickerList = byId( 'ace-exp-picker-list' );
		if ( pickerList ) {
			pickerList.addEventListener( 'change', function ( event ) {
				var el = event.target;
				if ( ! el || ! el.getAttribute || ! el.getAttribute( 'data-pick' ) ) { return; }
				togglePicked( el.value, el.checked );
			} );
		}

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

		loadOptions();
		loadFacets();
		loadGroups();
		refreshCount();
		loadList( false );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
