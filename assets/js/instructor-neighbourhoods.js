/**
 * Neighbourhoods tab for the Instructor Dashboard (inc/neighbourhoods.php).
 *
 * Plain JavaScript on top of Bootstrap (modals) and its component
 * classes. Spreadsheet files are read in the browser with SheetJS.
 * Reads ATK_NB: { restUrl, nonce }.
 */
( function () {
	"use strict";

	var cfg = window.ATK_NB || {};
	var root = document.getElementById( "atk-nb-root" );
	if ( ! root || ! cfg.restUrl ) {
		return;
	}

	var MAX_REVIEW_ROWS = 500;
	var state = {
		list: [],
		currentId: 0,
		streets: [],
		loading: false,
		search: "",
		payload: null,
		choices: {},
		additions: {},
		plan: null,
		onlyAttention: false
	};

	/* ---------- helpers ---------- */

	function esc( value ) {
		return String( value == null ? "" : value ).replace( /[&<>"']/g, function ( c ) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[ c ];
		} );
	}

	function plural( n, one, many ) {
		return n + " " + ( n === 1 ? one : many );
	}

	/* One colour per neighbourhood, the same everywhere it appears. A street in two neighbourhoods shows overlapping dots. */
	function dot( hue, overlap, big ) {
		var size = big ? ".95rem" : ".8rem";
		return "<span class=\"d-inline-block rounded-circle flex-shrink-0\" aria-hidden=\"true\" style=\"width:" + size + ";height:" + size +
			";background:hsl(" + ( parseInt( hue, 10 ) || 0 ) + " 62% 48%);box-shadow:0 0 0 2px var(--bs-body-bg,#fff);" +
			( overlap ? "margin-left:-.3rem;" : "" ) + "\"></span>";
	}

	function pair( chips ) {
		var big = chips.length > 1;
		return "<span class=\"d-inline-flex align-items-center flex-shrink-0 pe-1\">" +
			chips.map( function ( c, i ) { return dot( c.hue, i > 0, big ); } ).join( "" ) + "</span>";
	}

	function chip( item ) {
		return "<span class=\"d-inline-flex align-items-center gap-2 bg-light rounded px-3 py-1 me-2 mb-1 fs-6 text-gray-800\">" +
			dot( item.hue ) + esc( item.name ) + "</span>";
	}

	function badge( text, tone ) {
		return "<span class=\"badge badge-light-" + tone + " fs-7\">" + esc( text ) + "</span>";
	}

	function $( selector ) {
		return document.querySelector( selector );
	}

	function modal( id ) {
		return window.bootstrap.Modal.getOrCreateInstance( document.getElementById( id ) );
	}

	function isOpen( id ) {
		return document.getElementById( id ).classList.contains( "show" );
	}

	/* Bootstrap shows one modal at a time, so wait for the first to finish closing. */
	function swapModal( fromId, toId ) {
		if ( ! isOpen( fromId ) ) {
			modal( toId ).show();
			return;
		}
		var from = document.getElementById( fromId );
		from.addEventListener( "hidden.bs.modal", function () { modal( toId ).show(); }, { once: true } );
		modal( fromId ).hide();
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

	function current() {
		for ( var i = 0; i < state.list.length; i++ ) {
			if ( state.list[ i ].id === state.currentId ) {
				return state.list[ i ];
			}
		}
		return null;
	}

	function flash( message, kind ) {
		var box = $( "#atk-nb-flash" );
		var tone = kind === "error" ? "alert-danger" : "alert-success";
		box.innerHTML = message ? "<div class=\"alert " + tone + " d-flex align-items-center p-5 mb-6\" role=\"" + ( kind === "error" ? "alert" : "status" ) + "\">" + esc( message ) + "</div>" : "";
		$( "#atk-nb-status" ).textContent = message || "";
	}

	function fail( error ) {
		flash( error && error.message ? error.message : "Something went wrong. Try again.", "error" );
	}

	/* ---------- shell ---------- */

	function buildShell() {
		root.innerHTML =
			"<div id=\"atk-nb-flash\"></div>" +
			"<div class=\"row g-6\">" +
				"<div class=\"col-xl-4\">" +
					"<div class=\"card card-flush\">" +
						"<div class=\"card-header pt-7\"><h3 class=\"card-title fw-bold text-gray-900\">Neighbourhoods</h3></div>" +
						"<div class=\"card-body pt-3\"><div id=\"atk-nb-list\" class=\"d-flex flex-column gap-1\"></div></div>" +
						"<div class=\"card-footer\">" +
							"<form id=\"atk-nb-add\" class=\"d-flex gap-3\">" +
								"<input class=\"form-control form-control-solid\" name=\"name\" maxlength=\"120\" required placeholder=\"New neighbourhood\" aria-label=\"New neighbourhood name\">" +
								"<button class=\"btn btn-primary\" type=\"submit\">Add</button>" +
							"</form>" +
						"</div>" +
					"</div>" +
				"</div>" +
				"<div class=\"col-xl-8\"><div class=\"card card-flush\" id=\"atk-nb-main\"></div></div>" +
			"</div>" +
			"<p id=\"atk-nb-status\" class=\"visually-hidden\" role=\"status\" aria-live=\"polite\"></p>" +
			"<input id=\"atk-nb-file\" class=\"d-none\" type=\"file\" accept=\".xlsx,.xls,.csv\" aria-label=\"Choose a spreadsheet file\">" +

			"<div class=\"modal fade\" id=\"atk-nb-paste\" tabindex=\"-1\" aria-labelledby=\"atk-nb-paste-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-lg\"><div class=\"modal-content\">" +
					"<form id=\"atk-nb-paste-form\">" +
						"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-nb-paste-title\">Paste streets</h3>" +
							"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
						"<div class=\"modal-body\">" +
							"<p id=\"atk-nb-paste-help\" class=\"text-gray-700 fs-6\"></p>" +
							"<label class=\"visually-hidden\" for=\"atk-nb-paste-text\">Streets to paste</label>" +
							"<textarea id=\"atk-nb-paste-text\" class=\"form-control form-control-solid\" rows=\"10\" spellcheck=\"false\"></textarea>" +
							"<div id=\"atk-nb-paste-error\" class=\"text-danger fs-6 mt-3\" role=\"alert\"></div>" +
						"</div>" +
						"<div class=\"modal-footer\">" +
							"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
							"<button type=\"submit\" class=\"btn btn-primary\">Check streets</button>" +
						"</div>" +
					"</form>" +
				"</div></div>" +
			"</div>" +

			"<div class=\"modal fade\" id=\"atk-nb-review\" tabindex=\"-1\" aria-labelledby=\"atk-nb-review-title\" aria-hidden=\"true\">" +
				"<div class=\"modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl\"><div class=\"modal-content\" id=\"atk-nb-review-content\"></div></div>" +
			"</div>";
	}

	/* ---------- neighbourhood list ---------- */

	function renderList() {
		var box = $( "#atk-nb-list" );
		if ( ! state.list.length ) {
			box.innerHTML = "<p class=\"text-gray-600 fs-6 mb-0\">Nothing here yet. Add a neighbourhood below, or import your client sheet to create them from its Ward column.</p>";
			return;
		}
		box.innerHTML = state.list.map( function ( n ) {
			var on = n.id === state.currentId;
			return "<button type=\"button\" class=\"btn btn-flex btn-active-light-primary align-items-center gap-3 w-100 text-start text-wrap px-4 py-3" + ( on ? " active" : "" ) + "\" data-act=\"select\" data-id=\"" + n.id + "\"" + ( on ? " aria-current=\"true\"" : "" ) + ">" +
				dot( n.hue ) +
				"<span class=\"fw-bold text-gray-900 flex-grow-1\">" + esc( n.name ) + "</span>" +
				"<span class=\"text-gray-600 fs-7 flex-shrink-0\">" + esc( plural( n.count, "street", "streets" ) ) + "</span>" +
			"</button>";
		} ).join( "" );
	}

	/* ---------- selected neighbourhood ---------- */

	function renderMain() {
		var cur = current();
		var box = $( "#atk-nb-main" );
		if ( ! cur ) {
			box.innerHTML =
				"<div class=\"card-body d-flex flex-column justify-content-center align-items-center text-center py-15\">" +
					"<h3 class=\"fw-bold text-gray-900\">No neighbourhood selected</h3>" +
					"<p class=\"text-gray-600 fs-6 mw-450px\">Create one on the left, or import your client sheet and let its Ward column create them for you.</p>" +
					"<div class=\"d-flex gap-3\"><button type=\"button\" class=\"btn btn-primary\" data-act=\"import\">Import file</button>" +
					"<button type=\"button\" class=\"btn btn-light-primary\" data-act=\"paste\">Paste streets</button></div>" +
				"</div>";
			return;
		}
		var sharedText = cur.shared ? ", " + cur.shared + " also in another neighbourhood" : "";
		box.innerHTML =
			"<div class=\"card-header pt-7 flex-wrap gap-3\">" +
				"<div class=\"card-title d-flex align-items-center gap-3\">" + dot( cur.hue ) +
					"<div><h3 class=\"fw-bold text-gray-900 mb-0\">" + esc( cur.name ) + "</h3>" +
					"<div class=\"text-gray-600 fs-6\">" + esc( plural( cur.count, "street", "streets" ) + sharedText ) + "</div></div>" +
				"</div>" +
				"<div class=\"card-toolbar gap-3\">" +
					"<button type=\"button\" class=\"btn btn-light-primary\" data-act=\"paste\">Paste streets</button>" +
					"<button type=\"button\" class=\"btn btn-primary\" data-act=\"import\">Import file</button>" +
					"<button type=\"button\" class=\"btn btn-light-danger\" data-act=\"delete\">Delete</button>" +
				"</div>" +
			"</div>" +
			"<div class=\"card-body pt-4\">" +
				"<input id=\"atk-nb-search\" class=\"form-control form-control-solid w-100 mw-350px mb-5\" type=\"search\" placeholder=\"Search streets\" aria-label=\"Search streets in " + esc( cur.name ) + "\" value=\"" + esc( state.search ) + "\">" +
				"<div id=\"atk-nb-rows\"></div>" +
			"</div>";
		renderRows();
	}

	function renderRows() {
		var cur = current();
		var box = $( "#atk-nb-rows" );
		if ( ! cur || ! box ) {
			return;
		}
		var term = state.search.trim().toLowerCase();
		var rows = state.streets.filter( function ( s ) {
			return ! term || s.title.toLowerCase().indexOf( term ) !== -1;
		} );
		if ( state.loading ) {
			box.innerHTML = "<p class=\"text-gray-600 fs-6 mb-0\" role=\"status\">Loading streets</p>";
			return;
		}
		if ( ! state.streets.length ) {
			box.innerHTML = "<p class=\"text-gray-600 fs-6 mb-0\">No streets in " + esc( cur.name ) + " yet. Paste street names or import a file to fill it.</p>";
			return;
		}
		if ( ! rows.length ) {
			box.innerHTML = "<p class=\"text-gray-600 fs-6 mb-0\">No street matches \"" + esc( state.search ) + "\".</p>";
			return;
		}
		box.innerHTML =
			"<div class=\"table-responsive position-relative\"><table class=\"table align-middle table-row-dashed fs-6 gy-4 mb-0\">" +
			"<thead><tr class=\"text-start text-gray-600 fw-bold fs-7 gs-0\">" +
				"<th>Street</th><th>Postcodes</th><th>Also in</th><th class=\"text-end\"><span class=\"visually-hidden\">Actions</span></th>" +
			"</tr></thead><tbody>" +
			rows.map( function ( s ) {
				var chips = [ { hue: cur.hue } ].concat( s.also );
				var postcodes = s.postcodes.length ? esc( s.postcodes.join( ", " ) ) : "<span class=\"text-gray-600\">None saved</span>";
				var also = s.also.length ? s.also.map( chip ).join( "" ) : "<span class=\"text-gray-600\">Only here</span>";
				// A note sits on its own full-width row, so a long note never squeezes the street name.
				var tight = s.note ? " border-bottom-0 pb-1" : "";
				var note = s.note ? "<tr><td colspan=\"4\" class=\"pt-0 ps-12 text-gray-600 fs-7\">Instructor note: " + esc( s.note ) + "</td></tr>" : "";
				return "<tr>" +
					"<td class=\"" + tight + "\"><div class=\"d-flex align-items-center gap-3\">" + pair( chips ) + "<div class=\"fw-bold text-gray-900\">" + esc( s.title ) + "</div></div></td>" +
					"<td class=\"" + tight + "\">" + postcodes + "</td>" +
					"<td class=\"" + tight + "\">" + also + "</td>" +
					"<td class=\"text-end" + tight + "\"><button type=\"button\" class=\"btn btn-sm btn-light-danger\" data-act=\"remove\" data-street=\"" + s.id + "\" aria-label=\"Remove " + esc( s.title ) + " from " + esc( cur.name ) + "\">Remove</button></td>" +
				"</tr>" + note;
			} ).join( "" ) +
			"</tbody></table></div>";
	}

	/* ---------- loading ---------- */

	/* When switching neighbourhoods the old rows are cleared straight away, so a click can never land on another neighbourhood's street. */
	function loadStreets( switching ) {
		var id = state.currentId;
		if ( ! id ) {
			state.streets = [];
			state.loading = false;
			renderMain();
			return Promise.resolve();
		}
		if ( switching ) {
			state.streets = [];
			state.loading = true;
			renderMain();
		}
		return api( "neighbourhoods/" + id + "/streets" ).then( function ( streets ) {
			if ( id !== state.currentId ) {
				return; // A newer choice replaced this one while it loaded.
			}
			state.streets = streets;
			state.loading = false;
			renderMain();
		} );
	}

	function reload( selectId ) {
		return api( "neighbourhoods" ).then( function ( list ) {
			state.list = list;
			if ( selectId ) {
				state.currentId = selectId;
			}
			if ( ! current() ) {
				state.currentId = list.length ? list[ 0 ].id : 0;
			}
			renderList();
			return loadStreets( !! selectId || ! state.streets.length );
		} );
	}

	/* ---------- paste and import ---------- */

	function openPaste() {
		var cur = current();
		var help = cur ?
			"One street per line adds it to " + cur.name + ". Rows copied from a spreadsheet with the columns Road, Post Code, Address, Ward and Note set each street's neighbourhoods from its Ward column." :
			"Paste rows copied from a spreadsheet with the columns Road, Post Code, Address, Ward and Note. The Ward column creates the neighbourhoods.";
		$( "#atk-nb-paste-help" ).textContent = help;
		$( "#atk-nb-paste-error" ).textContent = "";
		$( "#atk-nb-paste-text" ).value = "";
		modal( "atk-nb-paste" ).show();
	}

	function startReview( payload ) {
		state.payload = payload;
		state.choices = {};
		state.additions = {};
		state.onlyAttention = false;
		runPreview( true );
	}

	function requestBody() {
		var body = { target: state.currentId, choices: state.choices, additions: Object.keys( state.additions ).map( Number ) };
		if ( state.payload.rows ) {
			body.rows = state.payload.rows;
		} else {
			body.text = state.payload.text;
		}
		return body;
	}

	function runPreview( open ) {
		flash( "" );
		return api( "neighbourhood-import/preview", { method: "POST", body: requestBody() } ).then( function ( plan ) {
			if ( ! plan.rows.length ) {
				throw new Error( "No streets found. Check that the first column holds road names." );
			}
			if ( plan.mode === "names" && ! state.currentId ) {
				throw new Error( "Pick a neighbourhood first, or paste rows that include a Ward column." );
			}
			state.plan = plan;
			renderReview();
			if ( open ) {
				swapModal( "atk-nb-paste", "atk-nb-review" );
			}
		} ).catch( function ( error ) {
			if ( open && isOpen( "atk-nb-paste" ) ) {
				$( "#atk-nb-paste-error" ).textContent = error.message;
			} else {
				fail( error );
			}
		} );
	}

	var STATUS = {
		ready: [ "Ready to save", "primary" ],
		unchanged: [ "No changes", "secondary" ],
		unmatched: [ "Not found", "danger" ],
		ambiguous: [ "Several streets match", "warning" ],
		invalid: [ "No road name", "danger" ],
		no_ward: [ "No ward given", "warning" ]
	};

	function needsAttention( row ) {
		return [ "unmatched", "ambiguous", "invalid", "no_ward" ].indexOf( row.status ) !== -1;
	}

	function renderReview() {
		var plan = state.plan;
		var s = plan.summary;
		var rows = plan.rows.filter( function ( r ) { return ! state.onlyAttention || needsAttention( r ) || r.issues.length; } );
		var clipped = rows.length > MAX_REVIEW_ROWS;
		var shown = clipped ? rows.slice( 0, MAX_REVIEW_ROWS ) : rows;

		var newNames = s.new_neighbourhoods.length ?
			"<p class=\"fs-6 text-gray-700 mb-4\">New neighbourhoods will be created: " + s.new_neighbourhoods.map( esc ).join( ", " ) + ".</p>" :
			"";

		var body = shown.map( function ( r ) {
			var st = STATUS[ r.status ] || [ "", "secondary" ];
			var wards = r.ward_chips.map( function ( c ) {
				return "<span class=\"d-inline-flex align-items-center gap-2 bg-light rounded px-3 py-1 me-2 mb-1 fs-6 text-gray-800\">" + dot( c.hue ) + esc( c.name ) + ( c.is_new ? " " + badge( "new", "success" ) : "" ) + "</span>";
			} ).join( "" );
			var shared = r.shared ? "<div class=\"fs-7 fw-bold text-gray-700 mt-1\">In " + r.ward_chips.length + " neighbourhoods, listed once in a course</div>" : "";
			var lines = [];
			r.changes.forEach( function ( c ) {
				lines.push( "<div>" + esc( c.field ) + ": " + esc( c.from ) + " to " + esc( c.to ) + "</div>" );
			} );
			r.removed.forEach( function ( name ) {
				lines.push( "<div class=\"text-danger\">Removed from " + esc( name ) + "</div>" );
			} );
			r.issues.forEach( function ( text ) {
				lines.push( "<div class=\"text-gray-800\">" + badge( "Check", "warning" ) + " " + esc( text ) + "</div>" );
			} );
			if ( r.status === "ambiguous" ) {
				lines.push( "<div class=\"text-gray-800\">" + badge( "Check", "warning" ) + " More than one street has this name, so it was left out.</div>" );
			}
			if ( r.status === "unmatched" ) {
				var buttons = "";
				if ( r.suggestion ) {
					buttons += "<button type=\"button\" class=\"btn btn-sm btn-light-primary me-2 mt-1\" data-act=\"use-suggestion\" data-line=\"" + r.line + "\" data-id=\"" + r.suggestion.id + "\">Use " + esc( r.suggestion.title ) + "</button>";
				} else {
					lines.push( "<div class=\"text-gray-600\">No street on the site has a similar name.</div>" );
				}
				buttons += "<button type=\"button\" class=\"btn btn-sm btn-light mt-1\" data-act=\"add-street\" data-line=\"" + r.line + "\">Add as new street</button>";
				lines.push( "<div>" + buttons + "</div>" );
			}
			if ( r.new_street ) {
				lines.push( "<div class=\"text-gray-800\">" + badge( "New street", "info" ) + " Will be added to the site's street list. <a href=\"#\" data-act=\"undo-street\" data-line=\"" + r.line + "\">Undo</a></div>" );
			}
			var accepted = state.choices[ r.line ] && ! r.new_street ? "<div class=\"fs-7 text-gray-600\">Matched to " + esc( r.street_title ) + "</div>" : "";
			return "<tr>" +
				"<td><div class=\"fw-bold text-gray-900\">" + esc( r.road ) + "</div><div class=\"fs-7 text-gray-600\">Row " + r.line + "</div>" + accepted + "</td>" +
				"<td>" + badge( st[ 0 ], st[ 1 ] ) + "</td>" +
				"<td>" + wards + shared + "</td>" +
				"<td>" + lines.join( "" ) + "</td>" +
			"</tr>";
		} ).join( "" );

		var loners = plan.rows.filter( function ( r ) { return r.status === "unmatched" && ! r.suggestion; } );
		var bulk = loners.length ?
			"<div class=\"d-flex align-items-center flex-wrap gap-3 mb-5\"><button type=\"button\" class=\"btn btn-sm btn-light-primary\" data-act=\"add-all-loners\">Add the " + plural( loners.length, "street", "streets" ) + " with no similar name as new streets</button>" +
			"<span class=\"fs-7 text-gray-600\">Rows with a close match, which may be typos, are left for you to decide one by one.</span></div>" :
			"";

		var clipNote = clipped ? "<p class=\"fs-7 text-gray-600 mt-3 mb-0\">Showing the first " + MAX_REVIEW_ROWS + " of " + rows.length + " rows. Use the filter to see only the rows that need attention.</p>" : "";

		$( "#atk-nb-review-content" ).innerHTML =
			"<div class=\"modal-header\"><h3 class=\"modal-title\" id=\"atk-nb-review-title\">Review before saving</h3>" +
				"<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button></div>" +
			"<div class=\"modal-body\">" +
				"<div class=\"d-flex flex-wrap gap-2 mb-5\">" +
					badge( plural( s.ready, "street", "streets" ) + " to save", "primary" ) +
					badge( s.unchanged + " unchanged", "secondary" ) +
					badge( s.shared + " in two or more neighbourhoods", "info" ) +
					badge( s.attention + " need attention", s.attention ? "danger" : "secondary" ) +
					( s.new_streets ? badge( plural( s.new_streets, "new street", "new streets" ), "info" ) : "" ) +
					( s.warnings ? badge( plural( s.warnings, "warning", "warnings" ), "warning" ) : "" ) +
				"</div>" +
				newNames +
				bulk +
				"<div class=\"form-check form-check-custom form-check-solid mb-5\">" +
					"<input class=\"form-check-input\" type=\"checkbox\" id=\"atk-nb-only-attention\" data-act=\"only-attention\"" + ( state.onlyAttention ? " checked" : "" ) + ">" +
					"<label class=\"form-check-label\" for=\"atk-nb-only-attention\">Show only rows that need attention</label>" +
				"</div>" +
				"<div class=\"table-responsive position-relative\"><table class=\"table align-middle table-row-dashed fs-6 gy-3 mb-0\">" +
					"<thead><tr class=\"text-start text-gray-600 fw-bold fs-7 gs-0\"><th>Road</th><th>Result</th><th>Neighbourhoods</th><th>Changes</th></tr></thead>" +
					"<tbody>" + body + "</tbody></table></div>" +
				clipNote +
			"</div>" +
			"<div class=\"modal-footer\">" +
				"<button type=\"button\" class=\"btn btn-light\" data-bs-dismiss=\"modal\">Cancel</button>" +
				"<button type=\"button\" class=\"btn btn-primary\" data-act=\"commit\"" + ( s.ready ? "" : " disabled" ) + ">Save " + esc( plural( s.ready, "street", "streets" ) ) + "</button>" +
			"</div>";
	}

	function commit() {
		var button = $( "[data-act=\"commit\"]" );
		button.disabled = true;
		button.textContent = "Saving";
		api( "neighbourhood-import/commit", { method: "POST", body: requestBody() } ).then( function ( result ) {
			modal( "atk-nb-review" ).hide();
			var made = result.created.length ? " " + plural( result.created.length, "new neighbourhood was", "new neighbourhoods were" ) + " created." : "";
			var streets = result.summary && result.summary.new_streets ? " " + plural( result.summary.new_streets, "street was", "streets were" ) + " added to the street list." : "";
			var failed = result.failed ? " " + plural( result.failed, "street", "streets" ) + " could not be saved." : "";
			// Refresh first, so the message never appears before the list it describes.
			return reload().then( function () {
				flash( "Saved " + plural( result.saved, "street", "streets" ) + "." + made + streets + failed, result.failed ? "error" : "ok" );
			} );
		} ).catch( function ( error ) {
			fail( error );
			renderReview();
		} );
	}

	function readFile( file ) {
		if ( ! window.XLSX ) {
			flash( "The spreadsheet reader did not load. Check your connection and try again.", "error" );
			return;
		}
		var isCsv = /\.csv$/i.test( file.name );
		var reading = isCsv ? file.text() : file.arrayBuffer();
		reading.then( function ( data ) {
			var book = isCsv ? XLSX.read( data, { type: "string" } ) : XLSX.read( data, { type: "array" } );
			var sheet = book.Sheets[ book.SheetNames[ 0 ] ];
			var rows = XLSX.utils.sheet_to_json( sheet, { header: 1, raw: false, defval: "" } );
			startReview( { rows: rows } );
		} ).catch( function () {
			flash( "That file could not be read. Save it as .xlsx or .csv and try again.", "error" );
		} );
	}

	/* ---------- events ---------- */

	function bind() {
		root.addEventListener( "click", function ( event ) {
			var el = event.target.closest( "[data-act]" );
			if ( ! el || el.tagName === "INPUT" ) {
				return;
			}
			var act = el.getAttribute( "data-act" );
			var cur = current();

			if ( act === "select" ) {
				state.currentId = parseInt( el.getAttribute( "data-id" ), 10 );
				state.search = "";
				renderList();
				loadStreets( true ).catch( fail );
			} else if ( act === "paste" ) {
				openPaste();
			} else if ( act === "import" ) {
				$( "#atk-nb-file" ).click();
			} else if ( act === "use-suggestion" ) {
				state.choices[ el.getAttribute( "data-line" ) ] = parseInt( el.getAttribute( "data-id" ), 10 );
				runPreview( false );
			} else if ( act === "add-street" ) {
				state.additions[ el.getAttribute( "data-line" ) ] = true;
				runPreview( false );
			} else if ( act === "undo-street" ) {
				event.preventDefault();
				delete state.additions[ el.getAttribute( "data-line" ) ];
				runPreview( false );
			} else if ( act === "add-all-loners" ) {
				state.plan.rows.forEach( function ( r ) {
					if ( r.status === "unmatched" && ! r.suggestion ) {
						state.additions[ r.line ] = true;
					}
				} );
				runPreview( false );
			} else if ( act === "commit" ) {
				commit();
			} else if ( act === "remove" && cur ) {
				var street = el.getAttribute( "data-street" );
				api( "neighbourhoods/" + cur.id + "/streets/" + street, { method: "DELETE" } )
					.then( function () { flash( "Removed from " + cur.name + "." ); return reload(); } )
					.catch( fail );
			} else if ( act === "delete" && cur ) {
				var question = "Delete " + cur.name + "? Its " + plural( cur.count, "street", "streets" ) + " stay on the site and just leave this neighbourhood.";
				if ( window.confirm( question ) ) {
					api( "neighbourhoods/" + cur.id, { method: "DELETE" } )
						.then( function () { flash( cur.name + " deleted." ); state.currentId = 0; return reload(); } )
						.catch( fail );
				}
			}
		} );

		root.addEventListener( "change", function ( event ) {
			if ( event.target.getAttribute( "data-act" ) === "only-attention" ) {
				state.onlyAttention = event.target.checked;
				renderReview();
			}
			if ( event.target.id === "atk-nb-file" && event.target.files.length ) {
				readFile( event.target.files[ 0 ] );
				event.target.value = "";
			}
		} );

		root.addEventListener( "input", function ( event ) {
			if ( event.target.id === "atk-nb-search" ) {
				state.search = event.target.value;
				renderRows();
			}
		} );

		root.addEventListener( "submit", function ( event ) {
			if ( event.target.id === "atk-nb-add" ) {
				event.preventDefault();
				var input = event.target.elements.name;
				api( "neighbourhoods", { method: "POST", body: { name: input.value } } )
					.then( function ( result ) {
						input.value = "";
						flash( result.created ? "Neighbourhood added." : "That neighbourhood already exists, so it is selected." );
						return reload( result.id );
					} )
					.catch( fail );
			}
			if ( event.target.id === "atk-nb-paste-form" ) {
				event.preventDefault();
				var text = $( "#atk-nb-paste-text" ).value;
				if ( ! text.trim() ) {
					$( "#atk-nb-paste-error" ).textContent = "Paste at least one street first.";
					return;
				}
				startReview( { text: text } );
			}
		} );
	}

	buildShell();
	bind();
	reload().catch( fail );
}() );
