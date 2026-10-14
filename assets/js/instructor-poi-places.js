/**
 * Places of Interest screen for the Instructor Dashboard (inc/poi-places.php).
 *
 * Plain JavaScript on top of Bootstrap (modals) and its component classes.
 * Reads ATK_POI_ADMIN: { restUrl, nonce, categories }.
 *
 * Other scripts for this screen (spreadsheet import, pin lookup and the hand
 * placement map) hook in through window.ATK_POI_ADMIN_UI:
 *   reload()            fetches the list again and redraws it
 *   openPinPicker(p)    opens the hand placement map for place p
 *   escapeHtml(s)       the one place text is made safe for markup
 */
( function () {
	"use strict";

	var cfg = window.ATK_POI_ADMIN || {};
	var root = document.getElementById( "atk-poi-admin-root" );
	if ( ! root || ! cfg.restUrl ) {
		return;
	}

	var state = {
		data: null,
		search: "",
		ward: "",
		flat: false,
		editing: null,
		deleting: null
	};

	/* ---------- helpers ---------- */

	function esc( value ) {
		return String( value == null ? "" : value ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	function $( selector ) {
		return document.querySelector( selector );
	}

	function plural( n, one, many ) {
		return n + " " + ( n === 1 ? one : many );
	}

	function modal( id ) {
		return window.bootstrap.Modal.getOrCreateInstance( document.getElementById( id ) );
	}

	function api( path, options ) {
		options = options || {};
		var headers = { "X-WP-Nonce": cfg.nonce };
		if ( options.body ) {
			headers[ "Content-Type" ] = "application/json";
		}
		return fetch( cfg.restUrl + path, {
			method: options.method || "GET",
			headers: headers,
			credentials: "same-origin",
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( response ) {
			return response.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data.message || "Something went wrong. Try again." );
				}
				return data;
			} );
		} );
	}

	function flash( message, kind ) {
		var box = $( "#atk-poi-flash" );
		var tone = kind === "error" ? "alert-danger" : "alert-success";
		box.innerHTML = message ? "<div class=\"alert " + tone + " d-flex align-items-center p-5 mb-6\" role=\"" + ( kind === "error" ? "alert" : "status" ) + "\">" + esc( message ) + "</div>" : "";
		$( "#atk-poi-status" ).textContent = message || "";
	}

	function fail( error ) {
		flash( error && error.message ? error.message : "Something went wrong. Try again.", "error" );
	}

	function allPlaces() {
		var out = [];
		( state.data ? state.data.sections : [] ).forEach( function ( section ) {
			section.places.forEach( function ( place ) {
				out.push( { place: place, ward: section.ward, wardId: section.ward_id } );
			} );
		} );
		return out;
	}

	function matches( item ) {
		if ( state.ward !== "" && String( item.wardId ) !== state.ward ) {
			return false;
		}
		var term = state.search.trim().toLowerCase();
		if ( ! term ) {
			return true;
		}
		var p = item.place;
		return [ p.name, p.address, p.postcode, p.street_answer, p.category ].join( " " ).toLowerCase().indexOf( term ) !== -1;
	}

	/* ---------- badges ---------- */

	function badge( text, tone ) {
		return "<span class=\"badge badge-light-" + tone + " fs-7\">" + esc( text ) + "</span>";
	}

	function pinBadge( place ) {
		if ( place.geo_state === "manual" ) {
			return badge( "Placed by hand", "primary" );
		}
		if ( place.lat !== null && place.geo_state === "looked_up" ) {
			return badge( "On the map", "success" );
		}
		if ( place.geo_state === "not_found" ) {
			return badge( "Not found", "danger" );
		}
		return badge( "No pin yet", "warning" );
	}

	/* ---------- shell ---------- */

	function buildShell() {
		var wardOptions = "";
		root.innerHTML =
			"<div id=\"atk-poi-flash\"></div>" +
			"<div class=\"card card-flush\">" +
				"<div class=\"card-header pt-7 flex-wrap gap-3\" id=\"atk-poi-toolbar\">" +
					"<div class=\"card-title flex-column align-items-start\">" +
						"<h3 class=\"fw-bold text-gray-900 mb-1\">Places of Interest</h3>" +
						"<div class=\"text-gray-600 fs-6\" id=\"atk-poi-summary\"></div>" +
					"</div>" +
					"<div class=\"card-toolbar flex-wrap gap-3\">" +
						"<button type=\"button\" class=\"btn btn-light-warning\" id=\"atk-poi-lookup-btn\" data-act=\"lookup-missing\"></button>" +
						"<button type=\"button\" class=\"btn btn-light-primary\" id=\"atk-poi-import-btn\" data-act=\"import\">Import spreadsheet</button>" +
						"<button type=\"button\" class=\"btn btn-primary\" data-act=\"add\">Add place</button>" +
					"</div>" +
				"</div>" +
				"<div class=\"card-body pt-4\">" +
					"<div class=\"d-flex flex-wrap align-items-center gap-4 mb-6\">" +
						"<input id=\"atk-poi-search\" class=\"form-control form-control-solid w-100 mw-350px\" type=\"search\" placeholder=\"Search places\" aria-label=\"Search places\">" +
						"<select id=\"atk-poi-ward-filter\" class=\"form-select form-select-solid w-100 mw-350px\" aria-label=\"Show one ward\">" + wardOptions + "</select>" +
						"<div class=\"form-check form-switch form-check-custom form-check-solid ms-lg-auto\">" +
							"<input class=\"form-check-input\" type=\"checkbox\" id=\"atk-poi-flat\">" +
							"<label class=\"form-check-label text-gray-700 fs-6\" for=\"atk-poi-flat\">Show as one list</label>" +
						"</div>" +
					"</div>" +
					"<div id=\"atk-poi-lookup\" class=\"mb-6\"></div>" +
					"<div id=\"atk-poi-list\"></div>" +
				"</div>" +
			"</div>" +
			"<p id=\"atk-poi-status\" class=\"visually-hidden\" role=\"status\" aria-live=\"polite\"></p>" +

			"<div class=\"modal fade\" id=\"atk-poi-edit-modal\" tabindex=\"-1\" aria-labelledby=\"atk-poi-edit-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg\"><div class=\"modal-content\">" +
					"<form id=\"atk-poi-edit-form\">" +
						"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-poi-edit-title\">Add place</h3>" +
							"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
						"<div class=\"modal-body\">" +
							"<div class=\"row g-5\">" +
								"<div class=\"col-12\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-name\">Place of interest</label>" +
									"<input class=\"form-control form-control-solid\" id=\"atk-poi-f-name\" name=\"name\" maxlength=\"255\" required></div>" +
								"<div class=\"col-12\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-address\">Address</label>" +
									"<input class=\"form-control form-control-solid\" id=\"atk-poi-f-address\" name=\"address\" placeholder=\"55 Rose Street, Aberdeen\"></div>" +
								"<div class=\"col-md-4\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-postcode\">Postcode</label>" +
									"<input class=\"form-control form-control-solid\" id=\"atk-poi-f-postcode\" name=\"postcode\" maxlength=\"12\" autocomplete=\"off\"></div>" +
								"<div class=\"col-md-8\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-street\">Street answer</label>" +
									"<input class=\"form-control form-control-solid\" id=\"atk-poi-f-street\" name=\"street_answer\" maxlength=\"255\" aria-describedby=\"atk-poi-f-street-help\">" +
									"<div class=\"form-text\" id=\"atk-poi-f-street-help\">The street a driver must give in the test.</div></div>" +
								"<div class=\"col-12\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-also\">Other accepted streets</label>" +
									"<textarea class=\"form-control form-control-solid\" id=\"atk-poi-f-also\" name=\"also_answers\" rows=\"2\" aria-describedby=\"atk-poi-f-also-help\" placeholder=\"One street per line\"></textarea>" +
									"<div class=\"form-text\" id=\"atk-poi-f-also-help\">For a place on a corner or with two entrances. A driver who gives any of these is marked right, as well as the street answer. Up to 6.</div>" +
									"<div class=\"form-text text-gray-700 d-none\" id=\"atk-poi-f-research\"></div></div>" +
								"<div class=\"col-md-6\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-ward\">Ward</label>" +
									"<select class=\"form-select form-select-solid\" id=\"atk-poi-f-ward\" name=\"ward_id\"></select></div>" +
								"<div class=\"col-md-6\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-category\">Category</label>" +
									"<select class=\"form-select form-select-solid\" id=\"atk-poi-f-category\" name=\"category\"></select></div>" +
								"<div class=\"col-md-6\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-status\">Status</label>" +
									"<select class=\"form-select form-select-solid\" id=\"atk-poi-f-status\" name=\"status\"><option value=\"current\">Open</option><option value=\"closed\">Closed</option></select></div>" +
								"<div class=\"col-12\"><label class=\"form-label fw-semibold\" for=\"atk-poi-f-notes\">Notes</label>" +
									"<textarea class=\"form-control form-control-solid\" id=\"atk-poi-f-notes\" name=\"notes\" rows=\"3\"></textarea></div>" +
							"</div>" +
							"<div id=\"atk-poi-edit-error\" class=\"text-danger fs-6 mt-4\" role=\"alert\"></div>" +
						"</div>" +
						"<div class=\"modal-footer\">" +
							"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
							"<button type=\"submit\" class=\"btn btn-primary\" id=\"atk-poi-edit-save\">Save place</button>" +
						"</div>" +
					"</form>" +
				"</div></div>" +
			"</div>" +

			"<input id=\"atk-poi-file\" class=\"d-none\" type=\"file\" accept=\".xlsx,.xls,.csv\" aria-label=\"Choose a spreadsheet file\">" +

			"<div class=\"modal fade\" id=\"atk-poi-import-modal\" tabindex=\"-1\" aria-labelledby=\"atk-poi-import-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-lg\"><div class=\"modal-content\">" +
					"<form id=\"atk-poi-paste-form\">" +
						"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-poi-import-title\">Import places of interest</h3>" +
							"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
						"<div class=\"modal-body\">" +
							"<div id=\"atk-poi-drop\" class=\"akn-poi-drop text-center p-10 mb-6\" tabindex=\"0\" role=\"button\" aria-label=\"Choose a spreadsheet file\">" +
								"<div class=\"fw-bold fs-5 text-gray-900 mb-1\">Drop a .xlsx or .csv file here</div>" +
								"<div class=\"text-gray-600 fs-6\">or click to choose one. The first sheet is read, with the columns Place of interest, Street, Postcode and Ward.</div>" +
							"</div>" +
							"<label class=\"form-label fw-semibold\" for=\"atk-poi-paste-text\">Or paste rows copied from a spreadsheet</label>" +
							"<textarea id=\"atk-poi-paste-text\" class=\"form-control form-control-solid\" rows=\"6\" spellcheck=\"false\"></textarea>" +
							"<div id=\"atk-poi-import-error\" class=\"text-danger fs-6 mt-3\" role=\"alert\"></div>" +
						"</div>" +
						"<div class=\"modal-footer\">" +
							"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
							"<button type=\"submit\" class=\"btn btn-primary\">Check pasted rows</button>" +
						"</div>" +
					"</form>" +
				"</div></div>" +
			"</div>" +

			"<div class=\"modal fade\" id=\"atk-poi-review-modal\" tabindex=\"-1\" aria-labelledby=\"atk-poi-review-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl\"><div class=\"modal-content\" id=\"atk-poi-review-content\"></div></div>" +
			"</div>" +

			"<div class=\"modal fade\" id=\"atk-poi-pin-modal\" tabindex=\"-1\" aria-labelledby=\"atk-poi-pin-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-lg\"><div class=\"modal-content\">" +
					"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-poi-pin-title\">Place the pin</h3>" +
						"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
					"<div class=\"modal-body\">" +
						"<p class=\"text-gray-700 fs-6 mb-4\" id=\"atk-poi-pin-help\"></p>" +
						"<form id=\"atk-poi-pin-search\" class=\"mb-4\" role=\"search\" novalidate>" +
							"<div class=\"input-group\">" +
								"<input type=\"search\" class=\"form-control form-control-solid\" id=\"atk-poi-pin-q\" placeholder=\"Search for the place, a street or a postcode\" aria-label=\"Search the map\" autocomplete=\"off\">" +
								"<button type=\"submit\" class=\"btn btn-light-primary\" id=\"atk-poi-pin-find\">Search</button>" +
							"</div>" +
							"<div id=\"atk-poi-pin-results\" class=\"list-group akn-poi-pin-results mt-3\"></div>" +
							"<div id=\"atk-poi-pin-note\" class=\"text-gray-600 fs-7 mt-2\" role=\"status\" aria-live=\"polite\"></div>" +
						"</form>" +
						"<div id=\"atk-poi-pin-map\" class=\"akn-poi-pin-map rounded\" role=\"application\" aria-label=\"Map. Click to place the pin.\"></div>" +
						"<div id=\"atk-poi-pin-error\" class=\"text-danger fs-6 mt-3\" role=\"alert\"></div>" +
					"</div>" +
					"<div class=\"modal-footer\">" +
						"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
						"<button type=\"button\" class=\"btn btn-primary\" id=\"atk-poi-pin-save\" disabled>Save pin</button>" +
					"</div>" +
				"</div></div>" +
			"</div>" +

			"<div class=\"modal fade\" id=\"atk-poi-delete-modal\" tabindex=\"-1\" aria-labelledby=\"atk-poi-delete-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered\"><div class=\"modal-content\">" +
					"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-poi-delete-title\">Delete this place?</h3>" +
						"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
					"<div class=\"modal-body\"><p class=\"mb-0 text-gray-700\" id=\"atk-poi-delete-text\"></p></div>" +
					"<div class=\"modal-footer\">" +
						"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Keep it</button>" +
						"<button type=\"button\" class=\"btn btn-danger\" id=\"atk-poi-delete-confirm\">Delete place</button>" +
					"</div>" +
				"</div></div>" +
			"</div>";
	}

	/* ---------- list ---------- */

	function renderToolbarState() {
		var counts = state.data.counts;
		var parts = [ plural( counts.total, "place", "places" ) ];
		if ( counts.total ) {
			parts.push( counts.pinned + " on the map" );
			if ( counts.no_pin ) {
				parts.push( counts.no_pin + " with no pin yet" );
			}
		}
		$( "#atk-poi-summary" ).textContent = parts.join(", ");

		var waiting = placesWithoutPin().length;
		var lookupBtn = $( "#atk-poi-lookup-btn" );
		lookupBtn.disabled = look.running || ! waiting;
		lookupBtn.textContent = look.running ? "Looking up pins" : "Look up missing pins" + ( waiting ? " (" + waiting + ")" : "" );
		lookupBtn.title = look.running ? "A lookup is running." : ( waiting ? "Search OpenStreetMap for the places that have no pin. Places you placed by hand are never moved." : "Every place has a pin." );

		var select = $( "#atk-poi-ward-filter" );
		var options = [ "<option value=\"\">All wards</option>" ];
		state.data.sections.forEach( function ( section ) {
			options.push( "<option value=\"" + section.ward_id + "\">" + esc( section.ward ) + "</option>" );
		} );
		select.innerHTML = options.join( "" );
		select.value = state.ward;
		if ( select.value !== state.ward ) {
			state.ward = "";
			select.value = "";
		}
	}

	/* The extra streets a place accepts: the ones checked in research first, then the ones typed in the form. */
	function acceptedExtras( p ) {
		var found = state.data.researched ? state.data.researched[ p.id ] : null;
		var list = ( found && found.also ? found.also : [] ).concat( p.also_answers || [] );
		var seen = {};
		return list.filter( function ( street ) {
			var key = String( street ).toLowerCase().replace( /\s+/g, " " ).trim();
			var main = String( p.street_answer ).toLowerCase().replace( /\s+/g, " " ).trim();
			if ( ! key || key === main || seen[ key ] ) {
				return false;
			}
			seen[ key ] = true;
			return true;
		} );
	}

	function row( item, withWard ) {
		var p = item.place;
		var closed = p.status === "closed" ? " " + badge( "Closed", "danger" ) : "";
		var also = acceptedExtras( p ).length ?
			"<div class=\"text-gray-600 fs-7\">Also accepts " + esc( acceptedExtras( p ).join( ", " ) ) + "</div>" : "";
		var street = p.street_answer ?
			"<span class=\"text-gray-800\">" + esc( p.street_answer ) + "</span>" + also :
			badge( "Needs a street", "warning" );
		return "<tr>" +
			"<td class=\"akn-poi-name\"><div class=\"fw-bold text-gray-900\">" + esc( p.name ) + closed + "</div>" +
				"<div class=\"text-gray-600 fs-7 d-lg-none\">" + esc( p.address ) + "</div></td>" +
			"<td class=\"d-none d-lg-table-cell text-gray-700\">" + esc( p.address ) + "</td>" +
			"<td class=\"text-nowrap text-gray-700\">" + esc( p.postcode ) + "</td>" +
			"<td>" + street + "</td>" +
			( withWard ? "<td class=\"text-gray-700\">" + esc( item.ward ) + "</td>" : "" ) +
			"<td class=\"d-none d-xl-table-cell\">" + badge( p.category, "info" ) + "</td>" +
			"<td><button type=\"button\" class=\"btn btn-sm p-0 border-0 bg-transparent\" data-act=\"pin\" data-id=\"" + p.id + "\" aria-label=\"Set the map pin for " + esc( p.name ) + "\">" + pinBadge( p ) + "</button></td>" +
			"<td class=\"akn-poi-actions text-end text-nowrap\">" +
				"<button type=\"button\" class=\"btn btn-sm btn-light-primary me-2\" data-act=\"edit\" data-id=\"" + p.id + "\" aria-label=\"Edit " + esc( p.name ) + "\">Edit</button>" +
				"<button type=\"button\" class=\"btn btn-sm btn-light-danger\" data-act=\"delete\" data-id=\"" + p.id + "\" aria-label=\"Delete " + esc( p.name ) + "\">Delete</button>" +
			"</td>" +
		"</tr>";
	}

	function table( items, withWard ) {
		var cols = withWard ? [ 17, 16, 9, 12, 14, 10, 9, 13 ] : [ 19, 19, 9, 15, 14, 10, 14 ];
		var colgroup = "<colgroup>" + cols.map( function ( w ) { return "<col style=\"width:" + w + "%\">"; } ).join( "" ) + "</colgroup>";
		return "<div class=\"table-responsive\"><table class=\"table akn-poi-table align-middle table-row-dashed fs-6 gy-4 mb-0\">" + colgroup +
			"<thead><tr class=\"text-start text-gray-600 fw-bold fs-7 gs-0\">" +
				"<th>Place of interest</th><th class=\"d-none d-lg-table-cell\">Address</th><th>Postcode</th><th>Street answer</th>" +
				( withWard ? "<th>Ward</th>" : "" ) +
				"<th class=\"d-none d-xl-table-cell\">Category</th><th>Pin</th><th class=\"text-end\"><span class=\"visually-hidden\">Actions</span></th>" +
			"</tr></thead><tbody>" +
			items.map( function ( item ) { return row( item, withWard ); } ).join( "" ) +
			"</tbody></table></div>";
	}

	function renderList() {
		renderToolbarState();
		var box = $( "#atk-poi-list" );
		if ( ! state.data.counts.total ) {
			box.innerHTML =
				"<div class=\"d-flex flex-column align-items-center text-center py-15\">" +
					"<h3 class=\"fw-bold text-gray-900\">No places of interest yet</h3>" +
					"<p class=\"text-gray-600 fs-6 mw-450px\">Import the official spreadsheet and the places appear here, on the public map and in the POI tests. You can also add them one at a time.</p>" +
					"<div class=\"d-flex gap-3\"><button type=\"button\" class=\"btn btn-primary\" data-act=\"import\">Import spreadsheet</button>" +
					"<button type=\"button\" class=\"btn btn-light-primary\" data-act=\"add\">Add place</button></div>" +
				"</div>";
			return;
		}
		var items = allPlaces().filter( matches );
		if ( ! items.length ) {
			box.innerHTML = "<p class=\"text-gray-600 fs-6 mb-0\">No place matches those filters.</p>";
			return;
		}
		if ( state.flat ) {
			items.sort( function ( a, b ) { return a.place.id - b.place.id; } );
			box.innerHTML = table( items, true );
			return;
		}
		var html = "";
		state.data.sections.forEach( function ( section ) {
			var inSection = items.filter( function ( item ) { return item.wardId === section.ward_id; } );
			if ( ! inSection.length ) {
				return;
			}
			html +=
				"<section class=\"mb-10\" aria-label=\"" + esc( section.ward ) + "\">" +
					"<div class=\"akn-poi-ward-head d-flex flex-wrap align-items-center gap-3 mb-3\">" +
						"<h4 class=\"fw-bold text-gray-900 mb-0\">" + esc( section.ward ) + "</h4>" +
						badge( plural( inSection.length, "place", "places" ), "primary" ) +
					"</div>" +
					table( inSection, false ) +
				"</section>";
		} );
		box.innerHTML = html;
	}

	/* ---------- loading ---------- */

	function reload() {
		return api( "poi-places" ).then( function ( data ) {
			state.data = data;
			renderList();
		} );
	}

	/* ---------- add and edit ---------- */

	function findPlace( id ) {
		var all = allPlaces();
		for ( var i = 0; i < all.length; i++ ) {
			if ( all[ i ].place.id === id ) {
				return all[ i ].place;
			}
		}
		return null;
	}

	function fillSelects( place ) {
		var wards = [ "<option value=\"0\">No ward yet</option>" ].concat( state.data.wards.map( function ( w ) {
			return "<option value=\"" + w.id + "\">" + esc( w.name ) + "</option>";
		} ) );
		$( "#atk-poi-f-ward" ).innerHTML = wards.join( "" );
		$( "#atk-poi-f-category" ).innerHTML = cfg.categories.map( function ( c ) {
			return "<option value=\"" + esc( c ) + "\">" + esc( c ) + "</option>";
		} ).join( "" );
		$( "#atk-poi-f-ward" ).value = String( place ? place.ward_id : 0 );
		$( "#atk-poi-f-category" ).value = place ? place.category : "Other Amenities";
	}

	/* What the researched-answers file already accepts for this place, shown under the box. */
	function showResearch( place ) {
		var box = $( "#atk-poi-f-research" );
		var found = place && state.data.researched ? state.data.researched[ place.id ] : null;
		if ( ! found ) {
			box.classList.add( "d-none" );
			box.textContent = "";
			return;
		}
		var extra = found.also && found.also.length ? " Also accepted from research: " + found.also.join( ", " ) + "." : "";
		box.textContent = "Checked answer: " + found.street + "." + extra + ( found.only ? " The sheet's own street is not accepted for this place." : "" );
		box.classList.remove( "d-none" );
	}

	function openEdit( place ) {
		state.editing = place || null;
		$( "#atk-poi-edit-title" ).textContent = place ? "Edit place" : "Add place";
		$( "#atk-poi-edit-error" ).textContent = "";
		fillSelects( place );
		$( "#atk-poi-f-name" ).value = place ? place.name : "";
		$( "#atk-poi-f-address" ).value = place ? place.address : "";
		$( "#atk-poi-f-postcode" ).value = place ? place.postcode : "";
		$( "#atk-poi-f-street" ).value = place ? place.street_answer : "";
		$( "#atk-poi-f-also" ).value = place && place.also_answers ? place.also_answers.join( "\n" ) : "";
		showResearch( place );
		$( "#atk-poi-f-status" ).value = place ? place.status : "current";
		$( "#atk-poi-f-notes" ).value = place ? place.notes : "";
		modal( "atk-poi-edit-modal" ).show();
	}

	function saveEdit( event ) {
		event.preventDefault();
		var body = {
			name: $( "#atk-poi-f-name" ).value,
			address: $( "#atk-poi-f-address" ).value,
			postcode: $( "#atk-poi-f-postcode" ).value,
			street_answer: $( "#atk-poi-f-street" ).value,
			also_answers: $( "#atk-poi-f-also" ).value,
			ward_id: parseInt( $( "#atk-poi-f-ward" ).value, 10 ) || 0,
			category: $( "#atk-poi-f-category" ).value,
			status: $( "#atk-poi-f-status" ).value,
			notes: $( "#atk-poi-f-notes" ).value
		};
		var editing = state.editing;
		var button = $( "#atk-poi-edit-save" );
		button.disabled = true;
		api( editing ? "poi-places/" + editing.id : "poi-places", { method: "POST", body: body } ).then( function ( saved ) {
			modal( "atk-poi-edit-modal" ).hide();
			return reload().then( function () {
				flash( ( editing ? "Saved " : "Added " ) + saved.name + ".", "ok" );
			} );
		} ).catch( function ( error ) {
			$( "#atk-poi-edit-error" ).textContent = error && error.message ? error.message : "That place could not be saved.";
		} ).then( function () {
			button.disabled = false;
		} );
	}

	/* ---------- delete ---------- */

	function openDelete( place ) {
		state.deleting = place;
		$( "#atk-poi-delete-text" ).textContent = place.name + " will be removed from this list, the public map and the POI tests.";
		modal( "atk-poi-delete-modal" ).show();
	}

	function confirmDelete() {
		var place = state.deleting;
		if ( ! place ) {
			return;
		}
		var button = $( "#atk-poi-delete-confirm" );
		button.disabled = true;
		api( "poi-places/" + place.id, { method: "DELETE" } ).then( function () {
			modal( "atk-poi-delete-modal" ).hide();
			return reload().then( function () {
				flash( "Deleted " + place.name + ".", "ok" );
			} );
		} ).catch( function ( error ) {
			modal( "atk-poi-delete-modal" ).hide();
			fail( error );
		} ).then( function () {
			button.disabled = false;
		} );
	}

	/* ---------- pin lookup (OpenStreetMap, one request a second) ---------- */

	var GEO = window.ATK_POI_GEO;
	var look = { running: false, stop: false, done: 0, total: 0, found: 0, missed: 0, note: "" };

	function sleep( ms ) {
		return new Promise( function ( resolve ) { window.setTimeout( resolve, ms ); } );
	}

	function placesWaiting() {
		return allPlaces().map( function ( item ) { return item.place; } ).filter( function ( p ) {
			return p.geo_state === "none";
		} );
	}

	/* Everything without a pin that was not placed by hand: never looked up, or looked up and not found. */
	function placesWithoutPin() {
		return allPlaces().map( function ( item ) { return item.place; } ).filter( function ( p ) {
			return p.geo_state === "none" || p.geo_state === "not_found";
		} );
	}

	function renderLookup() {
		var box = $( "#atk-poi-lookup" );
		if ( ! look.running && ! look.note ) {
			box.innerHTML = "";
			return;
		}
		var pct = look.total ? Math.round( look.done / look.total * 100 ) : 0;
		if ( look.running ) {
			box.innerHTML =
				"<div class=\"d-flex flex-wrap align-items-center gap-4 bg-light rounded p-5\">" +
					"<div class=\"flex-grow-1\">" +
						"<div class=\"fw-bold text-gray-900 mb-2\">Looking up map pins: " + look.done + " of " + look.total + "</div>" +
						"<div class=\"progress h-8px\"><div class=\"progress-bar\" role=\"progressbar\" style=\"width:" + pct + "%\" aria-valuenow=\"" + pct + "\" aria-valuemin=\"0\" aria-valuemax=\"100\" aria-label=\"Pin lookup progress\"></div></div>" +
						"<div class=\"fs-7 text-gray-600 mt-2\">" + look.found + " found, " + look.missed + " not found. One place a second, so you can keep working. Places not reached yet stay as they are.</div>" +
					"</div>" +
					"<button type=\"button\" class=\"btn btn-light\" data-act=\"lookup-stop\">Stop</button>" +
				"</div>";
			return;
		}
		box.innerHTML = "<div class=\"alert alert-" + ( look.error ? "warning" : "success" ) + " p-5 mb-0\" role=\"status\">" + esc( look.note ) + "</div>";
	}

	function postPin( id, lat, lng, stateName ) {
		return api( "poi-places/" + id + "/pin", { method: "POST", body: { lat: lat, lng: lng, state: stateName } } );
	}

	/* Tries each search in turn and resolves with { lat, lng }, or null when nothing passes the rules. */
	function lookupOne( place ) {
		var queries = GEO.buildQueries( place );
		var index = 0;
		function next() {
			if ( index >= queries.length ) {
				return Promise.resolve( null );
			}
			var query = queries[ index++ ];
			return fetch( GEO.searchUrl( query ), { headers: { Accept: "application/json" } } ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( "OpenStreetMap answered " + response.status + "." );
				}
				return response.json();
			} ).then( function ( results ) {
				var hit = GEO.pick( results );
				if ( hit || index >= queries.length ) {
					return hit;
				}
				return sleep( 1000 ).then( next );
			} );
		}
		return next();
	}

	function lookupPins( places ) {
		if ( look.running || ! GEO || ! places.length ) {
			return Promise.resolve();
		}
		look = { running: true, stop: false, done: 0, total: places.length, found: 0, missed: 0, note: "", error: false };
		renderLookup();
		renderToolbarState();

		function step( i ) {
			if ( i >= places.length || look.stop ) {
				return Promise.resolve();
			}
			var place = places[ i ];
			return lookupOne( place ).then( function ( hit ) {
				return hit ? postPin( place.id, hit.lat, hit.lng, "looked_up" ) : postPin( place.id, null, null, "not_found" );
			} ).then( function ( saved ) {
				look.done++;
				if ( saved.geo_state === "not_found" ) {
					look.missed++;
				} else {
					look.found++;
				}
				renderLookup();
				return i + 1 < places.length && ! look.stop ? sleep( 1000 ).then( function () { return step( i + 1 ); } ) : null;
			} );
		}

		return step( 0 ).catch( function ( error ) {
			look.error = true;
			look.failure = error && error.message ? error.message : "The lookup stopped.";
		} ).then( function () {
			var left = look.total - look.done;
			var summary = look.found + " pins found, " + look.missed + " not found.";
			look.note = look.error ?
				"The lookup stopped. " + look.failure + " " + summary + " " + plural( left, "place is", "places are" ) + " still waiting. Use Look up missing pins to try again later." :
				( look.stop ? "Lookup stopped. " + summary + " " + plural( left, "place is", "places are" ) + " still waiting." : "Lookup finished. " + summary + ( look.missed ? " Places that were not found can be placed by hand: click their pin badge, then search or click the map." : "" ) );
			look.running = false;
			return reload().then( renderLookup );
		} );
	}

	/* ---------- hand placement ---------- */

	var picker = { map: null, marker: null, place: null, latlng: null };

	function placePicker( latlng ) {
		picker.latlng = latlng;
		if ( picker.marker ) {
			picker.marker.setLatLng( latlng );
		} else {
			picker.marker = window.L.circleMarker( latlng, { radius: 9, weight: 3, color: "#ffffff", fillColor: "#e8590c", fillOpacity: 1 } ).addTo( picker.map );
		}
		$( "#atk-poi-pin-save" ).disabled = false;
	}

	/* ---------- search inside the pin box (one request a second, shared with the lookup) ---------- */

	var pinSearch = { token: 0, last: 0, results: [] };

	function pinSearchReset() {
		pinSearch.token++;
		pinSearch.results = [];
		$( "#atk-poi-pin-results" ).innerHTML = "";
		$( "#atk-poi-pin-note" ).textContent = "";
		$( "#atk-poi-pin-find" ).disabled = false;
	}

	function pinResultLabel( result ) {
		var parts = String( result.display_name || "" ).split( "," );
		var title = ( typeof result.name === "string" && result.name.trim() ) || parts[ 0 ].trim() || "Unnamed place";
		var rest = parts.slice( 1 ).join( "," ).trim();
		return "<span class=\"fw-bold text-gray-900 d-block\">" + esc( title ) + "</span>" +
			( rest ? "<span class=\"text-gray-600 fs-7 d-block\">" + esc( rest ) + "</span>" : "" );
	}

	function showPinResults( results ) {
		pinSearch.results = results;
		$( "#atk-poi-pin-results" ).innerHTML = results.map( function ( result, i ) {
			return "<button type=\"button\" class=\"list-group-item list-group-item-action\" data-result=\"" + i + "\">" + pinResultLabel( result ) + "</button>";
		} ).join( "" );
	}

	function choosePinResult( index ) {
		var result = pinSearch.results[ index ];
		if ( ! result || ! picker.map ) {
			return;
		}
		var latlng = window.L.latLng( parseFloat( result.lat ), parseFloat( result.lon ) );
		picker.map.setView( latlng, 18 );
		placePicker( latlng );
		Array.prototype.forEach.call( document.querySelectorAll( "#atk-poi-pin-results .list-group-item" ), function ( item ) {
			var on = item.getAttribute( "data-result" ) === String( index );
			item.classList.toggle( "active", on );
			if ( on ) {
				item.setAttribute( "aria-current", "true" );
			} else {
				item.removeAttribute( "aria-current" );
			}
		} );
		$( "#atk-poi-pin-note" ).textContent = "Pin set. Click the map to move it, then choose Save pin.";
	}

	function runPinSearch( event ) {
		event.preventDefault();
		var query = $( "#atk-poi-pin-q" ).value.trim();
		var note = $( "#atk-poi-pin-note" );
		if ( ! query ) {
			note.textContent = "Type a place, street or postcode to search.";
			return;
		}
		if ( look.running ) {
			note.textContent = "A pin lookup is running. Stop it or wait for it to finish, then search.";
			return;
		}
		var token = ++pinSearch.token;
		var button = $( "#atk-poi-pin-find" );
		button.disabled = true;
		$( "#atk-poi-pin-results" ).innerHTML = "";
		note.textContent = "Searching OpenStreetMap";
		var wait = Math.max( 0, pinSearch.last + 1000 - Date.now() );
		sleep( wait ).then( function () {
			pinSearch.last = Date.now();
			return fetch( GEO.searchUrl( query, 8 ), { headers: { Accept: "application/json" } } );
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( "OpenStreetMap answered " + response.status + "." );
			}
			return response.json();
		} ).then( function ( results ) {
			if ( token !== pinSearch.token ) {
				return;
			}
			results = ( results || [] ).filter( function ( r ) {
				return GEO.inBox( parseFloat( r.lat ), parseFloat( r.lon ) );
			} );
			button.disabled = false;
			if ( ! results.length ) {
				note.textContent = "Nothing found in the Aberdeen area for \u201c" + query + "\u201d. Try the street or the postcode, or click the map.";
				return;
			}
			showPinResults( results );
			note.textContent = plural( results.length, "match", "matches" ) + ". Choose one to put the pin there.";
		} ).catch( function ( error ) {
			if ( token !== pinSearch.token ) {
				return;
			}
			button.disabled = false;
			note.textContent = "The search could not run. " + ( error && error.message ? error.message : "Check your connection and try again." );
		} );
	}

	function openPinPicker( place ) {
		if ( ! window.L ) {
			fail( new Error( "The map did not load. Check your connection and try again." ) );
			return;
		}
		picker.place = place;
		$( "#atk-poi-pin-error" ).textContent = "";
		$( "#atk-poi-pin-save" ).disabled = true;
		$( "#atk-poi-pin-title" ).textContent = "Place the pin for " + place.name;
		$( "#atk-poi-pin-help" ).textContent = ( place.address ? place.address + ". " : "" ) + "Search for the place or click the map where it is. The pin is saved as placed by hand and a later lookup will not move it.";
		$( "#atk-poi-pin-q" ).value = place.name;
		pinSearchReset();
		var el = document.getElementById( "atk-poi-pin-modal" );
		el.addEventListener( "shown.bs.modal", function () {
			var B = GEO.BOX;
			if ( ! picker.map ) {
				picker.map = window.L.map( "atk-poi-pin-map", { maxBounds: [ [ B.latMin, B.lngMin ], [ B.latMax, B.lngMax ] ], minZoom: 10 } );
				window.L.tileLayer( "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
					maxZoom: 19,
					attribution: "&copy; <a href=\"https://www.openstreetmap.org/copyright\">OpenStreetMap</a> contributors"
				} ).addTo( picker.map );
				picker.map.on( "click", function ( event ) {
					if ( GEO.inBox( event.latlng.lat, event.latlng.lng ) ) {
						Array.prototype.forEach.call( document.querySelectorAll( "#atk-poi-pin-results .active" ), function ( item ) {
							item.classList.remove( "active" );
							item.removeAttribute( "aria-current" );
						} );
						placePicker( event.latlng );
					}
				} );
			}
			picker.map.invalidateSize();
			if ( picker.marker ) {
				picker.marker.remove();
				picker.marker = null;
			}
			if ( place.lat !== null ) {
				picker.map.setView( [ place.lat, place.lng ], 17 );
				placePicker( window.L.latLng( place.lat, place.lng ) );
				$( "#atk-poi-pin-save" ).disabled = true;
			} else {
				picker.map.setView( [ 57.1497, -2.0943 ], 13 );
			}
		}, { once: true } );
		modal( "atk-poi-pin-modal" ).show();
	}

	function savePin() {
		if ( ! picker.place || ! picker.latlng ) {
			return;
		}
		var button = $( "#atk-poi-pin-save" );
		button.disabled = true;
		postPin( picker.place.id, picker.latlng.lat, picker.latlng.lng, "manual" ).then( function ( saved ) {
			modal( "atk-poi-pin-modal" ).hide();
			return reload().then( function () {
				flash( "Pin saved for " + saved.name + ".", "ok" );
			} );
		} ).catch( function ( error ) {
			$( "#atk-poi-pin-error" ).textContent = error && error.message ? error.message : "That pin could not be saved.";
			button.disabled = false;
		} );
	}

	/* ---------- import ---------- */

	var MAX_SHEET_ROWS = 5000;
	var MAX_REVIEW_ROWS = 500;
	var imp = { payload: null, plan: null, overrides: {}, skipWard: {}, onlyAttention: false };

	function isOpen( id ) {
		return document.getElementById( id ).classList.contains( "show" );
	}

	/* Bootstrap shows one modal at a time, so wait for the first to finish closing. */
	function swapModal( fromId, toId ) {
		if ( ! isOpen( fromId ) ) {
			modal( toId ).show();
			return;
		}
		document.getElementById( fromId ).addEventListener( "hidden.bs.modal", function () { modal( toId ).show(); }, { once: true } );
		modal( fromId ).hide();
	}

	function wardKey( name ) {
		return String( name ).toLowerCase().replace( /\s+/g, " " ).replace( /\s*\/\s*/g, "/" ).trim();
	}

	function openImport() {
		$( "#atk-poi-import-error" ).textContent = "";
		$( "#atk-poi-paste-text" ).value = "";
		modal( "atk-poi-import-modal" ).show();
	}

	function importError( message ) {
		if ( isOpen( "atk-poi-import-modal" ) ) {
			$( "#atk-poi-import-error" ).textContent = message;
		} else {
			flash( message, "error" );
		}
	}

	function requestBody() {
		var body = imp.payload.rows ? { rows: imp.payload.rows } : { text: imp.payload.text };
		return body;
	}

	function startReview( payload ) {
		imp.payload = payload;
		imp.plan = null;
		imp.overrides = {};
		imp.skipWard = {};
		imp.onlyAttention = false;
		$( "#atk-poi-import-error" ).textContent = "";
		api( "poi-places/preview", { method: "POST", body: requestBody() } ).then( function ( plan ) {
			if ( plan.error ) {
				throw new Error( plan.error + " Check that the first row holds the column names." );
			}
			if ( ! plan.rows.length ) {
				throw new Error( "No places found in that sheet." );
			}
			imp.plan = plan;
			renderReview();
			swapModal( "atk-poi-import-modal", "atk-poi-review-modal" );
		} ).catch( function ( error ) {
			importError( error.message || "That sheet could not be checked." );
		} );
	}

	function readFile( file ) {
		if ( ! window.XLSX ) {
			importError( "The spreadsheet reader did not load. Check your connection and try again." );
			return;
		}
		var isCsv = /\.csv$/i.test( file.name );
		var reading = isCsv ? file.text() : file.arrayBuffer();
		reading.then( function ( data ) {
			var book = isCsv ? window.XLSX.read( data, { type: "string" } ) : window.XLSX.read( data, { type: "array" } );
			var sheet = book.Sheets[ book.SheetNames[ 0 ] ];
			var rows = window.XLSX.utils.sheet_to_json( sheet, { header: 1, raw: false, defval: "" } );
			if ( rows.length > MAX_SHEET_ROWS + 1 ) {
				importError( "That sheet has " + rows.length + " rows. The limit is " + MAX_SHEET_ROWS + " places at a time, so split it into parts and import each one." );
				return;
			}
			startReview( { rows: rows } );
		} ).catch( function () {
			importError( "That file could not be read. Save it as .xlsx or .csv and try again." );
		} );
	}

	function hasIssue( row, issue ) {
		return row.issues.indexOf( issue ) !== -1;
	}

	function needsAttention( row ) {
		return row.action !== "skip" && ( hasIssue( row, "needs_street" ) || hasIssue( row, "new_ward" ) );
	}

	function resultBadges( row ) {
		if ( row.action === "skip" ) {
			return badge( "Skipped", "secondary" ) + "<div class=\"fs-7 text-gray-600 mt-1\">Same name and postcode as an earlier row</div>";
		}
		var out = row.action === "update" ? badge( "Will update", "info" ) : badge( "Ready", "primary" );
		if ( hasIssue( row, "needs_street" ) ) {
			out += " " + badge( "Needs a street", "warning" );
		}
		if ( hasIssue( row, "new_ward" ) ) {
			out += " " + badge( "New ward", "success" );
		}
		return out;
	}

	function wardCell( row ) {
		if ( row.action === "skip" ) {
			return "";
		}
		if ( ! row.ward ) {
			return "<span class=\"text-gray-600\">No ward given</span>";
		}
		if ( ! hasIssue( row, "new_ward" ) ) {
			return "<span class=\"text-gray-800\">" + esc( row.ward ) + "</span>";
		}
		var key = wardKey( row.ward );
		var on = ! imp.skipWard[ key ];
		return "<div class=\"text-gray-800\">" + esc( row.ward ) + "</div>" +
			"<div class=\"form-check form-check-custom form-check-solid mt-2\">" +
				"<input class=\"form-check-input\" type=\"checkbox\" id=\"atk-poi-ward-" + row.line + "\" data-act=\"create-ward\" data-key=\"" + esc( key ) + "\"" + ( on ? " checked" : "" ) + ">" +
				"<label class=\"form-check-label fs-7\" for=\"atk-poi-ward-" + row.line + "\">Create this ward</label>" +
			"</div>";
	}

	function streetCell( row ) {
		if ( row.action === "skip" ) {
			return "";
		}
		if ( ! hasIssue( row, "needs_street" ) ) {
			return "<span class=\"text-gray-800\">" + esc( row.street_answer ) + "</span>";
		}
		var typed = imp.overrides[ row.line ] || "";
		var use = row.suggestion ?
			"<button type=\"button\" class=\"btn btn-sm btn-light-primary mt-2\" data-act=\"use-suggestion\" data-line=\"" + row.line + "\" data-street=\"" + esc( row.suggestion ) + "\">Use " + esc( row.suggestion ) + "</button>" :
			"<div class=\"fs-7 text-gray-600 mt-1\">No street on the site looks similar. Type one, or leave it empty and add it later.</div>";
		return "<input class=\"form-control form-control-sm form-control-solid\" type=\"text\" maxlength=\"255\" data-act=\"street-input\" data-line=\"" + row.line + "\" id=\"atk-poi-street-" + row.line + "\" value=\"" + esc( typed ) + "\" placeholder=\"Street answer\" aria-label=\"Street answer for " + esc( row.name ) + "\">" + use;
	}

	function renderReview() {
		var plan = imp.plan;
		var c = plan.counts;
		var rows = plan.rows.filter( function ( r ) { return ! imp.onlyAttention || needsAttention( r ); } );
		var clipped = rows.length > MAX_REVIEW_ROWS;
		var shown = clipped ? rows.slice( 0, MAX_REVIEW_ROWS ) : rows;
		var saving = c.add + c.update;

		var body = shown.map( function ( r ) {
			return "<tr>" +
				"<td><div class=\"fw-bold text-gray-900\">" + esc( r.name ) + "</div>" +
					"<div class=\"fs-7 text-gray-600\">Row " + r.line + ( r.postcode ? ", " + esc( r.postcode ) : "" ) + "</div>" +
					( r.address ? "<div class=\"fs-7 text-gray-600\">" + esc( r.address ) + "</div>" : "" ) + "</td>" +
				"<td>" + resultBadges( r ) + "</td>" +
				"<td>" + wardCell( r ) + "</td>" +
				"<td>" + streetCell( r ) + "</td>" +
			"</tr>";
		} ).join( "" );

		var clipNote = clipped ? "<p class=\"fs-7 text-gray-600 mt-3 mb-0\">Showing the first " + MAX_REVIEW_ROWS + " of " + rows.length + " rows. Use the filter to see only the rows that need attention. Every row is saved, not just the ones shown.</p>" : "";

		$( "#atk-poi-review-content" ).innerHTML =
			"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-poi-review-title\">Review before saving</h3>" +
				"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
			"<div class=\"modal-body\">" +
				"<div class=\"d-flex flex-wrap gap-2 mb-5\">" +
					badge( plural( c.add, "new place", "new places" ), "primary" ) +
					badge( c.update + " to update", "info" ) +
					badge( c.skip + " skipped", "secondary" ) +
					badge( c.needs_street + " need a street", c.needs_street ? "warning" : "secondary" ) +
					badge( plural( c.new_ward, "row with a new ward", "rows with a new ward" ), c.new_ward ? "success" : "secondary" ) +
				"</div>" +
				"<p class=\"fs-6 text-gray-700 mb-5\">Places are saved without a map pin. Pins are looked up from OpenStreetMap right after saving. A place with no street answer is kept but is not used in the POI tests until you add one.</p>" +
				"<div class=\"form-check form-check-custom form-check-solid mb-5\">" +
					"<input class=\"form-check-input\" type=\"checkbox\" id=\"atk-poi-only-attention\" data-act=\"only-attention\"" + ( imp.onlyAttention ? " checked" : "" ) + ">" +
					"<label class=\"form-check-label\" for=\"atk-poi-only-attention\">Show only rows that need attention</label>" +
				"</div>" +
				"<div class=\"table-responsive position-relative\"><table class=\"table akn-poi-review align-middle table-row-dashed fs-6 gy-3 mb-0\">" +
					"<thead><tr class=\"text-start text-gray-600 fw-bold fs-7 gs-0\"><th>Place</th><th>Result</th><th>Ward</th><th>Street answer</th></tr></thead>" +
					"<tbody>" + body + "</tbody></table></div>" +
				clipNote +
			"</div>" +
			"<div class=\"modal-footer\">" +
				"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
				"<button type=\"button\" class=\"btn btn-primary\" data-act=\"commit\"" + ( saving ? "" : " disabled" ) + ">Save " + esc( plural( saving, "place", "places" ) ) + "</button>" +
			"</div>";
	}

	function commitImport() {
		var button = document.querySelector( "#atk-poi-review-content [data-act=\"commit\"]" );
		button.disabled = true;
		button.textContent = "Saving";
		var body = requestBody();
		body.create_wards = imp.plan.rows.filter( function ( r ) {
			return hasIssue( r, "new_ward" ) && ! imp.skipWard[ wardKey( r.ward ) ];
		} ).map( function ( r ) { return r.line; } );
		body.street_overrides = {};
		Object.keys( imp.overrides ).forEach( function ( line ) {
			if ( imp.overrides[ line ].trim() ) {
				body.street_overrides[ line ] = imp.overrides[ line ].trim();
			}
		} );
		api( "poi-places/commit", { method: "POST", body: body } ).then( function ( result ) {
			modal( "atk-poi-review-modal" ).hide();
			var parts = [ "Added " + plural( result.added, "place", "places" ) + ", updated " + result.updated + ", skipped " + result.skipped + "." ];
			if ( result.wards_created ) {
				parts.push( plural( result.wards_created, "new ward was", "new wards were" ) + " created." );
			}
			return reload().then( function () {
				flash( parts.join( " " ), "ok" );
				var ui = window.ATK_POI_ADMIN_UI;
				if ( ui && typeof ui.afterCommit === "function" ) {
					ui.afterCommit( result );
				}
			} );
		} ).catch( function ( error ) {
			fail( error );
			renderReview();
		} );
	}

	function onImportClick( event ) {
		var el = event.target.closest( "[data-act]" );
		if ( ! el || el.tagName === "INPUT" ) {
			return false;
		}
		var act = el.getAttribute( "data-act" );
		if ( act === "use-suggestion" ) {
			var line = el.getAttribute( "data-line" );
			var street = el.getAttribute( "data-street" );
			imp.overrides[ line ] = street;
			var input = document.getElementById( "atk-poi-street-" + line );
			if ( input ) {
				input.value = street;
			}
			return true;
		}
		if ( act === "commit" ) {
			commitImport();
			return true;
		}
		return false;
	}

	function bindImport() {
		var drop = $( "#atk-poi-drop" );
		var file = $( "#atk-poi-file" );
		drop.addEventListener( "click", function () { file.click(); } );
		drop.addEventListener( "keydown", function ( event ) {
			if ( event.key === "Enter" || event.key === " " ) {
				event.preventDefault();
				file.click();
			}
		} );
		[ "dragenter", "dragover" ].forEach( function ( name ) {
			drop.addEventListener( name, function ( event ) {
				event.preventDefault();
				drop.classList.add( "akn-poi-drop-active" );
			} );
		} );
		[ "dragleave", "drop" ].forEach( function ( name ) {
			drop.addEventListener( name, function ( event ) {
				event.preventDefault();
				drop.classList.remove( "akn-poi-drop-active" );
			} );
		} );
		drop.addEventListener( "drop", function ( event ) {
			if ( event.dataTransfer && event.dataTransfer.files.length ) {
				readFile( event.dataTransfer.files[ 0 ] );
			}
		} );
		file.addEventListener( "change", function () {
			if ( file.files.length ) {
				if ( ! isOpen( "atk-poi-import-modal" ) ) {
					openImport();
				}
				readFile( file.files[ 0 ] );
				file.value = "";
			}
		} );
		$( "#atk-poi-paste-form" ).addEventListener( "submit", function ( event ) {
			event.preventDefault();
			var text = $( "#atk-poi-paste-text" ).value;
			if ( ! text.trim() ) {
				$( "#atk-poi-import-error" ).textContent = "Paste at least one row first.";
				return;
			}
			if ( text.length > 400000 ) {
				$( "#atk-poi-import-error" ).textContent = "That is too much text to paste. Import the file instead.";
				return;
			}
			startReview( { text: text } );
		} );
		var review = $( "#atk-poi-review-modal" );
		review.addEventListener( "click", onImportClick );
		review.addEventListener( "change", function ( event ) {
			var act = event.target.getAttribute( "data-act" );
			if ( act === "only-attention" ) {
				imp.onlyAttention = event.target.checked;
				renderReview();
			} else if ( act === "create-ward" ) {
				imp.skipWard[ event.target.getAttribute( "data-key" ) ] = ! event.target.checked;
				renderReview();
			}
		} );
		review.addEventListener( "input", function ( event ) {
			if ( event.target.getAttribute( "data-act" ) === "street-input" ) {
				imp.overrides[ event.target.getAttribute( "data-line" ) ] = event.target.value;
			}
		} );
	}

	/* ---------- events ---------- */

	function onClick( event ) {
		var target = event.target.closest( "[data-act]" );
		if ( ! target || ! root.contains( target ) ) {
			return;
		}
		var act = target.getAttribute( "data-act" );
		var id = parseInt( target.getAttribute( "data-id" ), 10 ) || 0;
		if ( act === "add" ) {
			openEdit( null );
		} else if ( act === "edit" ) {
			var place = findPlace( id );
			if ( place ) {
				openEdit( place );
			}
		} else if ( act === "delete" ) {
			var doomed = findPlace( id );
			if ( doomed ) {
				openDelete( doomed );
			}
		} else if ( act === "import" ) {
			openImport();
		} else if ( act === "pin" ) {
			var pinned = findPlace( id );
			if ( pinned ) {
				openPinPicker( pinned );
			}
		} else if ( act === "lookup-missing" ) {
			lookupPins( placesWithoutPin() );
		} else if ( act === "lookup-stop" ) {
			look.stop = true;
		}
	}

	function init() {
		buildShell();
		root.addEventListener( "click", onClick );
		$( "#atk-poi-edit-form" ).addEventListener( "submit", saveEdit );
		$( "#atk-poi-delete-confirm" ).addEventListener( "click", confirmDelete );
		bindImport();
		$( "#atk-poi-pin-save" ).addEventListener( "click", savePin );
		$( "#atk-poi-pin-search" ).addEventListener( "submit", runPinSearch );
		$( "#atk-poi-pin-results" ).addEventListener( "click", function ( event ) {
			var item = event.target.closest( "[data-result]" );
			if ( item ) {
				choosePinResult( parseInt( item.getAttribute( "data-result" ), 10 ) );
			}
		} );
		$( "#atk-poi-search" ).addEventListener( "input", function ( event ) {
			state.search = event.target.value;
			renderList();
		} );
		$( "#atk-poi-ward-filter" ).addEventListener( "change", function ( event ) {
			state.ward = event.target.value;
			renderList();
		} );
		$( "#atk-poi-flat" ).addEventListener( "change", function ( event ) {
			state.flat = event.target.checked;
			renderList();
		} );

		window.ATK_POI_ADMIN_UI = window.ATK_POI_ADMIN_UI || {};
		window.ATK_POI_ADMIN_UI.reload = reload;
		window.ATK_POI_ADMIN_UI.escapeHtml = esc;
		window.ATK_POI_ADMIN_UI.openPinPicker = openPinPicker;
		window.ATK_POI_ADMIN_UI.lookupPins = lookupPins;
		window.ATK_POI_ADMIN_UI.afterCommit = function ( result ) {
			if ( result && result.lookups && result.lookups.length ) {
				lookupPins( result.lookups );
			}
		};
		window.ATK_POI_ADMIN_UI.openImport = openImport;
		window.ATK_POI_ADMIN_UI.flash = flash;
		window.ATK_POI_ADMIN_UI.fail = fail;
		window.ATK_POI_ADMIN_UI.api = api;

		reload().catch( function ( error ) {
			root.insertAdjacentHTML( "afterbegin", "<div class=\"alert alert-danger p-5 mb-6\" role=\"alert\">" + esc( error && error.message ? error.message : "The places could not be loaded." ) + "</div>" );
		} );
	}

	init();
}() );
