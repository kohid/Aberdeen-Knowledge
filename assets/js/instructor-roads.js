"use strict";

/**
 * Major Streets Instructor Dashboard tab: the "Add a New Road" modal.
 * Three drawing modes:
 *   - "snap": click once on a real road; its exact OpenStreetMap geometry
 *     is fetched server-side (atk_road_snap_lookup) and drawn automatically
 *     — the default, since it gets a real curve with no manual clicking.
 *   - "manual": click a sequence of points, for anything not in OSM (a
 *     private road, a deliberately simplified path). The points you click
 *     are control points, not the final line — catmullRomSpline() below
 *     interpolates a smooth curve running exactly through all of them, so
 *     a handful of clicks along a bend produce a flowing curve rather than
 *     a jagged straight-segment polyline.
 *   - "roundabout": click to place a centre, click again to set the radius,
 *     then drag either the centre or the edge handle to adjust it live.
 *     circlePoints() turns the centre+radius into a closed ring of points.
 *     Roundabouts have no special data type anywhere else in this system —
 *     they're just an ordinary named road whose shape happens to be a
 *     circle, so this is purely a drawing convenience.
 * All three modes only ever produce a plain dense point list — same
 * {name, points, findable} shape the server already handles, so nothing
 * downstream (atk_road_add, the merge, the map) needs to know how the
 * points were generated.
 * Kept separate from the student-facing major-streets-map.js (different
 * audience) and from the shared instructor-dashboard.js (that file's
 * Select2 init only runs for row-templated edit modals; this is a single
 * static modal, simpler to just handle here).
 */
