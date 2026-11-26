/**
 * Rezolve Ai: native SVG chart kit (v4).
 *
 * Defines window.agencoen.charts: line, sparkline, area (stacked), barsV,
 * barsH, donut, gauge, ring, heatmap. Dependency free; every renderer is
 * (el, data, opts), clears the element, sizes itself from el.clientWidth and
 * re-renders on a shared debounced window resize. Scale math is ported from
 * the Rezolve Ai app's chart kit (src/components/charts/scales.ts); the x-unified
 * hover mirrors LineChart.tsx and the arc math mirrors DonutChart.tsx.
 */
( function () {
	'use strict';

	window.agencoen = window.agencoen || {};

	var SVGNS = 'http://www.w3.org/2000/svg';

	var PALETTE = [ '#6366f1', '#10b981', '#f59e0b', '#f43f5e', '#06b6d4', '#8b5cf6', '#84cc16', '#f97316' ];

	/* ------------------------------------------------------------------ */
	/* Plumbing                                                            */
	/* ------------------------------------------------------------------ */

	function el( tag, attrs ) {
		var node = document.createElementNS( SVGNS, tag );
		for ( var k in attrs ) {
			if ( Object.prototype.hasOwnProperty.call( attrs, k ) ) {
				node.setAttribute( k, attrs[ k ] );
			}
		}
		return node;
	}

	function esc( str ) {
		var d = document.createElement( 'div' );
		d.textContent = str == null ? '' : String( str );
		return d.innerHTML;
	}

	function fmtNum( v ) {
		var n = Number( v );
		if ( ! isFinite( n ) ) {
			return String( v );
		}
		try {
			return Number.isInteger( n )
				? n.toLocaleString()
				: n.toLocaleString( undefined, { maximumFractionDigits: 1 } );
		} catch ( e ) {
			return String( n );
		}
	}

	function i18nStr( key, fallback ) {
		var config = window.agencoenConfig || {};
		return ( config.i18n && config.i18n[ key ] ) || fallback;
	}

	/* Responsive re-render registry (one debounced handler for all charts). */
	var registry = [];
	var resizeTimer = null;

	function register( target, draw ) {
		for ( var i = registry.length - 1; i >= 0; i-- ) {
			if ( registry[ i ].el === target || ! document.body.contains( registry[ i ].el ) ) {
				registry.splice( i, 1 );
			}
		}
		registry.push( { el: target, draw: draw } );
		draw();
	}

	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( function () {
			for ( var i = registry.length - 1; i >= 0; i-- ) {
				if ( ! document.body.contains( registry[ i ].el ) ) {
					registry.splice( i, 1 );
				} else {
					registry[ i ].draw();
				}
			}
		}, 150 );
	} );

	function widthOf( target, fallback ) {
		var w = target.clientWidth;
		return w && w > 40 ? w : ( fallback || 600 );
	}

	function renderEmpty( target ) {
		target.innerHTML = '<div class="ace-chart-empty">' + esc( i18nStr( 'noData', 'No data yet' ) ) + '</div>';
	}

	/* Shared tooltip singleton. */
	function tipEl() {
		var tip = document.getElementById( 'ace-chart-tip' );
		if ( ! tip ) {
			tip = document.createElement( 'div' );
			tip.id = 'ace-chart-tip';
			tip.className = 'ace-chart-tip';
			tip.style.display = 'none';
			document.body.appendChild( tip );
		}
		return tip;
	}

	function showTip( html, x, y ) {
		var tip = tipEl();
		tip.innerHTML = html;
		tip.style.display = 'block';
		var w = tip.offsetWidth;
		var h = tip.offsetHeight;
		var left = x + 14;
		var top = y + 14;
		if ( left + w > window.innerWidth - 8 ) {
			left = x - w - 14;
		}
		if ( top + h > window.innerHeight - 8 ) {
			top = y - h - 14;
		}
		tip.style.left = Math.max( 4, left ) + 'px';
		tip.style.top = Math.max( 4, top ) + 'px';
	}

	function hideTip() {
		tipEl().style.display = 'none';
	}

	function tipRow( color, label, value ) {
		return '<span class="ace-tip-row"><span class="ace-tip-dot" style="background:' + esc( color ) + '"></span>' +
			esc( label ) + '<span class="ace-tip-val">' + esc( value ) + '</span></span>';
	}

	// Escape dismisses a tooltip without moving the pointer.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key || 'Esc' === e.key ) {
			var tip = document.getElementById( 'ace-chart-tip' );
			if ( tip ) {
				tip.style.display = 'none';
			}
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Accessibility: a summary and the numbers, for every chart           */
	/* ------------------------------------------------------------------ */

	/*
	 * A chart is a picture. To a screen reader each one is an image whose
	 * name is a one-line summary of what it shows, and every number it draws
	 * is also in a table that a "Show data table" button opens. The tooltip
	 * is a convenience for a pointer, never the only way to read a value.
	 */

	/**
	 * Fill a %1$s-style template in one pass with a function replacer, so a
	 * "$&" or a "%2$s" inside a label is printed as it is.
	 */
	function fmtStr( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( template ).replace( /%(\d+)\$s/g, function ( match, n ) {
			var arg = args[ Number( n ) - 1 ];
			return arg === undefined ? match : String( arg == null ? '' : arg );
		} );
	}

	/**
	 * What the chart is called: opts.title, else the heading just above it
	 * or the heading of the card it sits in (without any button inside it).
	 */
	function chartTitle( target, opts ) {
		if ( opts && opts.title ) {
			return String( opts.title );
		}
		function headingText( h ) {
			var copy = h.cloneNode( true );
			Array.prototype.slice.call( copy.querySelectorAll( 'button, [hidden], .screen-reader-text' ) ).forEach( function ( node ) {
				node.parentNode.removeChild( node );
			} );
			return copy.textContent.replace( /\s+/g, ' ' ).trim();
		}
		var node = target.previousElementSibling;
		for ( var hops = 0; node && hops < 3; hops++ ) {
			if ( /^H[2-6]$/.test( node.tagName ) || ( node.classList && node.classList.contains( 'ace-chart-head' ) ) ) {
				var h = /^H/.test( node.tagName ) ? node : node.querySelector( 'h2, h3, h4' );
				if ( h && headingText( h ) ) {
					return headingText( h );
				}
			}
			node = node.previousElementSibling;
		}
		var card = target.closest ? target.closest( '.ace-card' ) : null;
		var heading = card ? card.querySelector( 'h2, h3, .ace-card-title' ) : null;
		if ( heading && headingText( heading ) ) {
			return headingText( heading );
		}
		return i18nStr( 'chart', 'Chart' );
	}

	var dataSeq = 0;

	/**
	 * Name the SVG and add the data table after it.
	 * spec: { summary, caption, columns: [ header... ], rows: [ [ rowHeader, cell... ] ] }
	 */
	function accessible( target, svg, spec ) {
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'aria-label', spec.summary );
		svg.setAttribute( 'focusable', 'false' );
		if ( ! spec.rows || ! spec.rows.length ) {
			return;
		}
		if ( ! target._aceTableId ) {
			target._aceTableId = 'ace-chart-data-' + ( ++dataSeq );
		}
		var open = !! target._aceTableOpen;

		var box = document.createElement( 'div' );
		box.className = 'ace-chart-data';
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'ace-link-sm ace-chart-data-toggle';
		btn.setAttribute( 'aria-controls', target._aceTableId );

		var wrap = document.createElement( 'div' );
		wrap.id = target._aceTableId;
		wrap.className = 'ace-chart-data-table';
		wrap.innerHTML = '<table class="ace-table"><caption>' + esc( spec.caption ) + '</caption><thead><tr>' +
			spec.columns.map( function ( c ) {
				return '<th scope="col">' + esc( c ) + '</th>';
			} ).join( '' ) + '</tr></thead><tbody>' +
			spec.rows.map( function ( r ) {
				return '<tr><th scope="row">' + esc( r[ 0 ] ) + '</th>' + r.slice( 1 ).map( function ( cell ) {
					return '<td>' + esc( cell ) + '</td>';
				} ).join( '' ) + '</tr>';
			} ).join( '' ) + '</tbody></table>';

		function sync() {
			wrap.hidden = ! target._aceTableOpen;
			btn.setAttribute( 'aria-expanded', target._aceTableOpen ? 'true' : 'false' );
			btn.textContent = target._aceTableOpen
				? i18nStr( 'hideDataTable', 'Hide data table' )
				: i18nStr( 'showDataTable', 'Show data table' );
		}
		target._aceTableOpen = open;
		sync();
		btn.addEventListener( 'click', function () {
			target._aceTableOpen = ! target._aceTableOpen;
			sync();
		} );
		box.appendChild( btn );
		box.appendChild( wrap );
		target.appendChild( box );
	}

	/** "Low to high" range text for a set of values, formatted. */
	function extremes( values, fmt ) {
		var lo = null;
		var hi = null;
		values.forEach( function ( v ) {
			if ( v === null || v === undefined || ! isFinite( v ) ) {
				return;
			}
			lo = lo === null ? v : Math.min( lo, v );
			hi = hi === null ? v : Math.max( hi, v );
		} );
		return { lo: lo === null ? '' : fmt( lo ), hi: hi === null ? '' : fmt( hi ) };
	}

	/** A summary of named series over shared x keys (line, area). */
	function seriesSummary( title, kind, keys, series, fmt ) {
		var text = fmtStr( i18nStr( 'chartSummarySeries', '%1$s. %2$s from %3$s to %4$s.' ),
			title, kind, keys[ 0 ], keys[ keys.length - 1 ] );
		series.forEach( function ( s ) {
			var values = s.points.map( function ( p ) {
				return p.y;
			} );
			var measured = values.filter( function ( v ) {
				return v !== null && v !== undefined && isFinite( v );
			} );
			if ( ! measured.length ) {
				return;
			}
			var range = extremes( values, fmt );
			text += ' ' + fmtStr( i18nStr( 'chartSummarySeriesLine', '%1$s: lowest %2$s, highest %3$s, latest %4$s.' ),
				s.name || title, range.lo, range.hi, fmt( measured[ measured.length - 1 ] ) );
		} );
		return text;
	}

	/** A table of named series over shared x keys; a gap reads "No data". */
	function seriesRows( keys, series, valueAt, xFmt ) {
		return keys.map( function ( k ) {
			return [ xFmt ? xFmt( k ) : k ].concat( series.map( function ( s, si ) {
				var v = valueAt( si, k );
				return ( v === null || v === undefined ) ? i18nStr( 'chartNoValue', 'No data' ) : v;
			} ) );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Scale math (ported from src/components/charts/scales.ts)            */
	/* ------------------------------------------------------------------ */

	function linearScale( domain, range ) {
		var d0 = domain[ 0 ];
		var d1 = domain[ 1 ];
		var r0 = range[ 0 ];
		var r1 = range[ 1 ];
		var span = ( d1 - d0 ) || 1;
		var fn = function ( v ) {
			return r0 + ( ( v - d0 ) / span ) * ( r1 - r0 );
		};
		fn.domain = domain;
		fn.range = range;
		return fn;
	}

	function bandScale( keys, range, padding ) {
		padding = padding == null ? 0.2 : padding;
		var r0 = range[ 0 ];
		var r1 = range[ 1 ];
		var n = Math.max( 1, keys.length );
		var step = ( r1 - r0 ) / n;
		var bandwidth = Math.max( 0, step * ( 1 - padding ) );
		var index = {};
		keys.forEach( function ( k, i ) {
			index[ k ] = i;
		} );
		var fn = function ( key ) {
			var i = Object.prototype.hasOwnProperty.call( index, key ) ? index[ key ] : 0;
			return r0 + i * step + ( step - bandwidth ) / 2;
		};
		fn.bandwidth = bandwidth;
		fn.step = step;
		fn.center = function ( key ) {
			return fn( key ) + bandwidth / 2;
		};
		return fn;
	}

	function pointScale( keys, range ) {
		var r0 = range[ 0 ];
		var r1 = range[ 1 ];
		var n = keys.length;
		var index = {};
		keys.forEach( function ( k, i ) {
			index[ k ] = i;
		} );
		return function ( key ) {
			var i = Object.prototype.hasOwnProperty.call( index, key ) ? index[ key ] : 0;
			if ( n <= 1 ) {
				return ( r0 + r1 ) / 2;
			}
			return r0 + ( i / ( n - 1 ) ) * ( r1 - r0 );
		};
	}

	function niceTicks( min, max, count ) {
		count = count || 5;
		if ( ! isFinite( min ) || ! isFinite( max ) ) {
			return [];
		}
		if ( min === max ) {
			return [ min ];
		}
		var range = max - min;
		var rawStep = range / Math.max( 1, count );
		var mag = Math.pow( 10, Math.floor( Math.log( rawStep ) / Math.LN10 ) );
		var norm = rawStep / mag;
		var niceStep = ( norm >= 7.5 ? 10 : norm >= 3 ? 5 : norm >= 1.5 ? 2 : 1 ) * mag;
		var start = Math.ceil( min / niceStep ) * niceStep;
		var eps = niceStep * 1e-9;
		var ticks = [];
		for ( var v = start; v <= max + eps; v += niceStep ) {
			ticks.push( Math.abs( v ) < eps ? 0 : Number( v.toFixed( 10 ) ) );
		}
		return ticks;
	}

	function nearestIndex( positions, target ) {
		if ( ! positions.length ) {
			return -1;
		}
		var best = 0;
		var bestDist = Infinity;
		for ( var i = 0; i < positions.length; i++ ) {
			var d = Math.abs( positions[ i ] - target );
			if ( d < bestDist ) {
				bestDist = d;
				best = i;
			}
		}
		return best;
	}

	/* ------------------------------------------------------------------ */
	/* Cartesian frame helpers                                             */
	/* ------------------------------------------------------------------ */

	function yAxis( svg, scale, ticks, margin, innerW, yFmt ) {
		ticks.forEach( function ( tk ) {
			var y = scale( tk );
			svg.appendChild( el( 'line', {
				x1: margin.left, x2: margin.left + innerW, y1: y, y2: y, 'class': 'ace-grid-line'
			} ) );
			var label = el( 'text', { x: margin.left - 8, y: y + 3, 'text-anchor': 'end', 'class': 'ace-axis-label' } );
			label.textContent = yFmt ? yFmt( tk ) : fmtNum( tk );
			svg.appendChild( label );
		} );
	}

	function xLabels( svg, keys, positions, y, maxLabels, xFmt ) {
		var step = Math.max( 1, Math.ceil( keys.length / ( maxLabels || 8 ) ) );
		keys.forEach( function ( k, i ) {
			if ( i % step !== 0 && i !== keys.length - 1 ) {
				return;
			}
			var label = el( 'text', { x: positions[ i ], y: y, 'text-anchor': 'middle', 'class': 'ace-axis-label' } );
			label.textContent = xFmt ? xFmt( k ) : k;
			svg.appendChild( label );
		} );
	}

	function legendHtml( items ) {
		return '<div class="ace-chart-legend">' + items.map( function ( it ) {
			return '<span class="ace-legend-item-row"><span class="ace-legend-swatch" style="background:' + esc( it.color ) + '"></span>' + esc( it.label ) + '</span>';
		} ).join( '' ) + '</div>';
	}

	function normalizeSeries( data ) {
		var series = ( data && data.series ) ? data.series : [];
		return series.map( function ( s, i ) {
			return {
				name: s.name || '',
				color: s.color || PALETTE[ i % PALETTE.length ],
				/*
				 * A null y is UNMEASURED and must stay null all the way to the
				 * path. `Number( p.y ) || 0` used to turn it into a zero, which
				 * draws the line down to the axis and reads as "this version
				 * stopped converting" for a day a source simply did not report.
				 * Zero is a measurement; null is the absence of one.
				 */
				points: ( s.points || [] ).map( function ( p ) {
					var n = ( p.y === null || p.y === undefined ) ? null : Number( p.y );
					return { x: String( p.x ), y: ( n === null || ! isFinite( n ) ) ? null : n };
				} )
			};
		} );
	}

	/*
	 * Split a series into runs of consecutive MEASURED points, so a gap breaks
	 * the stroke instead of being drawn straight through. Mirrors
	 * `measuredRuns` in src/components/charts/impactGeometry.ts, which is the
	 * single sanctioned home of this rule in the app.
	 */
	function measuredRuns( points ) {
		var runs = [];
		var current = [];
		points.forEach( function ( p ) {
			if ( p.y === null || p.y === undefined || ! isFinite( p.y ) ) {
				if ( current.length ) {
					runs.push( current );
				}
				current = [];
				return;
			}
			current.push( p );
		} );
		if ( current.length ) {
			runs.push( current );
		}
		return runs;
	}

	function unionKeys( series ) {
		var seen = {};
		var keys = [];
		series.forEach( function ( s ) {
			s.points.forEach( function ( p ) {
				if ( ! Object.prototype.hasOwnProperty.call( seen, p.x ) ) {
					seen[ p.x ] = true;
					keys.push( p.x );
				}
			} );
		} );
		return keys;
	}

	/**
	 * Build the x-unified hover layer: one hit band per x key; a dashed guide
	 * and per-series emphasis dots follow the pointer; the shared tooltip
	 * lists every series value at that x.
	 */
	function unifiedHover( svg, opts ) {
		var keys = opts.keys;
		var positions = opts.positions;
		var margin = opts.margin;
		var innerH = opts.innerH;
		var innerW = opts.innerW;

		var guide = el( 'line', {
			y1: margin.top, y2: margin.top + innerH, x1: 0, x2: 0,
			stroke: '#d1d5db', 'stroke-width': 1, 'stroke-dasharray': '3 3', 'pointer-events': 'none', visibility: 'hidden'
		} );
		svg.appendChild( guide );

		var dots = opts.dotSpecs.map( function ( spec ) {
			var dot = el( 'circle', {
				r: 3.5, fill: spec.color, stroke: '#ffffff', 'stroke-width': 1.5,
				'pointer-events': 'none', visibility: 'hidden'
			} );
			svg.appendChild( dot );
			return dot;
		} );

		function activate( i, evt ) {
			var x = positions[ i ];
			guide.setAttribute( 'x1', x );
			guide.setAttribute( 'x2', x );
			guide.setAttribute( 'visibility', 'visible' );
			var rows = [];
			opts.dotSpecs.forEach( function ( spec, si ) {
				var y = spec.yAt( keys[ i ] );
				if ( y == null ) {
					dots[ si ].setAttribute( 'visibility', 'hidden' );
					return;
				}
				dots[ si ].setAttribute( 'cx', x );
				dots[ si ].setAttribute( 'cy', y );
				dots[ si ].setAttribute( 'visibility', 'visible' );
				rows.push( tipRow( spec.color, spec.name, spec.valueAt( keys[ i ] ) ) );
			} );
			showTip( '<b>' + esc( opts.xFmt ? opts.xFmt( keys[ i ] ) : keys[ i ] ) + '</b>' + rows.join( '' ), evt.clientX, evt.clientY );
		}

		function deactivate() {
			guide.setAttribute( 'visibility', 'hidden' );
			dots.forEach( function ( dot ) {
				dot.setAttribute( 'visibility', 'hidden' );
			} );
			hideTip();
		}

		// One transparent hit band per x key (mirrors LineChart.tsx bandFor()).
		keys.forEach( function ( k, i ) {
			var left = 0 === i ? margin.left : ( positions[ i - 1 ] + positions[ i ] ) / 2;
			var right = i === keys.length - 1 ? margin.left + innerW : ( positions[ i ] + positions[ i + 1 ] ) / 2;
			var hit = el( 'rect', {
				x: left, y: margin.top, width: Math.max( 0, right - left ), height: innerH, fill: 'transparent'
			} );
			hit.addEventListener( 'mouseenter', function ( evt ) {
				activate( i, evt );
			} );
			hit.addEventListener( 'mousemove', function ( evt ) {
				activate( i, evt );
			} );
			hit.addEventListener( 'mouseleave', deactivate );
			svg.appendChild( hit );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* line                                                                */
	/* ------------------------------------------------------------------ */

	function renderLine( target, data, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		var series = normalizeSeries( data );
		var keys = unionKeys( series );
		if ( ! keys.length ) {
			renderEmpty( target );
			return;
		}

		var w = widthOf( target );
		var h = opts.height || 260;
		// opts.baseline = { until, label, afterLabel }: shade the columns before
		// `until` and rule a line at it, for a before/after chart whose left half
		// is a baseline. Same as the app's LineChart. A key not on the axis draws nothing.
		var baseIdx = opts.baseline ? keys.indexOf( opts.baseline.until ) : -1;
		var margin = { top: baseIdx >= 0 ? 24 : 12, right: 16, bottom: 28, left: 44 };
		var innerW = Math.max( 0, w - margin.left - margin.right );
		var innerH = Math.max( 0, h - margin.top - margin.bottom );

		var lo = Infinity;
		var hi = -Infinity;
		series.forEach( function ( s ) {
			s.points.forEach( function ( p ) {
				if ( p.y === null ) {
					return;
				}
				if ( p.y < lo ) {
					lo = p.y;
				}
				if ( p.y > hi ) {
					hi = p.y;
				}
			} );
		} );
		if ( ! isFinite( lo ) ) {
			lo = 0;
			hi = 1;
		}
		lo = Math.min( 0, lo );
		// opts.yMax pins the top of a fixed scale (percentages), so an all-zero
		// series reads as 0 of 100 instead of filling a 0 to 1 axis. A floor, not
		// a clamp: data above it still fits.
		if ( typeof opts.yMax === 'number' && isFinite( opts.yMax ) ) {
			hi = Math.max( hi, opts.yMax );
		}
		if ( hi === lo ) {
			hi = lo + 1;
		}
		var ticks = niceTicks( lo, hi, 5 );
		var domainHi = Math.max( hi, ticks.length ? ticks[ ticks.length - 1 ] : hi );

		var xPos = pointScale( keys, [ margin.left, margin.left + innerW ] );
		var yScale = linearScale( [ lo, domainHi ], [ margin.top + innerH, margin.top ] );
		var positions = keys.map( xPos );

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );

		if ( baseIdx >= 0 ) {
			var cutX = positions[ baseIdx ];
			var CAPTION_ROOM = 96;
			svg.appendChild( el( 'rect', {
				x: margin.left, y: margin.top, width: Math.max( 0, cutX - margin.left ), height: innerH,
				fill: 'currentColor', 'fill-opacity': 0.04
			} ) );
			svg.appendChild( el( 'line', {
				x1: cutX, x2: cutX, y1: margin.top - 4, y2: margin.top + innerH,
				stroke: 'currentColor', 'stroke-opacity': 0.45, 'stroke-width': 1, 'stroke-dasharray': '3 3'
			} ) );
			if ( cutX - margin.left >= CAPTION_ROOM ) {
				var before = el( 'text', { x: cutX - 6, y: margin.top - 8, 'text-anchor': 'end', 'class': 'ace-axis-label' } );
				before.textContent = opts.baseline.label;
				svg.appendChild( before );
			}
			if ( opts.baseline.afterLabel && margin.left + innerW - cutX >= CAPTION_ROOM ) {
				var after = el( 'text', { x: cutX + 6, y: margin.top - 8, 'text-anchor': 'start', 'class': 'ace-axis-label', 'font-weight': 600 } );
				after.textContent = opts.baseline.afterLabel;
				svg.appendChild( after );
			}
		}

		yAxis( svg, yScale, ticks, margin, innerW, opts.yFmt );
		// At most eight labels, and fewer on a narrow chart: a short date needs
		// about 60px, so eight of them overlap on a phone.
		xLabels( svg, keys, positions, h - 8, Math.max( 2, Math.min( 8, Math.floor( innerW / 60 ) ) ), opts.xFmt );

		var maps = [];
		series.forEach( function ( s ) {
			var map = {};
			s.points.forEach( function ( p ) {
				map[ p.x ] = p.y;
			} );
			maps.push( map );
			measuredRuns( s.points ).forEach( function ( run ) {
				var d = run.map( function ( p, idx ) {
					return ( idx ? 'L ' : 'M ' ) + xPos( p.x ) + ' ' + yScale( p.y );
				} ).join( ' ' );
				svg.appendChild( el( 'path', {
					d: d, fill: 'none', stroke: s.color, 'stroke-width': 2,
					'stroke-linejoin': 'round', 'stroke-linecap': 'round'
				} ) );
			} );
		} );

		if ( false !== opts.unified ) {
			unifiedHover( svg, {
				keys: keys,
				xFmt: opts.xFmt,
				positions: positions,
				margin: margin,
				innerW: innerW,
				innerH: innerH,
				dotSpecs: series.map( function ( s, si ) {
					return {
						name: s.name,
						color: s.color,
						yAt: function ( k ) {
							if ( ! Object.prototype.hasOwnProperty.call( maps[ si ], k ) ) {
								return null;
							}
							/* A gap has no dot. One placed at zero would put a
							   point on the axis for a day nothing was measured. */
							return maps[ si ][ k ] === null ? null : yScale( maps[ si ][ k ] );
						},
						valueAt: function ( k ) {
							var v = maps[ si ][ k ];
							return opts.yFmt ? opts.yFmt( v ) : fmtNum( v );
						}
					};
				} )
			} );
		}

		target.appendChild( svg );
		var showLegend = opts.showLegend != null ? opts.showLegend : series.length > 1;
		if ( showLegend ) {
			target.insertAdjacentHTML( 'beforeend', legendHtml( series.map( function ( s ) {
				return { label: s.name, color: s.color };
			} ) ) );
		}

		var lineFmt = function ( v ) {
			return opts.yFmt ? opts.yFmt( v ) : fmtNum( v );
		};
		var lineTitle = chartTitle( target, opts );
		accessible( target, svg, {
			summary: seriesSummary( lineTitle, i18nStr( 'chartKindLine', 'Line chart' ), keys, series, lineFmt ),
			caption: lineTitle,
			columns: [ opts.xLabel || i18nStr( 'chartColDate', 'Date' ) ].concat( series.map( function ( s ) {
				return s.name || lineTitle;
			} ) ),
			rows: seriesRows( keys, series, function ( si, k ) {
				var v = maps[ si ][ k ];
				return ( v === null || v === undefined ) ? null : lineFmt( v );
			}, opts.xFmt )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* sparkline                                                           */
	/* ------------------------------------------------------------------ */

	function renderSparkline( target, points, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		var list = ( points || [] ).map( function ( p, i ) {
			if ( typeof p === 'number' ) {
				return { x: String( i ), y: p };
			}
			return { x: String( p.x != null ? p.x : i ), y: Number( p.y ) || 0 };
		} );
		if ( ! list.length ) {
			renderEmpty( target );
			return;
		}

		var w = widthOf( target, 200 );
		var h = opts.height || 36;
		var color = opts.color || PALETTE[ 0 ];
		var lo = Infinity;
		var hi = -Infinity;
		list.forEach( function ( p ) {
			if ( p.y < lo ) {
				lo = p.y;
			}
			if ( p.y > hi ) {
				hi = p.y;
			}
		} );
		if ( hi === lo ) {
			hi = lo + 1;
		}
		var pad = 3;
		var xPos = pointScale( list.map( function ( p ) {
			return p.x;
		} ), [ pad, w - pad ] );
		var yScale = linearScale( [ Math.min( 0, lo ), hi ], [ h - pad, pad ] );

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );
		var line = list.map( function ( p, i ) {
			return ( i ? 'L ' : 'M ' ) + xPos( p.x ) + ' ' + yScale( p.y );
		} ).join( ' ' );
		var fill = 'M ' + xPos( list[ 0 ].x ) + ' ' + ( h - pad ) + ' ' + list.map( function ( p ) {
			return 'L ' + xPos( p.x ) + ' ' + yScale( p.y );
		} ).join( ' ' ) + ' L ' + xPos( list[ list.length - 1 ].x ) + ' ' + ( h - pad ) + ' Z';

		svg.appendChild( el( 'path', { d: fill, fill: color, 'fill-opacity': 0.12, stroke: 'none' } ) );
		svg.appendChild( el( 'path', { d: line, fill: 'none', stroke: color, 'stroke-width': 1.75, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } ) );
		var last = list[ list.length - 1 ];
		svg.appendChild( el( 'circle', { cx: xPos( last.x ), cy: yScale( last.y ), r: 2.5, fill: color } ) );

		// Whole-strip hover: report the nearest point.
		var positions = list.map( function ( p ) {
			return xPos( p.x );
		} );
		svg.addEventListener( 'mousemove', function ( evt ) {
			var rect = svg.getBoundingClientRect();
			var sx = ( evt.clientX - rect.left ) * ( w / Math.max( 1, rect.width ) );
			var i = nearestIndex( positions, sx );
			if ( i > -1 ) {
				showTip( '<b>' + esc( list[ i ].x ) + '</b>' + tipRow( color, '', fmtNum( list[ i ].y ) ), evt.clientX, evt.clientY );
			}
		} );
		svg.addEventListener( 'mouseleave', hideTip );

		target.appendChild( svg );

		var sparkTitle = chartTitle( target, opts );
		var sparkRange = extremes( list.map( function ( p ) {
			return p.y;
		} ), fmtNum );
		accessible( target, svg, {
			summary: fmtStr( i18nStr( 'chartSummarySpark', '%1$s. Trend from %2$s to %3$s: lowest %4$s, highest %5$s, latest %6$s.' ),
				sparkTitle, list[ 0 ].x, last.x, sparkRange.lo, sparkRange.hi, fmtNum( last.y ) ),
			caption: sparkTitle,
			columns: [ opts.xLabel || i18nStr( 'chartColDate', 'Date' ), opts.valueLabel || i18nStr( 'chartColValue', 'Value' ) ],
			rows: list.map( function ( p ) {
				return [ p.x, fmtNum( p.y ) ];
			} )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* area (stacked)                                                      */
	/* ------------------------------------------------------------------ */

	function renderArea( target, data, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		var series = normalizeSeries( data );
		var keys = unionKeys( series );
		if ( ! keys.length ) {
			renderEmpty( target );
			return;
		}

		var w = widthOf( target );
		var h = opts.height || 260;
		var margin = { top: 12, right: 16, bottom: 28, left: 44 };
		var innerW = Math.max( 0, w - margin.left - margin.right );
		var innerH = Math.max( 0, h - margin.top - margin.bottom );

		// Per-series lookup (missing x counts as 0 for stacking).
		var maps = series.map( function ( s ) {
			var map = {};
			s.points.forEach( function ( p ) {
				map[ p.x ] = p.y;
			} );
			return map;
		} );
		var stacked = [];
		var running = keys.map( function () {
			return 0;
		} );
		series.forEach( function ( s, si ) {
			var tops = keys.map( function ( k, ki ) {
				running[ ki ] += maps[ si ][ k ] || 0;
				return running[ ki ];
			} );
			stacked.push( tops );
		} );
		var maxSum = 0;
		running.forEach( function ( v ) {
			if ( v > maxSum ) {
				maxSum = v;
			}
		} );
		if ( maxSum <= 0 ) {
			maxSum = 1;
		}
		var ticks = niceTicks( 0, maxSum, 5 );
		var domainHi = Math.max( maxSum, ticks.length ? ticks[ ticks.length - 1 ] : maxSum );

		var xPos = pointScale( keys, [ margin.left, margin.left + innerW ] );
		var yScale = linearScale( [ 0, domainHi ], [ margin.top + innerH, margin.top ] );
		var positions = keys.map( xPos );

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );
		yAxis( svg, yScale, ticks, margin, innerW, opts.yFmt );
		xLabels( svg, keys, positions, h - 8, 8 );

		// Draw bottom series first so upper layers sit on top of it.
		series.forEach( function ( s, si ) {
			var top = stacked[ si ];
			var bottom = 0 === si ? null : stacked[ si - 1 ];
			var d = keys.map( function ( k, ki ) {
				return ( ki ? 'L ' : 'M ' ) + positions[ ki ] + ' ' + yScale( top[ ki ] );
			} ).join( ' ' );
			var back = '';
			for ( var ki = keys.length - 1; ki >= 0; ki-- ) {
				back += ' L ' + positions[ ki ] + ' ' + yScale( bottom ? bottom[ ki ] : 0 );
			}
			svg.appendChild( el( 'path', {
				d: d + back + ' Z', fill: s.color, 'fill-opacity': 0.85, stroke: 'none'
			} ) );
			svg.appendChild( el( 'path', {
				d: d, fill: 'none', stroke: s.color, 'stroke-width': 1.5, 'stroke-linejoin': 'round'
			} ) );
		} );

		if ( false !== opts.unified ) {
			unifiedHover( svg, {
				keys: keys,
				positions: positions,
				margin: margin,
				innerW: innerW,
				innerH: innerH,
				dotSpecs: series.map( function ( s, si ) {
					return {
						name: s.name,
						color: s.color,
						yAt: function ( k ) {
							var ki = keys.indexOf( k );
							return ki > -1 ? yScale( stacked[ si ][ ki ] ) : null;
						},
						valueAt: function ( k ) {
							var v = maps[ si ][ k ] || 0;
							return opts.yFmt ? opts.yFmt( v ) : fmtNum( v );
						}
					};
				} )
			} );
		}

		target.appendChild( svg );
		var showLegend = opts.showLegend != null ? opts.showLegend : series.length > 1;
		if ( showLegend ) {
			target.insertAdjacentHTML( 'beforeend', legendHtml( series.map( function ( s ) {
				return { label: s.name, color: s.color };
			} ) ) );
		}

		var areaFmt = function ( v ) {
			return opts.yFmt ? opts.yFmt( v ) : fmtNum( v );
		};
		var areaTitle = chartTitle( target, opts );
		accessible( target, svg, {
			summary: seriesSummary( areaTitle, i18nStr( 'chartKindArea', 'Area chart' ), keys, series, areaFmt ),
			caption: areaTitle,
			columns: [ opts.xLabel || i18nStr( 'chartColDate', 'Date' ) ].concat( series.map( function ( s ) {
				return s.name || areaTitle;
			} ) ),
			rows: seriesRows( keys, series, function ( si, k ) {
				return areaFmt( maps[ si ][ k ] || 0 );
			} )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* barsV                                                               */
	/* ------------------------------------------------------------------ */

	function renderBarsV( target, data, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		var labels = ( data && data.labels ) || [];
		var values = ( data && data.values ) || [];
		if ( ! labels.length || ! values.length ) {
			renderEmpty( target );
			return;
		}

		var w = widthOf( target );
		var h = opts.height || 240;
		var margin = { top: 16, right: 12, bottom: 28, left: 44 };
		var innerW = Math.max( 0, w - margin.left - margin.right );
		var innerH = Math.max( 0, h - margin.top - margin.bottom );

		var max = 0;
		values.forEach( function ( v ) {
			if ( Number( v ) > max ) {
				max = Number( v );
			}
		} );
		if ( max <= 0 ) {
			max = 1;
		}
		var ticks = niceTicks( 0, max, 4 );
		var domainHi = Math.max( max, ticks.length ? ticks[ ticks.length - 1 ] : max );

		var keys = labels.map( function ( l ) {
			return String( l );
		} );
		var band = bandScale( keys, [ margin.left, margin.left + innerW ], 0.3 );
		var yScale = linearScale( [ 0, domainHi ], [ margin.top + innerH, margin.top ] );

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );
		yAxis( svg, yScale, ticks, margin, innerW, opts.yFmt );

		keys.forEach( function ( k, i ) {
			var v = Number( values[ i ] ) || 0;
			var color = ( opts.colors && opts.colors[ i ] ) || ( data.colors && data.colors[ i ] ) || PALETTE[ 0 ];
			var y = yScale( v );
			var bh = Math.max( 0, margin.top + innerH - y );
			var rect = el( 'rect', {
				x: band( k ), y: v > 0 ? y : margin.top + innerH - 1,
				width: band.bandwidth, height: v > 0 ? bh : 1,
				rx: 4, fill: color, 'fill-opacity': v > 0 ? 1 : 0.35
			} );
			rect.addEventListener( 'mousemove', function ( evt ) {
				showTip( '<b>' + esc( k ) + '</b>' + tipRow( color, '', opts.yFmt ? opts.yFmt( v ) : fmtNum( v ) ), evt.clientX, evt.clientY );
				rect.setAttribute( 'fill-opacity', '0.8' );
			} );
			rect.addEventListener( 'mouseleave', function () {
				hideTip();
				rect.setAttribute( 'fill-opacity', v > 0 ? '1' : '0.35' );
			} );
			svg.appendChild( rect );

			if ( keys.length <= 14 && v > 0 ) {
				var valueLabel = el( 'text', {
					x: band.center( k ), y: y - 5, 'text-anchor': 'middle', 'class': 'ace-bar-value'
				} );
				valueLabel.textContent = opts.yFmt ? opts.yFmt( v ) : fmtNum( v );
				svg.appendChild( valueLabel );
			}
		} );

		var centers = keys.map( function ( k ) {
			return band.center( k );
		} );
		xLabels( svg, keys, centers, h - 8, 10 );

		target.appendChild( svg );

		var barFmt = function ( v ) {
			return opts.yFmt ? opts.yFmt( v ) : fmtNum( v );
		};
		describeBars( target, svg, opts, keys.map( function ( k, i ) {
			return { label: k, value: Number( values[ i ] ) || 0 };
		} ), barFmt );
	}

	/** Bars (either way up): the highest value in words, every value in the table. */
	function describeBars( target, svg, opts, rows, fmt ) {
		var title = chartTitle( target, opts );
		var top = null;
		rows.forEach( function ( r ) {
			if ( ! top || r.value > top.value ) {
				top = r;
			}
		} );
		accessible( target, svg, {
			summary: fmtStr( i18nStr( 'chartSummaryBars', '%1$s. Bar chart of %2$s values. Highest: %3$s, %4$s.' ),
				title, rows.length, top ? top.label : '', top ? fmt( top.value ) : '' ),
			caption: title,
			columns: [ opts.labelHeader || i18nStr( 'chartColName', 'Name' ), opts.valueLabel || i18nStr( 'chartColValue', 'Value' ) ],
			rows: rows.map( function ( r ) {
				return [ r.label, fmt( r.value ) ];
			} )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* barsH                                                               */
	/* ------------------------------------------------------------------ */

	function renderBarsH( target, rows, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		rows = rows || [];
		if ( ! rows.length ) {
			renderEmpty( target );
			return;
		}

		var w = widthOf( target );
		var rowH = 30;
		var h = opts.height || ( rows.length * rowH + 10 );
		var labelW = 0;
		rows.forEach( function ( r ) {
			labelW = Math.max( labelW, String( r.label || '' ).length );
		} );
		labelW = Math.min( 170, Math.max( 70, labelW * 6.6 ) );
		var valueW = 58;
		var barLeft = labelW + 10;
		var barW = Math.max( 20, w - barLeft - valueW - 8 );

		var max = 0;
		rows.forEach( function ( r ) {
			if ( Number( r.value ) > max ) {
				max = Number( r.value );
			}
		} );
		if ( max <= 0 ) {
			max = 1;
		}

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );

		rows.forEach( function ( r, i ) {
			var v = Number( r.value ) || 0;
			var color = r.color || PALETTE[ i % PALETTE.length ];
			var y = 5 + i * rowH;
			var bw = Math.max( 2, ( v / max ) * barW );
			var fmt = opts.valueFmt ? opts.valueFmt( v ) : fmtNum( v );

			var label = el( 'text', { x: labelW, y: y + rowH / 2 + 3, 'text-anchor': 'end', 'class': 'ace-barh-label' } );
			label.textContent = String( r.label || '' );
			svg.appendChild( label );

			svg.appendChild( el( 'rect', {
				x: barLeft, y: y + 6, width: barW, height: rowH - 14, rx: 5, fill: '#f3f4f6'
			} ) );
			var bar = el( 'rect', {
				x: barLeft, y: y + 6, width: bw, height: rowH - 14, rx: 5, fill: color
			} );
			bar.addEventListener( 'mousemove', function ( evt ) {
				showTip( '<b>' + esc( r.label ) + '</b>' + tipRow( color, '', fmt ), evt.clientX, evt.clientY );
				bar.setAttribute( 'fill-opacity', '0.8' );
			} );
			bar.addEventListener( 'mouseleave', function () {
				hideTip();
				bar.setAttribute( 'fill-opacity', '1' );
			} );
			svg.appendChild( bar );

			var valueLabel = el( 'text', { x: barLeft + barW + 8, y: y + rowH / 2 + 3, 'text-anchor': 'start', 'class': 'ace-barh-value' } );
			valueLabel.textContent = fmt;
			svg.appendChild( valueLabel );
		} );

		target.appendChild( svg );

		describeBars( target, svg, opts, rows.map( function ( r ) {
			return { label: String( r.label || '' ), value: Number( r.value ) || 0 };
		} ), function ( v ) {
			return opts.valueFmt ? opts.valueFmt( v ) : fmtNum( v );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* donut (arc math ported from DonutChart.tsx)                         */
	/* ------------------------------------------------------------------ */

	var TAU = Math.PI * 2;

	function polar( cx, cy, r, angle ) {
		// Angle measured clockwise from 12 o'clock.
		return [ cx + r * Math.sin( angle ), cy - r * Math.cos( angle ) ];
	}

	function sliceArc( cx, cy, rOuter, rInner, a0, a1 ) {
		var large = ( a1 - a0 ) > Math.PI ? 1 : 0;
		var o0 = polar( cx, cy, rOuter, a0 );
		var o1 = polar( cx, cy, rOuter, a1 );
		if ( rInner <= 0 ) {
			return 'M ' + cx + ' ' + cy + ' L ' + o0[ 0 ] + ' ' + o0[ 1 ] +
				' A ' + rOuter + ' ' + rOuter + ' 0 ' + large + ' 1 ' + o1[ 0 ] + ' ' + o1[ 1 ] + ' Z';
		}
		var i0 = polar( cx, cy, rInner, a0 );
		var i1 = polar( cx, cy, rInner, a1 );
		return 'M ' + i0[ 0 ] + ' ' + i0[ 1 ] + ' L ' + o0[ 0 ] + ' ' + o0[ 1 ] +
			' A ' + rOuter + ' ' + rOuter + ' 0 ' + large + ' 1 ' + o1[ 0 ] + ' ' + o1[ 1 ] +
			' L ' + i1[ 0 ] + ' ' + i1[ 1 ] +
			' A ' + rInner + ' ' + rInner + ' 0 ' + large + ' 0 ' + i0[ 0 ] + ' ' + i0[ 1 ] + ' Z';
	}

	function renderDonut( target, slices, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		slices = ( slices || [] ).map( function ( s, i ) {
			return {
				raw: s,
				label: s.label || '',
				value: Math.max( 0, Number( s.value ) || 0 ),
				color: s.color || PALETTE[ i % PALETTE.length ]
			};
		} );
		var total = 0;
		slices.forEach( function ( s ) {
			total += s.value;
		} );
		if ( total <= 0 ) {
			renderEmpty( target );
			return;
		}

		var size = opts.size || 190;
		var cx = size / 2;
		var cy = size / 2;
		var rOuter = size / 2 - 2;
		var innerRatio = opts.inner == null ? 0.55 : Math.max( 0, Math.min( 0.9, opts.inner ) );
		var rInner = rOuter * innerRatio;

		var svg = el( 'svg', { viewBox: '0 0 ' + size + ' ' + size, width: size, height: size, 'class': 'ace-donut-svg' } );

		var visible = slices.filter( function ( s ) {
			return s.value > 0;
		} );
		var acc = 0;
		visible.forEach( function ( s, i ) {
			var frac = s.value / total;
			var a0 = acc * TAU;
			var a1 = ( acc + frac ) * TAU;
			acc += frac;
			var nearlyFull = frac >= 0.9999 || 1 === visible.length;
			var shape;
			if ( nearlyFull ) {
				// A full slice can't be a single arc path; draw a ring stroke.
				shape = el( 'circle', {
					cx: cx, cy: cy, r: ( rOuter + rInner ) / 2, fill: 'none',
					stroke: s.color, 'stroke-width': rOuter - rInner
				} );
			} else {
				shape = el( 'path', {
					d: sliceArc( cx, cy, rOuter, rInner, a0, a1 ),
					fill: s.color, stroke: '#ffffff', 'stroke-width': 2
				} );
			}
			var pctStr = ( frac * 100 ).toFixed( 1 ) + '%';
			shape.addEventListener( 'mousemove', function ( evt ) {
				showTip( tipRow( s.color, s.label, fmtNum( s.value ) + ' (' + pctStr + ')' ), evt.clientX, evt.clientY );
				shape.setAttribute( 'opacity', '0.85' );
			} );
			shape.addEventListener( 'mouseleave', function () {
				hideTip();
				shape.setAttribute( 'opacity', '1' );
			} );
			if ( opts.onClick ) {
				shape.style.cursor = 'pointer';
				shape.addEventListener( 'click', function () {
					opts.onClick( s.raw, i );
				} );
			}
			svg.appendChild( shape );
		} );

		// Center label: accepts a string or { value, label }.
		if ( opts.centerLabel ) {
			var center = opts.centerLabel;
			var big = 'object' === typeof center ? center.value : center;
			var small = 'object' === typeof center ? center.label : '';
			var bigText = el( 'text', {
				x: cx, y: small ? cy - 2 : cy, 'text-anchor': 'middle', 'dominant-baseline': 'middle',
				'class': 'ace-donut-center-value', 'pointer-events': 'none'
			} );
			bigText.textContent = big == null ? '' : String( big );
			svg.appendChild( bigText );
			if ( small ) {
				var smallText = el( 'text', {
					x: cx, y: cy + 18, 'text-anchor': 'middle', 'dominant-baseline': 'middle',
					'class': 'ace-donut-center-label', 'pointer-events': 'none'
				} );
				smallText.textContent = String( small );
				svg.appendChild( smallText );
			}
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'ace-donut-wrap';
		wrap.appendChild( svg );

		if ( false !== opts.legend ) {
			var legend = document.createElement( 'div' );
			legend.className = 'ace-donut-legend';
			slices.forEach( function ( s, i ) {
				if ( ! s.value ) {
					return;
				}
				var line = document.createElement( opts.onClick ? 'button' : 'span' );
				line.className = 'ace-legend-line';
				if ( opts.onClick ) {
					line.type = 'button';
					line.addEventListener( 'click', function () {
						opts.onClick( s.raw, i );
					} );
				}
				line.innerHTML = '<span class="ace-legend-dot" style="background:' + esc( s.color ) + '"></span>' +
					esc( s.label ) + ' <b>' + esc( fmtNum( s.value ) ) + '</b>';
				legend.appendChild( line );
			} );
			wrap.appendChild( legend );
		}

		target.appendChild( wrap );

		var donutTitle = chartTitle( target, opts );
		var shown = slices.filter( function ( s ) {
			return s.value > 0;
		} );
		var share = function ( s ) {
			return ( s.value / total * 100 ).toFixed( 1 ) + '%';
		};
		var centerText = '';
		if ( opts.centerLabel ) {
			centerText = 'object' === typeof opts.centerLabel
				? String( opts.centerLabel.value == null ? '' : opts.centerLabel.value ) + ' ' + String( opts.centerLabel.label || '' )
				: String( opts.centerLabel );
		}
		accessible( target, svg, {
			summary: fmtStr( i18nStr( 'chartSummaryDonut', '%1$s. Donut chart: %2$s.' ), donutTitle, shown.map( function ( s ) {
				return s.label + ' ' + fmtNum( s.value ) + ' (' + share( s ) + ')';
			} ).join( '; ' ) ) + ( centerText.trim() ? ' ' + centerText.trim() + '.' : '' ),
			caption: donutTitle,
			columns: [ i18nStr( 'chartColName', 'Name' ), i18nStr( 'chartColValue', 'Value' ), i18nStr( 'chartColShare', 'Share' ) ],
			rows: shown.map( function ( s ) {
				return [ s.label, fmtNum( s.value ), share( s ) ];
			} )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* gauge (semicircle, e.g. credits)                                    */
	/* ------------------------------------------------------------------ */

	function renderGauge( target, data, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		if ( ! data || 'object' !== typeof data ) {
			renderEmpty( target );
			return;
		}
		var value = Math.max( 0, Number( data.value ) || 0 );
		var max = Math.max( 1, Number( data.max ) || 0 );
		var frac = Math.min( 1, value / max );

		var w = Math.min( widthOf( target, 280 ), 340 );
		var stroke = 16;
		var r = ( w - stroke ) / 2 - 6;
		var cx = w / 2;
		var cy = r + stroke / 2 + 6;
		var h = cy + 40;

		var color = frac >= 0.9 ? '#f43f5e' : frac >= 0.7 ? '#d97706' : PALETTE[ 0 ];

		function arcPath( f ) {
			// Sweep clockwise across the top from 9 o'clock to 3 o'clock.
			var a0 = -Math.PI / 2;
			var a1 = a0 + Math.PI * Math.max( 0.001, f );
			var p0 = polar( cx, cy, r, a0 );
			var p1 = polar( cx, cy, r, a1 );
			var large = ( a1 - a0 ) > Math.PI ? 1 : 0;
			return 'M ' + p0[ 0 ] + ' ' + p0[ 1 ] + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + p1[ 0 ] + ' ' + p1[ 1 ];
		}

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );
		svg.appendChild( el( 'path', {
			d: arcPath( 1 ), fill: 'none', stroke: '#f3f4f6', 'stroke-width': stroke, 'stroke-linecap': 'round'
		} ) );
		if ( frac > 0 ) {
			var fg = el( 'path', {
				d: arcPath( frac ), fill: 'none', stroke: color, 'stroke-width': stroke, 'stroke-linecap': 'round'
			} );
			fg.addEventListener( 'mousemove', function ( evt ) {
				showTip( tipRow( color, data.label || '', fmtNum( value ) + ' / ' + fmtNum( max ) ), evt.clientX, evt.clientY );
			} );
			fg.addEventListener( 'mouseleave', hideTip );
			svg.appendChild( fg );
		}

		var valueText = el( 'text', { x: cx, y: cy - 8, 'text-anchor': 'middle', 'class': 'ace-gauge-value' } );
		valueText.textContent = ( opts.fmt ? opts.fmt( value ) : fmtNum( value ) ) + ' / ' + ( opts.fmt ? opts.fmt( max ) : fmtNum( max ) );
		svg.appendChild( valueText );

		if ( data.label ) {
			var labelText = el( 'text', { x: cx, y: cy + 14, 'text-anchor': 'middle', 'class': 'ace-gauge-label' } );
			labelText.textContent = String( data.label );
			svg.appendChild( labelText );
		}

		var pctText = el( 'text', { x: cx, y: h - 8, 'text-anchor': 'middle', 'class': 'ace-axis-label' } );
		pctText.textContent = Math.round( frac * 100 ) + '%';
		svg.appendChild( pctText );

		target.appendChild( svg );

		// One value, drawn as text and said in full by the image's name, so
		// there is no table to add.
		accessible( target, svg, {
			summary: fmtStr( i18nStr( 'chartSummaryGauge', '%1$s. %2$s of %3$s, %4$s.' ),
				data.label || chartTitle( target, opts ),
				opts.fmt ? opts.fmt( value ) : fmtNum( value ),
				opts.fmt ? opts.fmt( max ) : fmtNum( max ),
				Math.round( frac * 100 ) + '%' )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* ring (progress ring with score buckets)                             */
	/* ------------------------------------------------------------------ */

	function ringColor( value ) {
		if ( value >= 90 ) {
			return '#10b981';
		}
		if ( value >= 70 ) {
			return '#6366f1';
		}
		return '#f59e0b';
	}

	function renderRing( target, data ) {
		data = data || {};
		target.innerHTML = '';
		if ( data.value == null ) {
			renderEmpty( target );
			return;
		}
		var value = Math.max( 0, Math.min( 100, Number( data.value ) || 0 ) );
		var size = data.size || 140;
		var stroke = Math.max( 8, Math.round( size / 12 ) );
		var r = ( size - stroke ) / 2;
		var cx = size / 2;
		var cy = size / 2;
		var circ = 2 * Math.PI * r;
		var color = ringColor( value );

		var svg = el( 'svg', { viewBox: '0 0 ' + size + ' ' + size, width: size, height: size } );
		svg.appendChild( el( 'circle', {
			cx: cx, cy: cy, r: r, fill: 'none', stroke: '#f3f4f6', 'stroke-width': stroke
		} ) );
		var fg = el( 'circle', {
			cx: cx, cy: cy, r: r, fill: 'none', stroke: color, 'stroke-width': stroke,
			'stroke-linecap': 'round',
			'stroke-dasharray': circ,
			'stroke-dashoffset': circ,
			transform: 'rotate(-90 ' + cx + ' ' + cy + ')',
			'class': 'ace-ring-fg'
		} );
		svg.appendChild( fg );

		var valueText = el( 'text', {
			x: cx, y: data.label ? cy - 2 : cy, 'text-anchor': 'middle', 'dominant-baseline': 'middle', 'class': 'ace-ring-value'
		} );
		valueText.textContent = Math.round( value );
		svg.appendChild( valueText );
		if ( data.label ) {
			var labelText = el( 'text', {
				x: cx, y: cy + 20, 'text-anchor': 'middle', 'dominant-baseline': 'middle', 'class': 'ace-ring-label'
			} );
			labelText.textContent = String( data.label );
			svg.appendChild( labelText );
		}

		target.appendChild( svg );
		accessible( target, svg, {
			summary: fmtStr( i18nStr( 'chartSummaryRing', '%1$s. %2$s out of 100.' ),
				data.title || chartTitle( target, data ), Math.round( value ) )
		} );

		// Animate the stroke on the next frame (CSS transition on .ace-ring-fg).
		var targetOffset = circ * ( 1 - value / 100 );
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				fg.setAttribute( 'stroke-dashoffset', targetOffset );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* heatmap (table-based, indigo intensity)                             */
	/* ------------------------------------------------------------------ */

	function lerpColor( t ) {
		// #eef2ff (238,242,255) -> #4338ca (67,56,202).
		var r = Math.round( 238 + ( 67 - 238 ) * t );
		var g = Math.round( 242 + ( 56 - 242 ) * t );
		var b = Math.round( 255 + ( 202 - 255 ) * t );
		return 'rgb(' + r + ',' + g + ',' + b + ')';
	}

	/**
	 * White text only where it reads better than the black text: the number
	 * in every cell has to clear 4.5:1 against its own shade, and with black
	 * and white to choose from the worst shade on this scale still gives
	 * 4.58:1. A fixed cut-off put white on mid indigo at about 3:1.
	 */
	function whiteReads( t ) {
		var r = Math.round( 238 + ( 67 - 238 ) * t );
		var g = Math.round( 242 + ( 56 - 242 ) * t );
		var b = Math.round( 255 + ( 202 - 255 ) * t );
		var lum = [ r, g, b ].map( function ( c ) {
			var v = c / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		var L = 0.2126 * lum[ 0 ] + 0.7152 * lum[ 1 ] + 0.0722 * lum[ 2 ];
		// Contrast against white, then against black (luminance 0).
		return ( 1.05 / ( L + 0.05 ) ) > ( ( L + 0.05 ) / 0.05 );
	}

	function renderHeatmap( target, data, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		var cols = ( data && data.cols ) || [];
		var rows = ( data && data.rows ) || [];
		if ( ! cols.length || ! rows.length ) {
			renderEmpty( target );
			return;
		}

		var max = 0;
		rows.forEach( function ( row ) {
			( row.cells || [] ).forEach( function ( cell ) {
				var v = Number( cell && cell.v );
				if ( isFinite( v ) && v > max ) {
					max = v;
				}
			} );
		} );
		if ( opts.maxOverride ) {
			max = opts.maxOverride;
		}
		if ( max <= 0 ) {
			max = 1;
		}

		var html = '<div class="ace-heatmap-scroll"><table class="ace-heatmap"><caption class="screen-reader-text">' + esc( chartTitle( target, opts ) ) + '</caption>' +
			'<thead><tr><th scope="col"><span class="screen-reader-text">' + esc( opts.rowHeader || i18nStr( 'chartColName', 'Name' ) ) + '</span></th>';
		cols.forEach( function ( c ) {
			html += '<th scope="col">' + esc( c ) + '</th>';
		} );
		html += '</tr></thead><tbody>';
		rows.forEach( function ( row ) {
			html += '<tr><th scope="row" class="ace-heatmap-row-label" title="' + esc( row.label ) + '">' + esc( row.label ) + '</th>';
			cols.forEach( function ( c, ci ) {
				var cell = ( row.cells || [] )[ ci ];
				var v = cell ? Number( cell.v ) : null;
				if ( v == null || ! isFinite( v ) ) {
					html += '<td style="background:#fafafa" title=""></td>';
					return;
				}
				var t = Math.max( 0, Math.min( 1, v / max ) );
				var title = ( cell && cell.title ) ? cell.title : ( row.label + ' / ' + c + ': ' + fmtNum( v ) );
				html += '<td class="' + ( whiteReads( t ) ? 'is-deep' : '' ) + '" style="background:' + lerpColor( t ) + '" title="' + esc( title ) + '">' + esc( fmtNum( v ) ) + '</td>';
			} );
			html += '</tr>';
		} );
		html += '</tbody></table></div>';
		target.innerHTML = html;
	}


	/* ------------------------------------------------------------------ */
	/* band: a central estimate with its uncertainty range                 */
	/* ------------------------------------------------------------------ */

	/*
	 * The "when will I know" chart. `data` is
	 *   { keys: [...], line: [{x, y|null}], band: [{x, lo|null, hi|null}] }
	 * with y in whatever unit the caller is showing (percent, here).
	 *
	 * ONE BAND, NOT SEVERAL. Nested bands imply several confidence levels and
	 * the estimator produces exactly one: the always-valid interval, which is
	 * the only interval that stays correct when a merchant reads it every
	 * morning. Drawing a second, narrower band would promise a precision that
	 * does not exist.
	 *
	 * The zero rule drawn here IS the reading: while the band touches it, the
	 * versions cannot be told apart.
	 */
	function renderBand( target, data, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		data = data || {};
		var keys = ( data.keys || [] ).map( String );
		var line = ( data.line || [] ).map( function ( p ) {
			var n = ( p.y === null || p.y === undefined ) ? null : Number( p.y );
			return { x: String( p.x ), y: ( n === null || ! isFinite( n ) ) ? null : n };
		} );
		var band = ( data.band || [] ).map( function ( p ) {
			return {
				x: String( p.x ),
				lo: ( p.lo === null || p.lo === undefined ) ? null : Number( p.lo ),
				hi: ( p.hi === null || p.hi === undefined ) ? null : Number( p.hi )
			};
		} );

		if ( ! keys.length || ! line.some( function ( p ) { return p.y !== null; } ) ) {
			renderEmpty( target );
			return;
		}

		var w = widthOf( target );
		var h = opts.height || 260;
		var margin = { top: 12, right: 16, bottom: 28, left: 52 };
		var innerW = Math.max( 0, w - margin.left - margin.right );
		var innerH = Math.max( 0, h - margin.top - margin.bottom );

		var lo = 0;
		var hi = 0;
		function widen( v ) {
			if ( v === null || ! isFinite( v ) ) {
				return;
			}
			if ( v < lo ) {
				lo = v;
			}
			if ( v > hi ) {
				hi = v;
			}
		}
		line.forEach( function ( p ) { widen( p.y ); } );
		band.forEach( function ( p ) { widen( p.lo ); widen( p.hi ); } );
		if ( hi === lo ) {
			hi = lo + 1;
		}

		var ticks = niceTicks( lo, hi, 5 );
		var domainLo = Math.min( lo, ticks.length ? ticks[ 0 ] : lo );
		var domainHi = Math.max( hi, ticks.length ? ticks[ ticks.length - 1 ] : hi );
		var xPos = pointScale( keys, [ margin.left, margin.left + innerW ] );
		var yScale = linearScale( [ domainLo, domainHi ], [ margin.top + innerH, margin.top ] );
		var positions = keys.map( xPos );
		var color = opts.color || PALETTE[ 0 ];

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );
		yAxis( svg, yScale, ticks, margin, innerW, opts.yFmt );
		xLabels( svg, keys, positions, h - 8, 6 );

		/* The band, as one closed polygon per measured run. */
		var runs = measuredRuns( band.map( function ( p ) {
			return { x: p.x, y: ( p.lo === null || p.hi === null ) ? null : p.lo, lo: p.lo, hi: p.hi };
		} ) );
		runs.forEach( function ( run ) {
			if ( run.length < 2 ) {
				return;
			}
			var top = run.map( function ( p ) {
				return xPos( p.x ) + ' ' + yScale( p.hi );
			} );
			var bottom = run.slice().reverse().map( function ( p ) {
				return xPos( p.x ) + ' ' + yScale( p.lo );
			} );
			svg.appendChild( el( 'path', {
				d: 'M ' + top.join( ' L ' ) + ' L ' + bottom.join( ' L ' ) + ' Z',
				fill: color, 'fill-opacity': 0.18, stroke: 'none'
			} ) );
		} );

		/* The zero rule. Solid and darker than the grid: it is the reading. */
		if ( domainLo < 0 && domainHi > 0 ) {
			svg.appendChild( el( 'line', {
				x1: margin.left, x2: margin.left + innerW,
				y1: yScale( 0 ), y2: yScale( 0 ),
				stroke: '#6b7280', 'stroke-width': 1
			} ) );
		}

		measuredRuns( line ).forEach( function ( run ) {
			var d = run.map( function ( p, idx ) {
				return ( idx ? 'L ' : 'M ' ) + xPos( p.x ) + ' ' + yScale( p.y );
			} ).join( ' ' );
			svg.appendChild( el( 'path', {
				d: d, fill: 'none', stroke: color, 'stroke-width': 2,
				'stroke-linejoin': 'round', 'stroke-linecap': 'round'
			} ) );
		} );

		var lineMap = {};
		line.forEach( function ( p ) { lineMap[ p.x ] = p.y; } );
		var bandMap = {};
		band.forEach( function ( p ) { bandMap[ p.x ] = p; } );

		unifiedHover( svg, {
			keys: keys, positions: positions, margin: margin, innerW: innerW, innerH: innerH,
			dotSpecs: [ {
				name: opts.valueLabel || i18nStr( 'difference', 'Difference' ),
				color: color,
				yAt: function ( k ) {
					return ( lineMap[ k ] === null || lineMap[ k ] === undefined ) ? null : yScale( lineMap[ k ] );
				},
				valueAt: function ( k ) {
					var v = lineMap[ k ];
					var b = bandMap[ k ];
					var head = opts.yFmt ? opts.yFmt( v ) : fmtNum( v );
					if ( ! b || b.lo === null || b.hi === null ) {
						return head;
					}
					var loTxt = opts.yFmt ? opts.yFmt( b.lo ) : fmtNum( b.lo );
					var hiTxt = opts.yFmt ? opts.yFmt( b.hi ) : fmtNum( b.hi );
					return head + ' (' + loTxt + ' to ' + hiTxt + ')';
				}
			} ]
		} );

		target.appendChild( svg );

		var bandFmt = function ( v ) {
			return opts.yFmt ? opts.yFmt( v ) : fmtNum( v );
		};
		var bandTitle = chartTitle( target, opts );
		var rangeText = function ( b ) {
			return ( ! b || b.lo === null || b.hi === null || ! isFinite( b.lo ) || ! isFinite( b.hi ) )
				? i18nStr( 'chartNoValue', 'No data' )
				: fmtStr( i18nStr( 'chartRange', '%1$s to %2$s' ), bandFmt( b.lo ), bandFmt( b.hi ) );
		};
		var measuredKeys = keys.filter( function ( k ) {
			return lineMap[ k ] !== null && lineMap[ k ] !== undefined;
		} );
		var lastKey = measuredKeys[ measuredKeys.length - 1 ];
		accessible( target, svg, {
			summary: fmtStr( i18nStr( 'chartSummaryBand', '%1$s. Estimate from %2$s to %3$s. Latest %4$s, range %5$s.' ),
				bandTitle, keys[ 0 ], keys[ keys.length - 1 ], bandFmt( lineMap[ lastKey ] ), rangeText( bandMap[ lastKey ] ) ),
			caption: bandTitle,
			columns: [
				opts.xLabel || i18nStr( 'chartColDate', 'Date' ),
				opts.valueLabel || i18nStr( 'difference', 'Difference' ),
				i18nStr( 'chartColRange', 'Range' )
			],
			rows: keys.map( function ( k ) {
				var v = lineMap[ k ];
				return [ k, ( v === null || v === undefined ) ? i18nStr( 'chartNoValue', 'No data' ) : bandFmt( v ), rangeText( bandMap[ k ] ) ];
			} )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* forest: one estimate and its interval per row                       */
	/* ------------------------------------------------------------------ */

	/*
	 * `rows` is [{ label, estimate, lo, hi, conclusive }].
	 *
	 * The instrument that shows a result is NOT yet a result: a row whose whisker
	 * crosses the zero rule has not separated the versions, and that is visible
	 * at a glance in a way a p-value in a table is not. Rows are drawn muted
	 * until they clear zero, so "not yet" and "yes" never look alike.
	 */
	function renderForest( target, rows, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		rows = ( rows || [] ).filter( function ( r ) {
			return r && isFinite( Number( r.estimate ) ) && isFinite( Number( r.lo ) ) && isFinite( Number( r.hi ) );
		} );
		if ( ! rows.length ) {
			renderEmpty( target );
			return;
		}

		var w = widthOf( target );
		var rowH = opts.rowHeight || 34;
		var labelW = Math.min( 220, Math.max( 96, opts.labelWidth || 140 ) );
		var margin = { top: 12, right: 16, bottom: 28, left: labelW };
		var h = margin.top + margin.bottom + rows.length * rowH;
		var innerW = Math.max( 0, w - margin.left - margin.right );

		var lo = 0;
		var hi = 0;
		rows.forEach( function ( r ) {
			lo = Math.min( lo, Number( r.lo ) );
			hi = Math.max( hi, Number( r.hi ) );
		} );
		if ( hi === lo ) {
			hi = lo + 1;
		}
		var ticks = niceTicks( lo, hi, 5 );
		var domainLo = Math.min( lo, ticks.length ? ticks[ 0 ] : lo );
		var domainHi = Math.max( hi, ticks.length ? ticks[ ticks.length - 1 ] : hi );
		var xScale = linearScale( [ domainLo, domainHi ], [ margin.left, margin.left + innerW ] );

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );

		ticks.forEach( function ( tk ) {
			var x = xScale( tk );
			svg.appendChild( el( 'line', {
				x1: x, x2: x, y1: margin.top, y2: h - margin.bottom, 'class': 'ace-grid-line'
			} ) );
			var label = el( 'text', { x: x, y: h - 10, 'text-anchor': 'middle', 'class': 'ace-axis-label' } );
			label.textContent = opts.xFmt ? opts.xFmt( tk ) : fmtNum( tk );
			svg.appendChild( label );
		} );

		/* The zero rule, darker than the grid: crossing it is the whole reading. */
		if ( domainLo < 0 && domainHi > 0 ) {
			svg.appendChild( el( 'line', {
				x1: xScale( 0 ), x2: xScale( 0 ), y1: margin.top, y2: h - margin.bottom,
				stroke: '#6b7280', 'stroke-width': 1
			} ) );
		}

		rows.forEach( function ( r, i ) {
			var y = margin.top + i * rowH + rowH / 2;
			var conclusive = !! r.conclusive;
			var color = conclusive ? ( opts.color || PALETTE[ 1 ] ) : '#9ca3af';

			var label = el( 'text', {
				x: margin.left - 10, y: y + 4, 'text-anchor': 'end', 'class': 'ace-axis-label'
			} );
			label.textContent = r.label == null ? '' : String( r.label );
			svg.appendChild( label );

			svg.appendChild( el( 'line', {
				x1: xScale( Number( r.lo ) ), x2: xScale( Number( r.hi ) ), y1: y, y2: y,
				stroke: color, 'stroke-width': 2, 'stroke-linecap': 'round'
			} ) );
			svg.appendChild( el( 'circle', {
				cx: xScale( Number( r.estimate ) ), cy: y, r: conclusive ? 5 : 4, fill: color
			} ) );

			var hit = el( 'rect', {
				x: margin.left, y: margin.top + i * rowH, width: innerW, height: rowH, fill: 'transparent'
			} );
			var body = '<b>' + esc( r.label ) + '</b>' +
				tipRow( color, opts.valueLabel || i18nStr( 'change', 'Change' ),
					( opts.xFmt ? opts.xFmt( r.estimate ) : fmtNum( r.estimate ) ) +
					' (' + ( opts.xFmt ? opts.xFmt( r.lo ) : fmtNum( r.lo ) ) + ' to ' +
					( opts.xFmt ? opts.xFmt( r.hi ) : fmtNum( r.hi ) ) + ')' ) +
				( r.detail ? tipRow( color, '', String( r.detail ) ) : '' );
			hit.addEventListener( 'mousemove', function ( evt ) {
				showTip( body, evt.clientX, evt.clientY );
			} );
			hit.addEventListener( 'mouseleave', hideTip );
			svg.appendChild( hit );
		} );

		target.appendChild( svg );

		var forestFmt = function ( v ) {
			return opts.xFmt ? opts.xFmt( v ) : fmtNum( v );
		};
		var forestTitle = chartTitle( target, opts );
		var forestRange = function ( r ) {
			return fmtStr( i18nStr( 'chartRange', '%1$s to %2$s' ), forestFmt( r.lo ), forestFmt( r.hi ) );
		};
		accessible( target, svg, {
			summary: fmtStr( i18nStr( 'chartSummaryForest', '%1$s. %2$s.' ), forestTitle, rows.map( function ( r ) {
				return String( r.label == null ? '' : r.label ) + ' ' + forestFmt( r.estimate ) + ' (' + forestRange( r ) + ')';
			} ).join( '; ' ) ),
			caption: forestTitle,
			columns: [
				i18nStr( 'chartColName', 'Name' ),
				opts.valueLabel || i18nStr( 'change', 'Change' ),
				i18nStr( 'chartColRange', 'Range' )
			],
			rows: rows.map( function ( r ) {
				return [ String( r.label == null ? '' : r.label ), forestFmt( r.estimate ), forestRange( r ) ];
			} )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* funnel: the same steps, one column per version                      */
	/* ------------------------------------------------------------------ */

	/*
	 * `data` is [{ name, color, stages: [{ label, value }] }].
	 *
	 * Bars are scaled against the LARGEST value across every series, so two
	 * versions can be compared by width. Shares within a version are the
	 * caller's business: this draws counted shoppers, and a counted shopper is
	 * a fact rather than a rate.
	 */
	function renderFunnel( target, data, opts ) {
		opts = opts || {};
		target.innerHTML = '';
		var series = ( data || [] ).map( function ( s, i ) {
			return {
				name: s.name || '',
				color: s.color || PALETTE[ i % PALETTE.length ],
				stages: ( s.stages || [] ).map( function ( st ) {
					return { label: String( st.label == null ? '' : st.label ), value: Number( st.value ) || 0 };
				} )
			};
		} );
		var stageLabels = series.length ? series[ 0 ].stages.map( function ( s ) { return s.label; } ) : [];
		if ( ! stageLabels.length ) {
			renderEmpty( target );
			return;
		}

		var w = widthOf( target );
		var labelW = Math.min( 200, Math.max( 90, opts.labelWidth || 130 ) );
		var margin = { top: 12, right: 16, bottom: 12, left: labelW };
		var stageH = opts.stageHeight || 46;
		var h = margin.top + margin.bottom + stageLabels.length * stageH;
		var innerW = Math.max( 0, w - margin.left - margin.right );

		var max = 1;
		series.forEach( function ( s ) {
			s.stages.forEach( function ( st ) {
				if ( st.value > max ) {
					max = st.value;
				}
			} );
		} );

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );
		var barH = Math.max( 6, ( stageH - 10 ) / Math.max( 1, series.length ) - 3 );

		stageLabels.forEach( function ( label, si ) {
			var top = margin.top + si * stageH;
			var text = el( 'text', {
				x: margin.left - 10, y: top + stageH / 2 + 4, 'text-anchor': 'end', 'class': 'ace-axis-label'
			} );
			text.textContent = label;
			svg.appendChild( text );

			series.forEach( function ( s, i ) {
				var stage = s.stages[ si ];
				if ( ! stage ) {
					return;
				}
				var y = top + 5 + i * ( barH + 3 );
				var width = Math.max( 0, ( stage.value / max ) * innerW );
				svg.appendChild( el( 'rect', {
					x: margin.left, y: y, width: width, height: barH, rx: 3, fill: s.color
				} ) );

				var hit = el( 'rect', {
					x: margin.left, y: y, width: innerW, height: barH, fill: 'transparent'
				} );
				var body = '<b>' + esc( label ) + '</b>' + tipRow( s.color, s.name, fmtNum( stage.value ) );
				hit.addEventListener( 'mousemove', function ( evt ) {
					showTip( body, evt.clientX, evt.clientY );
				} );
				hit.addEventListener( 'mouseleave', hideTip );
				svg.appendChild( hit );
			} );
		} );

		target.appendChild( svg );
		if ( series.length > 1 ) {
			target.insertAdjacentHTML( 'beforeend', legendHtml( series.map( function ( s ) {
				return { label: s.name, color: s.color };
			} ) ) );
		}

		var funnelTitle = chartTitle( target, opts );
		accessible( target, svg, {
			summary: fmtStr( i18nStr( 'chartSummaryFunnel', '%1$s. %2$s.' ), funnelTitle, series.map( function ( s ) {
				return ( s.name ? s.name + ': ' : '' ) + s.stages.map( function ( st ) {
					return st.label + ' ' + fmtNum( st.value );
				} ).join( ', ' );
			} ).join( '; ' ) ),
			caption: funnelTitle,
			columns: [ i18nStr( 'chartColStep', 'Step' ) ].concat( series.map( function ( s ) {
				return s.name || funnelTitle;
			} ) ),
			rows: stageLabels.map( function ( label, si ) {
				return [ label ].concat( series.map( function ( s ) {
					return s.stages[ si ] ? fmtNum( s.stages[ si ].value ) : i18nStr( 'chartNoValue', 'No data' );
				} ) );
			} )
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Public API (every chart re-renders on container resize)             */
	/* ------------------------------------------------------------------ */

	function responsive( renderer ) {
		return function ( target, data, opts ) {
			if ( ! target ) {
				return;
			}
			register( target, function () {
				// A resize redraws the chart, and with it the data table
				// button; keep keyboard focus on the new one.
				var active = document.activeElement;
				var refocus = !! ( active && target.contains( active ) && active.classList.contains( 'ace-chart-data-toggle' ) );
				renderer( target, data, opts );
				if ( refocus ) {
					var again = target.querySelector( '.ace-chart-data-toggle' );
					if ( again ) {
						again.focus();
					}
				}
			} );
		};
	}

	window.agencoen.charts = {
		palette: PALETTE.slice(),
		line: responsive( renderLine ),
		sparkline: responsive( renderSparkline ),
		area: responsive( renderArea ),
		barsV: responsive( renderBarsV ),
		barsH: responsive( renderBarsH ),
		donut: responsive( renderDonut ),
		gauge: responsive( renderGauge ),
		ring: responsive( renderRing ),
		heatmap: responsive( renderHeatmap ),
		band: responsive( renderBand ),
		forest: responsive( renderForest ),
		funnel: responsive( renderFunnel )
	};
} )();
