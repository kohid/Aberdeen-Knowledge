"use strict";

/**
 * Instructor Dashboard: populates the shared add/edit modals from the
 * clicked row's data-* attributes before showing them, so we don't have to
 * render one modal per question (some tests/banks have dozens of rows).
 * Uses the same bootstrap.Modal.getOrCreateInstance() pattern already
 * established in major-streets-map.js.
 */
(function () {
	function show( modalId ) {
		var el = document.getElementById( modalId );
		if ( el && window.bootstrap ) {
			bootstrap.Modal.getOrCreateInstance( el ).show();
		}
	}

	function setValue( id, value ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.value = value || "";
		}
	}

	/**
	 * "Accepted Answers" and "Neighbourhoods" fields are <select multiple>
	 * elements (options rendered server-side from the known-values
	 * whitelist, see atk_render_tag_select() in inc/instructor.php),
	 * enhanced into Select2 (bundled in assets/vendor) for
	 * the chip/search UI. tags:true still allows entering a value that
	 * isn't in the list. dropdownParent is the standard fix for Select2's
	 * dropdown otherwise positioning itself wrong inside a Bootstrap modal.
	 */
	function initSelect( id ) {
		var $el = window.jQuery ? jQuery( "#" + id ) : null;
		if ( ! $el || ! $el.length || ! $el.select2 || $el.data( "select2" ) ) {
			return;
		}
		var $modal = $el.closest( ".modal" );
		$el.select2( {
			tags: true,
			width: "100%",
			dropdownParent: $modal.length ? $modal : jQuery( document.body )
		} );
	}

	// Select2 reads its selection from the underlying <select>'s <option>
	// elements, so a value with no matching <option> yet (e.g. editing a
	// row whose answer isn't in the site-wide whitelist) needs one added
	// before it can be selected.
	function setSelectValue( id, value ) {
		var $el = window.jQuery ? jQuery( "#" + id ) : null;
		if ( ! $el || ! $el.length ) {
			setValue( id, value );
			return;
		}
		var parts = String( value || "" ).split( "," ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
		parts.forEach( function ( v ) {
			if ( ! $el.find( "option[value='" + v.replace( /'/g, "\\'" ) + "']" ).length ) {
				$el.append( new Option( v, v, true, true ) );
			}
		} );
		$el.val( parts ).trigger( "change" );
	}

	// Same idea as setSelectValue(), but for the single-value Select2 fields
	// (Road A/B, Point of Interest, From/To — atk_render_single_select() in
	// inc/instructor.php): one value, not a comma-separated list.
	function setSingleSelectValue( id, value ) {
		var $el = window.jQuery ? jQuery( "#" + id ) : null;
		if ( ! $el || ! $el.length ) {
			setValue( id, value );
			return;
		}
		value = value || "";
		if ( value && ! $el.find( "option[value='" + value.replace( /'/g, "\\'" ) + "']" ).length ) {
			$el.append( new Option( value, value, true, true ) );
		}
		$el.val( value ).trigger( "change" );
	}

	/**
	 * Route options (atk-cq-route-option-N) are ordered multi-selects: the
	 * roads picked, IN PICK ORDER, form the route sequence — order matters
	 * here, unlike Accepted Answers/Neighbourhoods where it doesn't. A
	 * native <select multiple> always submits its <option> elements in DOM
	 * order regardless of pick order, so every selection re-appends that
	 * option to the end of the list (moving it, since it already exists),
	 * keeping DOM order in sync with pick order for both live editing and
	 * form submission.
	 */
	function initOrderedTagSelect( id ) {
		initSelect( id );
		var $el = window.jQuery ? jQuery( "#" + id ) : null;
		if ( ! $el || ! $el.length || $el.data( "atk-ordered" ) ) {
			return;
		}
		$el.data( "atk-ordered", true );
		$el.on( "select2:select", function ( e ) {
			var value = String( e.params.data.id );
			var opt = $el.find( "option[value='" + value.replace( /'/g, "\\'" ) + "']" );
			if ( opt.length ) {
				$el.append( opt );
			}
		} );
	}

	// Seeds an ordered multi-select with values in a given order (editing an
	// existing route option, or clearing one back to empty).
	function setOrderedSelectValue( id, values ) {
		var $el = window.jQuery ? jQuery( "#" + id ) : null;
		if ( ! $el || ! $el.length ) {
			return;
		}
		values.forEach( function ( v ) {
			var existing = $el.find( "option[value='" + v.replace( /'/g, "\\'" ) + "']" );
			var opt = existing.length ? existing : jQuery( new Option( v, v, true, true ) );
			$el.append( opt );
		} );
		$el.val( values ).trigger( "change" );
	}

	// The route-option data attributes are still rendered server-side as a
	// " -> "-joined string (data-options, inc/instructor.php) purely for
	// display/pre-population — split it back into an ordered road list.
	function splitRouteString( raw ) {
		return String( raw || "" ).split( /->|→/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
	}

	[
		"atk-new-week-neighbourhoods",
		"atk-ew-neighbourhoods",
		"atk-cq-accepted",
		"atk-cq-poi-accepted",
		"atk-street-accepted",
		"atk-poi-accepted",
		"atk-cq-road-a",
		"atk-cq-road-b",
		"atk-cq-poi-poi",
		"atk-cq-route-from",
		"atk-cq-route-to"
	].forEach( initSelect );

	[ 0, 1, 2, 3 ].forEach( function ( i ) {
		initOrderedTagSelect( "atk-cq-route-option-" + i );
	} );

	document.addEventListener( "click", function ( e ) {
		var addCourseQ = e.target.closest( ".atk-add-course-question" );
		if ( addCourseQ ) {
			var ct = addCourseQ.dataset.courseType;
			if ( "street" === ct ) {
				document.getElementById( "atk-cq-title" ).textContent = "Add Question";
				setValue( "atk-cq-id", "" );
				setValue( "atk-cq-week-id", addCourseQ.dataset.weekId );
				setValue( "atk-cq-test-number", addCourseQ.dataset.testNumber );
				setSingleSelectValue( "atk-cq-road-a", "" );
				setSingleSelectValue( "atk-cq-road-b", "" );
				setSelectValue( "atk-cq-accepted", "" );
			} else if ( "poi" === ct ) {
				document.getElementById( "atk-cq-poi-title" ).textContent = "Add Question";
				setValue( "atk-cq-poi-id", "" );
				setValue( "atk-cq-poi-week-id", addCourseQ.dataset.weekId );
				setValue( "atk-cq-poi-test-number", addCourseQ.dataset.testNumber );
				setSingleSelectValue( "atk-cq-poi-poi", "" );
				setSelectValue( "atk-cq-poi-accepted", "" );
			} else if ( "route" === ct ) {
				document.getElementById( "atk-cq-route-title" ).textContent = "Add Question";
				setValue( "atk-cq-route-id", "" );
				setValue( "atk-cq-route-week-id", addCourseQ.dataset.weekId );
				setValue( "atk-cq-route-test-number", addCourseQ.dataset.testNumber );
				setSingleSelectValue( "atk-cq-route-from", "" );
				setSingleSelectValue( "atk-cq-route-to", "" );
				for ( var ai = 0; ai < 4; ai++ ) {
					setOrderedSelectValue( "atk-cq-route-option-" + ai, [] );
					var aRadio = document.getElementById( "atk-cq-route-correct-" + ai );
					if ( aRadio ) { aRadio.checked = 0 === ai; }
				}
			}
			show( "atk-course-question-modal" );
			return;
		}

		var editCourseStreet = e.target.closest( ".atk-edit-course-street-question" );
		if ( editCourseStreet ) {
			document.getElementById( "atk-cq-title" ).textContent = "Edit Question";
			setValue( "atk-cq-id", editCourseStreet.dataset.id );
			setValue( "atk-cq-week-id", editCourseStreet.dataset.weekId );
			setValue( "atk-cq-test-number", editCourseStreet.dataset.testNumber );
			setSingleSelectValue( "atk-cq-road-a", editCourseStreet.dataset.roadA );
			setSingleSelectValue( "atk-cq-road-b", editCourseStreet.dataset.roadB );
			setSelectValue( "atk-cq-accepted", editCourseStreet.dataset.accepted );
			show( "atk-course-question-modal" );
			return;
		}

		var editCoursePoi = e.target.closest( ".atk-edit-course-poi-question" );
		if ( editCoursePoi ) {
			document.getElementById( "atk-cq-poi-title" ).textContent = "Edit Question";
			setValue( "atk-cq-poi-id", editCoursePoi.dataset.id );
			setValue( "atk-cq-poi-week-id", editCoursePoi.dataset.weekId );
			setValue( "atk-cq-poi-test-number", editCoursePoi.dataset.testNumber );
			setSingleSelectValue( "atk-cq-poi-poi", editCoursePoi.dataset.poi );
			setSelectValue( "atk-cq-poi-accepted", editCoursePoi.dataset.accepted );
			show( "atk-course-question-modal" );
			return;
		}

		var editCourseRoute = e.target.closest( ".atk-edit-course-route-question" );
		if ( editCourseRoute ) {
			document.getElementById( "atk-cq-route-title" ).textContent = "Edit Question";
			setValue( "atk-cq-route-id", editCourseRoute.dataset.id );
			setValue( "atk-cq-route-week-id", editCourseRoute.dataset.weekId );
			setValue( "atk-cq-route-test-number", editCourseRoute.dataset.testNumber );
			setSingleSelectValue( "atk-cq-route-from", editCourseRoute.dataset.from );
			setSingleSelectValue( "atk-cq-route-to", editCourseRoute.dataset.to );
			var cOptions = [];
			try {
				cOptions = JSON.parse( editCourseRoute.dataset.options || "[]" );
			} catch ( cErr ) {
				cOptions = [];
			}
			for ( var ci = 0; ci < 4; ci++ ) {
				setOrderedSelectValue( "atk-cq-route-option-" + ci, splitRouteString( cOptions[ ci ] ) );
				var cRadio = document.getElementById( "atk-cq-route-correct-" + ci );
				if ( cRadio ) {
					cRadio.checked = ci === parseInt( editCourseRoute.dataset.correct, 10 );
				}
			}
			show( "atk-course-question-modal" );
			return;
		}

		var editWeek = e.target.closest( ".atk-edit-week" );
		if ( editWeek ) {
			setValue( "atk-ew-id", editWeek.dataset.id );
			setValue( "atk-ew-week-number", editWeek.dataset.weekNumber );
			setSelectValue( "atk-ew-neighbourhoods", editWeek.dataset.neighbourhoods );
			show( "atk-edit-week-modal" );
			return;
		}

		var editStreet = e.target.closest( ".atk-edit-street-question" );
		if ( editStreet ) {
			setValue( "atk-street-id", editStreet.dataset.id );
			setValue( "atk-street-road-a", editStreet.dataset.roadA );
			setValue( "atk-street-road-b", editStreet.dataset.roadB );
			setSelectValue( "atk-street-accepted", editStreet.dataset.accepted );
			show( "atk-street-modal" );
			return;
		}

		var editPoi = e.target.closest( ".atk-edit-poi-question" );
		if ( editPoi ) {
			setValue( "atk-poi-id", editPoi.dataset.id );
			setValue( "atk-poi-poi", editPoi.dataset.poi );
			setSelectValue( "atk-poi-accepted", editPoi.dataset.accepted );
			show( "atk-poi-modal" );
			return;
		}

		var editRoute = e.target.closest( ".atk-edit-route-question" );
		if ( editRoute ) {
			setValue( "atk-route-id", editRoute.dataset.id );
			setValue( "atk-route-from", editRoute.dataset.from );
			setValue( "atk-route-to", editRoute.dataset.to );

			var options = [];
			try {
				options = JSON.parse( editRoute.dataset.options || "[]" );
			} catch ( err ) {
				options = [];
			}
			for ( var i = 0; i < 4; i++ ) {
				setValue( "atk-route-option-" + i, options[ i ] || "" );
				var radio = document.getElementById( "atk-route-correct-" + i );
				if ( radio ) {
					radio.checked = i === parseInt( editRoute.dataset.correct, 10 );
				}
			}
			show( "atk-route-modal" );
			return;
		}
	} );

	/**
	 * Clears one of the quiz-bank "Add" modals — called from the inline
	 * onclick on each "Add Question" button, since those already use
	 * data-bs-toggle to open (unlike the JS-driven edit/add-course flows
	 * above, which don't have a fixed target element to hang the attribute
	 * off — plain HTML content only, since the modal is shared).
	 */
	window.atkResetQuizModal = function ( type ) {
		if ( "street" === type ) {
			setValue( "atk-street-id", "" );
			setValue( "atk-street-road-a", "" );
			setValue( "atk-street-road-b", "" );
			setSelectValue( "atk-street-accepted", "" );
		} else if ( "poi" === type ) {
			setValue( "atk-poi-id", "" );
			setValue( "atk-poi-poi", "" );
			setSelectValue( "atk-poi-accepted", "" );
		} else if ( "route" === type ) {
			setValue( "atk-route-id", "" );
			setValue( "atk-route-from", "" );
			setValue( "atk-route-to", "" );
			for ( var i = 0; i < 4; i++ ) {
				setValue( "atk-route-option-" + i, "" );
				var radio = document.getElementById( "atk-route-correct-" + i );
				if ( radio ) {
					radio.checked = 0 === i;
				}
			}
		}
	};

	/* POI Question Bank "Edit as list": live line count + guard against silently dropping questions. */
	var poiBulkForm = document.getElementById( "atk-poi-bulk-form" );
	if ( poiBulkForm ) {
		var poiBulkText = document.getElementById( "atk-poi-bulk-text" );
		var poiBulkCount = document.getElementById( "atk-poi-bulk-count" );
		var countLines = function () {
			return poiBulkText.value.split( /\r\n|\r|\n/ ).filter( function ( l ) { return l.trim() !== ""; } ).length;
		};
		poiBulkText.addEventListener( "input", function () {
			var n = countLines();
			poiBulkCount.textContent = n + ( 1 === n ? " question" : " questions" );
		} );
		poiBulkForm.addEventListener( "submit", function ( e ) {
			var existing = parseInt( poiBulkForm.dataset.existing, 10 ) || 0;
			if ( countLines() < existing && ! window.confirm( "The list has fewer lines than the " + existing + " saved questions. Any place no longer listed will be deleted. Continue?" ) ) {
				e.preventDefault();
			}
		} );
	}
})();
