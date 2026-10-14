"use strict";

/**
 * "Main Arteries" full-screen map study tool. Draws the real Aberdeen
 * major-road network (assets/data/major-roads.json) over the same
 * OpenStreetMap tile layer used by the Street/POI/Route Test maps.
 * UI chrome is plain Bootstrap (modals via the bootstrap.Modal API,
 * buttons, badges, cards) — no custom theme.
 */
(function () {
	if ( typeof ATK_ARTERIES === "undefined" ) {
		return;
	}

	var mapEl = document.getElementById( "atk-arteries-map-el" );
	if ( ! mapEl ) {
		return;
	}

	var statusTextEl = document.getElementById( "atk-map-status-text" );
	var hoverTextEl  = document.getElementById( "atk-map-hover-text" );
	var timerPillEl  = document.getElementById( "atk-map-timer-pill" );
	var timerTextEl  = document.getElementById( "atk-map-timer-text" );
	var checklistEl     = document.getElementById( "atk-map-checklist" );
	var checklistBodyEl = document.getElementById( "atk-map-checklist-body" );

	var introModal    = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-map-intro" ) );
	var completeModal = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-map-complete" ) );
	var reviewModal   = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-map-review" ) );
	var completeBodyEl = document.getElementById( "atk-map-complete-body" );
	var reviewBodyEl   = document.getElementById( "atk-map-review-body" );

	var splashBtn = document.getElementById( "atk-map-btn-splash" );
	var studyBtn  = document.getElementById( "atk-map-btn-study" );
	var testBtn   = document.getElementById( "atk-map-btn-test" );
	var reviewBtn = document.getElementById( "atk-map-btn-review" );

	// Take Test builds its session from the fetched road data — disable it
	// until that's actually loaded, otherwise a click before the fetch
	// resolves starts a session with zero possible targets (every answer
	// would then compare against `undefined` and always read as wrong).
	var testBtnDefaultText = testBtn.textContent;
	testBtn.disabled = true;
	testBtn.textContent = "Loading streets…";

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
	var LAST_SESSION_KEY = "atk_major_streets_last_session";
	var FLASH_MS = 450;

	// Same tile provider as the Street/POI/Route Test maps (quiz.js), for a
	// consistent look across every map on the site.
	var map = L.map( mapEl, { zoomControl: true } ).setView( ABERDEEN_CENTER, 11 );
	L.tileLayer( "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
		maxZoom: 19,
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
	} ).addTo( map );

	// name -> array of polylines (a street can be made of several OSM ways)
	var streetLayers = {};
	var streetNames  = [];
	// Flat list of every segment's raw points, for the nearest-line hit test.
	var allSegments  = [];
	var HIT_THRESHOLD_PX = 18;
	// synthetic unnamed key -> nearest real named road (not an official name —
	// just proximity context, precomputed into major-roads.json).
	var nearNamedRoad = {};

	var quizActive    = false;
	var sessionNames  = [];
	var sessionIndex  = 0;
	var timerHandle   = null;
	var remainingSecs = 0;
	var advancing     = false; // true while a flash/advance is mid-timeout, blocks further clicks
	var sessionGen    = 0;     // bumped whenever a session starts/stops, so a flash's setTimeout
	                           // from a previous session can tell it's stale and skip mutating state

	var currentGuesses = [];
	var sessionResults = [];
	var roadsLoaded = false;
	var resultsHighlightActive = false;
	// name -> COLOR_SUCCESS/COLOR_DANGER for whichever streets are part of
	// the just-finished session — the only ones still hoverable while
	// resultsHighlightActive is true; every other (muted) street is inert.
	var resultColorByName = null;

	function setStatusText( text ) {
		statusTextEl.textContent = text;
	}

	function idleStatusText() {
		return resultsHighlightActive
			? "Test complete — see results below, or use Study Mode / Take Test to continue"
			: "Hover over street to view name";
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
		if ( ! ATK_ARTERIES.ajaxUrl ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( "action", "atk_track_attempt" );
		body.set( "test", "major-streets" );
		body.set( "nonce", ATK_ARTERIES.attemptNonce );
		fetch( ATK_ARTERIES.ajaxUrl, { method: "POST", body: body } ).catch( function () {} );
	}

	/**
	 * Distance from point p to the segment a→b, in layer (pixel) space.
	 */
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

	/**
	 * Find the street whose line is geometrically nearest to a click/hover
	 * point, by actual pixel distance — not by which overlapping element
	 * happens to sit on top in the DOM. In a dense grid (central Aberdeen),
	 * wide clickable lines for adjacent streets can visually overlap; only a
	 * real nearest-distance check reliably picks the one the user meant.
	 */
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

	/**
	 * Segments with no `name` (roundabout slip lanes, unclassified stubs —
	 * drawn for visual continuity) still need to be hoverable/clickable like
	 * every other line, but there's no real name to quiz on. Each gets its
	 * own unique synthetic key so hovering/clicking one only highlights that
	 * one line, without ever entering the quiz-target pool.
	 */
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

	function loadRoads() {
		fetch( ATK_ARTERIES.dataUrl )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) {
				var bounds = L.latLngBounds( [] );

				( data.segments || [] ).forEach( function ( segment, index ) {
					var visualLine = L.polyline( segment.points, {
						color: segment.name ? COLOR_DEFAULT : COLOR_UNNAMED,
						weight: 7,
						opacity: 0.85,
						interactive: false,
						smoothFactor: 0.3,
						lineJoin: "round",
						lineCap: "round"
					} ).addTo( map );
					bounds.extend( segment.points );

					var name = segment.name || ( "__unnamed_" + index );

					if ( ! segment.name && segment.near ) {
						nearNamedRoad[ name ] = segment.near;
					}

					if ( ! streetLayers[ name ] ) {
						streetLayers[ name ] = [];
						// Real named roads (findable !== false) are quiz targets;
						// named roundabouts and unnamed connectors are hoverable
						// for context only.
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

				if ( bounds.isValid() ) {
					map.fitBounds( bounds, { padding: [ 30, 30 ] } );
				}

				roadsLoaded = true;
				testBtn.disabled = false;
				testBtn.textContent = testBtnDefaultText;
			} )
			.catch( function () {
				setStatusText( "Couldn't load street data — please try again later." );
			} );
	}

	function pickSessionNames() {
		var pool = streetNames.slice();
		for ( var i = pool.length - 1; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var tmp = pool[ i ];
			pool[ i ] = pool[ j ];
			pool[ j ] = tmp;
		}
		return pool.slice( 0, Math.min( ATK_ARTERIES.sessionSize, pool.length ) );
	}

	/**
	 * Show the next prompt without hinting at the answer — the target street
	 * looks identical to every other street until the user finds it.
	 */
	function renderCurrentTarget() {
		var name = sessionNames[ sessionIndex ];
		currentGuesses = [];
		advancing = false;
		setStatusText( ( sessionIndex + 1 ) + " of " + sessionNames.length + " | Find: " + name );
		renderChecklist();
	}

	/**
	 * Like a real test: whichever street you click IS your answer for this
	 * question — right or wrong, it's graded and the test moves on. It does
	 * not require you to keep guessing until you land on the correct one.
	 */
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
				return; // a new session/study-mode switch happened while this flash was pending
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
		setStatusText( "Hover over street to view name" );
		setHoverText( "" );
		clearResultHighlights();
		hideChecklist();
	}

	/**
	 * "Study Mode": stop any active test and return to free hover-explore,
	 * with all modals closed.
	 */
	function enterStudyMode() {
		hideAllModals();
		stopActiveSession();
	}

	function showSplash() {
		hideAllModals();
		introModal.show();
	}

	function startTest() {
		if ( ! roadsLoaded ) {
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

		remainingSecs = ATK_ARTERIES.minutes * 60;
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
			results: sessionResults,
			total: sessionNames.length,
			foundCount: foundCount,
			timedOut: !! timedOut,
			completedAt: Date.now()
		} );

		completeBodyEl.textContent = timedOut
			? "Time's up — you found " + foundCount + " of " + sessionNames.length + " major streets."
			: "You found all " + foundCount + " of " + sessionNames.length + " major streets!";
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
		stopActiveSession(); // stop any running test, and clear any previous review highlight

		var summary = loadLastSession();
		if ( ! summary || ! summary.results || ! summary.results.length ) {
			reviewBodyEl.innerHTML = "<p>You haven't completed a test yet. Use <strong>Take Test</strong> to get started.</p>";
			reviewModal.show();
			return;
		}

		var html = "<p>" + summary.foundCount + " of " + summary.total + " found"
			+ ( summary.timedOut ? " (time ran out)" : "" ) + ". Correct answers are shown in green on the map; if you picked wrong, your answer is shown in red.</p>";

		var reviewBounds = L.latLngBounds( [] );

		summary.results.forEach( function ( r ) {
			// r.name is the street that was asked about (i.e. the correct
			// answer) — shown as the row title; the guess line below is
			// what the user actually clicked for that prompt.
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

			( streetLayers[ r.name ] || [] ).forEach( function ( layer ) {
				reviewBounds.extend( layer.getLatLngs() );
			} );
		} );

		reviewBodyEl.innerHTML = html;
		if ( reviewBounds.isValid() ) {
			map.fitBounds( reviewBounds, { padding: [ 60, 60 ] } );
		}

		// Reuse the same session state the live end-of-test view uses, so
		// the left-side checklist and the map highlighting match exactly
		// whether you're looking at this right after finishing or reopening
		// it later in the same visit.
		sessionNames   = summary.results.map( function ( r ) { return r.name; } );
		sessionResults = summary.results;
		applyResultHighlights( summary.results );
		renderChecklist();
		setStatusText( idleStatusText() );

		reviewModal.show();
	}

	splashBtn.addEventListener( "click", showSplash );
	studyBtn.addEventListener( "click", enterStudyMode );
	testBtn.addEventListener( "click", startTest );
	reviewBtn.addEventListener( "click", showReview );

	// Hover and click are both driven by the same nearest-line lookup, bound
	// once on the map itself, so "what you're hovering" and "what a click
	// selects" are always the same street for a given cursor position.
	var hoveredName   = null;
	var hoverThrottled = false;

	map.on( "mousemove", function ( e ) {
		if ( hoverThrottled ) {
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
		if ( resultsHighlightActive ) {
			return;
		}
		var nearest = findNearestStreet( map.latLngToLayerPoint( e.latlng ) );
		if ( nearest.name && nearest.distance <= HIT_THRESHOLD_PX ) {
			handleStreetClick( nearest.name );
		}
	} );

	loadRoads();
	introModal.show();
})();
