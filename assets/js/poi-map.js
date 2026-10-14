"use strict";

/**
 * "Places of Interest" full-screen map study tool. Same Study Mode/Take
 * Test/Review Last Test mechanic as major-streets-map.js, but pins instead
 * of street lines — clicking/hovering a marker is native Leaflet, so this
 * doesn't need that file's custom nearest-line hit test.
 *
 * Pin colour/icon per category comes from the theme palette (Bootstrap colour names, set in akn.css) and
 * Bootstrap Icons (verified against the compiled bundle), built with the
 * theme's existing "symbol" component — not an invented marker style.
 */
(function () {
	if ( typeof ATK_POI === "undefined" ) {
		return;
	}

	var mapEl = document.getElementById( "atk-poi-map-el" );
	if ( ! mapEl ) {
		return;
	}

	var statusTextEl = document.getElementById( "atk-poi-status-text" );
	var hoverTextEl  = document.getElementById( "atk-poi-hover-text" );
	var timerPillEl  = document.getElementById( "atk-poi-timer-pill" );
	var timerTextEl  = document.getElementById( "atk-poi-timer-text" );
	var legendEl     = document.getElementById( "atk-poi-legend" );
	var legendBodyEl = document.getElementById( "atk-poi-legend-body" );
	var checklistEl     = document.getElementById( "atk-poi-checklist" );
	var checklistBodyEl = document.getElementById( "atk-poi-checklist-body" );

	var introModal    = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-poi-intro" ) );
	var completeModal = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-poi-complete" ) );
	var reviewModal   = bootstrap.Modal.getOrCreateInstance( document.getElementById( "atk-poi-review" ) );
	var completeBodyEl = document.getElementById( "atk-poi-complete-body" );
	var reviewBodyEl   = document.getElementById( "atk-poi-review-body" );

	var splashBtn = document.getElementById( "atk-poi-btn-splash" );
	var legendBtn = document.getElementById( "atk-poi-btn-legend" );
	var studyBtn  = document.getElementById( "atk-poi-btn-study" );
	var testBtn   = document.getElementById( "atk-poi-btn-test" );
	var reviewBtn = document.getElementById( "atk-poi-btn-review" );

	var testBtnDefaultText = testBtn.textContent;
	testBtn.disabled = true;
	testBtn.textContent = "Loading venues…";

	var ABERDEEN_CENTER = [ 57.1497, -2.0943 ];
	var LAST_SESSION_KEY = "atk_poi_last_session";
	var CLOSED_STATUSES = [ "closed", "relocated" ];

	// Named theme colours, one (color, icon) pair per category —
	// only 7 solid theme colours really exist, so a few categories share a
	// colour but never an icon, and the legend always pairs both with the
	// label so nothing is ambiguous.
	var CATEGORY_META = {
		"Clubs & Members Clubs": { color: "dark", icon: "bi-people-fill" },
		"Hotels": { color: "primary", icon: "bi-key-fill" },
		"Restaurants and Cafes": { color: "warning", icon: "bi-cup-fill" },
		"Museums Arts & Culture": { color: "info", icon: "bi-bank2" },
		"Transport Hubs": { color: "success", icon: "bi-signpost-split-fill" },
		"Schools Libraries & Learning": { color: "secondary", icon: "bi-book-fill" },
		"Community Centres": { color: "primary", icon: "bi-house-fill" },
		"Sports Facilities": { color: "success", icon: "bi-trophy-fill" },
		"Religious Buildings & Cemeteries": { color: "dark", icon: "bi-building" },
		"Living Spaces": { color: "warning", icon: "bi-house-door-fill" },
		"Other Amenities": { color: "danger", icon: "bi-geo-alt-fill" }
	};
	var DEFAULT_META = { color: "secondary", icon: "bi-geo-alt-fill" };

	function categoryMeta( category ) {
		return CATEGORY_META[ category ] || DEFAULT_META;
	}

	var map = L.map( mapEl, { zoomControl: true } ).setView( ABERDEEN_CENTER, 12 );
	L.tileLayer( "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
		maxZoom: 19,
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
	} ).addTo( map );

	// name -> venue record; name -> marker; category -> bool (visible)
	var venuesByName  = {};
	var markersByName = {};
	var venueNames    = [];    // findable (non closed/relocated) venue names — the quiz pool
	var activeCategories = {};

	var quizActive    = false;
	var sessionNames  = [];
	var sessionIndex  = 0;
	var timerHandle   = null;
	var remainingSecs = 0;
	var sessionGen    = 0;

	var currentGuesses = [];
	var sessionResults = [];
	var venuesLoaded = false;
	var resultsHighlightActive = false;

	function setStatusText( text ) {
		statusTextEl.textContent = text;
	}

	function setHoverText( text ) {
		hoverTextEl.textContent = text || "";
	}

	function isFindable( venue ) {
		return CLOSED_STATUSES.indexOf( venue.status ) === -1;
	}

	function statusLabel( venue ) {
		if ( "closed" === venue.status ) { return "Closed"; }
		if ( "relocated" === venue.status ) { return "Relocated"; }
		if ( "renovating" === venue.status ) { return "Temporarily closed for renovation"; }
		return "";
	}

	function markerIcon( category, highlight, muted ) {
		var meta = categoryMeta( category );
		var color = highlight || meta.color;
		var classes = "atk-poi-pin" + ( muted ? " atk-poi-pin-muted" : "" );
		return L.divIcon( {
			className: classes,
			html: '<div class="symbol symbol-30px symbol-circle"><div class="symbol-label bg-' + color + '"><i class="bi ' + meta.icon + ' text-white fs-7"></i></div></div>',
			iconSize: [ 30, 30 ],
			iconAnchor: [ 15, 15 ],
			popupAnchor: [ 0, -18 ]
		} );
	}

	function formatTime( totalSeconds ) {
		var m = Math.floor( totalSeconds / 60 );
		var s = totalSeconds % 60;
		return m + ":" + ( s < 10 ? "0" : "" ) + s;
	}

	function trackAttempt() {
		if ( ! ATK_POI.ajaxUrl ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( "action", "atk_track_attempt" );
		body.set( "test", "places-of-interest" );
		body.set( "nonce", ATK_POI.attemptNonce );
		fetch( ATK_POI.ajaxUrl, { method: "POST", body: body } ).catch( function () {} );
	}

	function applyCategoryVisibility() {
		Object.keys( markersByName ).forEach( function ( name ) {
			var venue  = venuesByName[ name ];
			var marker = markersByName[ name ];
			var show   = !! activeCategories[ venue.category ];
			if ( show && ! map.hasLayer( marker ) ) {
				marker.addTo( map );
			} else if ( ! show && map.hasLayer( marker ) ) {
				map.removeLayer( marker );
			}
		} );
	}

	function renderLegend() {
		var html = "";
		Object.keys( CATEGORY_META ).forEach( function ( category ) {
			var meta = categoryMeta( category );
			var checked = activeCategories[ category ] ? " checked" : "";
			html += '<label class="d-flex align-items-center gap-3 mb-3">'
				+ '<input type="checkbox" class="form-check-input atk-poi-legend-check" data-category="' + category.replace( /"/g, "&quot;" ) + '"' + checked + ' />'
				+ '<span class="symbol symbol-25px symbol-circle"><span class="symbol-label bg-' + meta.color + '"><i class="bi ' + meta.icon + ' text-white fs-8"></i></span></span>'
				+ '<span class="fs-7 text-gray-700">' + category + "</span>"
				+ "</label>";
		} );
		legendBodyEl.innerHTML = html;

		legendBodyEl.querySelectorAll( ".atk-poi-legend-check" ).forEach( function ( input ) {
			input.addEventListener( "change", function () {
				activeCategories[ input.getAttribute( "data-category" ) ] = input.checked;
				applyCategoryVisibility();
			} );
		} );
	}

	function loadVenues() {
		fetch( ATK_POI.dataUrl )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) {
				var bounds = L.latLngBounds( [] );

				( data.venues || [] ).forEach( function ( venue ) {
					venuesByName[ venue.name ] = venue;
					activeCategories[ venue.category ] = true;

					var marker = L.marker( [ venue.lat, venue.lng ], { icon: markerIcon( venue.category ) } ).addTo( map );
					markersByName[ venue.name ] = marker;
					bounds.extend( [ venue.lat, venue.lng ] );

					if ( isFindable( venue ) ) {
						venueNames.push( venue.name );
					}

					marker.on( "mouseover", function () {
						if ( quizActive ) {
							return;
						}
						var label = statusLabel( venue );
						setStatusText( venue.name + ( label ? " (" + label + ")" : "" ) );
						setHoverText( venue.category + ( venue.address ? " — " + venue.address : "" ) );
					} );
					marker.on( "mouseout", function () {
						if ( ! quizActive ) {
							setStatusText( "Hover over a pin to view its name" );
							setHoverText( "" );
						}
					} );
					marker.on( "click", function () {
						handleVenueClick( venue.name );
					} );
				} );

				if ( bounds.isValid() ) {
					map.fitBounds( bounds, { padding: [ 30, 30 ] } );
				}

				renderLegend();
				venuesLoaded = true;
				testBtn.disabled = false;
				testBtn.textContent = testBtnDefaultText;
			} )
			.catch( function () {
				setStatusText( "Couldn't load places of interest — please try again later." );
			} );
	}

	function pickSessionNames() {
		var pool = venueNames.slice();
		for ( var i = pool.length - 1; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var tmp = pool[ i ];
			pool[ i ] = pool[ j ];
			pool[ j ] = tmp;
		}
		return pool.slice( 0, Math.min( ATK_POI.sessionSize, pool.length ) );
	}

	function renderCurrentTarget() {
		var name = sessionNames[ sessionIndex ];
		currentGuesses = [];
		setStatusText( ( sessionIndex + 1 ) + " of " + sessionNames.length + " | Find: " + name );
		renderChecklist();
	}

	/**
	 * Left-side "Test Progress" card: one row per session question. Answered
	 * questions show what was clicked and whether it was correct; the
	 * current question is called out but future ones only show their
	 * number — revealing every target up front would let a player scan the
	 * list and find pins ahead of time instead of actually being tested.
	 */
	function renderChecklist() {
		if ( ! sessionNames.length ) {
			checklistEl.classList.add( "d-none" );
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
					: ( result.guesses && result.guesses.length ? "You picked: " + result.guesses[ result.guesses.length - 1 ] : "Time ran out" );
				html += '<div class="d-flex align-items-start gap-3 mb-4">'
					+ '<span class="badge ' + badgeClass + ' p-2"><i class="bi ' + badgeIcon + '"></i></span>'
					+ '<div class="flex-grow-1">'
					+ '<div class="fw-bold fs-8 text-gray-800">' + ( i + 1 ) + ". " + result.name + "</div>"
					+ '<div class="fs-9 text-gray-500">' + answerText + "</div>"
					+ "</div></div>";
			} else if ( quizActive && i === sessionIndex ) {
				html += '<div class="d-flex align-items-start gap-3 mb-4">'
					+ '<span class="badge badge-light-primary p-2"><i class="bi bi-geo-alt-fill"></i></span>'
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

	function handleVenueClick( clickedName ) {
		if ( ! quizActive ) {
			return;
		}
		var target    = sessionNames[ sessionIndex ];
		var myGen     = sessionGen;
		var isCorrect = ( clickedName === target );

		currentGuesses.push( clickedName );
		markersByName[ clickedName ].setIcon( markerIcon( venuesByName[ clickedName ].category, isCorrect ? "success" : "danger" ) );

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
			markersByName[ clickedName ].setIcon( markerIcon( venuesByName[ clickedName ].category ) );
			sessionIndex++;
			if ( sessionIndex >= sessionNames.length ) {
				finishSession( false );
			} else {
				renderCurrentTarget();
			}
		}, 450 );
	}

	function hideAllModals() {
		introModal.hide();
		completeModal.hide();
		reviewModal.hide();
	}

	/**
	 * Every pin not part of the just-finished session fades to muted gray;
	 * each question's correct venue is highlighted green, and — if the
	 * player answered wrong — whichever venue they actually clicked is
	 * highlighted red right alongside it, so both the right answer and the
	 * player's mistake are visible on the map at the same time.
	 */
	function applyResultHighlights( results ) {
		Object.keys( markersByName ).forEach( function ( name ) {
			markersByName[ name ].setIcon( markerIcon( venuesByName[ name ].category, null, true ) );
		} );

		results.forEach( function ( r ) {
			if ( markersByName[ r.name ] ) {
				markersByName[ r.name ].setIcon( markerIcon( venuesByName[ r.name ].category, "success" ) );
			}
			if ( ! r.found && r.guesses && r.guesses.length ) {
				var wrongName = r.guesses[ r.guesses.length - 1 ];
				if ( wrongName !== r.name && markersByName[ wrongName ] ) {
					markersByName[ wrongName ].setIcon( markerIcon( venuesByName[ wrongName ].category, "danger" ) );
				}
			}
		} );

		resultsHighlightActive = true;
	}

	function clearResultHighlights() {
		if ( ! resultsHighlightActive ) {
			return;
		}
		Object.keys( markersByName ).forEach( function ( name ) {
			markersByName[ name ].setIcon( markerIcon( venuesByName[ name ].category ) );
		} );
		resultsHighlightActive = false;
	}

	function hideChecklist() {
		sessionNames = [];
		checklistEl.classList.add( "d-none" );
	}

	function stopActiveSession() {
		sessionGen++;
		clearInterval( timerHandle );
		quizActive = false;
		timerPillEl.classList.add( "d-none" );
		setStatusText( "Hover over a pin to view its name" );
		setHoverText( "" );
		clearResultHighlights();
		hideChecklist();
	}

	function enterStudyMode() {
		hideAllModals();
		legendEl.classList.add( "d-none" );
		stopActiveSession();
	}

	function showSplash() {
		hideAllModals();
		legendEl.classList.add( "d-none" );
		introModal.show();
	}

	function toggleLegend() {
		legendEl.classList.toggle( "d-none" );
	}

	function startTest() {
		if ( ! venuesLoaded ) {
			return;
		}
		hideAllModals();
		legendEl.classList.add( "d-none" );
		clearResultHighlights();

		sessionGen++;
		quizActive = true;
		setHoverText( "" );
		sessionNames = pickSessionNames();
		sessionIndex = 0;
		sessionResults = [];
		trackAttempt();

		remainingSecs = ATK_POI.minutes * 60;
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
		timerPillEl.classList.add( "d-none" );
		setStatusText( "Hover over a pin to view its name" );

		var foundCount = sessionResults.filter( function ( r ) { return r.found; } ).length;
		saveLastSession( {
			results: sessionResults,
			total: sessionNames.length,
			foundCount: foundCount,
			timedOut: !! timedOut,
			completedAt: Date.now()
		} );

		completeBodyEl.textContent = timedOut
			? "Time's up — you found " + foundCount + " of " + sessionNames.length + " places of interest."
			: "You found all " + foundCount + " of " + sessionNames.length + " places of interest!";
		completeModal.show();

		applyResultHighlights( sessionResults );
		renderChecklist();
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
		legendEl.classList.add( "d-none" );
		stopActiveSession();

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
			var badgeClass = r.found ? "badge-success" : "badge-danger";
			var badgeText  = r.found ? "Correct" : "Incorrect";
			var guessesText = r.guesses && r.guesses.length
				? "Your answer: " + r.guesses.join( " → " )
				: "No pin clicked (time ran out)";
			html += '<div class="atk-map-review-row">'
				+ '<div><div class="fw-bold">' + r.name + "</div>"
				+ '<div class="atk-map-review-guesses">' + guessesText + "</div></div>"
				+ '<span class="badge ' + badgeClass + '">' + badgeText + "</span>"
				+ "</div>";

			if ( markersByName[ r.name ] ) {
				reviewBounds.extend( markersByName[ r.name ].getLatLng() );
			}
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

		reviewModal.show();
	}

	splashBtn.addEventListener( "click", showSplash );
	legendBtn.addEventListener( "click", toggleLegend );
	studyBtn.addEventListener( "click", enterStudyMode );
	testBtn.addEventListener( "click", startTest );
	reviewBtn.addEventListener( "click", showReview );

	loadVenues();
	introModal.show();
})();