(function () {
	var ABERDEEN_CENTER = [ 57.1497, -2.0943 ];
	var SPLINE_SEGMENTS_PER_SPAN = 12;
	var ROUNDABOUT_RING_POINTS = 32;
	var EARTH_RADIUS_METRES = 6371000;

	var map = null;
	var polyline = null;
	var points = []; // Manual mode: the clicked control points. Snap mode: the fetched OSM points (already dense, no smoothing applied).
	var mode = "snap";
	var pending = false;

	// Roundabout mode: a centre + radius, adjusted live via two draggable markers.
	var roundaboutCenter = null;
	var roundaboutRadius = 0;
	var centerMarker = null;
	var edgeMarker = null;

	/**
	 * A closed ring of `numPoints` lat/lng points around `center`, `radiusMetres`
	 * out — a small-scale equirectangular approximation (fine at city scale),
	 * matching the flat local-tangent-plane math already used for on-map
	 * dragging elsewhere in this file. First and last point are identical,
	 * closing the ring the same way a real roundabout's geometry would.
	 */
	function circlePoints( center, radiusMetres, numPoints ) {
		var latRad = center[ 0 ] * Math.PI / 180;
		var pts = [];
		for ( var i = 0; i <= numPoints; i++ ) {
			var angle = ( i / numPoints ) * 2 * Math.PI;
			var dLat = ( radiusMetres * Math.cos( angle ) ) / EARTH_RADIUS_METRES;
			var dLng = ( radiusMetres * Math.sin( angle ) ) / ( EARTH_RADIUS_METRES * Math.cos( latRad ) );
			pts.push( [
				center[ 0 ] + dLat * 180 / Math.PI,
				center[ 1 ] + dLng * 180 / Math.PI
			] );
		}
		return pts;
	}

	function metresBetween( a, b ) {
		var latRad = a[ 0 ] * Math.PI / 180;
		var dLat = ( b[ 0 ] - a[ 0 ] ) * Math.PI / 180 * EARTH_RADIUS_METRES;
		var dLng = ( b[ 1 ] - a[ 1 ] ) * Math.PI / 180 * EARTH_RADIUS_METRES * Math.cos( latRad );
		return Math.sqrt( dLat * dLat + dLng * dLng );
	}

	/**
	 * Standard uniform Catmull-Rom spline: interpolates a smooth curve
	 * that passes exactly through every point in `pts`, with no extra
	 * handles to place — just the clicked points themselves. Duplicating
	 * the first/last point gives the curve a sensible tangent at both
	 * ends instead of an undefined one. Fewer than 3 points can't define
	 * a curve, so those are returned unchanged (a straight line, matching
	 * the previous behaviour for a simple 2-point road).
	 */
	function catmullRomSpline( pts, segmentsPerSpan ) {
		if ( pts.length < 3 ) {
			return pts.slice();
		}
		var extended = [ pts[ 0 ] ].concat( pts, [ pts[ pts.length - 1 ] ] );
		var result = [];
		for ( var i = 1; i < extended.length - 2; i++ ) {
			var p0 = extended[ i - 1 ], p1 = extended[ i ], p2 = extended[ i + 1 ], p3 = extended[ i + 2 ];
			for ( var step = 0; step < segmentsPerSpan; step++ ) {
				result.push( catmullRomPoint( p0, p1, p2, p3, step / segmentsPerSpan ) );
			}
		}
		result.push( pts[ pts.length - 1 ] );
		return result;
	}

	function catmullRomPoint( p0, p1, p2, p3, t ) {
		var t2 = t * t, t3 = t2 * t;
		function axis( a0, a1, a2, a3 ) {
			return 0.5 * ( ( 2 * a1 ) + ( -a0 + a2 ) * t + ( 2 * a0 - 5 * a1 + 4 * a2 - a3 ) * t2 + ( -a0 + 3 * a1 - 3 * a2 + a3 ) * t3 );
		}
		return [ axis( p0[ 0 ], p1[ 0 ], p2[ 0 ], p3[ 0 ] ), axis( p0[ 1 ], p1[ 1 ], p2[ 1 ], p3[ 1 ] ) ];
	}

	/**
	 * What actually gets drawn and saved: the raw points as-is in Snap
	 * mode (already real OSM geometry, smoothing would only distort it),
	 * the spline-smoothed curve through the clicked control points in
	 * Manual mode.
	 */
	function effectivePath() {
		if ( "roundabout" === mode ) {
			return roundaboutCenter && roundaboutRadius > 0 ? circlePoints( roundaboutCenter, roundaboutRadius, ROUNDABOUT_RING_POINTS ) : [];
		}
		return "manual" === mode ? catmullRomSpline( points, SPLINE_SEGMENTS_PER_SPAN ) : points;
	}

	function pointCountLabel() {
		if ( "roundabout" === mode ) {
			if ( ! roundaboutCenter ) {
				return "No centre placed yet";
			}
			return roundaboutRadius > 0 ? "Radius: " + Math.round( roundaboutRadius ) + "m" : "Centre placed — click again to set the radius";
		}
		var suffix = "manual" === mode && points.length >= 3 ? " (curve control points)" : "";
		return points.length + ( 1 === points.length ? " point" : " points" ) + suffix;
	}

	function roundaboutIsValid() {
		return !! ( roundaboutCenter && roundaboutRadius > 0 );
	}

	function setStatus( message, isError ) {
		var el = document.getElementById( "atk-add-road-status" );
		el.textContent = message || "";
		el.classList.toggle( "d-none", ! message );
		el.classList.toggle( "text-danger", !! isError );
		el.classList.toggle( "text-gray-500", ! isError );
	}

	function render() {
		var path = effectivePath();
		var isRoundabout = "roundabout" === mode;

		document.getElementById( "atk-add-road-point-count" ).textContent = pointCountLabel();
		document.getElementById( "atk-add-road-undo" ).disabled = pending || ( isRoundabout ? ! roundaboutCenter : ( points.length === 0 || "manual" !== mode ) );
		document.getElementById( "atk-add-road-clear" ).disabled = pending || ( isRoundabout ? ! roundaboutCenter : points.length === 0 );
		document.getElementById( "atk-add-road-submit" ).disabled = pending || ( isRoundabout ? ! roundaboutIsValid() : points.length < 2 );
		document.getElementById( "atk-add-road-points" ).value = JSON.stringify( path );

		if ( polyline ) {
			polyline.setLatLngs( path );
		}
	}

	function removeRoundaboutShapes() {
		if ( centerMarker ) {
			map.removeLayer( centerMarker );
			centerMarker = null;
		}
		if ( edgeMarker ) {
			map.removeLayer( edgeMarker );
			edgeMarker = null;
		}
		roundaboutCenter = null;
		roundaboutRadius = 0;
	}

	function updateEdgeMarkerPosition() {
		if ( ! edgeMarker || ! roundaboutCenter ) {
			return;
		}
		var ring = circlePoints( roundaboutCenter, roundaboutRadius, ROUNDABOUT_RING_POINTS );
		edgeMarker.setLatLng( ring[ 0 ] );
	}

	function placeRoundaboutCenter( lat, lng ) {
		removeRoundaboutShapes();
		roundaboutCenter = [ lat, lng ];
		centerMarker = L.marker( roundaboutCenter, { draggable: true } ).addTo( map );
		centerMarker.on( "drag", function ( e ) {
			roundaboutCenter = [ e.target.getLatLng().lat, e.target.getLatLng().lng ];
			updateEdgeMarkerPosition();
			render();
		} );
		setStatus( "Click again to set the roundabout's size.", false );
		render();
	}

	function setRoundaboutRadius( lat, lng ) {
		if ( ! roundaboutCenter ) {
			return;
		}
		roundaboutRadius = Math.max( 3, metresBetween( roundaboutCenter, [ lat, lng ] ) );
		if ( ! edgeMarker ) {
			edgeMarker = L.marker( [ lat, lng ], { draggable: true } ).addTo( map );
			edgeMarker.on( "drag", function ( e ) {
				roundaboutRadius = Math.max( 3, metresBetween( roundaboutCenter, [ e.target.getLatLng().lat, e.target.getLatLng().lng ] ) );
				render();
			} );
		} else {
			updateEdgeMarkerPosition();
		}
		setStatus( "Drag the centre or edge handle to adjust the roundabout.", false );
		render();
	}

	function resetDrawing() {
		points = [];
		pending = false;
		setStatus( "" );
		if ( map ) {
			removeRoundaboutShapes();
		}
		if ( polyline ) {
			polyline.setLatLngs( [] );
		}
		render();
	}

	function setNameField( name ) {
		var $el = window.jQuery ? jQuery( "#atk-add-road-name" ) : null;
		if ( ! $el || ! $el.length || ! name ) {
			return;
		}
		if ( ! $el.find( "option[value='" + name.replace( /'/g, "\\'" ) + "']" ).length ) {
			$el.append( new Option( name, name, true, true ) );
		}
		$el.val( name ).trigger( "change" );
	}

	function snapToRoad( lat, lng ) {
		var nonceField = document.querySelector( "#atk-add-road-modal input[name='atk_nonce']" );
		pending = true;
		setStatus( "Looking up the real road shape…", false );
		render();

		var body = new URLSearchParams();
		body.set( "action", "atk_road_snap_lookup" );
		body.set( "atk_nonce", nonceField ? nonceField.value : "" );
		body.set( "lat", lat );
		body.set( "lng", lng );

		fetch( ATK_INSTRUCTOR_ROADS.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				pending = false;
				if ( json && json.success && json.data && json.data.points && json.data.points.length >= 2 ) {
					points = json.data.points;
					setNameField( json.data.name );
					setStatus( "Matched: " + ( json.data.name || "an unnamed road" ), false );
				} else {
					setStatus( ( json && json.data && json.data.message ) || "No road found there. Try again, or trace manually.", true );
				}
				render();
			} )
			.catch( function () {
				pending = false;
				setStatus( "Could not reach OpenStreetMap right now. Try again, or trace manually.", true );
				render();
			} );
	}

	function initMap() {
		if ( map ) {
			map.invalidateSize();
			return;
		}
		map = L.map( "atk-add-road-map" ).setView( ABERDEEN_CENTER, 13 );
		L.tileLayer( "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		} ).addTo( map );
		polyline = L.polyline( [], { color: ( window.getComputedStyle( document.documentElement ).getPropertyValue( "--akn-brand" ).trim() || "#555555" ), weight: 4 } ).addTo( map );

		map.on( "click", function ( e ) {
			if ( pending ) {
				return;
			}
			if ( "snap" === mode ) {
				points = [];
				snapToRoad( e.latlng.lat, e.latlng.lng );
			} else if ( "roundabout" === mode ) {
				if ( ! roundaboutCenter ) {
					placeRoundaboutCenter( e.latlng.lat, e.latlng.lng );
				} else if ( ! edgeMarker ) {
					setRoundaboutRadius( e.latlng.lat, e.latlng.lng );
				}
			} else {
				points.push( [ e.latlng.lat, e.latlng.lng ] );
				setStatus( "" );
				render();
			}
		} );

		document.getElementById( "atk-add-road-undo" ).addEventListener( "click", function () {
			if ( "roundabout" === mode ) {
				if ( edgeMarker ) {
					map.removeLayer( edgeMarker );
					edgeMarker = null;
					roundaboutRadius = 0;
					setStatus( "Click again to set the roundabout's size.", false );
				} else if ( centerMarker ) {
					removeRoundaboutShapes();
					setStatus( "" );
				}
			} else {
				points.pop();
			}
			render();
		} );

		document.getElementById( "atk-add-road-clear" ).addEventListener( "click", resetDrawing );

		setTimeout( function () { map.invalidateSize(); }, 0 );
	}

	var modeToggleInitialized = false;
	function initModeToggle() {
		if ( modeToggleInitialized ) {
			return;
		}
		modeToggleInitialized = true;
		var instructions = document.getElementById( "atk-add-road-instructions" );
		function applyMode() {
			if ( document.getElementById( "atk-add-road-mode-manual" ).checked ) {
				mode = "manual";
			} else if ( document.getElementById( "atk-add-road-mode-roundabout" ).checked ) {
				mode = "roundabout";
			} else {
				mode = "snap";
			}
			instructions.textContent = {
				snap: "Click once on a real road and its exact shape is fetched from OpenStreetMap automatically.",
				manual: "Click a few points along the road's path — they'll be joined into a smooth curve automatically, not a straight line.",
				roundabout: "Click to place the roundabout's centre, click again to set its size, then drag either handle to adjust it."
			}[ mode ];
			resetDrawing();
		}
		document.getElementById( "atk-add-road-mode-snap" ).addEventListener( "change", applyMode );
		document.getElementById( "atk-add-road-mode-manual" ).addEventListener( "change", applyMode );
		document.getElementById( "atk-add-road-mode-roundabout" ).addEventListener( "change", applyMode );
	}

	function initNameSelect() {
		var $el = window.jQuery ? jQuery( "#atk-add-road-name" ) : null;
		if ( ! $el || ! $el.length || ! $el.select2 || $el.data( "select2" ) ) {
			return;
		}
		$el.select2( {
			tags: true,
			width: "100%",
			dropdownParent: jQuery( "#atk-add-road-modal" )
		} );
	}

	document.addEventListener( "DOMContentLoaded", function () {
		var modalEl = document.getElementById( "atk-add-road-modal" );
		if ( ! modalEl ) {
			return;
		}
		modalEl.addEventListener( "shown.bs.modal", function () {
			initMap();
			initModeToggle();
			initNameSelect();
		} );
		modalEl.addEventListener( "hidden.bs.modal", resetDrawing );
	} );
})();
