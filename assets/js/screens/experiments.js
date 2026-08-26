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
		var root = document.getElementById( 'ace-experiments' );
		if ( ! core || ! root ) { return; }

		var byId = function ( id ) { return document.getElementById( id ); };
		var esc = core.esc;

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

			if ( e.status === 'draft' ) {
				html += '<p>' + esc(
					cov.liveProducts > 0
						? 'A second version is live on ' + core.num( cov.liveProducts ) + ' of ' +
							core.num( cov.enrolledProducts ) + ' products. It starts on its own as the rest are pushed, or you can start it now.'
						: 'Nothing is being split and no days are counting. It starts by itself the moment the first product’s second version is approved and pushed live, because until then both groups would read the same words.'
				) + '</p>';
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

			/* HEALTH FIRST: a broken split still produces a confident interval. */
			var checks = ( d.health && d.health.checks ) || [];
			if ( checks.length ) {
				html += '<h3>' + esc( 'Can this be trusted?' ) + '</h3>';
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
			html += '<h3>' + esc( 'What it found' ) + '</h3>';
			if ( ! results.length ) {
				html += '<p>' + esc( 'Nothing has been computed yet. Results are worked out overnight, once there is enough traffic for a comparison to mean anything.' ) + '</p>';
			} else {
				html += '<table class="ace-table"><thead><tr><th>Version</th><th>Change</th><th>Range</th><th>Reading</th></tr></thead><tbody>' +
					results.map( function ( r ) {
						var range = interval( r.lo, r.hi );
						return '<tr><td>' + esc( r.armLabel || ( r.arm ? 'Version ' + String( r.arm ).toUpperCase() : 'Test group' ) ) +
							// A refusal renders as its reason and NO number.
							'</td><td>' + esc( lift( r.point ) ) +
							'</td><td>' + esc( range || 'n/a' ) +
							'</td><td>' + esc( r.refusal || r.claimChip || '' ) + '</td></tr>';
					} ).join( '' ) + '</tbody></table>';
			}

			detailEl.innerHTML = html;
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
					core.errorToast( ( err && err.message ) || 'Could not check that design.' );
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
		/* Wiring                                                             */
		/* ================================================================== */

		core.tabs( root );

		root.addEventListener( 'click', function ( event ) {
			var open = event.target.closest( '.ace-exp-open' );
			if ( open ) {
				event.preventDefault();
				openDetail( open.getAttribute( 'data-id' ), false );
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
