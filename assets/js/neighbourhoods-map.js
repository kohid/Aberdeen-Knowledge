"use strict";

/**
 * "Neighbourhoods" full-screen map study tool. Two views on one Leaflet map:
 *
 * 1. Overview — every real Aberdeen community council area (assets/data/
 *    neighbourhoods.json, real OSM administrative boundaries) drawn as a
 *    coloured, clickable polygon.
 * 2. Detail — once an area is clicked, the map zooms to its boundary and
 *    shows only the streets that fall inside it (filtered from assets/data/
 *    major-roads.json, the same full-city street data Major Streets uses),
 *    with the identical hover-to-reveal / Take Test quiz mechanic as the
 *    Major Streets tool.
 *
 * UI chrome is plain Bootstrap, matching major-streets-map.js.
 */
(function () {
	if ( typeof ATK_NEIGHBOURHOODS === "undefined" ) {
		return;
	}

	var mapEl = document.getElementById( "atk-neigh-map-el" );
	if ( ! mapEl ) {
		return;
	}

	var statusTextEl = document.getElementById( "atk-neigh-status-text" );
	var hoverTextEl  = document.getElementById( "atk-neigh-hover-text" );
	var timerPillEl  = document.getElementById( "atk-neigh-timer-pill" );
	var timerTextEl  = document.getElementById( "atk-neigh-timer-text" );
	var checklistEl     = document.getElementById( "atk-neigh-checklist" );
	var checklistBodyEl = document.getElementById( "atk-neigh-checklist-body" );
	var controlsEl   = document.getElementById( "atk-neigh-controls" );
	var backBtn      = document.getElementById( "atk-neigh-btn-back" );

	var introModal    = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-neigh-intro" ) );
	var completeModal = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-neigh-complete" ) );
	var reviewModal   = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-neigh-review" ) );
	var introTitleEl   = document.getElementById( "atk-neigh-intro-title" );
	var introBodyEl    = document.getElementById( "atk-neigh-intro-body" );
	var completeBodyEl = document.getElementById( "atk-neigh-complete-body" );
	var reviewBodyEl   = document.getElementById( "atk-neigh-review-body" );

	var splashBtn = document.getElementById( "atk-neigh-btn-splash" );
	var studyBtn  = document.getElementById( "atk-neigh-btn-study" );
	var testBtn   = document.getElementById( "atk-neigh-btn-test" );
	var reviewBtn = document.getElementById( "atk-neigh-btn-review" );

	var ABERDEEN_CENTER = [ 57.1497, -2.0943 ];
	// Colours come from the stylesheet's variables, so the map follows the
	// brand colour setting and the site's status colours.
	var rootStyle = window.getComputedStyle( document.documentElement );
	function cssVar( name, fallback ) {
		var v = rootStyle.getPropertyValue( name ).trim();
		return v || fallback;
	}
	var COLOR_DEFAULT = cssVar( "--akn-brand", "#555555" );
	var COLOR_HOVER   = cssVar( "--bs-warning", "#FFC700" );
	var COLOR_SUCCESS = cssVar( "--bs-success", "#50CD89" );
	var COLOR_DANGER  = cssVar( "--bs-danger", "#D92B57" );
	// Visual aid so unnamed connector segments (see displayName()) read as
	// secondary/background roads next to real named ones, rather than
	// competing with them for attention — the neutral gray.
	var COLOR_UNNAMED = "#B5B5C3";
	var LAST_SESSION_KEY = "atk_neighbourhoods_last_session";
	var FLASH_MS = 450;
	var HIT_THRESHOLD_PX = 18;

	var map = L.map( mapEl, { zoomControl: true } ).setView( ABERDEEN_CENTER, 11 );
	L.tileLayer( "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
		maxZoom: 19,
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
	} ).addTo( map );

	var cityBounds = L.latLngBounds( [] );

	// ---- overview state ---------------------------------------------------
	var viewMode = "overview"; // "overview" | "detail"
	var areas = [];
	var areaLayers = {}; // name -> L.polygon
	var areasLoaded = false;

	// ---- detail (per-area quiz) state -------------------------------------
	var allRoadSegments = null; // full parsed major-roads.json segments, fetched once
	var roadsLoaded = false;
	var currentArea = null;

	var streetLayers = {};
	var streetNames  = [];
	var allSegments  = [];
	var detailLayerGroup = null;
	// synthetic unnamed key -> nearest real named road (not an official name —
	// just proximity context, precomputed into major-roads.json).
	var nearNamedRoad = {};

	var quizActive    = false;
	var sessionNames  = [];
	var sessionIndex  = 0;
	var timerHandle   = null;
	var remainingSecs = 0;
	var advancing     = false;
	var sessionGen    = 0;

	var currentGuesses = [];
	var sessionResults = [];
	var resultsHighlightActive = false;
	// name -> COLOR_SUCCESS/COLOR_DANGER for whichever streets are part of
	// the just-finished session — the only ones still hoverable while
	// resultsHighlightActive is true; every other (muted) street is inert.
	var resultColorByName = null;

	function setStatusText( text ) {
		statusTextEl.textContent = text;
	}

	function idleStatusText() {
		if ( resultsHighlightActive ) {
			return "Test complete — see results below, or use Study Mode / Take Test to continue";
		}
		return currentArea ? "Hover over a street in " + currentArea.name + " to view its name" : "Choose a neighbourhood to explore its streets";
	}

	/**
	 * Secondary readout under the main status line: whatever street the
	 * cursor is currently over. Study Mode only — blank during an active
	 * test, so hovering can't be used to read off the answer.
	 */
	function setHoverText( text ) {
		hoverTextEl.textContent = text || "";
	}

	function styleStreet( name, color ) {
		( streetLayers[ name ] || [] ).forEach( function ( layer ) {
			layer.setStyle( { color: color } );
		} );
	}

	function isUnnamed( name ) {
		return 0 === name.indexOf( "__unnamed_" );
	}

	function restColorFor( name ) {
		if ( resultsHighlightActive ) {
			return ( resultColorByName && resultColorByName[ name ] ) || COLOR_UNNAMED;
		}
		return isUnnamed( name ) ? COLOR_UNNAMED : COLOR_DEFAULT;
	}

	/**
	 * Every street not part of the just-finished session fades to the same
	 * muted gray already used for unnamed connectors; each question's
	 * correct street is highlighted green, and — if the player answered
	 * wrong — whichever street they actually clicked is highlighted red
	 * right alongside it, so both the right answer and the mistake are
	 * visible on the map at the same time. Only these highlighted streets
	 * stay hoverable (see the mousemove handler below) — muted ones are
	 * inert until the player leaves this results view.
	 */
	function applyResultHighlights( results ) {
		Object.keys( streetLayers ).forEach( function ( name ) {
			styleStreet( name, COLOR_UNNAMED );
		} );

		resultColorByName = {};
		results.forEach( function ( r ) {
			resultColorByName[ r.name ] = COLOR_SUCCESS;
			styleStreet( r.name, COLOR_SUCCESS );
			if ( ! r.found && r.guesses && r.guesses.length ) {
				var wrongName = r.guesses[ r.guesses.length - 1 ];
				if ( wrongName !== r.name ) {
					resultColorByName[ wrongName ] = COLOR_DANGER;
					styleStreet( wrongName, COLOR_DANGER );
				}
			}
		} );

		resultsHighlightActive = true;
	}

	function clearResultHighlights() {
		if ( ! resultsHighlightActive ) {
			return;
		}
		resultsHighlightActive = false;
		Object.keys( streetLayers ).forEach( function ( name ) {
			styleStreet( name, restColorFor( name ) );
		} );
		resultColorByName = null;
	}

	function hideChecklist() {
		checklistEl.classList.add( "d-none" );
	}

	/**
	 * Left-side "Test Progress" card: one row per session question. Answered
	 * questions show what was clicked and whether it was correct; the
	 * current question is called out but future ones only show their
	 * number — revealing every target up front would let a player scan the
	 * list and find streets ahead of time instead of actually being tested.
	 */
	function renderChecklist() {
		if ( ! sessionNames.length ) {
			hideChecklist();
			return;
		}
		checklistEl.classList.remove( "d-none" );

		var html = "";
		sessionNames.forEach( function ( name, i ) {
			var result = sessionResults[ i ];
			if ( result ) {
				var badgeClass = result.found ? "badge-light-success" : "badge-light-danger";
				var badgeIcon  = result.found ? "bi-check-circle-fill" : "bi-x-circle-fill";
				var answerText = result.found
					? "Correct"
					: ( result.guesses && result.guesses.length ? "You picked: " + displayName( result.guesses[ result.guesses.length - 1 ] ) : "Time ran out" );
				html += '<div class="d-flex align-items-start gap-3 mb-4">'
					+ '<span class="badge ' + badgeClass + ' p-2"><i class="bi ' + badgeIcon + '"></i></span>'
					+ '<div class="flex-grow-1">'
					+ '<div class="fw-bold fs-8 text-gray-800">' + ( i + 1 ) + ". " + result.name + "</div>"
					+ '<div class="fs-9 text-gray-500">' + answerText + "</div>"
					+ "</div></div>";
			} else if ( quizActive && i === sessionIndex ) {
				html += '<div class="d-flex align-items-start gap-3 mb-4">'
					+ '<span class="badge badge-light-primary p-2"><i class="bi bi-signpost-split-fill"></i></span>'
					+ '<div class="flex-grow-1">'
					+ '<div class="fw-bold fs-8 text-gray-800">' + ( i + 1 ) + ". " + name + "</div>"
					+ '<div class="fs-9 text-gray-500">Current question</div>'
					+ "</div></div>";
			} else {
				html += '<div class="d-flex align-items-start gap-3 mb-4 opacity-50">'
					+ '<span class="badge badge-light p-2"><i class="bi bi-question-lg"></i></span>'
					+ '<div class="flex-grow-1">'
					+ '<div class="fw-bold fs-8 text-gray-500">Question ' + ( i + 1 ) + "</div>"
					+ "</div></div>";
			}
		} );
		checklistBodyEl.innerHTML = html;
	}

	function formatTime( totalSeconds ) {
		var m = Math.floor( totalSeconds / 60 );
		var s = totalSeconds % 60;
		return m + ":" + ( s < 10 ? "0" : "" ) + s;
	}

	function trackAttempt() {
		if ( ! ATK_NEIGHBOURHOODS.ajaxUrl ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( "action", "atk_track_attempt" );
		body.set( "test", "neighbourhoods" );
		body.set( "nonce", ATK_NEIGHBOURHOODS.attemptNonce );
		fetch( ATK_NEIGHBOURHOODS.ajaxUrl, { method: "POST", body: body } ).catch( function () {} );
	}

	/**
	 * Ray-casting point-in-polygon test, used to work out which streets fall
	 * inside a chosen neighbourhood's boundary. latlng is [lat, lon]; ring is
	 * an array of [lat, lon] pairs (same shape as our stored polygon points).
	 */
	function pointInRing( latlng, ring ) {
		var inside = false;
		var x = latlng[ 1 ], y = latlng[ 0 ];
		for ( var i = 0, j = ring.length - 1; i < ring.length; j = i++ ) {
			var xi = ring[ i ][ 1 ], yi = ring[ i ][ 0 ];
			var xj = ring[ j ][ 1 ], yj = ring[ j ][ 0 ];
			var intersect = ( ( yi > y ) !== ( yj > y ) ) &&
				( x < ( xj - xi ) * ( y - yi ) / ( yj - yi ) + xi );
			if ( intersect ) {
				inside = ! inside;
			}
		}
		return inside;
	}

	function displayName( name ) {
		return isUnnamed( name ) ? "an unnamed road" : name;
	}

	/**
	 * Bottom-line detail for the hovered line. Real named roads have nothing
	 * extra to add there (the top line already shows their one official
	 * name); unnamed segments show which real named road they're nearest to
	 * instead — clearly not an official name, just proximity context.
	 */
	function hoverDetailFor( name ) {
		return nearNamedRoad[ name ] ? ( "Nearest named road: " + nearNamedRoad[ name ] ) : "";
	}

	function pointToSegmentDistance( p, a, b ) {
		var dx = b.x - a.x;
		var dy = b.y - a.y;
		var lengthSq = dx * dx + dy * dy;
		if ( 0 === lengthSq ) {
			return Math.sqrt( ( p.x - a.x ) * ( p.x - a.x ) + ( p.y - a.y ) * ( p.y - a.y ) );
		}
		var t = Math.max( 0, Math.min( 1, ( ( p.x - a.x ) * dx + ( p.y - a.y ) * dy ) / lengthSq ) );
		var projX = a.x + t * dx;
		var projY = a.y + t * dy;
		return Math.sqrt( ( p.x - projX ) * ( p.x - projX ) + ( p.y - projY ) * ( p.y - projY ) );
	}

	function findNearestStreet( layerPoint ) {
		var bestName = null;
		var bestDist = Infinity;

		allSegments.forEach( function ( seg ) {
			for ( var i = 0; i < seg.latlngs.length - 1; i++ ) {
				var a = map.latLngToLayerPoint( seg.latlngs[ i ] );
				var b = map.latLngToLayerPoint( seg.latlngs[ i + 1 ] );
				var d = pointToSegmentDistance( layerPoint, a, b );
				if ( d < bestDist ) {
					bestDist = d;
					bestName = seg.name;
				}
			}
		} );

		return { name: bestName, distance: bestDist };
	}

	// ---- data loading -------------------------------------------------------

	function loadAreas() {
		return fetch( ATK_NEIGHBOURHOODS.areasUrl )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) {
				areas = data.areas || [];
				areas.forEach( function ( area ) {
					var latlngs = area.points;
					var polygon = L.polygon( latlngs, {
						color: area.color,
						weight: 2,
						fillColor: area.color,
						fillOpacity: 0.45
					} ).addTo( map );

					polygon.bindTooltip( area.name, { sticky: true } );

					polygon.on( "mouseover", function () {
						if ( "overview" !== viewMode ) {
							return;
						}
						polygon.setStyle( { fillOpacity: 0.7, weight: 3 } );
					} );
					polygon.on( "mouseout", function () {
						if ( "overview" !== viewMode ) {
							return;
						}
						polygon.setStyle( { fillOpacity: 0.45, weight: 2 } );
					} );
					polygon.on( "click", function () {
						if ( "overview" === viewMode ) {
							enterArea( area );
						}
					} );

					areaLayers[ area.name ] = polygon;
					cityBounds.extend( latlngs );
				} );

				if ( cityBounds.isValid() ) {
					map.fitBounds( cityBounds, { padding: [ 20, 20 ] } );
				}

				areasLoaded = true;
			} )
			.catch( function () {
				setStatusText( "Couldn't load neighbourhood data — please try again later." );
			} );
	}

	function loadRoadsOnce() {
		if ( allRoadSegments ) {
			return Promise.resolve( allRoadSegments );
		}
		return fetch( ATK_NEIGHBOURHOODS.roadsUrl )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) {
				allRoadSegments = data.segments || [];
				roadsLoaded = true;
				return allRoadSegments;
			} );
	}

	// ---- overview <-> detail transitions -------------------------------------

	function setAreaLayersVisible( visible ) {
		Object.keys( areaLayers ).forEach( function ( name ) {
			var layer = areaLayers[ name ];
			if ( visible ) {
				if ( ! map.hasLayer( layer ) ) {
					map.addLayer( layer );
				}
			} else if ( map.hasLayer( layer ) ) {
				map.removeLayer( layer );
			}
		} );
	}

	function clearDetailLayers() {
		if ( detailLayerGroup ) {
			map.removeLayer( detailLayerGroup );
			detailLayerGroup = null;
		}
		streetLayers = {};
		streetNames  = [];
		allSegments  = [];
		nearNamedRoad = {};
	}

	function enterArea( area ) {
		hideAllModals();
		stopActiveSession();
		clearDetailLayers();

		currentArea = area;
		viewMode = "detail";

		setAreaLayersVisible( false );
		backBtn.classList.remove( "d-none" );
		controlsEl.classList.remove( "d-none" );

		testBtn.disabled = true;
		testBtn.textContent = "Loading streets…";
		setStatusText( "Loading " + area.name + "…" );

		var areaBounds = L.latLngBounds( area.points );
		map.fitBounds( areaBounds, { padding: [ 30, 30 ] } );

		loadRoadsOnce().then( function ( segments ) {
			if ( currentArea !== area ) {
				return; // user already left this area before the fetch resolved
			}

			detailLayerGroup = L.layerGroup().addTo( map );
			var unnamedCounter = 0;

			segments.forEach( function ( segment ) {
				if ( ! segment.points.length || ! pointInRing( segment.points[ 0 ], area.points ) ) {
					return;
				}

				var visualLine = L.polyline( segment.points, {
					color: segment.name ? COLOR_DEFAULT : COLOR_UNNAMED,
					weight: 7,
					opacity: 0.85,
					interactive: false,
					smoothFactor: 0.3,
					lineJoin: "round",
					lineCap: "round"
				} ).addTo( detailLayerGroup );

				// Unnamed connector segments (roundabout slip lanes, unclassified
				// stubs) still need to be hoverable/clickable like every other
				// line, but there's no real name to quiz on — each gets its own
				// unique synthetic key so hovering/clicking one only highlights
				// that one line, without ever entering the quiz-target pool.
				var name = segment.name || ( "__unnamed_" + ( unnamedCounter++ ) );

				if ( ! segment.name && segment.near ) {
					nearNamedRoad[ name ] = segment.near;
				}

				if ( ! streetLayers[ name ] ) {
					streetLayers[ name ] = [];
					if ( segment.name && false !== segment.findable ) {
						streetNames.push( name );
					}
				}
				streetLayers[ name ].push( visualLine );

				allSegments.push( {
					name: name,
					latlngs: segment.points.map( function ( p ) { return L.latLng( p[ 0 ], p[ 1 ] ); } )
				} );
			} );

			testBtn.disabled = streetNames.length < 1;
			testBtn.textContent = "Take Test";
			setStatusText( idleStatusText() );

			introTitleEl.textContent = area.name;
			introBodyEl.textContent = streetNames.length
				? "Study the streets of " + area.name + ". Hover any street in Study Mode to see its name. When you're ready, use Take Test to be prompted to find "
					+ Math.min( ATK_NEIGHBOURHOODS.sessionSize, streetNames.length ) + " of the " + streetNames.length + " streets here."
				: "No named streets were found inside " + area.name + "'s boundary in this data set yet.";
			introModal.show();
		} );
	}

	function exitToOverview() {
		hideAllModals();
		stopActiveSession();
		clearDetailLayers();

		currentArea = null;
		viewMode = "overview";

		setAreaLayersVisible( true );
		backBtn.classList.add( "d-none" );
		controlsEl.classList.add( "d-none" );
		setStatusText( "Choose a neighbourhood to explore its streets" );

		if ( cityBounds.isValid() ) {
			map.fitBounds( cityBounds, { padding: [ 20, 20 ] } );
		}
	}

	// ---- quiz engine (identical mechanic to Major Streets, scoped to currentArea) ----

	function pickSessionNames() {
		var pool = streetNames.slice();
		for ( var i = pool.length - 1; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var tmp = pool[ i ];
			pool[ i ] = pool[ j ];
			pool[ j ] = tmp;
		}
		return pool.slice( 0, Math.min( ATK_NEIGHBOURHOODS.sessionSize, pool.length ) );
	}

	function renderCurrentTarget() {
		var name = sessionNames[ sessionIndex ];
		currentGuesses = [];
		advancing = false;
		setStatusText( ( sessionIndex + 1 ) + " of " + sessionNames.length + " | Find: " + name );
		renderChecklist();
	}

	function handleStreetClick( clickedName ) {
		if ( ! quizActive || advancing ) {
			return;
		}
		var target    = sessionNames[ sessionIndex ];
		var myGen     = sessionGen;
		var isCorrect = ( clickedName === target );

		currentGuesses.push( clickedName );
		advancing = true;
		styleStreet( clickedName, isCorrect ? COLOR_SUCCESS : COLOR_DANGER );

		sessionResults.push( {
			name: target,
			found: isCorrect,
			guesses: currentGuesses.slice()
		} );
		renderChecklist();

		setTimeout( function () {
			if ( myGen !== sessionGen ) {
				return;
			}
			styleStreet( clickedName, restColorFor( clickedName ) );
			sessionIndex++;
			if ( sessionIndex >= sessionNames.length ) {
				finishSession( false );
			} else {
				renderCurrentTarget();
			}
		}, FLASH_MS );
	}

	function hideAllModals() {
		introModal.hide();
		completeModal.hide();
		reviewModal.hide();
	}

	function stopActiveSession() {
		sessionGen++;
		clearInterval( timerHandle );
		quizActive = false;
		advancing = false;
		timerPillEl.classList.add( "d-none" );
		setHoverText( "" );
		clearResultHighlights();
		hideChecklist();
		setStatusText( idleStatusText() );
	}

	function enterStudyMode() {
		hideAllModals();
		stopActiveSession();
	}

	function showSplash() {
		if ( ! currentArea ) {
			return;
		}
		hideAllModals();
		introModal.show();
	}

	function startTest() {
		if ( ! currentArea || ! streetNames.length ) {
			return;
		}
		hideAllModals();
		clearResultHighlights();

		sessionGen++;
		quizActive = true;
		setHoverText( "" );
		sessionNames = pickSessionNames();
		sessionIndex = 0;
		sessionResults = [];
		trackAttempt();

		remainingSecs = ATK_NEIGHBOURHOODS.minutes * 60;
		timerPillEl.classList.remove( "d-none" );
		timerTextEl.textContent = formatTime( remainingSecs );
		clearInterval( timerHandle );
		timerHandle = setInterval( function () {
			remainingSecs--;
			timerTextEl.textContent = formatTime( Math.max( remainingSecs, 0 ) );
			if ( remainingSecs <= 0 ) {
				finishSession( true );
			}
		}, 1000 );

		if ( sessionNames.length ) {
			renderCurrentTarget();
		}
	}

	function finishSession( timedOut ) {
		clearInterval( timerHandle );

		if ( timedOut && sessionIndex < sessionNames.length ) {
			sessionResults.push( {
				name: sessionNames[ sessionIndex ],
				found: false,
				guesses: currentGuesses.slice()
			} );
		}

		quizActive = false;
		advancing = false;
		timerPillEl.classList.add( "d-none" );
		setHoverText( "" );

		var foundCount = sessionResults.filter( function ( r ) { return r.found; } ).length;
		saveLastSession( {
			area: currentArea ? currentArea.name : "",
			results: sessionResults,
			total: sessionNames.length,
			foundCount: foundCount,
			timedOut: !! timedOut,
			completedAt: Date.now()
		} );

		completeBodyEl.textContent = timedOut
			? "Time's up — you found " + foundCount + " of " + sessionNames.length + " streets in " + ( currentArea ? currentArea.name : "this area" ) + "."
			: "You found all " + foundCount + " of " + sessionNames.length + " streets in " + ( currentArea ? currentArea.name : "this area" ) + "!";
		completeModal.show();

		applyResultHighlights( sessionResults );
		renderChecklist();
		setStatusText( idleStatusText() );
	}

	function saveLastSession( summary ) {
		try {
			localStorage.setItem( LAST_SESSION_KEY, JSON.stringify( summary ) );
		} catch ( e ) {
			// Private browsing / storage disabled — review just won't have anything to show.
		}
	}

	function loadLastSession() {
		try {
			var raw = localStorage.getItem( LAST_SESSION_KEY );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function showReview() {
		hideAllModals();
		stopActiveSession();

		var summary = loadLastSession();
		if ( ! summary || ! summary.results || ! summary.results.length ) {
			reviewBodyEl.innerHTML = "<p>You haven't completed a test yet. Use <strong>Take Test</strong> to get started.</p>";
			reviewModal.show();
			return;
		}

		var sameArea = currentArea && summary.area === currentArea.name;
		var html = "<p>" + ( summary.area ? "<strong>" + summary.area + "</strong> — " : "" ) + summary.foundCount + " of " + summary.total + " found"
			+ ( summary.timedOut ? " (time ran out)" : "" ) + "."
			+ ( sameArea ? " Correct answers are shown in green on the map; if you picked wrong, your answer is shown in red." : "" )
			+ "</p>";

		if ( sameArea ) {
			var reviewBounds = L.latLngBounds( [] );
			summary.results.forEach( function ( r ) {
				( streetLayers[ r.name ] || [] ).forEach( function ( layer ) {
					reviewBounds.extend( layer.getLatLngs() );
				} );
			} );
			if ( reviewBounds.isValid() ) {
				map.fitBounds( reviewBounds, { padding: [ 60, 60 ] } );
			}

			// Reuse the same session state the live end-of-test view uses, so
			// the left-side checklist and the map highlighting match exactly
			// whether you're looking at this right after finishing or
			// reopening it later in the same area.
			sessionNames   = summary.results.map( function ( r ) { return r.name; } );
			sessionResults = summary.results;
			applyResultHighlights( summary.results );
			renderChecklist();
			setStatusText( idleStatusText() );
		}

		summary.results.forEach( function ( r ) {
			var badgeClass = r.found ? "badge-success" : "badge-danger";
			var badgeText  = r.found ? "Correct" : "Incorrect";
			var guessesText = r.guesses && r.guesses.length
				? "Your answer: " + r.guesses.map( displayName ).join( " → " )
				: "No street clicked (time ran out)";
			html += '<div class="atk-map-review-row">'
				+ '<div><div class="fw-bold">' + r.name + "</div>"
				+ '<div class="atk-map-review-guesses">' + guessesText + "</div></div>"
				+ '<span class="badge ' + badgeClass + '">' + badgeText + "</span>"
				+ "</div>";
		} );

		reviewBodyEl.innerHTML = html;
		reviewModal.show();
	}

	backBtn.addEventListener( "click", exitToOverview );
	splashBtn.addEventListener( "click", showSplash );
	studyBtn.addEventListener( "click", enterStudyMode );
	testBtn.addEventListener( "click", startTest );
	reviewBtn.addEventListener( "click", showReview );

	var hoveredName    = null;
	var hoverThrottled = false;

	map.on( "mousemove", function ( e ) {
		if ( "detail" !== viewMode || hoverThrottled ) {
			return;
		}
		hoverThrottled = true;
		setTimeout( function () { hoverThrottled = false; }, 60 );

		var nearest = findNearestStreet( map.latLngToLayerPoint( e.latlng ) );
		var newHovered = ( nearest.name && nearest.distance <= HIT_THRESHOLD_PX ) ? nearest.name : null;
		if ( newHovered === hoveredName ) {
			return;
		}
		if ( hoveredName ) {
			styleStreet( hoveredName, restColorFor( hoveredName ) );
		}
		hoveredName = newHovered;
		if ( hoveredName ) {
			// A correct/wrong-answer street keeps its green/red colour even
			// while hovered — overwriting it with the hover colour would
			// make the highlight disappear the moment you look closer at it,
			// which defeats the point of highlighting it. Everything else
			// still gets the normal gold hover flash.
			var isHighlighted = resultsHighlightActive && resultColorByName && resultColorByName[ hoveredName ];
			if ( ! isHighlighted ) {
				styleStreet( hoveredName, COLOR_HOVER );
			}
			if ( ! quizActive ) {
				setStatusText( displayName( hoveredName ) );
				setHoverText( hoverDetailFor( hoveredName ) );
			}
		} else if ( ! quizActive ) {
			setStatusText( idleStatusText() );
			setHoverText( "" );
		}
	} );

	map.on( "mouseout", function () {
		if ( "detail" !== viewMode ) {
			return;
		}
		if ( hoveredName ) {
			styleStreet( hoveredName, restColorFor( hoveredName ) );
			hoveredName = null;
		}
		if ( ! quizActive ) {
			setStatusText( idleStatusText() );
			setHoverText( "" );
		}
	} );

	map.on( "click", function ( e ) {
		if ( "detail" !== viewMode || resultsHighlightActive ) {
			return;
		}
		var nearest = findNearestStreet( map.latLngToLayerPoint( e.latlng ) );
		if ( nearest.name && nearest.distance <= HIT_THRESHOLD_PX ) {
			handleStreetClick( nearest.name );
		}
	} );

	loadAreas();
})();
