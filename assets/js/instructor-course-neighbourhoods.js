/**
 * Course Builder helpers for the Neighbourhoods tab (inc/neighbourhoods.php).
 *
 * 1. Under every Neighbourhoods field, a live line such as "41 streets, 2 in
 *    more than one chosen neighbourhood and counted once". A street that sits
 *    in two of a week's neighbourhoods is counted once.
 * 2. In the Street question form, a switch that narrows Road A and Road B to
 *    the streets of the week's neighbourhoods. The fields stay Select2 with
 *    tags:true, so a street outside the list can still be typed.
 *
 * 3. In the POI question form, a place picker fed by the saved Places of
 *    Interest: choosing one fills Accepted Answers with its street, and a switch
 *    narrows the list to the places in the week's wards.
 *
 * Reads ATK_NB_COURSE: { restUrl, nonce, weekStreets: { weekId: [names] },
 * weekPlaces: { weekId: [{name, street}] }, places: [{name, street}] }.
 */
( function () {
	"use strict";

	var $ = window.jQuery;
	if ( ! $ || ! window.ATK_NB_COURSE ) {
		return;
	}
	var cfg = window.ATK_NB_COURSE;

	/* ------------------------------------------------------------------ */
	/* Live street count under each Neighbourhoods field                   */
	/* ------------------------------------------------------------------ */

	function watchSummary( $line ) {
		var $select = $( "#" + $line.data( "atk-nb-summary" ) );
		if ( ! $select.length ) {
			return;
		}
		var timer = null;
		var latest = 0;

		function refresh() {
			var names = $select.val() || [];
			var ticket = ++latest;
			if ( ! names.length ) {
				$line.text( "" );
				return;
			}
			window.fetch( cfg.restUrl + "neighbourhoods/summary", {
				method: "POST",
				credentials: "same-origin",
				headers: { "Content-Type": "application/json", "X-WP-Nonce": cfg.nonce },
				body: JSON.stringify( { names: names } )
			} )
				.then( function ( res ) { return res.ok ? res.json() : null; } )
				.then( function ( data ) {
					if ( ticket !== latest ) {
						return; // A newer answer is on its way.
					}
					$line.text( data && data.streets ? data.text : "" );
				} )
				.catch( function () {
					if ( ticket === latest ) {
						$line.text( "" );
					}
				} );
		}

		$select.on( "change", function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 150 );
		} );
		refresh();
	}

	$( "[data-atk-nb-summary]" ).each( function () {
		watchSummary( $( this ) );
	} );

	/* ------------------------------------------------------------------ */
	/* POI question form: pick a saved place                                */
	/* ------------------------------------------------------------------ */

	var $placeWrap = $( "#atk-cq-place-wrap" );
	if ( $placeWrap.length ) {
		( function () {
			var $poi = $( "#atk-cq-poi-poi" );
			var $accepted = $( "#atk-cq-poi-accepted" );
			var $placeOnly = $( "#atk-cq-place-only" );
			var $placeCount = $( "#atk-cq-place-count" );
			var originalPoi = $poi.html();
			var streets = {};
			var weekPlaces = [];

			( cfg.places || [] ).forEach( function ( place ) {
				streets[ place.name ] = place.street || "";
			} );

			function applyPlaces() {
				var current = $poi.val() || "";
				if ( weekPlaces.length && $placeOnly.is( ":checked" ) ) {
					var seen = {};
					$poi.empty().append( new Option( "", "", false, false ) );
					weekPlaces.forEach( function ( place ) {
						if ( ! seen[ place.name ] ) {
							seen[ place.name ] = true;
							$poi.append( new Option( place.name, place.name, false, false ) );
						}
					} );
					if ( current && ! seen[ current ] ) {
						$poi.append( new Option( current, current, false, false ) );
					}
				} else {
					$poi.html( originalPoi );
					if ( current && ! $poi.find( "option" ).filter( function () { return this.value === current; } ).length ) {
						$poi.append( new Option( current, current, false, false ) );
					}
				}
				$poi.val( current ).trigger( "change.select2" );
			}

			function openForPlaces( weekId ) {
				weekPlaces = ( cfg.weekPlaces && cfg.weekPlaces[ weekId ] ) || [];
				if ( weekPlaces.length ) {
					$placeCount.text( "(" + weekPlaces.length + ")" );
					$placeWrap.removeClass( "d-none" );
					$placeOnly.prop( "checked", true );
				} else {
					$placeWrap.addClass( "d-none" );
					$placeOnly.prop( "checked", false );
				}
				applyPlaces();
			}

			// Only a person's own choice fills Accepted Answers: select2:select does not fire when the
			// dashboard script sets the values to edit an existing question.
			$poi.on( "select2:select", function ( event ) {
				var street = streets[ event.params.data.id ];
				if ( street ) {
					$accepted.empty().append( new Option( street, street, true, true ) ).trigger( "change" );
				}
			} );
			$placeOnly.on( "change", applyPlaces );
			$( document ).on( "click", ".atk-add-course-question, .atk-edit-course-poi-question", function () {
				var weekId = String( $( this ).data( "week-id" ) );
				window.setTimeout( function () { openForPlaces( weekId ); }, 0 );
			} );
		}() );
	}

	/* ------------------------------------------------------------------ */
	/* Narrow Road A / Road B to the week's streets                        */
	/* ------------------------------------------------------------------ */

	var $wrap = $( "#atk-cq-nb-wrap" );
	if ( ! $wrap.length ) {
		return;
	}
	var $only = $( "#atk-cq-nb-only" );
	var $count = $( "#atk-cq-nb-count" );
	var fields = [ "atk-cq-road-a", "atk-cq-road-b" ];
	var original = {};
	var weekStreets = [];

	fields.forEach( function ( id ) {
		var $el = $( "#" + id );
		original[ id ] = $el.length ? $el.html() : "";
	} );

	function setOptions( id, names ) {
		var $el = $( "#" + id );
		if ( ! $el.length ) {
			return;
		}
		var current = $el.val() || "";
		if ( ! names ) {
			$el.html( original[ id ] );
		} else {
			$el.empty().append( new Option( "", "", false, false ) );
			names.forEach( function ( name ) {
				$el.append( new Option( name, name, false, false ) );
			} );
			if ( current && names.indexOf( current ) === -1 ) {
				$el.append( new Option( current, current, false, false ) );
			}
		}
		$el.val( current ).trigger( "change.select2" );
	}

	function apply() {
		var narrow = weekStreets.length && $only.is( ":checked" );
		fields.forEach( function ( id ) {
			setOptions( id, narrow ? weekStreets : null );
		} );
	}

	function openFor( weekId ) {
		weekStreets = ( cfg.weekStreets && cfg.weekStreets[ weekId ] ) || [];
		if ( weekStreets.length ) {
			$count.text( "(" + weekStreets.length + ")" );
			$wrap.removeClass( "d-none" );
			$only.prop( "checked", true );
		} else {
			$wrap.addClass( "d-none" );
			$only.prop( "checked", false );
		}
		apply();
	}

	// The dashboard script fills the form on the same click, so wait a tick:
	// the narrowing keeps whatever Road A and Road B it just set.
	$( document ).on( "click", ".atk-add-course-question, .atk-edit-course-street-question", function () {
		var weekId = String( $( this ).data( "week-id" ) );
		window.setTimeout( function () { openFor( weekId ); }, 0 );
	} );

	$only.on( "change", apply );
} )();
