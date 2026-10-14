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

	function row( item, withWard ) {
		var p = item.place;
		var closed = p.status === "closed" ? " " + badge( "Closed", "danger" ) : "";
		var street = p.street_answer ?
			"<span class=\"text-gray-800\">" + esc( p.street_answer ) + "</span>" :
			badge( "Needs a street", "warning" );
		return "<tr>" +
			"<td class=\"akn-poi-name\"><div class=\"fw-bold text-gray-900\">" + esc( p.name ) + closed + "</div>" +
				"<div class=\"text-gray-600 fs-7 d-lg-none\">" + esc( p.address ) + "</div></td>" +
			"<td class=\"d-none d-lg-table-cell text-gray-700\">" + esc( p.address ) + "</td>" +
			"<td class=\"text-nowrap text-gray-700\">" + esc( p.postcode ) + "</td>" +
			"<td>" + street + "</td>" +
			( withWard ? "<td class=\"text-gray-700\">" + esc( item.ward ) + "</td>" : "" ) +
			"<td class=\"d-none d-xl-table-cell\">" + badge( p.category, "info" ) + "</td>" +
			"<td>" + pinBadge( p ) + "</td>" +
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

	function openEdit( place ) {
		state.editing = place || null;
		$( "#atk-poi-edit-title" ).textContent = place ? "Edit place" : "Add place";
		$( "#atk-poi-edit-error" ).textContent = "";
		fillSelects( place );
		$( "#atk-poi-f-name" ).value = place ? place.name : "";
		$( "#atk-poi-f-address" ).value = place ? place.address : "";
		$( "#atk-poi-f-postcode" ).value = place ? place.postcode : "";
		$( "#atk-poi-f-street" ).value = place ? place.street_answer : "";
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
		} else if ( act === "import" && window.ATK_POI_ADMIN_UI && typeof window.ATK_POI_ADMIN_UI.openImport === "function" ) {
			window.ATK_POI_ADMIN_UI.openImport();
		}
	}

	function init() {
		buildShell();
		root.addEventListener( "click", onClick );
		$( "#atk-poi-edit-form" ).addEventListener( "submit", saveEdit );
		$( "#atk-poi-delete-confirm" ).addEventListener( "click", confirmDelete );
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
		window.ATK_POI_ADMIN_UI.openPinPicker = window.ATK_POI_ADMIN_UI.openPinPicker || null;
		window.ATK_POI_ADMIN_UI.flash = flash;
		window.ATK_POI_ADMIN_UI.fail = fail;
		window.ATK_POI_ADMIN_UI.api = api;

		reload().catch( function ( error ) {
			root.insertAdjacentHTML( "afterbegin", "<div class=\"alert alert-danger p-5 mb-6\" role=\"alert\">" + esc( error && error.message ? error.message : "The places could not be loaded." ) + "</div>" );
		} );
	}

	init();
}() );
