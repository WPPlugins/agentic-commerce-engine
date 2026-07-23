/**
 * AI Search screen: research console (Overview, Prompts, Competitors, Runs)
 * plus the native AI crawler analytics (Crawlers tab).
 *
 * Hydrates the skeleton in admin/views/page-aisearch.php over admin-ajax via
 * window.agencoen.core and renders with window.agencoen.charts. Vanilla JS,
 * no framework.
 */
( function () {
	'use strict';

	function boot() {
		var agencoen = window.agencoen || {};
		var core = agencoen.core;
		var charts = agencoen.charts;
		var cfg = window.agencoenConfig || {};
		var root = document.getElementById( 'agencoen-aisearch' );
		if ( ! core || ! charts || ! root ) { return; }

		var i18n = cfg.i18n || {};
		var features = cfg.features || {};
		var connected = !! cfg.connected;
		var researchOn = connected && features.ecomAeo !== false;

		var COLOR_YOURS = '#6366f1';
		var COLOR_OTHERS = '#94a3b8';
		var COLOR_MUTED = '#cbd5e1';

		function t( key, fallback ) { return i18n[ key ] || fallback; }
		function byId( id ) { return document.getElementById( id ); }
		function fmtPct( value ) {
			var n = typeof value === 'number' && isFinite( value ) ? value : 0;
			return ( Math.round( n * 10 ) / 10 ) + '%';
		}
		function failWith( btn ) {
			return function ( err ) {
				if ( btn ) { core.setBusy( btn, false ); }
				core.errorToast( ( err && err.message ) || t( 'genericError', 'Something went wrong. Please try again.' ) );
			};
		}
		function emptyHtml( message ) {
			return '<div class="ace-empty">' + core.esc( message ) + '</div>';
		}

		var loaded = { overview: false, prompts: false, competitors: false, crawlers: false, runs: false };
		var overviewData = null;

		/* =================================================================== */
		/* Crawlers tab (works without a connection)                           */
		/* =================================================================== */

		var bots = {
			range: byId( 'agencoen-aeo-bots-range' ),
			stats: byId( 'agencoen-aeo-bots-stats' ),
			trend: byId( 'agencoen-aeo-bots-trend' ),
			byBot: byId( 'agencoen-aeo-bots-bybot' ),
			pagesCard: byId( 'agencoen-aeo-bots-pages-card' ),
			pages: byId( 'agencoen-aeo-bots-pages' ),
			heatmap: byId( 'agencoen-aeo-bots-heatmap' ),
			report: byId( 'agencoen-aeo-bots-report' ),
			send: byId( 'agencoen-aeo-bots-send' )
		};

		function loadBots() {
			loaded.crawlers = true;
			var days = parseInt( bots.range && bots.range.value, 10 ) || 30;
			core.skeleton( bots.stats, 'tiles' );
			core.skeleton( bots.trend, 'chart' );
			core.skeleton( bots.byBot, 'chart' );
			core.skeleton( bots.pages, 'table' );
			core.skeleton( bots.heatmap, 'chart' );

			core.post( 'bots_summary', { days: days } ).then( function ( data ) {
				renderBots( data || {} );
			}, function ( err ) {
				loaded.crawlers = false;
				failWith( null )( err );
			} );
		}

		function renderBots( data ) {
			var tiles = data.tiles || {};
			var hits = tiles.hits || 0;
			var prev = tiles.prevHits || 0;

			var trendInfo = null;
			if ( prev > 0 ) {
				var deltaPct = Math.round( ( ( hits - prev ) / prev ) * 100 );
				trendInfo = {
					dir: deltaPct >= 0 ? 'up' : 'down',
					text: ( deltaPct >= 0 ? '+' : '' ) + deltaPct + '% ' + t( 'vsPreviousPeriod', 'vs previous period' )
				};
			}

			bots.stats.innerHTML =
				core.statTile( {
					label: t( 'botsHits', 'Crawler hits' ),
					value: core.num( hits ),
					accent: 'indigo',
					trend: trendInfo
				} ) +
				core.statTile( {
					label: t( 'botsActive', 'Active crawlers' ),
					value: core.num( tiles.activeBots || 0 ),
					accent: 'violet'
				} ) +
				core.statTile( {
					label: t( 'botsTop', 'Top crawler' ),
					value: tiles.topBot ? tiles.topBot.label : t( 'botsNone', 'None yet' ),
					accent: 'emerald'
				} ) +
				core.statTile( {
					label: t( 'botsTrackedDays', 'Days with activity' ),
					value: core.num( tiles.trackedDays || 0 ),
					accent: 'cyan'
				} );

			renderBotsTrend( data );

			var byBotRows = ( data.byBot || [] ).map( function ( b ) {
				return { label: b.label || b.slug || '', value: b.hits || 0, color: b.color || undefined };
			} );
			charts.barsH( bots.byBot, byBotRows, {} );

			renderBotsPages( data );

			var heat = data.heatmap || {};
			charts.heatmap( bots.heatmap, { cols: heat.cols || [], rows: heat.rows || [] }, {} );
		}

		function renderBotsTrend( data ) {
			var byBot = data.byBot || [];
			var top = byBot.slice( 0, 5 );
			var topSlugs = {};
			top.forEach( function ( b ) { topSlugs[ b.slug ] = true; } );

			var series = top.map( function ( b ) {
				return { name: b.label || b.slug, color: b.color || undefined, points: [] };
			} );
			var other = { name: t( 'botsOther', 'Other' ), color: COLOR_OTHERS, points: [] };
			var otherHasData = false;

			( data.trend || [] ).forEach( function ( dayRow ) {
				var x = core.fmtDate( dayRow.day );
				var map = dayRow.byBot || {};
				top.forEach( function ( b, i ) {
					series[ i ].points.push( { x: x, y: map[ b.slug ] || 0 } );
				} );
				var rest = 0;
				Object.keys( map ).forEach( function ( slug ) {
					if ( ! topSlugs[ slug ] ) { rest += map[ slug ] || 0; }
				} );
				if ( rest > 0 ) { otherHasData = true; }
				other.points.push( { x: x, y: rest } );
			} );

			if ( otherHasData ) { series.push( other ); }
			charts.area( bots.trend, { series: series }, { height: 220, unified: true } );
		}

		function renderBotsPages( data ) {
			if ( ! bots.pagesCard ) { return; }
			if ( data.pathsEnabled === false || ! data.topPages ) {
				bots.pagesCard.hidden = true;
				return;
			}
			bots.pagesCard.hidden = false;

			var rows = data.topPages || [];
			if ( ! rows.length ) {
				bots.pages.innerHTML = emptyHtml( t( 'botsNoPages', 'No crawler visits recorded yet. Data appears as soon as an AI crawler hits your site.' ) );
				return;
			}

			var html = '<table class="ace-table"><thead><tr>' +
				'<th>' + core.esc( t( 'botsPage', 'Page' ) ) + '</th>' +
				'<th>' + core.esc( t( 'botsHitsCol', 'Hits' ) ) + '</th>' +
				'<th>' + core.esc( t( 'botsLastSeen', 'Last seen' ) ) + '</th>' +
				'</tr></thead><tbody>';
			rows.forEach( function ( r ) {
				html += '<tr>' +
					'<td>' + core.esc( r.path || '' ) + '</td>' +
					'<td>' + core.num( r.hits || 0 ) + '</td>' +
					'<td>' + core.esc( r.lastDay ? core.fmtDate( r.lastDay ) : '' ) + '</td>' +
					'</tr>';
			} );
			html += '</tbody></table>';
			bots.pages.innerHTML = html;
		}

		if ( bots.range ) {
			bots.range.addEventListener( 'change', function () { loadBots(); } );
		}

		if ( bots.report ) {
			bots.report.addEventListener( 'change', function () {
				var box = bots.report;
				core.post( 'save_settings', { bots_report: box.checked ? 'yes' : 'no' } ).then( function () {
					core.toast( t( 'saved', 'Saved.' ) );
				}, function ( err ) {
					box.checked = ! box.checked;
					failWith( null )( err );
				} );
			} );
		}

		if ( bots.send ) {
			bots.send.addEventListener( 'click', function () {
				core.setBusy( bots.send, true );
				core.post( 'bots_report_now', {} ).then( function () {
					core.setBusy( bots.send, false );
					core.toast( t( 'botsReported', 'Crawler summary sent to ACE.' ) );
				}, failWith( bots.send ) );
			} );
		}

		/* =================================================================== */
		/* Overview tab                                                        */
		/* =================================================================== */

		function loadOverview() {
			loaded.overview = true;
			var stats = byId( 'agencoen-aeo-overview-stats' );
			core.skeleton( stats, 'tiles' );
			core.skeleton( byId( 'agencoen-aeo-trend' ), 'chart' );
			core.skeleton( byId( 'agencoen-aeo-presence' ), 'chart' );
			core.skeleton( byId( 'agencoen-aeo-domains' ), 'chart' );
			core.skeleton( byId( 'agencoen-aeo-entities' ), 'chart' );

			core.post( 'aeo_overview', {} ).then( function ( data ) {
				overviewData = data || {};
				renderOverview( overviewData );
				if ( loaded.competitors ) { renderCompetitors(); }
			}, function ( err ) {
				loaded.overview = false;
				failWith( null )( err );
			} );
		}

		function renderOverview( data ) {
			var body = byId( 'agencoen-aeo-overview-body' );
			var empty = byId( 'agencoen-aeo-overview-empty' );

			if ( ! data.hasData ) {
				if ( body ) { body.hidden = true; }
				if ( empty ) { empty.hidden = false; }
				return;
			}
			if ( body ) { body.hidden = false; }
			if ( empty ) { empty.hidden = true; }

			var kpis = data.kpis || {};
			var delta = data.delta || null;

			function ptsTrend( value ) {
				if ( ! delta || typeof value !== 'number' ) { return null; }
				return {
					dir: value >= 0 ? 'up' : 'down',
					text: ( value >= 0 ? '+' : '' ) + ( Math.round( value * 10 ) / 10 ) + ' pts ' + t( 'vsPrevRun', 'vs previous run' )
				};
			}

			byId( 'agencoen-aeo-overview-stats' ).innerHTML =
				core.statTile( {
					label: t( 'shareOfMentions', 'Share of brand mentions' ),
					value: fmtPct( kpis.brandMentionRatePct ),
					accent: 'indigo',
					trend: ptsTrend( delta ? delta.brandMentionRatePct : null )
				} ) +
				core.statTile( {
					label: t( 'shareOfAnswers', 'Share of answers' ),
					value: fmtPct( kpis.shareOfAnswersPct ),
					accent: 'violet',
					trend: ptsTrend( delta ? delta.shareOfAnswersPct : null )
				} ) +
				core.statTile( {
					label: t( 'promptsTracked', 'Prompts tracked' ),
					value: core.num( kpis.prompts || 0 ),
					accent: 'cyan'
				} ) +
				core.statTile( {
					label: t( 'aiResponses', 'AI responses' ),
					value: core.num( kpis.responses || 0 ),
					accent: 'emerald',
					sub: data.runDate ? t( 'lastRun', 'Latest run' ) + ' ' + core.fmtDate( data.runDate ) : ''
				} );

			// SOV trend line.
			var sovPoints = [];
			var mentionPoints = [];
			( data.trend || [] ).forEach( function ( p ) {
				if ( ! p || ! p.date ) { return; }
				var x = core.fmtDate( p.date );
				if ( typeof p.shareOfAnswersPct === 'number' ) { sovPoints.push( { x: x, y: p.shareOfAnswersPct } ); }
				if ( typeof p.brandMentionRatePct === 'number' ) { mentionPoints.push( { x: x, y: p.brandMentionRatePct } ); }
			} );
			charts.line( byId( 'agencoen-aeo-trend' ), {
				series: [
					{ name: t( 'shareOfAnswers', 'Share of answers' ), color: COLOR_YOURS, points: sovPoints },
					{ name: t( 'mentionRate', 'Brand mention rate' ), color: '#06b6d4', points: mentionPoints }
				]
			}, { height: 220, unified: true, yFmt: function ( v ) { return v + '%'; } } );

			// Presence donut.
			var responses = kpis.responses || 0;
			var withBrand = kpis.responsesWithBrandMention || 0;
			var withoutBrand = Math.max( 0, responses - withBrand );
			charts.donut( byId( 'agencoen-aeo-presence' ), [
				{ label: t( 'answersWithBrand', 'Mention your brand' ), value: withBrand, color: COLOR_YOURS },
				{ label: t( 'answersWithoutBrand', 'No brand mention' ), value: withoutBrand, color: COLOR_MUTED }
			], {
				inner: 0.68,
				centerLabel: responses > 0 ? fmtPct( kpis.brandMentionRatePct ) : '',
				legend: true
			} );

			// Top domains.
			charts.barsH( byId( 'agencoen-aeo-domains' ), ( data.topDomains || [] ).slice( 0, 10 ).map( function ( d ) {
				return { label: d.domain || '', value: d.count || 0 };
			} ), {} );

			// Top entities (your brand highlighted).
			charts.barsH( byId( 'agencoen-aeo-entities' ), entityBars( data.topEntities, 10 ), {} );
		}

		function entityBars( list, cap ) {
			var rows = ( list || [] ).map( function ( e ) {
				return {
					label: e.entity || '',
					value: e.total || 0,
					color: e.isYours ? COLOR_YOURS : COLOR_OTHERS
				};
			} );
			rows.sort( function ( a, b ) { return b.value - a.value; } );
			return cap ? rows.slice( 0, cap ) : rows;
		}

		var gotoRuns = byId( 'agencoen-aeo-goto-runs' );
		if ( gotoRuns ) {
			gotoRuns.addEventListener( 'click', function () {
				var tab = root.querySelector( '.ace-tabs [data-tab="runs"]' );
				if ( tab ) { tab.click(); }
			} );
		}

		/* =================================================================== */
		/* Prompts tab                                                         */
		/* =================================================================== */

		var promptRows = [];
		var promptSearch = byId( 'agencoen-aeo-prompt-search' );

		function loadPrompts() {
			loaded.prompts = true;
			var el = byId( 'agencoen-aeo-prompts-table' );
			core.skeleton( el, 'table' );
			core.post( 'aeo_prompts', { page: 1 } ).then( function ( data ) {
				promptRows = ( data && data.prompts ) || [];
				renderPrompts();
			}, function ( err ) {
				loaded.prompts = false;
				failWith( null )( err );
			} );
		}

		function renderPrompts() {
			var el = byId( 'agencoen-aeo-prompts-table' );
			if ( ! el ) { return; }
			var query = ( promptSearch && promptSearch.value ? promptSearch.value : '' ).toLowerCase();
			var rows = promptRows.filter( function ( p ) {
				return ! query || ( p.text || '' ).toLowerCase().indexOf( query ) !== -1;
			} );

			if ( ! rows.length ) {
				el.innerHTML = emptyHtml( promptRows.length
					? t( 'promptsNoMatch', 'No prompts match your filter.' )
					: t( 'promptsNone', 'No prompts saved yet. Add prompts from the Runs tab.' ) );
				return;
			}

			var html = '<table class="ace-table"><thead><tr>' +
				'<th>' + core.esc( t( 'promptCol', 'Prompt' ) ) + '</th>' +
				'<th>' + core.esc( t( 'tagsCol', 'Tags' ) ) + '</th>' +
				'</tr></thead><tbody>';
			rows.forEach( function ( p ) {
				var tags = ( p.tags || [] ).map( function ( tag ) {
					return core.chip( 'neutral', tag );
				} ).join( ' ' );
				html += '<tr><td>' + core.esc( p.text || '' ) + '</td><td>' + tags + '</td></tr>';
			} );
			html += '</tbody></table>';
			el.innerHTML = html;
		}

		if ( promptSearch ) {
			promptSearch.addEventListener( 'input', core.debounce( renderPrompts, 150 ) );
		}

		/* =================================================================== */
		/* Competitors tab (reuses the overview payload)                       */
		/* =================================================================== */

		function loadCompetitors() {
			loaded.competitors = true;
			if ( overviewData ) {
				renderCompetitors();
				return;
			}
			core.skeleton( byId( 'agencoen-aeo-competitors-chart' ), 'chart' );
			core.skeleton( byId( 'agencoen-aeo-competitors-table' ), 'table' );
			core.post( 'aeo_overview', {} ).then( function ( data ) {
				overviewData = data || {};
				renderCompetitors();
			}, function ( err ) {
				loaded.competitors = false;
				failWith( null )( err );
			} );
		}

		function renderCompetitors() {
			var chartEl = byId( 'agencoen-aeo-competitors-chart' );
			var tableEl = byId( 'agencoen-aeo-competitors-table' );
			if ( ! chartEl || ! tableEl ) { return; }

			var list = ( overviewData && overviewData.topEntities ) || [];
			if ( ! overviewData || ! overviewData.hasData || ! list.length ) {
				var message = t( 'competitorsNone', 'No entity data yet. Launch a research run from the Runs tab first.' );
				chartEl.innerHTML = emptyHtml( message );
				tableEl.innerHTML = '';
				return;
			}

			charts.barsH( chartEl, entityBars( list, 12 ), {} );

			var html = '<table class="ace-table"><thead><tr>' +
				'<th>' + core.esc( t( 'brandCol', 'Brand' ) ) + '</th>' +
				'<th>' + core.esc( t( 'mentionsCol', 'Answer mentions' ) ) + '</th>' +
				'<th></th>' +
				'</tr></thead><tbody>';
			list.forEach( function ( e ) {
				html += '<tr>' +
					'<td>' + core.esc( e.entity || '' ) + '</td>' +
					'<td>' + core.num( e.total || 0 ) + '</td>' +
					'<td>' + ( e.isYours ? core.chip( 'info', t( 'yourBrand', 'Your brand' ) ) : '' ) + '</td>' +
					'</tr>';
			} );
			html += '</tbody></table>';
			tableEl.innerHTML = html;
		}

		/* =================================================================== */
		/* Runs tab                                                            */
		/* =================================================================== */

		var runsState = { runs: [], config: null, poller: null };

		function loadRunsTab() {
			loaded.runs = true;
			loadConfig( false );
			fetchRuns( false );
		}

		function loadConfig( fresh ) {
			core.post( 'aeo_config', fresh ? { fresh: 1 } : {} ).then( function ( data ) {
				runsState.config = data || {};
				renderConfig();
			}, failWith( null ) );
		}

		function renderConfig() {
			var c = runsState.config || {};
			var note = byId( 'agencoen-aeo-config-note' );
			var flowForm = byId( 'agencoen-aeo-flow-form' );
			var runForm = byId( 'agencoen-aeo-run-form' );
			if ( ! note || ! flowForm || ! runForm ) { return; }

			if ( ! c.configured ) {
				note.hidden = false;
				note.textContent = t( 'aeoNotConfigured', 'AI Search research is not configured for your ACE workspace yet. Finish setup in the ACE dashboard, then reload this page.' );
				flowForm.hidden = true;
				runForm.hidden = true;
				return;
			}

			if ( ! c.flowId ) {
				note.hidden = true;
				flowForm.hidden = false;
				runForm.hidden = true;
				return;
			}

			note.hidden = true;
			flowForm.hidden = true;
			runForm.hidden = false;

			var defaults = c.defaults || {};
			fillModelsSelect( byId( 'agencoen-aeo-models' ), c.engines || [], defaults.models || [] );
			fillSelect( byId( 'agencoen-aeo-region' ), c.regions || [], defaults.regionCode );
			fillSelect( byId( 'agencoen-aeo-language' ), c.languages || [], defaults.languageCode );

			var countEl = byId( 'agencoen-aeo-promptcount' );
			if ( countEl ) {
				countEl.textContent = ( typeof c.promptCount === 'number' )
					? core.num( c.promptCount ) + ' ' + t( 'promptsOnFlow', 'prompts saved on your research flow.' )
					: '';
			}
		}

		function pickValue( item, keys ) {
			for ( var i = 0; i < keys.length; i++ ) {
				var v = item[ keys[ i ] ];
				if ( typeof v === 'string' && v !== '' ) { return v; }
				if ( typeof v === 'number' ) { return String( v ); }
			}
			return '';
		}

		function fillModelsSelect( select, items, selectedValues ) {
			if ( ! select ) { return; }
			select.innerHTML = '';
			var chosen = {};
			( selectedValues || [] ).forEach( function ( v ) { chosen[ String( v ) ] = true; } );
			( items || [] ).forEach( function ( item ) {
				if ( item === null || typeof item !== 'object' ) { item = { id: item }; }
				var value = pickValue( item, [ 'id', 'model_id', 'value', 'code', 'slug' ] );
				if ( ! value ) { return; }
				var option = document.createElement( 'option' );
				option.value = value;
				option.textContent = pickValue( item, [ 'name', 'label', 'title' ] ) || value;
				if ( chosen[ value ] ) { option.selected = true; }
				select.appendChild( option );
			} );
		}

		function fillSelect( select, items, selectedValue ) {
			if ( ! select ) { return; }
			select.innerHTML = '';
			var placeholder = document.createElement( 'option' );
			placeholder.value = '';
			placeholder.textContent = t( 'flowDefault', 'Flow default' );
			select.appendChild( placeholder );
			var selected = selectedValue ? String( selectedValue ) : '';
			( items || [] ).forEach( function ( item ) {
				if ( item === null || typeof item !== 'object' ) { item = { code: item }; }
				var value = pickValue( item, [ 'code', 'region_code', 'language_code', 'id', 'value' ] );
				if ( ! value ) { return; }
				var option = document.createElement( 'option' );
				option.value = value;
				option.textContent = pickValue( item, [ 'name', 'label', 'title' ] ) || value;
				if ( selected && selected === value ) { option.selected = true; }
				select.appendChild( option );
			} );
		}

		function gatherRunArgs() {
			var args = {};
			var models = [];
			var select = byId( 'agencoen-aeo-models' );
			if ( select ) {
				for ( var i = 0; i < select.options.length; i++ ) {
					if ( select.options[ i ].selected ) { models.push( select.options[ i ].value ); }
				}
			}
			if ( models.length ) {
				args.models = models;
			} else {
				args.use_defaults = 1;
			}
			var region = byId( 'agencoen-aeo-region' );
			if ( region && region.value ) { args.region_code = region.value; }
			var language = byId( 'agencoen-aeo-language' );
			if ( language && language.value ) { args.language_code = language.value; }
			return args;
		}

		function readPromptLines() {
			var textarea = byId( 'agencoen-aeo-prompts-input' );
			if ( ! textarea ) { return []; }
			return textarea.value.split( '\n' ).map( function ( line ) {
				return line.replace( /^\s+|\s+$/g, '' );
			} ).filter( function ( line ) {
				return line.length >= 3;
			} );
		}

		function wireRunActions() {
			var suggestBtn = byId( 'agencoen-aeo-suggest' );
			if ( suggestBtn ) {
				suggestBtn.addEventListener( 'click', function () {
					var topicEl = byId( 'agencoen-aeo-suggest-topic' );
					var brandedEl = byId( 'agencoen-aeo-branded' );
					var payload = { branded: brandedEl && brandedEl.checked ? 1 : 0 };
					var topic = topicEl && topicEl.value ? topicEl.value.replace( /^\s+|\s+$/g, '' ) : '';
					if ( topic ) { payload.topic = topic; }
					core.setBusy( suggestBtn, true );
					core.post( 'aeo_prompts_suggest', payload ).then( function ( data ) {
						core.setBusy( suggestBtn, false );
						var lines = ( ( data && data.prompts ) || [] ).map( function ( p ) {
							return typeof p === 'string' ? p : ( p && p.text ) || '';
						} ).filter( function ( line ) { return !! line; } );
						if ( ! lines.length ) {
							core.toast( t( 'noSuggestions', 'No suggestions came back. Try adding a topic.' ) );
							return;
						}
						var textarea = byId( 'agencoen-aeo-prompts-input' );
						if ( textarea ) {
							textarea.value = ( textarea.value ? textarea.value.replace( /\s+$/, '' ) + '\n' : '' ) + lines.join( '\n' );
						}
						core.toast( core.num( lines.length ) + ' ' + t( 'suggestionsAdded', 'suggested prompts added to the editor.' ) );
					}, failWith( suggestBtn ) );
				} );
			}

			var saveBtn = byId( 'agencoen-aeo-prompts-save' );
			if ( saveBtn ) {
				saveBtn.addEventListener( 'click', function () {
					var lines = readPromptLines();
					if ( ! lines.length ) {
						core.errorToast( t( 'promptsRequired', 'Add at least one prompt (three characters or more, one per line).' ) );
						return;
					}
					var modeEl = root.querySelector( 'input[name="agencoen_aeo_prompt_mode"]:checked' );
					var mode = modeEl ? modeEl.value : 'append';
					if ( mode === 'replace' && ! window.confirm( t( 'confirmReplacePrompts', 'Replace all saved prompts with the lines in the editor?' ) ) ) {
						return;
					}
					core.setBusy( saveBtn, true );
					core.post( 'aeo_prompts_save', { prompts: lines, mode: mode } ).then( function () {
						core.setBusy( saveBtn, false );
						core.toast( t( 'promptsSaved', 'Prompts saved.' ) );
						loaded.prompts = false;
						loadConfig( true );
					}, failWith( saveBtn ) );
				} );
			}

			var estimateBtn = byId( 'agencoen-aeo-estimate' );
			if ( estimateBtn ) {
				estimateBtn.addEventListener( 'click', function () {
					core.setBusy( estimateBtn, true );
					core.post( 'aeo_estimate', gatherRunArgs() ).then( function ( data ) {
						core.setBusy( estimateBtn, false );
						core.toast( estimateSummary( data ) );
					}, failWith( estimateBtn ) );
				} );
			}

			var launchBtn = byId( 'agencoen-aeo-launch' );
			if ( launchBtn ) {
				launchBtn.addEventListener( 'click', function () {
					core.setBusy( launchBtn, true );
					core.post( 'aeo_run_start', gatherRunArgs() ).then( function () {
						core.setBusy( launchBtn, false );
						core.toast( t( 'runLaunched', 'Run launched. Results usually arrive within a few minutes.' ) );
						fetchRuns( true );
					}, failWith( launchBtn ) );
				} );
			}

			var flowBtn = byId( 'agencoen-aeo-flow-create' );
			if ( flowBtn ) {
				flowBtn.addEventListener( 'click', function () {
					var nameEl = byId( 'agencoen-aeo-flow-name' );
					var brandEl = byId( 'agencoen-aeo-flow-brand' );
					var name = nameEl && nameEl.value ? nameEl.value.replace( /^\s+|\s+$/g, '' ) : '';
					var brand = brandEl && brandEl.value ? brandEl.value.replace( /^\s+|\s+$/g, '' ) : '';
					if ( name.length < 2 || ! brand ) {
						core.errorToast( t( 'flowFieldsRequired', 'Enter a flow name and your brand name.' ) );
						return;
					}
					core.setBusy( flowBtn, true );
					core.post( 'aeo_flow_create', { name: name, brand_name: brand } ).then( function () {
						core.setBusy( flowBtn, false );
						core.toast( t( 'flowCreated', 'Research flow created.' ) );
						loadConfig( true );
					}, failWith( flowBtn ) );
				} );
			}
		}

		function estimateSummary( data ) {
			var est = ( data && data.estimate ) || data || {};
			if ( typeof est !== 'object' || est === null ) { est = {}; }

			// Look for a credits-like numeric field, defensively.
			var keys = Object.keys( est );
			for ( var i = 0; i < keys.length; i++ ) {
				var key = keys[ i ];
				if ( /credit|cost/i.test( key ) && typeof est[ key ] === 'number' ) {
					return t( 'estimatePrefix', 'Estimated cost:' ) + ' ' + core.num( est[ key ] ) + ' ' + t( 'credits', 'credits' );
				}
			}
			var raw = '';
			try { raw = JSON.stringify( est ); } catch ( e ) { raw = ''; }
			if ( raw && raw.length > 140 ) { raw = raw.slice( 0, 140 ) + '…'; }
			return raw && raw !== '{}'
				? t( 'estimateReady', 'Estimate:' ) + ' ' + raw
				: t( 'estimateEmpty', 'The estimate came back empty. You can launch the run directly.' );
		}

		/* --- Runs table + polling ----------------------------------------- */

		function fetchRuns( fresh ) {
			var el = byId( 'agencoen-aeo-runs-table' );
			if ( ! el ) { return; }
			if ( ! runsState.runs.length ) { core.skeleton( el, 'table' ); }
			core.post( 'aeo_runs', fresh ? { fresh: 1 } : {} ).then( function ( data ) {
				runsState.runs = ( data && data.runs ) || [];
				renderRuns();
				maybePoll();
			}, function ( err ) {
				el.innerHTML = emptyHtml( ( err && err.message ) || t( 'genericError', 'Something went wrong. Please try again.' ) );
			} );
		}

		function runIsActive( run ) {
			if ( run.completed ) { return false; }
			var status = String( run.status || '' ).toLowerCase();
			return status !== 'failed' && status !== 'cancelled' && status !== 'canceled';
		}

		function maybePoll() {
			var active = false;
			for ( var i = 0; i < runsState.runs.length; i++ ) {
				if ( runIsActive( runsState.runs[ i ] ) ) { active = true; break; }
			}
			if ( active ) {
				if ( ! runsState.poller ) {
					runsState.poller = core.makePoller( function () { fetchRuns( true ); }, {
						interval: 20000,
						backoffAfterMs: 180000,
						maxInterval: 60000
					} );
				}
				runsState.poller.start();
			} else if ( runsState.poller ) {
				runsState.poller.stop();
			}
		}

		function statusChip( run ) {
			var status = String( run.status || '' ).toLowerCase();
			if ( run.completed || status === 'completed' ) { return core.chip( 'success', t( 'statusCompleted', 'Completed' ) ); }
			if ( status === 'failed' ) { return core.chip( 'error', t( 'statusFailed', 'Failed' ) ); }
			if ( ! status ) { return core.chip( 'neutral', t( 'statusUnknown', 'Unknown' ) ); }
			// Humanize upstream statuses like "in_progress".
			var label = status.replace( /[_-]+/g, ' ' );
			return core.chip( 'info', label.charAt( 0 ).toUpperCase() + label.slice( 1 ) );
		}

		function snapshotChip( run ) {
			if ( run.hasSnapshot ) { return core.chip( 'success', t( 'snapshotReady', 'Ready' ) ); }
			if ( run.completed ) { return core.chip( 'warning', t( 'snapshotBuilding', 'Building' ) ); }
			return core.chip( 'neutral', t( 'snapshotPendingRun', 'After run' ) );
		}

		function renderRuns() {
			var el = byId( 'agencoen-aeo-runs-table' );
			if ( ! el ) { return; }
			var runs = runsState.runs;
			if ( ! runs.length ) {
				el.innerHTML = emptyHtml( t( 'runsNone', 'No research runs yet. Configure your prompts above and launch the first run.' ) );
				return;
			}

			var html = '<table class="ace-table"><thead><tr>' +
				'<th>' + core.esc( t( 'runCol', 'Run' ) ) + '</th>' +
				'<th>' + core.esc( t( 'statusCol', 'Status' ) ) + '</th>' +
				'<th>' + core.esc( t( 'startedCol', 'Started' ) ) + '</th>' +
				'<th>' + core.esc( t( 'endedCol', 'Ended' ) ) + '</th>' +
				'<th>' + core.esc( t( 'snapshotCol', 'Results' ) ) + '</th>' +
				'<th></th>' +
				'</tr></thead><tbody>';
			runs.forEach( function ( run ) {
				var id = String( run.id || '' );
				html += '<tr>' +
					'<td><code>' + core.esc( id.slice( 0, 8 ) ) + '</code></td>' +
					'<td>' + statusChip( run ) + '</td>' +
					'<td>' + core.esc( run.startedAt ? core.fmtDate( run.startedAt ) : '' ) + '</td>' +
					'<td>' + core.esc( run.endedAt ? core.fmtDate( run.endedAt ) : '' ) + '</td>' +
					'<td>' + snapshotChip( run ) + '</td>' +
					'<td><button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-run="' + core.esc( id ) + '">' + core.esc( t( 'viewRun', 'View' ) ) + '</button></td>' +
					'</tr>';
			} );
			html += '</tbody></table>';
			el.innerHTML = html;
		}

		function runDetailHtml( data ) {
			if ( data.snapshotPending ) {
				return '<p class="ace-help">' + core.esc( t( 'snapshotStillBuilding', 'The results snapshot is still building. Check back in a minute.' ) ) + '</p>';
			}
			var prompts = data.prompts || [];
			if ( ! prompts.length ) {
				return '<p class="ace-help">' + core.esc( t( 'noPromptResults', 'No per prompt results are available for this run yet.' ) ) + '</p>';
			}
			var html = '<table class="ace-table"><thead><tr>' +
				'<th>' + core.esc( t( 'promptCol', 'Prompt' ) ) + '</th>' +
				'<th>' + core.esc( t( 'engineCol', 'Engine' ) ) + '</th>' +
				'<th>' + core.esc( t( 'brandCol', 'Brand' ) ) + '</th>' +
				'<th>' + core.esc( t( 'citationsCol', 'Citations' ) ) + '</th>' +
				'<th>' + core.esc( t( 'entitiesCol', 'Top entities' ) ) + '</th>' +
				'</tr></thead><tbody>';
			prompts.forEach( function ( p ) {
				var citations = ( p.citations && p.citations.length ) || 0;
				var entities = ( p.topEntities || [] ).join( ', ' );
				html += '<tr>' +
					'<td>' + core.esc( p.question || '' ) + '</td>' +
					'<td>' + core.esc( p.model || '' ) + '</td>' +
					'<td>' + ( p.brandMentioned
						? core.chip( 'success', t( 'mentioned', 'Mentioned' ) )
						: core.chip( 'neutral', t( 'notMentioned', 'Not mentioned' ) ) ) + '</td>' +
					'<td>' + core.num( citations ) + '</td>' +
					'<td>' + core.esc( entities ) + '</td>' +
					'</tr>';
			} );
			html += '</tbody></table>';
			return html;
		}

		function wireRunDetails() {
			var el = byId( 'agencoen-aeo-runs-table' );
			if ( ! el ) { return; }
			el.addEventListener( 'click', function ( event ) {
				var btn = event.target && event.target.closest ? event.target.closest( '[data-run]' ) : null;
				if ( ! btn ) { return; }
				var runId = btn.getAttribute( 'data-run' );
				var row = btn.closest( 'tr' );
				if ( ! row || ! row.parentNode ) { return; }

				// Toggle off when this run's detail is already open.
				var next = row.nextElementSibling;
				if ( next && next.getAttribute( 'data-detail' ) === runId ) {
					next.parentNode.removeChild( next );
					return;
				}
				// Close any other open detail row.
				var open = el.querySelector( 'tr[data-detail]' );
				if ( open && open.parentNode ) { open.parentNode.removeChild( open ); }

				var detail = document.createElement( 'tr' );
				detail.setAttribute( 'data-detail', runId );
				var cell = document.createElement( 'td' );
				cell.colSpan = 6;
				detail.appendChild( cell );
				row.parentNode.insertBefore( detail, row.nextSibling );
				core.skeleton( cell, 'table' );

				core.setBusy( btn, true );
				core.post( 'aeo_run_get', { run_id: runId } ).then( function ( data ) {
					core.setBusy( btn, false );
					cell.innerHTML = runDetailHtml( data || {} );
				}, function ( err ) {
					core.setBusy( btn, false );
					cell.innerHTML = emptyHtml( ( err && err.message ) || t( 'genericError', 'Something went wrong. Please try again.' ) );
				} );
			} );
		}

		/* =================================================================== */
		/* Tab routing                                                         */
		/* =================================================================== */

		function activate( tab ) {
			if ( tab === 'crawlers' && ! loaded.crawlers ) { loadBots(); }

			if ( runsState.poller && tab !== 'runs' ) { runsState.poller.stop(); }

			if ( ! researchOn ) { return; }
			if ( tab === 'overview' && ! loaded.overview ) { loadOverview(); }
			if ( tab === 'prompts' && ! loaded.prompts ) { loadPrompts(); }
			if ( tab === 'competitors' && ! loaded.competitors ) { loadCompetitors(); }
			if ( tab === 'runs' ) {
				if ( ! loaded.runs ) { loadRunsTab(); } else { maybePoll(); }
			}
		}

		if ( researchOn ) {
			wireRunActions();
			wireRunDetails();
		}

		core.tabs( root, function ( tab ) { activate( tab ); } );

		var activeTab = root.querySelector( '.ace-tabs .ace-tab.is-active' );
		activate( activeTab ? activeTab.getAttribute( 'data-tab' ) : ( root.getAttribute( 'data-default-tab' ) || 'overview' ) );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
