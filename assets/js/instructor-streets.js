/**
 * Streets screen for the Instructor Dashboard (inc/streets.php).
 *
 * Plain JavaScript on top of Bootstrap (modals) and its component classes.
 * Reads ATK_STREETS: { restUrl, nonce, maxPairs }.
 *
 * Two views of one list:
 *   Alphabetical  the editable list (add, edit, remove, import)
 *   By ward       the same streets grouped under their wards, read only
 * Both draw from state.data, so a change made in Alphabetical shows in By ward at once.
 */
( function () {
	"use strict";

	var cfg = window.ATK_STREETS || {};
	var root = document.getElementById( "atk-streets-root" );
	if ( ! root || ! cfg.restUrl ) {
		return;
	}

	var MAX_PAIRS = cfg.maxPairs || 20;
	var MAX_SHEET_ROWS = 6000;
	var MAX_REVIEW_ROWS = 400;

	var STATUS = {
		ready: { label: "Ready", tone: "success" },
		needs_answer: { label: "Needs an answer", tone: "warning" },
		clarify: { label: "Needs clarification", tone: "danger" }
	};

	/** A link from the Course Builder (?tab=streets&q=Street name) opens the list already searched. */
	function initialSearch() {
		try {
			return new URLSearchParams( window.location.search ).get( "q" ) || "";
		} catch ( e ) {
			return "";
		}
	}

	var state = {
		data: null,
		view: "alphabetical",
		search: initialSearch(),
		status: "",
		ward: "",
		editing: null,
		removing: null
	};

	var imp = { rows: null, plan: null, addNew: false };

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
		return n.toLocaleString( "en-GB" ) + " " + ( n === 1 ? one : many );
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
		var tone = kind === "error" ? "alert-danger" : "alert-success";
		$( "#atk-streets-flash" ).innerHTML = message ? "<div class=\"alert " + tone + " d-flex align-items-center p-5 mb-6\" role=\"" + ( kind === "error" ? "alert" : "status" ) + "\">" + esc( message ) + "</div>" : "";
		$( "#atk-streets-status" ).textContent = message || "";
	}

	function badge( text, tone ) {
		return "<span class=\"badge badge-light-" + tone + " fs-7\">" + esc( text ) + "</span>";
	}

	var FLAG_LABEL = {
		joined_gap: "Joined across a gap in OpenStreetMap, check both ends",
		branched: "The street branches, check the two ends",
		loop: "The street loops back on itself",
		dead_end_from: "One end is a dead end",
		dead_end_to: "One end is a dead end",
		ambiguous_segments: "The name appears more than once nearby",
		snapped_end: "An end is not connected in OpenStreetMap",
		far_from_postcode: "Far from its postcode",
		single_end: "Only one end could be found"
	};

	/** The status, plus a Researched or Review mark for answers filled from the research file and not yet edited. */
	function statusBadge( street ) {
		var s = STATUS[ street.status ];
		var html = badge( s.label, s.tone );
		if ( street.research ) {
			var review = street.research.confidence === "low";
			var why = ( review ? "Less certain, check these answers. " : "Filled from OpenStreetMap research; check them. " ) +
				( street.research.flags || [] ).map( function ( f ) { return FLAG_LABEL[ f ] || f; } ).filter( function ( x, i, a ) { return a.indexOf( x ) === i; } ).join( ". " );
			html += " <span class=\"badge badge-light-" + ( review ? "warning" : "info" ) + " fs-7\" title=\"" + esc( why.trim() ) + "\">" + ( review ? "Review" : "Researched" ) + "</span>";
		}
		return html;
	}

	function wardName( id ) {
		var wards = state.data.wards;
		for ( var i = 0; i < wards.length; i++ ) {
			if ( wards[ i ].id === id ) {
				return wards[ i ].name;
			}
		}
		return "";
	}

	function recount() {
		var counts = { total: 0, ready: 0, needs_answer: 0, clarify: 0, no_ward: 0 };
		state.data.streets.forEach( function ( s ) {
			counts.total++;
			counts[ s.status ]++;
			if ( ! s.wards.length ) {
				counts.no_ward++;
			}
		} );
		state.data.counts = counts;
	}

	function matches( street ) {
		if ( state.status === "no_ward" ) {
			if ( street.wards.length ) {
				return false;
			}
		} else if ( state.status !== "" && street.status !== state.status ) {
			return false;
		}
		if ( state.ward !== "" ) {
			if ( state.ward === "0" ? street.wards.length : street.wards.indexOf( parseInt( state.ward, 10 ) ) === -1 ) {
				return false;
			}
		}
		var term = state.search.trim().toLowerCase();
		if ( ! term ) {
			return true;
		}
		var hay = [ street.name, street.postcodes.join( " " ), street.note, street.pairs.map( function ( p ) { return p[ 0 ] + " " + p[ 1 ]; } ).join( " " ) ].join( " " ).toLowerCase();
		return hay.indexOf( term ) !== -1;
	}

	/* ---------- shell ---------- */

	function buildShell() {
		root.innerHTML =
			"<div id=\"atk-streets-flash\"></div>" +
			"<div class=\"card card-flush\">" +
				"<div class=\"card-header pt-7 flex-wrap gap-3\">" +
					"<div class=\"card-title flex-column align-items-start\">" +
						"<h3 class=\"fw-bold text-gray-900 mb-1\">Streets</h3>" +
						"<div class=\"text-gray-600 fs-6\" id=\"atk-streets-summary\"></div>" +
					"</div>" +
					"<div class=\"card-toolbar flex-wrap gap-3\">" +
						"<button type=\"button\" class=\"btn btn-light-primary\" data-act=\"import\">Import spreadsheet</button>" +
						"<button type=\"button\" class=\"btn btn-primary\" data-act=\"add\">Add street</button>" +
					"</div>" +
				"</div>" +
				"<div class=\"card-body pt-4\">" +
					"<ul class=\"nav nav-tabs nav-line-tabs mb-6 fs-6\" role=\"tablist\">" +
						"<li class=\"nav-item\" role=\"presentation\"><button type=\"button\" class=\"nav-link\" role=\"tab\" data-act=\"view\" data-view=\"alphabetical\" id=\"atk-streets-tab-a\">Alphabetical</button></li>" +
						"<li class=\"nav-item\" role=\"presentation\"><button type=\"button\" class=\"nav-link\" role=\"tab\" data-act=\"view\" data-view=\"ward\" id=\"atk-streets-tab-w\">By ward</button></li>" +
					"</ul>" +
					"<div class=\"d-flex flex-wrap align-items-center gap-4 mb-5\">" +
						"<input id=\"atk-streets-search\" class=\"form-control form-control-solid akn-streets-filter\" type=\"search\" value=\"" + esc( state.search ) + "\" placeholder=\"Search streets, postcodes or answers\" aria-label=\"Search streets\">" +
						"<select id=\"atk-streets-status-filter\" class=\"form-select form-select-solid akn-streets-filter\" aria-label=\"Show streets by status\">" +
							"<option value=\"\">All streets</option>" +
							"<option value=\"ready\">Ready</option>" +
							"<option value=\"needs_answer\">Needs an answer</option>" +
							"<option value=\"clarify\">Needs clarification</option>" +
							"<option value=\"no_ward\">No ward</option>" +
						"</select>" +
						"<select id=\"atk-streets-ward-filter\" class=\"form-select form-select-solid akn-streets-filter\" aria-label=\"Show one ward\"></select>" +
					"</div>" +
					"<div id=\"atk-streets-list\"></div>" +
				"</div>" +
			"</div>" +
			"<p id=\"atk-streets-status\" class=\"visually-hidden\" role=\"status\" aria-live=\"polite\"></p>" +

			"<div class=\"modal fade\" id=\"atk-streets-edit-modal\" tabindex=\"-1\" aria-labelledby=\"atk-streets-edit-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg\"><div class=\"modal-content\">" +
					"<form id=\"atk-streets-edit-form\" novalidate>" +
						"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-streets-edit-title\">Add street</h3>" +
							"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
						"<div class=\"modal-body\">" +
							"<div class=\"row g-5\">" +
								"<div class=\"col-12\"><label class=\"form-label fw-semibold\" for=\"atk-streets-f-name\">Street</label>" +
									"<input class=\"form-control form-control-solid\" id=\"atk-streets-f-name\" maxlength=\"191\" required></div>" +
								"<div class=\"col-12\"><div class=\"form-label fw-semibold\" id=\"atk-streets-f-wards-label\">Wards</div>" +
									"<div class=\"form-text mt-0 mb-3\">Tick two wards for a street that crosses a boundary.</div>" +
									"<div id=\"atk-streets-f-wards\" class=\"akn-streets-wards\" role=\"group\" aria-labelledby=\"atk-streets-f-wards-label\"></div></div>" +
								"<div class=\"col-12\"><div class=\"form-label fw-semibold\" id=\"atk-streets-f-pairs-label\">Where the route runs: From and To</div>" +
									"<div class=\"form-text mt-0 mb-3\" id=\"atk-streets-f-pairs-help\">The driver gets a box, and a point, for each different From road and each different To road listed here, in either order. Add a pair to include another road, up to " + MAX_PAIRS + ".</div>" +
									"<div id=\"atk-streets-f-pairs\" role=\"group\" aria-labelledby=\"atk-streets-f-pairs-label\" aria-describedby=\"atk-streets-f-pairs-help\"></div>" +
									"<button type=\"button\" class=\"btn btn-sm btn-light-primary mt-3\" data-act=\"pair-add\" id=\"atk-streets-pair-add\">Add another pair</button></div>" +
								"<div class=\"col-12\"><div class=\"form-check form-switch form-check-custom form-check-solid\">" +
									"<input class=\"form-check-input\" type=\"checkbox\" id=\"atk-streets-f-flag\">" +
									"<label class=\"form-check-label fw-semibold\" for=\"atk-streets-f-flag\">Needs clarification</label></div>" +
									"<div class=\"form-text\">A street marked this way is kept on the list but left out of the Official Route test until you clear it.</div></div>" +
								"<div class=\"col-md-6\"><label class=\"form-label fw-semibold\" for=\"atk-streets-f-postcodes\">Postcodes</label>" +
									"<input class=\"form-control form-control-solid\" id=\"atk-streets-f-postcodes\" placeholder=\"AB10 1AA, AB10 1AB\" autocomplete=\"off\"></div>" +
								"<div class=\"col-md-6\"><label class=\"form-label fw-semibold\" for=\"atk-streets-f-note\">Note</label>" +
									"<textarea class=\"form-control form-control-solid\" id=\"atk-streets-f-note\" rows=\"2\"></textarea></div>" +
							"</div>" +
							"<div id=\"atk-streets-edit-error\" class=\"text-danger fs-6 mt-4\" role=\"alert\"></div>" +
						"</div>" +
						"<div class=\"modal-footer\">" +
							"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
							"<button type=\"submit\" class=\"btn btn-primary\" id=\"atk-streets-edit-save\">Save street</button>" +
						"</div>" +
					"</form>" +
				"</div></div>" +
			"</div>" +

			"<div class=\"modal fade\" id=\"atk-streets-remove-modal\" tabindex=\"-1\" aria-labelledby=\"atk-streets-remove-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered\"><div class=\"modal-content\">" +
					"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-streets-remove-title\">Remove this street?</h3>" +
						"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
					"<div class=\"modal-body\"><p class=\"mb-0 text-gray-700\" id=\"atk-streets-remove-text\"></p></div>" +
					"<div class=\"modal-footer\">" +
						"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Keep it</button>" +
						"<button type=\"button\" class=\"btn btn-danger\" id=\"atk-streets-remove-confirm\">Remove street</button>" +
					"</div>" +
				"</div></div>" +
			"</div>" +

			"<input id=\"atk-streets-file\" class=\"d-none\" type=\"file\" accept=\".xlsx,.xls,.csv\" aria-label=\"Choose a spreadsheet file\">" +

			"<div class=\"modal fade\" id=\"atk-streets-import-modal\" tabindex=\"-1\" aria-labelledby=\"atk-streets-import-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-lg\"><div class=\"modal-content\">" +
					"<form id=\"atk-streets-paste-form\">" +
						"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-streets-import-title\">Import streets</h3>" +
							"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
						"<div class=\"modal-body\">" +
							"<div id=\"atk-streets-drop\" class=\"akn-poi-drop text-center p-10 mb-6\" tabindex=\"0\" role=\"button\" aria-label=\"Choose a spreadsheet file\">" +
								"<div class=\"fw-bold fs-5 text-gray-900 mb-1\">Drop a .xlsx or .csv file here</div>" +
								"<div class=\"text-gray-600 fs-6\">or click to choose one. The first sheet is read. Use the columns Road, Ward, Post Code, Address, Note, Clarify, From and To. Only Road is needed.</div>" +
							"</div>" +
							"<label class=\"form-label fw-semibold\" for=\"atk-streets-paste-text\">Or paste rows copied from a spreadsheet</label>" +
							"<textarea id=\"atk-streets-paste-text\" class=\"form-control form-control-solid\" rows=\"6\" spellcheck=\"false\"></textarea>" +
							"<div class=\"form-text\">A street on several rows gets every From and To pair. Blank cells never erase what is saved. Any text in Clarify marks a street, and \"no\" clears it.</div>" +
							"<div id=\"atk-streets-import-error\" class=\"text-danger fs-6 mt-3\" role=\"alert\"></div>" +
						"</div>" +
						"<div class=\"modal-footer\">" +
							"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
							"<button type=\"submit\" class=\"btn btn-primary\">Check pasted rows</button>" +
						"</div>" +
					"</form>" +
				"</div></div>" +
			"</div>" +

			"<div class=\"modal fade\" id=\"atk-streets-review-modal\" tabindex=\"-1\" aria-labelledby=\"atk-streets-review-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl\"><div class=\"modal-content\" id=\"atk-streets-review-content\"></div></div>" +
			"</div>";
	}

	/* ---------- lists ---------- */

	function answersCell( street ) {
		if ( ! street.pairs.length ) {
			return "<span class=\"text-gray-500\">None yet</span>";
		}
		if ( window.ATK_ROUTE_PAIRS ) {
			// One answer per road at each end: the driver gets a field, and a point, for each.
			var e = window.ATK_ROUTE_PAIRS.ends( street.pairs );
			return "<div class=\"akn-streets-pair\"><span class=\"text-gray-500\">From</span> " + esc( e.from.join( ", " ) ) + "</div>" +
				"<div class=\"akn-streets-pair\"><span class=\"text-gray-500\">To</span> " + esc( e.to.join( ", " ) ) + "</div>";
		}
		var shown = street.pairs.slice( 0, 2 ).map( function ( p ) {
			return "<div class=\"akn-streets-pair\">" + esc( p[ 0 ] ) + " <span class=\"text-gray-500\">to</span> " + esc( p[ 1 ] ) + "</div>";
		} ).join( "" );
		var more = street.pairs.length > 2 ? "<div class=\"text-gray-600 fs-7\">+" + ( street.pairs.length - 2 ) + " more</div>" : "";
		return shown + more;
	}

	function wardsCell( street ) {
		if ( ! street.wards.length ) {
			return "<span class=\"text-gray-500\">No ward</span>";
		}
		return street.wards.map( function ( id ) {
			return "<div class=\"text-gray-800\">" + esc( wardName( id ) ) + "</div>";
		} ).join( "" );
	}

	function editableRow( street ) {
		return "<tr>" +
			"<td class=\"akn-streets-name\"><div class=\"fw-bold text-gray-900\">" + esc( street.name ) + "</div>" +
				( street.postcodes.length ? "<div class=\"text-gray-600 fs-7\">" + esc( street.postcodes.join( ", " ) ) + "</div>" : "" ) + "</td>" +
			"<td>" + wardsCell( street ) + "</td>" +
			"<td>" + answersCell( street ) + "</td>" +
			"<td>" + statusBadge( street ) + "</td>" +
			"<td class=\"akn-streets-actions text-end text-nowrap\">" +
				"<button type=\"button\" class=\"btn btn-sm btn-light-primary me-2\" data-act=\"edit\" data-id=\"" + street.id + "\" aria-label=\"Edit " + esc( street.name ) + "\">Edit</button>" +
				"<button type=\"button\" class=\"btn btn-sm btn-light-danger\" data-act=\"remove\" data-id=\"" + street.id + "\" aria-label=\"Remove " + esc( street.name ) + "\">Remove</button>" +
			"</td>" +
		"</tr>";
	}

	function readOnlyRow( street, here ) {
		var also = street.wards.filter( function ( id ) { return id !== here; } ).map( wardName );
		return "<tr>" +
			"<td class=\"akn-streets-name\"><div class=\"fw-bold text-gray-900\">" + esc( street.name ) + "</div>" +
				( also.length ? "<div class=\"text-gray-600 fs-7\">Also in " + esc( also.join( " and " ) ) + "</div>" : "" ) + "</td>" +
			"<td class=\"text-gray-700\">" + esc( street.postcodes.join( ", " ) ) + "</td>" +
			"<td>" + answersCell( street ) + "</td>" +
			"<td>" + statusBadge( street ) + "</td>" +
		"</tr>";
	}

	function azLetter( name ) {
		var c = name.charAt( 0 ).toUpperCase();
		return /[A-Z]/.test( c ) ? c : "#";
	}

	function renderAlphabetical( items ) {
		var letters = {};
		items.forEach( function ( s ) { letters[ azLetter( s.name ) ] = true; } );
		var jump = "<nav class=\"akn-streets-jump d-flex flex-wrap gap-1 mb-5\" aria-label=\"Jump to a letter\">" +
			"ABCDEFGHIJKLMNOPQRSTUVWXYZ".split( "" ).concat( letters[ "#" ] ? [ "#" ] : [] ).map( function ( l ) {
				return letters[ l ] ?
					"<a class=\"btn btn-sm btn-icon btn-light-primary\" href=\"#atk-az-" + ( l === "#" ? "other" : l ) + "\" data-act=\"jump\" data-letter=\"" + l + "\">" + l + "</a>" :
					"<span class=\"btn btn-sm btn-icon btn-light disabled\" aria-hidden=\"true\">" + l + "</span>";
			} ).join( "" ) + "</nav>";

		var body = "";
		var last = "";
		items.forEach( function ( s ) {
			var letter = azLetter( s.name );
			if ( letter !== last ) {
				last = letter;
				body += "<tr class=\"akn-streets-letter\" id=\"atk-az-" + ( letter === "#" ? "other" : letter ) + "\"><th colspan=\"5\" scope=\"colgroup\">" + letter + "</th></tr>";
			}
			body += editableRow( s );
		} );

		return jump + "<div class=\"table-responsive\"><table class=\"table akn-streets-table align-middle table-row-dashed fs-6 gy-4 mb-0\">" +
			"<colgroup><col style=\"width:27%\"><col style=\"width:22%\"><col style=\"width:27%\"><col style=\"width:12%\"><col style=\"width:12%\"></colgroup>" +
			"<thead><tr class=\"text-start text-gray-600 fw-bold fs-7 gs-0\"><th>Street</th><th>Wards</th><th>From and To</th><th>Status</th><th class=\"text-end\"><span class=\"visually-hidden\">Actions</span></th></tr></thead>" +
			"<tbody>" + body + "</tbody></table></div>";
	}

	function renderByWard( items ) {
		var html = "<p class=\"text-gray-600 fs-6 mb-6\">Read only. Add a street or change its wards under Alphabetical and it moves here.</p>";
		var groups = state.data.wards.map( function ( w ) {
			return { id: w.id, name: w.name, official: w.official, streets: [] };
		} );
		groups.push( { id: 0, name: "No ward yet", official: 0, streets: [] } );
		var byId = {};
		groups.forEach( function ( g ) { byId[ g.id ] = g; } );
		items.forEach( function ( s ) {
			if ( ! s.wards.length ) {
				byId[ 0 ].streets.push( s );
			}
			s.wards.forEach( function ( id ) {
				if ( byId[ id ] ) {
					byId[ id ].streets.push( s );
				}
			} );
		} );
		var shown = 0;
		groups.forEach( function ( g ) {
			if ( ! g.streets.length ) {
				return;
			}
			shown++;
			var ready = g.streets.filter( function ( s ) { return s.status === "ready"; } ).length;
			html +=
				"<section class=\"mb-10\" aria-label=\"" + esc( g.name ) + "\">" +
					"<div class=\"akn-poi-ward-head d-flex flex-wrap align-items-center gap-3 mb-3\">" +
						"<h4 class=\"fw-bold text-gray-900 mb-0\">" + ( g.official ? esc( g.official + ". " ) : "" ) + esc( g.name ) + "</h4>" +
						badge( plural( g.streets.length, "street", "streets" ), "primary" ) +
						badge( ready + " ready", ready ? "success" : "secondary" ) +
					"</div>" +
					"<div class=\"table-responsive\"><table class=\"table akn-streets-table akn-streets-readonly align-middle table-row-dashed fs-6 gy-3 mb-0\">" +
						"<colgroup><col style=\"width:34%\"><col style=\"width:18%\"><col style=\"width:32%\"><col style=\"width:16%\"></colgroup>" +
						"<thead><tr class=\"text-start text-gray-600 fw-bold fs-7 gs-0\"><th>Street</th><th>Postcodes</th><th>From and To</th><th>Status</th></tr></thead><tbody>" +
						g.streets.map( function ( s ) { return readOnlyRow( s, g.id ); } ).join( "" ) +
					"</tbody></table></div>" +
				"</section>";
		} );
		return shown ? html : html + "<p class=\"text-gray-600 fs-6 mb-0\">No street matches those filters.</p>";
	}

	function renderSummary() {
		var c = state.data.counts;
		var parts = [ plural( c.total, "street", "streets" ) ];
		if ( c.total ) {
			parts.push( c.ready.toLocaleString( "en-GB" ) + " ready" );
			parts.push( c.needs_answer.toLocaleString( "en-GB" ) + " need an answer" );
			if ( c.clarify ) {
				parts.push( c.clarify + " need clarification" );
			}
			if ( c.no_ward ) {
				parts.push( c.no_ward + " with no ward" );
			}
		}
		$( "#atk-streets-summary" ).textContent = parts.join( ", " );

		var wards = [ "<option value=\"\">All wards</option>" ].concat( state.data.wards.map( function ( w ) {
			return "<option value=\"" + w.id + "\">" + esc( ( w.official ? w.official + ". " : "" ) + w.name ) + "</option>";
		} ) ).concat( [ "<option value=\"0\">No ward yet</option>" ] );
		var select = $( "#atk-streets-ward-filter" );
		select.innerHTML = wards.join( "" );
		select.value = state.ward;
		if ( select.value !== state.ward ) {
			state.ward = "";
			select.value = "";
		}
		$( "#atk-streets-status-filter" ).value = state.status;

		[ [ "a", "alphabetical" ], [ "w", "ward" ] ].forEach( function ( pair ) {
			var tab = $( "#atk-streets-tab-" + pair[ 0 ] );
			var on = state.view === pair[ 1 ];
			tab.classList.toggle( "active", on );
			tab.setAttribute( "aria-selected", on ? "true" : "false" );
		} );
	}

	function renderList() {
		renderSummary();
		var box = $( "#atk-streets-list" );
		if ( ! state.data.counts.total ) {
			box.innerHTML =
				"<div class=\"d-flex flex-column align-items-center text-center py-15\">" +
					"<h3 class=\"fw-bold text-gray-900\">No streets on the list yet</h3>" +
					"<p class=\"text-gray-600 fs-6 mw-450px\">Import the street list and each street becomes a question in the Official Route test once it has a From and To answer. You can also add streets one at a time.</p>" +
					"<div class=\"d-flex gap-3\"><button type=\"button\" class=\"btn btn-primary\" data-act=\"import\">Import spreadsheet</button>" +
					"<button type=\"button\" class=\"btn btn-light-primary\" data-act=\"add\">Add street</button></div>" +
				"</div>";
			return;
		}
		var items = state.data.streets.filter( matches );
		if ( ! items.length ) {
			box.innerHTML = "<p class=\"text-gray-600 fs-6 mb-0\">No street matches those filters.</p>";
			return;
		}
		box.innerHTML = state.view === "ward" ? renderByWard( items ) : renderAlphabetical( items );
	}

	/* ---------- loading ---------- */

	function reload() {
		return api( "streets" ).then( function ( data ) {
			state.data = data;
			renderList();
		} );
	}

	function findStreet( id ) {
		var list = state.data.streets;
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].id === id ) {
				return list[ i ];
			}
		}
		return null;
	}

	/* A saved street replaces its old copy (or joins the list) without fetching everything again. */
	function putStreet( saved ) {
		var list = state.data.streets;
		var at = -1;
		list.forEach( function ( s, i ) { if ( s.id === saved.id ) { at = i; } } );
		if ( at === -1 ) {
			list.push( saved );
		} else {
			list[ at ] = saved;
		}
		list.sort( function ( a, b ) {
			return a.name.localeCompare( b.name, "en-GB", { numeric: true, sensitivity: "base" } );
		} );
		recount();
		renderList();
	}

	/* ---------- add and edit ---------- */

	function pairRow( from, to ) {
		return "<div class=\"akn-streets-pair-row d-flex align-items-center gap-3 mb-3\">" +
			"<input class=\"form-control form-control-solid\" data-pair=\"from\" maxlength=\"120\" value=\"" + esc( from ) + "\" placeholder=\"From\" aria-label=\"From\" autocomplete=\"off\">" +
			"<span class=\"text-gray-500\" aria-hidden=\"true\">to</span>" +
			"<input class=\"form-control form-control-solid\" data-pair=\"to\" maxlength=\"120\" value=\"" + esc( to ) + "\" placeholder=\"To\" aria-label=\"To\" autocomplete=\"off\">" +
			"<button type=\"button\" class=\"btn btn-sm btn-icon btn-light-danger\" data-act=\"pair-remove\" aria-label=\"Remove this pair\">&times;</button>" +
		"</div>";
	}

	function pairBox() {
		return $( "#atk-streets-f-pairs" );
	}

	function syncPairButton() {
		$( "#atk-streets-pair-add" ).disabled = pairBox().children.length >= MAX_PAIRS;
	}

	function addPairRow( from, to, focus ) {
		pairBox().insertAdjacentHTML( "beforeend", pairRow( from || "", to || "" ) );
		syncPairButton();
		if ( focus ) {
			pairBox().lastElementChild.querySelector( "input" ).focus();
		}
	}

	function readPairs() {
		return Array.prototype.map.call( pairBox().children, function ( row ) {
			return [ row.querySelector( "[data-pair=from]" ).value, row.querySelector( "[data-pair=to]" ).value ];
		} );
	}

	function openEdit( street ) {
		state.editing = street || null;
		$( "#atk-streets-edit-title" ).textContent = street ? "Edit street" : "Add street";
		$( "#atk-streets-edit-error" ).textContent = "";
		$( "#atk-streets-f-name" ).value = street ? street.name : "";
		$( "#atk-streets-f-postcodes" ).value = street ? street.postcodes.join( ", " ) : "";
		$( "#atk-streets-f-note" ).value = street ? street.note : "";
		$( "#atk-streets-f-flag" ).checked = street ? street.flagged : false;
		$( "#atk-streets-f-wards" ).innerHTML = state.data.wards.length ? state.data.wards.map( function ( w ) {
			var on = street && street.wards.indexOf( w.id ) !== -1;
			return "<div class=\"form-check form-check-custom form-check-solid\">" +
				"<input class=\"form-check-input\" type=\"checkbox\" value=\"" + w.id + "\" id=\"atk-streets-ward-" + w.id + "\"" + ( on ? " checked" : "" ) + ">" +
				"<label class=\"form-check-label\" for=\"atk-streets-ward-" + w.id + "\">" + esc( ( w.official ? w.official + ". " : "" ) + w.name ) + "</label></div>";
		} ).join( "" ) : "<div class=\"text-gray-600 fs-6\">No wards yet. Add them on the Neighbourhoods tab.</div>";
		pairBox().innerHTML = "";
		( street && street.pairs.length ? street.pairs : [ [ "", "" ] ] ).forEach( function ( p ) { addPairRow( p[ 0 ], p[ 1 ], false ); } );
		modal( "atk-streets-edit-modal" ).show();
	}

	function saveEdit( event ) {
		event.preventDefault();
		var name = $( "#atk-streets-f-name" ).value.trim();
		if ( ! name ) {
			$( "#atk-streets-edit-error" ).textContent = "Give the street a name.";
			$( "#atk-streets-f-name" ).focus();
			return;
		}
		var half = readPairs().filter( function ( p ) { return ( p[ 0 ].trim() !== "" ) !== ( p[ 1 ].trim() !== "" ); } );
		if ( half.length ) {
			$( "#atk-streets-edit-error" ).textContent = "A pair needs both a From and a To. Fill in both boxes or remove the pair.";
			return;
		}
		var body = {
			name: name,
			wards: Array.prototype.map.call( document.querySelectorAll( "#atk-streets-f-wards input:checked" ), function ( box ) { return parseInt( box.value, 10 ); } ),
			pairs: readPairs(),
			flagged: $( "#atk-streets-f-flag" ).checked,
			postcodes: $( "#atk-streets-f-postcodes" ).value,
			note: $( "#atk-streets-f-note" ).value
		};
		var editing = state.editing;
		var button = $( "#atk-streets-edit-save" );
		button.disabled = true;
		api( editing ? "streets/" + editing.id : "streets", { method: "POST", body: body } ).then( function ( saved ) {
			modal( "atk-streets-edit-modal" ).hide();
			putStreet( saved );
			flash( ( editing ? "Saved " : "Added " ) + saved.name + ".", "ok" );
		} ).catch( function ( error ) {
			$( "#atk-streets-edit-error" ).textContent = error && error.message ? error.message : "That street could not be saved.";
		} ).then( function () {
			button.disabled = false;
		} );
	}

	/* ---------- remove ---------- */

	function openRemove( street ) {
		state.removing = street;
		$( "#atk-streets-remove-text" ).textContent = street.name + " comes off this list. Its wards, From and To answers and clarification mark are cleared, and it is no longer asked in the Official Route test.";
		modal( "atk-streets-remove-modal" ).show();
	}

	function confirmRemove() {
		var street = state.removing;
		if ( ! street ) {
			return;
		}
		var button = $( "#atk-streets-remove-confirm" );
		button.disabled = true;
		api( "streets/" + street.id, { method: "DELETE" } ).then( function () {
			modal( "atk-streets-remove-modal" ).hide();
			state.data.streets = state.data.streets.filter( function ( s ) { return s.id !== street.id; } );
			recount();
			renderList();
			flash( "Removed " + street.name + ".", "ok" );
		} ).catch( function ( error ) {
			modal( "atk-streets-remove-modal" ).hide();
			flash( error && error.message ? error.message : "That street could not be removed.", "error" );
		} ).then( function () {
			button.disabled = false;
		} );
	}

	/* ---------- import ---------- */

	function importError( message ) {
		$( "#atk-streets-import-error" ).textContent = message || "";
	}

	function swapModal( from, to ) {
		var fromEl = document.getElementById( from );
		fromEl.addEventListener( "hidden.bs.modal", function show() {
			fromEl.removeEventListener( "hidden.bs.modal", show );
			modal( to ).show();
		} );
		modal( from ).hide();
	}

	function requestBody() {
		return imp.rows ? { rows: imp.rows } : { text: imp.text || "" };
	}

	function startReview( source ) {
		imp.rows = source.rows || null;
		imp.text = source.text || "";
		imp.addNew = false;
		importError( "" );
		api( "streets/import/preview", { method: "POST", body: requestBody() } ).then( function ( plan ) {
			if ( ! plan.summary.rows ) {
				throw new Error( "No streets found. Check that the first row holds the column names, starting with Road." );
			}
			imp.plan = plan;
			renderReview();
			swapModal( "atk-streets-import-modal", "atk-streets-review-modal" );
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
				importError( "That sheet has " + rows.length + " rows. The limit is " + MAX_SHEET_ROWS + " streets at a time, so split it into parts and import each one." );
				return;
			}
			startReview( { rows: rows } );
		} ).catch( function () {
			importError( "That file could not be read. Save it as .xlsx or .csv and try again." );
		} );
	}

	function renderReview() {
		var plan = imp.plan;
		var s = plan.summary;
		var saving = s.changed + ( imp.addNew ? s.new : 0 );
		var shown = plan.rows.slice( 0, MAX_REVIEW_ROWS );
		var body = shown.map( function ( r ) {
			var result = r.changes.map( function ( c ) { return badge( c, c === "new" ? "success" : "primary" ); } ).join( " " );
			var issues = r.issues.map( function ( i ) { return "<div class=\"fs-7 text-warning mt-1\">" + esc( i ) + "</div>"; } ).join( "" );
			return "<tr><td><div class=\"fw-bold text-gray-900\">" + esc( r.road ) + "</div><div class=\"fs-7 text-gray-600\">Row " + r.line + "</div></td>" +
				"<td>" + ( result || badge( "No change", "secondary" ) ) + issues + "</td></tr>";
		} ).join( "" );
		var clip = plan.rows.length >= MAX_REVIEW_ROWS ? "<p class=\"fs-7 text-gray-600 mt-3 mb-0\">Showing the first " + MAX_REVIEW_ROWS + " rows with a change or a problem. Every changed row is saved, not just the ones shown.</p>" : "";

		var newBox = "";
		if ( s.new ) {
			var names = plan.new_names.slice( 0, 12 ).map( esc ).join( ", " ) + ( plan.new_names.length > 12 ? " and " + ( plan.new_names.length - 12 ) + " more" : "" );
			newBox =
				"<div class=\"bg-light rounded p-5 mb-5\">" +
					"<div class=\"form-check form-check-custom form-check-solid\">" +
						"<input class=\"form-check-input\" type=\"checkbox\" id=\"atk-streets-add-new\" data-act=\"add-new\"" + ( imp.addNew ? " checked" : "" ) + ">" +
						"<label class=\"form-check-label fw-semibold\" for=\"atk-streets-add-new\">Add " + esc( plural( s.new, "street that is not on the site yet", "streets that are not on the site yet" ) ) + "</label>" +
					"</div>" +
					"<div class=\"fs-7 text-gray-700 mt-2\">" + names + ". Streets are only added when this is ticked. A close spelling of a street already on the site is not matched for you, so check these are really new.</div>" +
				"</div>";
		}
		var wards = s.unknown_wards.length ? "<p class=\"fs-6 text-gray-700 mb-5\">These wards are not on the site, so they were skipped: " + esc( s.unknown_wards.join( ", " ) ) + ". Add them on the Neighbourhoods tab and import again.</p>" : "";

		$( "#atk-streets-review-content" ).innerHTML =
			"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-streets-review-title\">Review before saving</h3>" +
				"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
			"<div class=\"modal-body\">" +
				"<div class=\"d-flex flex-wrap gap-2 mb-5\">" +
					badge( plural( s.rows, "row", "rows" ), "secondary" ) +
					badge( plural( s.changed, "street to update", "streets to update" ), "primary" ) +
					badge( plural( s.new, "new street", "new streets" ), s.new ? "success" : "secondary" ) +
					badge( plural( s.pairs_added, "answer added", "answers added" ), s.pairs_added ? "primary" : "secondary" ) +
					badge( s.flagged + " marked for clarification", s.flagged ? "danger" : "secondary" ) +
					badge( s.same + " unchanged", "secondary" ) +
				"</div>" +
				newBox + wards +
				( shown.length ? "<div class=\"table-responsive\"><table class=\"table akn-streets-review align-middle table-row-dashed fs-6 gy-3 mb-0\"><thead><tr class=\"text-start text-gray-600 fw-bold fs-7 gs-0\"><th>Street</th><th>What changes</th></tr></thead><tbody>" + body + "</tbody></table></div>" + clip : "<p class=\"text-gray-700 fs-6 mb-0\">Nothing in this sheet changes what is saved.</p>" ) +
			"</div>" +
			"<div class=\"modal-footer\">" +
				"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
				"<button type=\"button\" class=\"btn btn-primary\" data-act=\"commit\"" + ( saving ? "" : " disabled" ) + ">Save " + esc( plural( saving, "street", "streets" ) ) + "</button>" +
			"</div>";
	}

	function commitImport() {
		var button = document.querySelector( "#atk-streets-review-content [data-act=\"commit\"]" );
		button.disabled = true;
		button.textContent = "Saving";
		var body = requestBody();
		body.add_new = imp.addNew;
		api( "streets/import/commit", { method: "POST", body: body } ).then( function ( result ) {
			modal( "atk-streets-review-modal" ).hide();
			var text = "Saved " + plural( result.saved, "street", "streets" ) + ".";
			if ( result.left_out ) {
				text += " " + plural( result.left_out, "new street was", "new streets were" ) + " left out.";
			}
			if ( result.failed ) {
				text += " " + plural( result.failed, "street", "streets" ) + " could not be saved.";
			}
			return reload().then( function () {
				flash( text, result.failed ? "error" : "ok" );
			} );
		} ).catch( function ( error ) {
			modal( "atk-streets-review-modal" ).hide();
			flash( error && error.message ? error.message : "That import could not be saved.", "error" );
		} );
	}

	/* ---------- events ---------- */

	function debounce( fn, ms ) {
		var timer = null;
		return function () {
			var args = arguments;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () { fn.apply( null, args ); }, ms );
		};
	}

	function onClick( event ) {
		var target = event.target.closest( "[data-act]" );
		if ( ! target ) {
			return;
		}
		var act = target.getAttribute( "data-act" );
		var id = parseInt( target.getAttribute( "data-id" ), 10 );
		if ( act === "view" ) {
			state.view = target.getAttribute( "data-view" );
			renderList();
		} else if ( act === "add" ) {
			openEdit( null );
		} else if ( act === "edit" ) {
			openEdit( findStreet( id ) );
		} else if ( act === "remove" ) {
			openRemove( findStreet( id ) );
		} else if ( act === "pair-add" ) {
			addPairRow( "", "", true );
		} else if ( act === "pair-remove" ) {
			var row = target.closest( ".akn-streets-pair-row" );
			row.parentNode.removeChild( row );
			if ( ! pairBox().children.length ) {
				addPairRow( "", "", false );
			}
			syncPairButton();
		} else if ( act === "import" ) {
			importError( "" );
			$( "#atk-streets-paste-text" ).value = "";
			modal( "atk-streets-import-modal" ).show();
		} else if ( act === "commit" ) {
			commitImport();
		} else if ( act === "jump" ) {
			event.preventDefault();
			var anchor = document.getElementById( "atk-az-" + ( target.getAttribute( "data-letter" ) === "#" ? "other" : target.getAttribute( "data-letter" ) ) );
			if ( anchor ) {
				anchor.scrollIntoView( { behavior: "instant", block: "start" } );
			}
		}
	}

	function bind() {
		root.addEventListener( "click", onClick );
		root.addEventListener( "change", function ( event ) {
			if ( event.target.id === "atk-streets-status-filter" ) {
				state.status = event.target.value;
				renderList();
			} else if ( event.target.id === "atk-streets-ward-filter" ) {
				state.ward = event.target.value;
				renderList();
			} else if ( event.target.getAttribute( "data-act" ) === "add-new" ) {
				imp.addNew = event.target.checked;
				renderReview();
			}
		} );
		$( "#atk-streets-search" ).addEventListener( "input", debounce( function ( event ) {
			state.search = event.target.value;
			renderList();
		}, 150 ) );
		$( "#atk-streets-edit-form" ).addEventListener( "submit", saveEdit );
		$( "#atk-streets-remove-confirm" ).addEventListener( "click", confirmRemove );

		var drop = $( "#atk-streets-drop" );
		var file = $( "#atk-streets-file" );
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
			drop.addEventListener( name, function () { drop.classList.remove( "akn-poi-drop-active" ); } );
		} );
		drop.addEventListener( "drop", function ( event ) {
			event.preventDefault();
			if ( event.dataTransfer.files.length ) {
				readFile( event.dataTransfer.files[ 0 ] );
			}
		} );
		file.addEventListener( "change", function () {
			if ( file.files.length ) {
				readFile( file.files[ 0 ] );
				file.value = "";
			}
		} );
		$( "#atk-streets-paste-form" ).addEventListener( "submit", function ( event ) {
			event.preventDefault();
			var text = $( "#atk-streets-paste-text" ).value;
			if ( ! text.trim() ) {
				importError( "Paste some rows first, or choose a file." );
				return;
			}
			startReview( { text: text } );
		} );
	}

	/* ---------- start ---------- */

	buildShell();
	bind();
	api( "streets" ).then( function ( data ) {
		state.data = data;
		renderList();
	} ).catch( function ( error ) {
		$( "#atk-streets-list" ).innerHTML = "<div class=\"alert alert-danger p-5 mb-0\" role=\"alert\">" + esc( error && error.message ? error.message : "The streets could not be loaded." ) + "</div>";
	} );
}() );
