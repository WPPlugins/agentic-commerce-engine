/**
 * Content screen: generate AI articles, resume project briefs, publish to WP.
 *
 * Runs against the shared window.agencoen.core contracts (post, tabs, toast,
 * chips, statTile, makePoller) and charts.ring for job progress.
 *
 * @package Agencoen
 */
( function () {
	'use strict';

	var ns = window.agencoen || {};
	var core = ns.core;
	var charts = ns.charts || {};
	var cfg = window.agencoenConfig || {};
	var i18n = cfg.i18n || {};
	var root = document.getElementById( 'agencoen-content' );

	// Briefs, the type catalog and the brand voice come from ACE. The posts
	// list and its counts are local, so they load either way.
	var connected = !! cfg.connected;

	if ( ! root || ! core ) {
		return;
	}

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function $( suffix ) {
		return document.getElementById( 'agencoen-content-' + suffix );
	}

	var state = {
		types: {},
		defaultStatus: root.getAttribute( 'data-content-status' ) || 'draft',
		counts: { drafts: 0, published: 0 },
		briefsTotal: null,
		job: null,
		poller: null,
		briefsPage: 1,
		briefsLoaded: false,
		postsPage: 1,
		postsLoaded: false
	};

	var BRIEF_STATUS = {
		waiting_to_be_picked_up: [ 'info', t( 'stQueued', 'Queued' ) ],
		work_in_progress: [ 'warning', t( 'stGenerating', 'Generating' ) ],
		with_editor: [ 'info', t( 'stWithEditor', 'With editor' ) ],
		draft: [ 'warning', t( 'stReady', 'Ready to publish' ) ],
		published: [ 'success', t( 'stPublished', 'Published' ) ]
	};

	var POST_STATUS = {
		publish: [ 'success', t( 'postLive', 'Live' ) ],
		draft: [ 'warning', t( 'postDraft', 'Draft' ) ],
		pending: [ 'info', t( 'postPending', 'Pending' ) ],
		future: [ 'info', t( 'postScheduled', 'Scheduled' ) ],
		'private': [ 'neutral', t( 'postPrivate', 'Private' ) ]
	};

	/* ------------------------------------------------------------------ */
	/* Stats                                                               */
	/* ------------------------------------------------------------------ */

	function renderStats() {
		var el = $( 'stats' );
		if ( ! el ) {
			return;
		}
		el.innerHTML =
			core.statTile( {
				label: t( 'statBriefs', 'Briefs' ),
				value: connected ? ( null === state.briefsTotal ? '…' : core.num( state.briefsTotal ) ) : '–',
				sub: t( 'statBriefsSub', 'In your ACE project' ),
				accent: 'info'
			} ) +
			core.statTile( {
				label: t( 'statDrafts', 'WP drafts' ),
				value: core.num( state.counts.drafts || 0 ),
				sub: t( 'statDraftsSub', 'Waiting to go live' ),
				accent: 'warning'
			} ) +
			core.statTile( {
				label: t( 'statPublished', 'Published' ),
				value: core.num( state.counts.published || 0 ),
				sub: t( 'statPublishedSub', 'Live on this site' ),
				accent: 'success'
			} );
	}

	function refreshCounts() {
		core.post( 'content_posts', { page: 1 } ).then( function ( res ) {
			state.counts = res.counts || state.counts;
			renderStats();
			if ( state.postsLoaded && 1 === state.postsPage ) {
				renderPostsTable( res.items || [] );
				renderPagination( $( 'posts-pagination' ), res, loadPosts );
			}
		} ).catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* Brand note + type catalog                                           */
	/* ------------------------------------------------------------------ */

	function brandLink( url ) {
		var href = url || ( ( cfg.baseUrl || '' ) + '/settings/brand' );
		return '<a href="' + core.esc( href ) + '" target="_blank" rel="noopener">' +
			core.esc( t( 'addVoice', 'Add a brand voice in ACE' ) ) + '</a>';
	}

	function loadBrand() {
		var note = $( 'brand-note' );
		if ( ! note ) {
			return;
		}
		core.post( 'brand_get' ).then( function ( brand ) {
			if ( brand && brand.name ) {
				var line = t( 'writingAs', 'Writing as %s.' ).replace( '%s', brand.name );
				var html = '<strong>' + core.esc( line ) + '</strong>';
				if ( brand.voiceSummary ) {
					var snippet = brand.voiceSummary.length > 140 ? brand.voiceSummary.slice( 0, 140 ) + '…' : brand.voiceSummary;
					html += ' ' + core.esc( snippet );
				} else if ( ! brand.hasVoice ) {
					html += ' ' + brandLink( brand.editUrl );
				}
				note.innerHTML = html;
			} else {
				note.innerHTML = brandLink( brand && brand.editUrl );
			}
		} ).catch( function () {
			note.textContent = '';
		} );
	}

	function typeName( id ) {
		if ( ! id ) {
			return '';
		}
		if ( state.types[ id ] ) {
			return state.types[ id ].name;
		}
		return id.replace( /^(preset:|custom:)/, '' ).replace( /[-_]+/g, ' ' );
	}

	function onTypeChange() {
		var select = $( 'type' );
		var entry = state.types[ select.value ] || null;
		var hint = $( 'type-hint' );
		if ( hint ) {
			hint.textContent = entry && entry.description ? entry.description : '';
		}
		var category = $( 'category' );
		if ( category ) {
			category.hidden = ! ( entry && entry.hasCategorySelection );
		}
		var tone = $( 'tone' );
		if ( tone && ! tone.value && entry && entry.defaultTone ) {
			tone.placeholder = String( entry.defaultTone );
		}
		var words = $( 'wordcount' );
		if ( words && ! words.value && entry && entry.targetLength ) {
			words.placeholder = String( entry.targetLength );
		}
	}

	function populateTypes( catalog ) {
		var select = $( 'type' );
		if ( ! select ) {
			return;
		}
		var groups = [
			[ 'builtin', t( 'typesBuiltin', 'Built in' ) ],
			[ 'presets', t( 'typesPresets', 'Presets' ) ],
			[ 'custom', t( 'typesCustom', 'Custom' ) ]
		];
		var html = '<option value="">' + core.esc( t( 'typeDefault', 'Standard article' ) ) + '</option>';
		state.types = {};
		groups.forEach( function ( group ) {
			var list = ( catalog.articleTypes && catalog.articleTypes[ group[ 0 ] ] ) || [];
			if ( ! list.length ) {
				return;
			}
			html += '<optgroup label="' + core.esc( group[ 1 ] ) + '">';
			list.forEach( function ( entry ) {
				state.types[ entry.id ] = entry;
				html += '<option value="' + core.esc( entry.id ) + '">' + core.esc( entry.name ) + '</option>';
			} );
			html += '</optgroup>';
		} );
		select.innerHTML = html;
		onTypeChange();
	}

	function loadTypes() {
		core.post( 'content_types_list' ).then( populateTypes ).catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* Generate + job progress                                             */
	/* ------------------------------------------------------------------ */

	function stopPolling() {
		if ( state.poller ) {
			state.poller.stop();
			state.poller = null;
		}
	}

	function resetProgress() {
		stopPolling();
		state.job = null;
		var card = $( 'progress' );
		if ( card ) {
			card.hidden = true;
		}
	}

	function showProgress() {
		var card = $( 'progress' );
		var live = $( 'progress-live' );
		var result = $( 'result' );
		if ( card ) {
			card.hidden = false;
		}
		if ( live ) {
			live.hidden = false;
		}
		if ( result ) {
			result.hidden = true;
		}
		var extra = $( 'result-view' );
		if ( extra ) {
			extra.remove();
		}
		updateRing( 4, t( 'phaseQueued', 'Queued' ) );
	}

	function updateRing( pct, message ) {
		var ringEl = $( 'ring' );
		if ( ringEl && charts.ring ) {
			charts.ring( ringEl, { value: pct, size: 120, label: Math.round( pct ) + '%' } );
		}
		var phase = $( 'phase' );
		if ( phase ) {
			phase.textContent = message || t( 'phaseWorking', 'Working' );
		}
	}

	function updateProgress( res ) {
		var progress = res.progress || {};
		var pct = 8;
		if ( progress.total > 0 ) {
			pct = Math.max( 4, Math.min( 100, ( ( progress.completed || 0 ) / progress.total ) * 100 ) );
		}
		updateRing( pct, progress.message || progress.phase || t( 'phaseWorking', 'Working' ) );
	}

	function renderResult( res ) {
		var live = $( 'progress-live' );
		var panel = $( 'result' );
		var article = res.article || {};

		if ( live ) {
			live.hidden = true;
		}
		if ( ! panel ) {
			return;
		}
		panel.hidden = false;

		var titleEl = $( 'result-title' );
		if ( titleEl ) {
			titleEl.textContent = article.title || '';
		}

		var meta = [];
		if ( article.wordCount ) {
			meta.push( t( 'wordCountLabel', '%s words' ).replace( '%s', core.num( article.wordCount ) ) );
		}
		if ( article.locale && 'auto' !== article.locale ) {
			meta.push( article.locale );
		}
		var metaEl = $( 'result-meta' );
		if ( metaEl ) {
			metaEl.textContent = meta.join( ' | ' );
		}

		var seoTitle = $( 'result-seotitle' );
		if ( seoTitle ) {
			seoTitle.textContent = article.seoTitle || t( 'none', 'None' );
		}
		var seoDesc = $( 'result-seodesc' );
		if ( seoDesc ) {
			seoDesc.textContent = article.seoDescription || t( 'none', 'None' );
		}

		var edit = $( 'result-edit' );
		if ( edit ) {
			if ( res.editLink ) {
				edit.href = res.editLink;
				edit.hidden = false;
			} else {
				edit.hidden = true;
			}
		}
	}

	function pollOnce() {
		if ( ! state.job ) {
			stopPolling();
			return Promise.resolve();
		}
		return core.post( 'content_job_status', { job_id: state.job.jobId } ).then( function ( res ) {
			if ( ! state.job ) {
				return;
			}
			if ( 'completed' === res.status || 'partial' === res.status ) {
				stopPolling();
				state.job.postId = res.postId || 0;
				state.job.editLink = res.editLink || '';
				updateRing( 100, t( 'phaseDone', 'Article ready' ) );
				renderResult( res );
				refreshCounts();
			} else if ( 'failed' === res.status ) {
				stopPolling();
				var message = res.error && res.error.message ? res.error.message : t( 'generationFailed', 'Generation failed. Please try again.' );
				core.errorToast( message );
				resetProgress();
			} else {
				updateProgress( res );
			}
		} ).catch( function () {
			// Transient poll error: keep polling; the cron fallback also covers us.
		} );
	}

	function startPolling() {
		stopPolling();
		state.poller = core.makePoller( pollOnce, {
			interval: 6000,
			backoffAfterMs: 120000,
			maxInterval: 15000
		} );
		state.poller.start();
	}

	function onGenerateSubmit( event ) {
		event.preventDefault();

		var title = $( 'title' ).value.trim();
		if ( title.length < 3 ) {
			core.errorToast( t( 'titleShort', 'Give the article a title of at least 3 characters.' ) );
			return;
		}

		var data = { title: title };
		var type = $( 'type' ).value;
		if ( type ) {
			data.type = type;
		}
		var locale = $( 'locale' ).value.trim();
		if ( locale ) {
			data.locale = locale;
		}
		var keywords = $( 'keywords' ).value.trim();
		if ( keywords ) {
			data.keywords = keywords;
		}
		var overview = $( 'overview' ).value.trim();
		if ( overview ) {
			data.overview = overview;
		}
		var tone = $( 'tone' ).value.trim();
		if ( tone ) {
			data.tone = tone;
		}
		var words = parseInt( $( 'wordcount' ).value, 10 );
		if ( words > 0 ) {
			data.word_count = words;
		}

		var category = $( 'category' );
		if ( category && ! category.hidden ) {
			var path = $( 'category-path' ).value.trim();
			var name = $( 'category-name' ).value.trim();
			if ( path && name ) {
				data.category_path = path;
				data.category_name = name;
			}
		}

		var btn = $( 'generate' );
		core.setBusy( btn, true );
		core.post( 'content_generate', data ).then( function ( res ) {
			core.setBusy( btn, false );
			state.job = { jobId: res.jobId, briefId: res.briefId, postId: 0, editLink: '' };
			showProgress();
			startPolling();
			core.toast( t( 'generateStarted', 'Writing has started. This usually takes a minute or two.' ) );
		} ).catch( function ( err ) {
			core.setBusy( btn, false );
			core.errorToast( ( err && err.message ) || t( 'generateFailed', 'Could not start the article.' ) );
		} );
	}

	function onPublishNow() {
		if ( ! state.job || ! state.job.briefId ) {
			return;
		}
		var btn = $( 'result-publish' );
		var data = { brief_id: state.job.briefId };
		if ( state.job.postId > 0 ) {
			data.post_id = state.job.postId;
		}
		core.setBusy( btn, true );
		core.post( 'content_publish', data ).then( function ( res ) {
			core.setBusy( btn, false );
			btn.disabled = true;
			core.toast( res.url ? t( 'publishedLive', 'Published. The article is live.' ) : t( 'draftCreated', 'Draft created.' ) );
			if ( res.url ) {
				var panel = $( 'result' );
				if ( panel && ! $( 'result-view' ) ) {
					panel.insertAdjacentHTML(
						'beforeend',
						'<p id="agencoen-content-result-view"><a href="' + core.esc( res.url ) + '" target="_blank" rel="noopener">' +
							core.esc( t( 'viewLive', 'View the live article' ) ) + '</a></p>'
					);
				}
			}
			refreshCounts();
			if ( state.briefsLoaded ) {
				loadBriefs( state.briefsPage );
			}
		} ).catch( function ( err ) {
			core.setBusy( btn, false );
			core.errorToast( ( err && err.message ) || t( 'publishFailed', 'Could not publish the article.' ) );
		} );
	}

	function onDiscard() {
		if ( ! state.job || ! state.job.postId ) {
			resetProgress();
			return;
		}
		if ( ! window.confirm( t( 'discardConfirm', 'Move this draft to the trash?' ) ) ) {
			return;
		}
		var btn = $( 'result-discard' );
		core.setBusy( btn, true );
		core.post( 'content_discard', { post_id: state.job.postId, brief_id: state.job.briefId } ).then( function () {
			core.setBusy( btn, false );
			core.toast( t( 'discarded', 'Draft moved to the trash.' ) );
			resetProgress();
			refreshCounts();
		} ).catch( function ( err ) {
			core.setBusy( btn, false );
			core.errorToast( ( err && err.message ) || t( 'discardFailed', 'Could not discard the draft.' ) );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Briefs tab                                                          */
	/* ------------------------------------------------------------------ */

	function briefChip( status ) {
		var map = BRIEF_STATUS[ status ];
		return map ? core.chip( map[ 0 ], map[ 1 ] ) : core.chip( 'neutral', status || t( 'stUnknown', 'Unknown' ) );
	}

	function emptyState( message ) {
		return '<div class="ace-empty">' + core.esc( message ) + '</div>';
	}

	function renderPagination( el, meta, loader ) {
		if ( ! el ) {
			return;
		}
		var page = meta.page || 1;
		var totalPages = meta.totalPages || 1;
		if ( totalPages <= 1 ) {
			el.innerHTML = '';
			return;
		}
		el.innerHTML =
			'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-page="' + ( page - 1 ) + '"' + ( page <= 1 ? ' disabled' : '' ) + '>' +
				core.esc( t( 'prev', 'Previous' ) ) + '</button>' +
			'<span class="ace-help">' + core.esc( t( 'pageOf', 'Page %1 of %2' ).replace( '%1', page ).replace( '%2', totalPages ) ) + '</span>' +
			'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-page="' + ( page + 1 ) + '"' + ( page >= totalPages ? ' disabled' : '' ) + '>' +
				core.esc( t( 'next', 'Next' ) ) + '</button>';
		el.querySelectorAll( 'button[data-page]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				loader( parseInt( btn.getAttribute( 'data-page' ), 10 ) );
			} );
		} );
	}

	function renderBriefsTable( items ) {
		var el = $( 'briefs-table' );
		if ( ! el ) {
			return;
		}
		if ( ! items.length ) {
			el.innerHTML = emptyState( t( 'noBriefs', 'No briefs yet. Generate your first article to get started.' ) );
			return;
		}

		var sendLabel = 'publish' === state.defaultStatus ? t( 'rowPublish', 'Publish' ) : t( 'rowCreateDraft', 'Create draft' );
		var html = '<table class="ace-table"><thead><tr>' +
			'<th>' + core.esc( t( 'colTitle', 'Title' ) ) + '</th>' +
			'<th>' + core.esc( t( 'colType', 'Type' ) ) + '</th>' +
			'<th>' + core.esc( t( 'colStatus', 'Status' ) ) + '</th>' +
			'<th>' + core.esc( t( 'colUpdated', 'Updated' ) ) + '</th>' +
			'<th></th>' +
			'</tr></thead><tbody>';

		items.forEach( function ( row ) {
			var actions = [];
			if ( row.hasArticle ) {
				actions.push(
					'<button type="button" class="ace-btn ace-btn-primary ace-btn-sm" data-action="send" data-brief="' + core.esc( row.id ) + '">' +
						core.esc( sendLabel ) + '</button>'
				);
			}
			if ( row.publishedUrl ) {
				actions.push(
					'<a class="ace-btn ace-btn-secondary ace-btn-sm" href="' + core.esc( row.publishedUrl ) + '" target="_blank" rel="noopener">' +
						core.esc( t( 'rowView', 'View' ) ) + '</a>'
				);
			}
			html += '<tr>' +
				'<td>' + core.esc( row.title || '' ) + '</td>' +
				'<td>' + ( row.type ? core.chip( 'neutral', typeName( row.type ) ) : '' ) + '</td>' +
				'<td>' + briefChip( row.status ) + '</td>' +
				'<td>' + core.esc( row.updatedAt ? core.timeAgo( row.updatedAt ) : '' ) + '</td>' +
				'<td>' + actions.join( ' ' ) + '</td>' +
				'</tr>';
		} );

		el.innerHTML = html + '</tbody></table>';
	}

	function loadBriefs( page ) {
		state.briefsPage = page > 0 ? page : 1;
		state.briefsLoaded = true;
		var el = $( 'briefs-table' );
		if ( el && ! el.innerHTML ) {
			core.skeleton( el, 'table' );
		}

		var data = { page: state.briefsPage };
		var status = $( 'briefs-status' ) ? $( 'briefs-status' ).value : 'all';
		if ( status && 'all' !== status ) {
			data.status = status;
		}
		var search = $( 'briefs-search' ) ? $( 'briefs-search' ).value.trim() : '';
		if ( search ) {
			data.search = search;
		}

		core.post( 'content_briefs', data ).then( function ( res ) {
			var meta = ( res._meta && res._meta.pagination ) || {};
			if ( 'number' === typeof meta.total ) {
				state.briefsTotal = meta.total;
				renderStats();
			}
			renderBriefsTable( res.items || [] );
			renderPagination( $( 'briefs-pagination' ), meta, loadBriefs );
		} ).catch( function ( err ) {
			if ( el ) {
				el.innerHTML = emptyState( ( err && err.message ) || t( 'briefsFailed', 'Could not load briefs.' ) );
			}
		} );
	}

	function onBriefsTableClick( event ) {
		var btn = event.target.closest( 'button[data-action="send"]' );
		if ( ! btn ) {
			return;
		}
		var briefId = btn.getAttribute( 'data-brief' );
		core.setBusy( btn, true );
		core.post( 'content_publish', { brief_id: briefId } ).then( function ( res ) {
			core.setBusy( btn, false );
			core.toast( res.url ? t( 'publishedLive', 'Published. The article is live.' ) : t( 'draftCreated', 'Draft created.' ) );
			refreshCounts();
			loadBriefs( state.briefsPage );
		} ).catch( function ( err ) {
			core.setBusy( btn, false );
			core.errorToast( ( err && err.message ) || t( 'publishFailed', 'Could not publish the article.' ) );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Published tab                                                       */
	/* ------------------------------------------------------------------ */

	function postChip( status ) {
		var map = POST_STATUS[ status ];
		return map ? core.chip( map[ 0 ], map[ 1 ] ) : core.chip( 'neutral', status || '' );
	}

	function renderPostsTable( items ) {
		var el = $( 'posts-table' );
		if ( ! el ) {
			return;
		}
		if ( ! items.length ) {
			el.innerHTML = emptyState( t( 'noPosts', 'No ACE articles in WordPress yet. Generated drafts will appear here.' ) );
			return;
		}

		var html = '<table class="ace-table"><thead><tr>' +
			'<th>' + core.esc( t( 'colTitle', 'Title' ) ) + '</th>' +
			'<th>' + core.esc( t( 'colStatus', 'Status' ) ) + '</th>' +
			'<th>' + core.esc( t( 'colDate', 'Date' ) ) + '</th>' +
			'<th></th>' +
			'</tr></thead><tbody>';

		items.forEach( function ( row ) {
			var actions = [];
			if ( 'publish' === row.status && row.permalink ) {
				actions.push(
					'<a class="ace-btn ace-btn-secondary ace-btn-sm" href="' + core.esc( row.permalink ) + '" target="_blank" rel="noopener">' +
						core.esc( t( 'rowView', 'View' ) ) + '</a>'
				);
			}
			if ( row.briefId ) {
				actions.push(
					'<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" data-action="republish" data-brief="' + core.esc( row.briefId ) + '" data-post="' + core.esc( String( row.postId ) ) + '">' +
						core.esc( t( 'rowRepublish', 'Republish latest' ) ) + '</button>'
				);
			}
			var title = row.editLink
				? '<a href="' + core.esc( row.editLink ) + '">' + core.esc( row.title || '' ) + '</a>'
				: core.esc( row.title || '' );

			html += '<tr>' +
				'<td>' + title + '</td>' +
				'<td>' + postChip( row.status ) + '</td>' +
				'<td>' + core.esc( row.publishedAt ? core.fmtDate( row.publishedAt ) : '' ) + '</td>' +
				'<td>' + actions.join( ' ' ) + '</td>' +
				'</tr>';
		} );

		el.innerHTML = html + '</tbody></table>';
	}

	function loadPosts( page ) {
		state.postsPage = page > 0 ? page : 1;
		state.postsLoaded = true;
		var el = $( 'posts-table' );
		if ( el && ! el.innerHTML ) {
			core.skeleton( el, 'table' );
		}

		core.post( 'content_posts', { page: state.postsPage } ).then( function ( res ) {
			state.counts = res.counts || state.counts;
			renderStats();
			renderPostsTable( res.items || [] );
			renderPagination( $( 'posts-pagination' ), res, loadPosts );
		} ).catch( function ( err ) {
			if ( el ) {
				el.innerHTML = emptyState( ( err && err.message ) || t( 'postsFailed', 'Could not load articles.' ) );
			}
		} );
	}

	function onPostsTableClick( event ) {
		var btn = event.target.closest( 'button[data-action="republish"]' );
		if ( ! btn ) {
			return;
		}
		var data = {
			brief_id: btn.getAttribute( 'data-brief' ),
			post_id: btn.getAttribute( 'data-post' ),
			republish: 1
		};
		core.setBusy( btn, true );
		core.post( 'content_publish', data ).then( function ( res ) {
			core.setBusy( btn, false );
			core.toast( res.url ? t( 'republished', 'Updated with the latest version from ACE.' ) : t( 'draftUpdated', 'Draft updated with the latest version.' ) );
			loadPosts( state.postsPage );
		} ).catch( function ( err ) {
			core.setBusy( btn, false );
			core.errorToast( ( err && err.message ) || t( 'publishFailed', 'Could not publish the article.' ) );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	function onTab( key ) {
		if ( 'briefs' === key && ! state.briefsLoaded && connected ) {
			loadBriefs( 1 );
		}
		if ( 'published' === key && ! state.postsLoaded ) {
			loadPosts( 1 );
		}
	}

	function boot() {
		var tabsEl = $( 'tabs' );
		if ( tabsEl && core.tabs ) {
			core.tabs( tabsEl, onTab );
		}

		renderStats();
		refreshCounts();
		if ( connected ) {
			loadBrand();
			loadTypes();
		}

		var form = $( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', onGenerateSubmit );
		}
		var typeSelect = $( 'type' );
		if ( typeSelect ) {
			typeSelect.addEventListener( 'change', onTypeChange );
		}
		var publishBtn = $( 'result-publish' );
		if ( publishBtn ) {
			publishBtn.addEventListener( 'click', onPublishNow );
		}
		var discardBtn = $( 'result-discard' );
		if ( discardBtn ) {
			discardBtn.addEventListener( 'click', onDiscard );
		}

		var briefsTable = $( 'briefs-table' );
		if ( briefsTable ) {
			briefsTable.addEventListener( 'click', onBriefsTableClick );
		}
		var briefsStatus = $( 'briefs-status' );
		if ( briefsStatus ) {
			briefsStatus.addEventListener( 'change', function () {
				loadBriefs( 1 );
			} );
		}
		var briefsSearch = $( 'briefs-search' );
		if ( briefsSearch ) {
			briefsSearch.addEventListener( 'input', core.debounce( function () {
				loadBriefs( 1 );
			}, 400 ) );
		}

		var postsTable = $( 'posts-table' );
		if ( postsTable ) {
			postsTable.addEventListener( 'click', onPostsTableClick );
		}

		var newBtn = $( 'new' );
		if ( newBtn ) {
			newBtn.addEventListener( 'click', function () {
				var tab = tabsEl ? tabsEl.querySelector( '[data-tab="generate"]' ) : null;
				if ( tab ) {
					tab.click();
				}
				var title = $( 'title' );
				if ( title ) {
					title.focus();
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
