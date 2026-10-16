"use strict";

/**
 * Shared engine for the mockup knowledge tests (street / POI / route) and
 * study tools (e.g. Major Streets). Reads its config + question bank from
 * the inline `ATK_QUIZ` object.
 */
(function () {
	if ( typeof ATK_QUIZ === "undefined" ) {
		return;
	}

	var root = document.getElementById( "atk-quiz-root" );
	if ( ! root ) {
		return;
	}

	var introEl      = document.getElementById( "atk-quiz-intro" );
	var questionEl   = document.getElementById( "atk-quiz-question" );
	var resultsEl    = document.getElementById( "atk-quiz-results" );
	var progressEl   = document.getElementById( "atk-quiz-progress" );
	var progressBarEl = document.getElementById( "atk-quiz-progress-bar" );
	var timerEl      = document.getElementById( "atk-quiz-timer" );
	var promptEl     = document.getElementById( "atk-quiz-prompt" );
	var answerAreaEl = document.getElementById( "atk-quiz-answer-area" );
	var nextBtn      = document.getElementById( "atk-quiz-next" );
	var backBtn      = document.getElementById( "atk-quiz-back" );
	var startBtn     = document.getElementById( "atk-quiz-start" );
	var retryBtn     = document.getElementById( "atk-quiz-retry" );
	var scoreEl      = document.getElementById( "atk-quiz-score" );
	var reviewEl     = document.getElementById( "atk-quiz-review" );

	var mapEl        = document.getElementById( "atk-quiz-map" );
	var mapLoadingEl = document.getElementById( "atk-quiz-map-loading" );
	var feedbackEl   = document.getElementById( "atk-quiz-feedback" );

	var questions = ATK_QUIZ.questions || [];
	var mode      = ATK_QUIZ.mode;

	// sessionQuestions/sessionTotal are the questions actually in play for
	// the current attempt. For most tests that's just the full pool, but
	// study tools with a sessionSize draw a fresh random subset each time
	// start() runs (initial Start, or Retry/Study Again).
	var sessionQuestions = questions;
	var sessionTotal     = questions.length;

	var index, score, answers, remainingSeconds, timerHandle, endTime;
	var attemptCount = null;
	var awaitingAdvance = false; // instant-feedback mode: graded, waiting for the user to move on

	/**
	 * Persists an in-progress attempt (question order, position, answers so
	 * far, and the timer's real end time) to localStorage, so refreshing,
	 * closing the tab, or a crash doesn't lose it — resumed on next load by
	 * the loadState()/resume() pair near the bottom of this file. Scoped to
	 * the real timed tests only (not instantFeedback study tools, a
	 * different, lower-stakes practice flow with no "resume" concept).
	 * endTime is a real timestamp, not a countdown number, so time keeps
	 * passing while the tab is closed rather than pausing — reopening after
	 * the original time allowance has fully elapsed ends the test
	 * immediately, same as it would have if the tab had stayed open.
	 */
	function storageKey() {
		return "atk_quiz_state_" + ( ATK_QUIZ.testKey || "default" );
	}

	function saveState() {
		if ( ATK_QUIZ.instantFeedback ) {
			return;
		}
		try {
			localStorage.setItem( storageKey(), JSON.stringify( {
				sessionQuestions: sessionQuestions,
				index: index,
				answers: answers,
				endTime: endTime,
				attemptCount: attemptCount
			} ) );
		} catch ( e ) {}
	}

	function clearState() {
		try {
			localStorage.removeItem( storageKey() );
		} catch ( e ) {}
	}

	function loadState() {
		if ( ATK_QUIZ.instantFeedback ) {
			return null;
		}
		try {
			var raw = localStorage.getItem( storageKey() );
			if ( ! raw ) {
				return null;
			}
			var state = JSON.parse( raw );
			if ( ! state || ! Array.isArray( state.sessionQuestions ) || ! state.sessionQuestions.length || typeof state.endTime !== "number" ) {
				return null;
			}
			// A saved attempt from before the test changed shape (the Route test used to be multiple choice) cannot be continued.
			if ( mode === "pair" && ! state.sessionQuestions.every( function ( q ) { return q && Array.isArray( q.pairs ); } ) ) {
				return null;
			}
			return state;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Always shuffles into a fresh order (every test/attempt), then
	 * optionally subsets down to sessionSize for tools that draw a smaller
	 * sample from a larger pool (e.g. the Major Streets study flashcards).
	 * Previously this only shuffled when subsetting happened, so any test
	 * that uses its whole question set as-is (every Course test, and the
	 * mockup Street/POI/Route Test) showed the exact same fixed order
	 * every single time.
	 */
	function buildSession() {
		var pool = questions.slice();
		for ( var i = pool.length - 1; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var tmp = pool[ i ];
			pool[ i ] = pool[ j ];
			pool[ j ] = tmp;
		}
		if ( ATK_QUIZ.sessionSize && ATK_QUIZ.sessionSize < pool.length ) {
			return pool.slice( 0, ATK_QUIZ.sessionSize );
		}
		return pool;
	}

	function trackAttempt() {
		if ( ! ATK_QUIZ.ajaxUrl ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( "action", "atk_track_attempt" );
		body.set( "test", ATK_QUIZ.testKey );
		body.set( "nonce", ATK_QUIZ.attemptNonce );

		fetch( ATK_QUIZ.ajaxUrl, { method: "POST", body: body } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) {
				if ( data && data.success && data.data ) {
					attemptCount = data.data.count;
				}
			} )
			.catch( function () {} );
	}

	/**
	 * Course tests report their final score back to the server so the
	 * course hub can show pass/fail per test — the mockup tests don't do
	 * this (no persistent per-user progress for those, by design).
	 */
	function reportCourseCompletion() {
		if ( ! ATK_QUIZ.courseWeek || ! ATK_QUIZ.ajaxUrl ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( "action", "atk_course_complete" );
		body.set( "week", ATK_QUIZ.courseWeek );
		body.set( "test", ATK_QUIZ.courseTest );
		body.set( "course_type", ATK_QUIZ.courseType || "street" );
		body.set( "score", score );
		body.set( "total", scoreTotal() );
		body.set( "nonce", ATK_QUIZ.courseNonce );

		fetch( ATK_QUIZ.ajaxUrl, { method: "POST", body: body } ).catch( function () {} );
	}

	/**
	 * Logged-in users get their full question-by-question review saved so
	 * they can revisit past attempts from the "Previous Attempts" button on
	 * the test hub (mockup and Course both use this — instantFeedback study
	 * tools opt out since they already show each answer live and don't
	 * build a review list at all, see finish() below).
	 */
	function reportAttemptReview( durationSeconds ) {
		if ( ! ATK_QUIZ.isLoggedIn || ! ATK_QUIZ.ajaxUrl || ATK_QUIZ.instantFeedback ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( "action", "atk_record_review" );
		body.set( "test", ATK_QUIZ.testKey );
		body.set( "score", score );
		body.set( "total", scoreTotal() );
		body.set( "answers", JSON.stringify( answers ) );
		body.set( "duration", durationSeconds );
		body.set( "nonce", ATK_QUIZ.reviewNonce );

		fetch( ATK_QUIZ.ajaxUrl, { method: "POST", body: body } ).catch( function () {} );
	}

	var ABERDEEN_CENTER = [ 57.1497, -2.0943 ];
	var map = null;
	var mapMarkers = [];
	var mapRequestId = 0;

	// Nominatim's usage policy asks for max 1 request/second and no bulk
	// use; this queue spaces requests out and caches results per session
	// so retrying a test doesn't re-geocode the same places.
	var GEOCODE_MIN_INTERVAL_MS = 1100;
	var geocodeCache = {};
	var geocodeQueue = [];
	var geocodeBusy  = false;

	// Cached by the promise itself (not just the resolved value), so a
	// prefetch already in flight is shared rather than queued twice when
	// the same location is requested again moments later.
	function geocode( query ) {
		if ( Object.prototype.hasOwnProperty.call( geocodeCache, query ) ) {
			return geocodeCache[ query ];
		}
		var promise = new Promise( function ( resolve ) {
			geocodeQueue.push( { query: query, resolve: resolve } );
			processGeocodeQueue();
		} );
		geocodeCache[ query ] = promise;
		return promise;
	}

	function processGeocodeQueue() {
		if ( geocodeBusy || ! geocodeQueue.length ) {
			return;
		}
		geocodeBusy = true;
		var job = geocodeQueue.shift();
		var url = "https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=gb&q=" + encodeURIComponent( job.query );

		fetch( url )
			.then( function ( response ) { return response.ok ? response.json() : []; } )
			.then( function ( results ) {
				var latlng = ( results && results[ 0 ] ) ? [ parseFloat( results[ 0 ].lat ), parseFloat( results[ 0 ].lon ) ] : null;
				job.resolve( latlng );
			} )
			.catch( function () {
				job.resolve( null );
			} )
			.then( function () {
				setTimeout( function () {
					geocodeBusy = false;
					processGeocodeQueue();
				}, GEOCODE_MIN_INTERVAL_MS );
			} );
	}

	function renderMapForQuestion( q ) {
		if ( ! mapEl || ! q.locations || ! q.locations.length ) {
			if ( mapEl ) {
				mapEl.classList.add( "d-none" );
			}
			return;
		}

		mapEl.classList.remove( "d-none" );
		if ( mapLoadingEl ) {
			mapLoadingEl.classList.remove( "d-none" );
		}

		if ( ! map ) {
			map = L.map( mapEl ).setView( ABERDEEN_CENTER, 13 );
			L.tileLayer( "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
				maxZoom: 19,
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
			} ).addTo( map );
		} else {
			setTimeout( function () { map.invalidateSize(); }, 0 );
		}

		mapMarkers.forEach( function ( marker ) {
			map.removeLayer( marker );
		} );
		mapMarkers = [];

		var requestId = ++mapRequestId;
		var points    = [];
		var remaining = q.locations.length;

		q.locations.forEach( function ( loc ) {
			geocode( loc.query + ", Aberdeen, UK" ).then( function ( latlng ) {
				if ( requestId !== mapRequestId ) {
					return; // a later question has since taken over the map
				}
				remaining--;

				if ( latlng ) {
					var marker = L.marker( latlng ).addTo( map ).bindPopup( loc.query );
					if ( loc.label ) {
						marker.bindTooltip( loc.label, { permanent: true, direction: "top" } );
					}
					mapMarkers.push( marker );
					points.push( latlng );
				}

				if ( 0 === remaining ) {
					if ( mapLoadingEl ) {
						mapLoadingEl.classList.add( "d-none" );
					}
					if ( points.length ) {
						map.fitBounds( L.latLngBounds( points ), { padding: [ 40, 40 ], maxZoom: 16 } );
					}
				}
			} );
		} );
	}

	/**
	 * Warm the geocode cache for a question in the background, without
	 * touching the map. Called for the next question while the user is
	 * still answering the current one, so its map is ready instantly
	 * by the time they click Next.
	 */
	function prefetchQuestion( q ) {
		if ( ! q || ! q.locations ) {
			return;
		}
		q.locations.forEach( function ( loc ) {
			geocode( loc.query + ", Aberdeen, UK" );
		} );
	}

	function normalize( value ) {
		return String( value || "" )
			.toLowerCase()
			.replace( /[^a-z0-9\s]/g, "" )
			.replace( /\b(street|st)\b/g, "st" )
			.replace( /\b(road|rd)\b/g, "rd" )
			.replace( /\b(avenue|ave)\b/g, "ave" )
			.replace( /\b(drive|dr)\b/g, "dr" )
			.replace( /\b(boulevard|blvd)\b/g, "blvd" )
			.replace( /\s+/g, " " )
			.trim();
	}

	function escapeHtml( value ) {
		var div = document.createElement( "div" );
		div.textContent = value;
		return div.innerHTML;
	}

	function formatTime( totalSeconds ) {
		var m = Math.floor( totalSeconds / 60 );
		var s = totalSeconds % 60;
		return m + ":" + ( s < 10 ? "0" : "" ) + s;
	}

	/**
	 * Remaining time is derived from endTime (an absolute timestamp), not a
	 * countdown number, so a resumed attempt (see resume() below) reflects
	 * real elapsed time rather than "pausing" while the tab was closed.
	 * start() always nulls endTime first, so a fresh attempt gets a new
	 * one here; resume() sets it from the saved state before calling this,
	 * so that value is kept as-is.
	 */
	function startTimer() {
		if ( ! endTime ) {
			endTime = Date.now() + ATK_QUIZ.minutes * 60 * 1000;
		}

		var tick = function () {
			remainingSeconds = Math.max( 0, Math.round( ( endTime - Date.now() ) / 1000 ) );
			timerEl.textContent = formatTime( remainingSeconds );
			if ( remainingSeconds <= 0 ) {
				clearInterval( timerHandle );
				// In instantFeedback mode, awaitingAdvance means the current
				// question was already graded when the answer was checked —
				// grading it again here would double-count it.
				if ( ! awaitingAdvance ) {
					gradeCurrent();
				}
				finish();
			}
		};

		clearInterval( timerHandle );
		tick();
		timerHandle = setInterval( tick, 1000 );
	}

	/**
	 * Text-mode answer field: a Select2-enhanced <select> offering real
	 * Aberdeen street names (ATK_QUIZ.roadNames) as suggestions while
	 * typing, so a driver who knows the right street isn't marked wrong
	 * for a stray typo. tags:true still allows entering any free text.
	 * Falls back to a plain input if jQuery/Select2 isn't available for
	 * any reason — answering the test must never hard-depend on it.
	 *
	 * $previousValue re-fills a question revisited via the Back button —
	 * picking a suggestion or pressing Enter no longer auto-advances (the
	 * user asked for that back, since it made re-checking an answer via
	 * Back pointless if leaving it just jumped forward again), so the
	 * user always advances via the Next/Back buttons explicitly.
	 */
	function renderTextAnswerInput( previousValue ) {
		answerAreaEl.innerHTML = roadFieldHtml( "atk-quiz-input", "Type the street name" );
		initRoadField( "atk-quiz-input", "Type the street name", previousValue, true );
	}

	/** Markup for one street-name field: a <select> when Select2 is there, otherwise a plain text box. */
	function roadFieldHtml( id, placeholder ) {
		var $ = window.jQuery;
		if ( $ && $.fn && $.fn.select2 ) {
			return '<select class="form-select form-select-lg" id="' + id + '"></select>';
		}
		return '<input type="text" class="form-control form-control-lg" id="' + id + '" autocomplete="off" placeholder="' + placeholder + '&hellip;" />';
	}

	/**
	 * Turns the field made by roadFieldHtml() into a Select2 offering the real street names,
	 * and puts a previous answer back (a question revisited with Back). $focusPlain focuses
	 * the plain fallback box when there is nothing to put back.
	 */
	function initRoadField( id, placeholder, previousValue, focusPlain ) {
		var $ = window.jQuery;
		var input = document.getElementById( id );
		if ( $ && $.fn && $.fn.select2 && input && input.tagName === "SELECT" ) {
			var $input = $( input );
			var roadNames = ATK_QUIZ.roadNames || [];
			$input.select2( {
				tags: true,
				width: "100%",
				placeholder: placeholder + "…",
				data: roadNames.map( function ( name ) { return { id: name, text: name }; } )
			} );
			if ( previousValue && roadNames.indexOf( previousValue ) === -1 ) {
				$input.append( new Option( previousValue, previousValue, true, true ) );
			}
			// Suggestions only appear once the user actually interacts with
			// the field — not opened automatically on question load, which
			// showed the full list of every real Aberdeen street before
			// anyone had even tried to recall the answer themselves.
			$input.val( previousValue || null ).trigger( "change" );
			return;
		}
		if ( ! input ) {
			return;
		}
		if ( previousValue ) {
			input.value = previousValue;
		} else if ( focusPlain ) {
			input.focus();
		}
	}

	/**
	 * Route test answer: where the street runs From and To. There is one street-name field for
	 * every distinct road listed at each end, and each right road is a point. A street with more
	 * than two answers in all gets a notice saying so. $previous is the { from: [], to: [] } of a
	 * question revisited with Back.
	 */
	function routeColumnHtml( side, label, count ) {
		var html = '<div class="col-md-6" role="group" aria-labelledby="atk-quiz-' + side + '-label">' +
			'<div class="form-label fw-bold" id="atk-quiz-' + side + '-label">' + label +
			( count > 1 ? ' <span class="text-gray-600 fw-normal fs-7">(' + count + " answers)</span>" : "" ) + "</div>";
		for ( var i = 0; i < count; i++ ) {
			html += '<div class="mb-3">' + roadFieldHtml( "atk-quiz-" + side + "-" + i, "Type the street" ) + "</div>";
		}
		return html + "</div>";
	}

	function renderPairInputs( q, previous ) {
		var ends = window.ATK_ROUTE_PAIRS.ends( q.pairs );
		var given = previous && typeof previous === "object" ? previous : {};
		var total = ends.from.length + ends.to.length;
		var html = "";
		if ( total > 2 ) {
			html += '<div class="alert alert-info mb-6" role="status" id="atk-quiz-route-notice">This street has <strong>' + total + " answers</strong> in all. " +
				"Each right answer scores a point. Leave a box empty if you don&rsquo;t know that one.</div>";
		}
		html += '<div class="row g-5">' + routeColumnHtml( "from", "From", ends.from.length ) + routeColumnHtml( "to", "To", ends.to.length ) + "</div>" +
			'<div class="form-text mt-2">You can give the two ends either way round. If the street ends without joining another road, choose <strong>Dead end</strong> as that answer.</div>';
		answerAreaEl.innerHTML = html;
		[ [ "from", ends.from.length, "From" ], [ "to", ends.to.length, "To" ] ].forEach( function ( side ) {
			var saved = [].concat( given[ side[ 0 ] ] || [] );
			for ( var i = 0; i < side[ 1 ]; i++ ) {
				initRoadField( "atk-quiz-" + side[ 0 ] + "-" + i, "Type the street", saved[ i ], side[ 0 ] === "from" && i === 0 );
				var field = document.getElementById( "atk-quiz-" + side[ 0 ] + "-" + i );
				if ( field ) {
					field.setAttribute( "aria-label", side[ 2 ] + ( side[ 1 ] > 1 ? ", answer " + ( i + 1 ) + " of " + side[ 1 ] : "" ) );
				}
			}
		} );
	}

	/** What the student typed in the Route test's fields, From and To. */
	function readPairInputs( q ) {
		var ends = window.ATK_ROUTE_PAIRS.ends( q.pairs );
		var given = { from: [], to: [] };
		[ [ "from", ends.from.length ], [ "to", ends.to.length ] ].forEach( function ( side ) {
			for ( var i = 0; i < side[ 1 ]; i++ ) {
				var field = document.getElementById( "atk-quiz-" + side[ 0 ] + "-" + i );
				given[ side[ 0 ] ].push( field ? field.value : "" );
			}
		} );
		return given;
	}

	/** The Route test's total: a point for every distinct answer, not one per street. */
	function scoreTotal() {
		if ( mode !== "pair" ) {
			return sessionTotal;
		}
		return sessionQuestions.reduce( function ( sum, q ) {
			var e = window.ATK_ROUTE_PAIRS.ends( q.pairs );
			return sum + e.from.length + e.to.length;
		}, 0 );
	}

	function renderQuestion() {
		var q = sessionQuestions[ index ];
		var previous = answers[ index ]; // set if revisiting a question already answered via Back
		progressEl.textContent = "Question " + ( index + 1 ) + " of " + sessionTotal;
		progressBarEl.style.width = ( ( index / sessionTotal ) * 100 ) + "%";
		promptEl.innerHTML = q.prompt;

		awaitingAdvance = false;
		if ( feedbackEl ) {
			feedbackEl.classList.add( "d-none" );
			feedbackEl.textContent = "";
		}
		nextBtn.textContent = ATK_QUIZ.instantFeedback ? "Check Answer" : "Next";
		if ( backBtn ) {
			backBtn.classList.toggle( "d-none", ATK_QUIZ.instantFeedback || 0 === index );
		}

		renderMapForQuestion( q );
		prefetchQuestion( sessionQuestions[ index + 1 ] );

		if ( mode === "text" ) {
			renderTextAnswerInput( previous ? previous.rawGiven : "" );
		} else if ( mode === "pair" ) {
			renderPairInputs( q, previous ? previous.rawGiven : null );
		} else {
			var html = "";
			q.options.forEach( function ( option, i ) {
				var checkedAttr = ( previous && previous.rawGivenIndex === i ) ? " checked" : "";
				html += '<div class="form-check form-check-custom form-check-solid mb-4">' +
					'<input class="form-check-input" type="radio" name="atk-quiz-choice" id="atk-quiz-choice-' + i + '" value="' + i + '"' + checkedAttr + ' />' +
					'<label class="form-check-label fs-5" for="atk-quiz-choice-' + i + '">' + option + "</label>" +
					"</div>";
			} );
			answerAreaEl.innerHTML = html;
		}
	}

	/**
	 * Reads the currently-displayed answer and grades it against the
	 * question at $index (defaults to the current question) — used both to
	 * record the current question when leaving it (Next/Back/time-up) and,
	 * for the regular (non-instantFeedback) flow, to re-grade after the
	 * user has gone Back and changed an earlier answer.
	 */
	function gradeQuestion( q ) {
		if ( mode === "text" ) {
			var input = document.getElementById( "atk-quiz-input" );
			var given = input ? input.value : "";
			var normalizedGiven = normalize( given );
			var correct = q.accepted.some( function ( accepted ) {
				return normalize( accepted ) === normalizedGiven;
			} ) && normalizedGiven !== "";

			return {
				prompt: q.prompt,
				given: given ? escapeHtml( given ) : "(no answer)",
				correctText: q.accepted.join( " / " ),
				correct: correct,
				rawGiven: given
			};
		}

		if ( mode === "pair" ) {
			var given  = readPairInputs( q );
			var result = window.ATK_ROUTE_PAIRS.grade( q.pairs, given.from, given.to );
			var typed  = function ( list ) {
				var filled = list.filter( function ( v ) { return String( v ).trim() !== ""; } );
				return filled.length ? escapeHtml( filled.join( ", " ) ) : "(no answer)";
			};
			return {
				prompt: q.prompt,
				given: "From: " + typed( given.from ) + " &mdash; To: " + typed( given.to ),
				correctText: escapeHtml( window.ATK_ROUTE_PAIRS.answerSheet( q.pairs ) ),
				correct: result.points === result.max,
				points: result.points,
				max: result.max,
				rawGiven: given
			};
		}

		var checked = answerAreaEl.querySelector( 'input[name="atk-quiz-choice"]:checked' );
		var givenIndex = checked ? parseInt( checked.value, 10 ) : -1;

		return {
			prompt: q.prompt,
			given: checked ? q.options[ givenIndex ] : "(no answer)",
			correctText: q.options[ q.correct ],
			correct: givenIndex === q.correct,
			rawGivenIndex: givenIndex
		};
	}

	/**
	 * Regular (non-instantFeedback) flow: answers are stored by question
	 * index rather than appended, so revisiting a question via Back and
	 * changing its answer overwrites that slot instead of duplicating it.
	 * Score is only ever computed fresh from this array (computeScore()),
	 * never accumulated incrementally, since an edited answer can flip
	 * from correct to incorrect or back.
	 */
	function gradeCurrent() {
		var result = gradeQuestion( sessionQuestions[ index ] );

		if ( ATK_QUIZ.instantFeedback ) {
			answers.push( result );
			if ( result.correct ) {
				score++;
			}
		} else {
			answers[ index ] = result;
		}
	}

	function computeScore() {
		if ( mode === "pair" ) {
			return answers.reduce( function ( sum, a ) { return sum + ( a ? a.points || 0 : 0 ); }, 0 );
		}
		return answers.filter( function ( a ) { return a && a.correct; } ).length;
	}

	/**
	 * Reveal the just-graded answer in place and lock the inputs, for
	 * instantFeedback mode.
	 */
	function showInstantFeedback() {
		if ( ! feedbackEl ) {
			return;
		}
		var last = answers[ answers.length - 1 ];
		feedbackEl.className = "alert d-flex align-items-center p-4 mb-8 " + ( last.correct ? "alert-success" : "alert-danger" );
		feedbackEl.textContent = last.correct ? "Correct!" : "Not quite — correct answer: " + last.correctText;
		feedbackEl.classList.remove( "d-none" );

		var input = document.getElementById( "atk-quiz-input" );
		if ( input ) {
			input.disabled = true;
		}
		answerAreaEl.querySelectorAll( 'input[name="atk-quiz-choice"]' ).forEach( function ( radio ) {
			radio.disabled = true;
		} );
	}

	function finish() {
		clearInterval( timerHandle );
		clearState(); // this attempt is done — a later Start/Retry begins a genuinely fresh one
		score = computeScore(); // fresh, not accumulated — a Back-edited answer can flip correctness
		questionEl.classList.add( "d-none" );
		resultsEl.classList.remove( "d-none" );
		scoreEl.textContent = score;
		var outOfEl = document.getElementById( "atk-quiz-out-of" );
		if ( outOfEl && mode === "pair" ) {
			outOfEl.textContent = "out of " + scoreTotal() + " points";
		}
		// remainingSeconds was last set by the tick that either ran the clock
		// out (0, i.e. the full allowance elapsed) or by whichever tick most
		// recently ran before the user submitted early — accurate to within
		// the 1s tick interval either way.
		var elapsedSeconds = Math.max( 0, ATK_QUIZ.minutes * 60 - ( remainingSeconds || 0 ) );
		reportCourseCompletion();
		reportAttemptReview( elapsedSeconds );

		var passFailWrapEl = document.getElementById( "atk-quiz-pass-fail-wrap" );
		if ( ATK_QUIZ.instantFeedback ) {
			if ( passFailWrapEl ) {
				passFailWrapEl.classList.add( "d-none" );
			}
		} else {
			var passFailEl = document.getElementById( "atk-quiz-pass-fail" );
			var passed = ( score / scoreTotal() ) >= ATK_QUIZ.passThreshold;
			passFailEl.textContent = passed ? "Passed" : "Fail";
			passFailEl.className = "badge fs-5 px-4 py-2 " + ( passed ? "badge-success" : "badge-danger" );
		}

		var attemptEl = document.getElementById( "atk-quiz-attempt-count" );
		if ( attemptEl ) {
			attemptEl.textContent = attemptCount
				? "This test has been attempted " + attemptCount + " time" + ( 1 === attemptCount ? "" : "s" ) + " in total (across all visitors)."
				: "";
		}

		// Instant-feedback (study) mode already showed each answer as it
		// happened, so skip the redundant full review list here.
		if ( ATK_QUIZ.instantFeedback ) {
			reviewEl.innerHTML = "";
			return;
		}

		var html = "";
		answers.forEach( function ( a, i ) {
			var cardClass  = a.correct ? "bg-light-success" : "bg-light-danger";
			var badgeClass = a.correct ? "badge-success" : "badge-danger";
			var badgeText  = a.correct ? "Correct" : "Incorrect";
			if ( mode === "pair" ) {
				// A point for every right answer, so a street can be part right.
				var partial = ! a.correct && a.points > 0;
				cardClass  = a.correct ? "bg-light-success" : ( partial ? "bg-light-warning" : "bg-light-danger" );
				badgeClass = a.correct ? "badge-success" : ( partial ? "badge-warning" : "badge-danger" );
				badgeText  = a.points + " / " + a.max;
			}
			html += '<div class="rounded ' + cardClass + ' p-5 mb-4">' +
				'<div class="d-flex align-items-center justify-content-between mb-2">' +
				'<div class="fw-bold text-gray-700">Question ' + ( i + 1 ) + "</div>" +
				'<span class="badge ' + badgeClass + '">' + badgeText + "</span>" +
				"</div>" +
				'<div class="fs-6 text-gray-900 mb-2">' + a.prompt + "</div>" +
				'<div class="fs-7 text-gray-600' + ( a.correct ? "" : " mb-3" ) + '">Your answer: ' + a.given + "</div>" +
				( a.correct ? "" : '<span class="atk-answer-pill bg-success fs-7">' + ( mode === "pair" ? "Every answer: " : "Correct answer: " ) + a.correctText + "</span>" ) +
				"</div>";
		} );
		reviewEl.innerHTML = html;
	}

	function start() {
		sessionQuestions = buildSession();
		sessionTotal     = sessionQuestions.length;

		index = 0;
		score = 0;
		answers = [];
		attemptCount = null;
		endTime = null; // startTimer() sets a fresh one since none is carried over
		trackAttempt();

		introEl.classList.add( "d-none" );
		resultsEl.classList.add( "d-none" );
		questionEl.classList.remove( "d-none" );

		startTimer();
		renderQuestion();
		saveState();
	}

	/**
	 * Drops the user straight back into an in-progress attempt found by
	 * loadState() — skips the intro screen and buildSession()'s reshuffle
	 * entirely, and doesn't call trackAttempt() again (this isn't a new
	 * attempt, just continuing the same one). If the saved endTime has
	 * already passed — the full time allowance elapsed while the tab was
	 * closed — the attempt ends immediately, exactly as it would have if
	 * the tab had stayed open and the timer had simply run out.
	 */
	function resume( state ) {
		sessionQuestions = state.sessionQuestions;
		sessionTotal     = sessionQuestions.length;
		index            = Math.min( Math.max( 0, state.index || 0 ), sessionTotal - 1 );
		answers          = Array.isArray( state.answers ) ? state.answers : [];
		attemptCount     = state.attemptCount || null;
		endTime          = state.endTime;

		introEl.classList.add( "d-none" );
		resultsEl.classList.add( "d-none" );
		questionEl.classList.remove( "d-none" );

		if ( endTime <= Date.now() ) {
			gradeCurrent();
			finish();
			return;
		}

		startTimer();
		renderQuestion();
	}

	/**
	 * Study tools with instantFeedback need two clicks per question: the
	 * first grades the answer and reveals it in place, the second (button
	 * now reading "Next Question"/"Finish") actually advances. Regular
	 * tests keep the original one-click grade-and-advance behaviour.
	 */
	nextBtn.addEventListener( "click", function () {
		if ( ! ATK_QUIZ.instantFeedback ) {
			gradeCurrent();
			index++;
			if ( index >= sessionTotal ) {
				finish();
			} else {
				renderQuestion();
				saveState();
			}
			return;
		}

		if ( ! awaitingAdvance ) {
			gradeCurrent();
			showInstantFeedback();
			awaitingAdvance = true;
			nextBtn.textContent = ( index + 1 >= sessionTotal ) ? "Finish" : "Next Question";
		} else {
			index++;
			if ( index >= sessionTotal ) {
				finish();
			} else {
				renderQuestion();
			}
		}
	} );

	if ( backBtn ) {
		backBtn.addEventListener( "click", function () {
			if ( ATK_QUIZ.instantFeedback || index <= 0 ) {
				return;
			}
			gradeCurrent(); // save any edit to the current question before leaving it
			index--;
			renderQuestion();
			saveState();
		} );
	}

	if ( startBtn ) {
		startBtn.addEventListener( "click", start );
	}
	retryBtn.addEventListener( "click", function () {
		resultsEl.classList.add( "d-none" );
		start();
	} );

	// Get a head start on the first couple of questions' map data while
	// the user is still reading the intro screen.
	prefetchQuestion( questions[ 0 ] );
	prefetchQuestion( questions[ 1 ] );

	// An in-progress attempt (refresh, closed tab, browser crash) picks up
	// right where it left off instead of showing the intro screen again.
	var savedState = loadState();
	if ( savedState ) {
		resume( savedState );
	}
})();
