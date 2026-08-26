/**
 * Agentic Commerce Engine: native SVG chart kit (v4).
 *
 * Defines window.agencoen.charts: line, sparkline, area (stacked), barsV,
 * barsH, donut, gauge, ring, heatmap. Dependency free; every renderer is
 * (el, data, opts), clears the element, sizes itself from el.clientWidth and
 * re-renders on a shared debounced window resize. Scale math is ported from
 * the ACE app's chart kit (src/components/charts/scales.ts); the x-unified
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

	function xLabels( svg, keys, positions, y, maxLabels ) {
		var step = Math.max( 1, Math.ceil( keys.length / ( maxLabels || 8 ) ) );
		keys.forEach( function ( k, i ) {
			if ( i % step !== 0 && i !== keys.length - 1 ) {
				return;
			}
			var label = el( 'text', { x: positions[ i ], y: y, 'text-anchor': 'middle', 'class': 'ace-axis-label' } );
			label.textContent = k;
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
			showTip( '<b>' + esc( keys[ i ] ) + '</b>' + rows.join( '' ), evt.clientX, evt.clientY );
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
		var margin = { top: 12, right: 16, bottom: 28, left: 44 };
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
		if ( hi === lo ) {
			hi = lo + 1;
		}
		var ticks = niceTicks( lo, hi, 5 );
		var domainHi = Math.max( hi, ticks.length ? ticks[ ticks.length - 1 ] : hi );

		var xPos = pointScale( keys, [ margin.left, margin.left + innerW ] );
		var yScale = linearScale( [ lo, domainHi ], [ margin.top + innerH, margin.top ] );
		var positions = keys.map( xPos );

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, width: '100%', height: h } );

		yAxis( svg, yScale, ticks, margin, innerW, opts.yFmt );
		xLabels( svg, keys, positions, h - 8, 8 );

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

		var html = '<div class="ace-heatmap-scroll"><table class="ace-heatmap"><thead><tr><th></th>';
		cols.forEach( function ( c ) {
			html += '<th>' + esc( c ) + '</th>';
		} );
		html += '</tr></thead><tbody>';
		rows.forEach( function ( row ) {
			html += '<tr><th class="ace-heatmap-row-label" title="' + esc( row.label ) + '">' + esc( row.label ) + '</th>';
			cols.forEach( function ( c, ci ) {
				var cell = ( row.cells || [] )[ ci ];
				var v = cell ? Number( cell.v ) : null;
				if ( v == null || ! isFinite( v ) ) {
					html += '<td style="background:#fafafa" title=""></td>';
					return;
				}
				var t = Math.max( 0, Math.min( 1, v / max ) );
				var title = ( cell && cell.title ) ? cell.title : ( row.label + ' / ' + c + ': ' + fmtNum( v ) );
				html += '<td class="' + ( t > 0.55 ? 'is-deep' : '' ) + '" style="background:' + lerpColor( t ) + '" title="' + esc( title ) + '">' + esc( fmtNum( v ) ) + '</td>';
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
				renderer( target, data, opts );
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
