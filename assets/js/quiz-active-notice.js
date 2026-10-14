"use strict";

/**
 * Test hubs (Knowledge Tests, Course) are server-rendered, but an
 * in-progress attempt only lives in this browser's localStorage
 * (assets/js/quiz.js's saveState()/loadState()) — so the only way to flag
 * "you have an unfinished test" here is client-side, after the page loads.
 * Every test tile on these hubs carries a data-test-key attribute; this
 * checks each one against the same storage key format quiz.js uses and
 * marks any tile with a real, unexpired attempt still waiting.
 */
(function () {
	document.addEventListener( "DOMContentLoaded", function () {
		document.querySelectorAll( ".atk-test-tile[data-test-key]" ).forEach( function ( tile ) {
			var state;
			try {
				var raw = localStorage.getItem( "atk_quiz_state_" + tile.dataset.testKey );
				if ( ! raw ) {
					return;
				}
				state = JSON.parse( raw );
			} catch ( e ) {
				return;
			}

			if ( ! state || typeof state.endTime !== "number" || state.endTime <= Date.now() ) {
				return;
			}

			var minutesLeft = Math.max( 1, Math.ceil( ( state.endTime - Date.now() ) / 60000 ) );
			var badge = document.createElement( "span" );
			badge.className = "badge bg-white text-warning mt-2";
			badge.innerHTML = '<i class="bi bi-clock-history me-1"></i>In progress — ' + minutesLeft + " min left";

			var tileLink = tile.querySelector( "a.btn" );

			if ( tileLink && tileLink.classList.contains( "atk-start-test-btn" ) ) {
				// Study Tool / Knowledge Test hubs: a plain "Start Test" button
				// with no result colouring — just rename it and drop the badge
				// on the tile below, as before.
				tileLink.textContent = "Resume Test";
				tile.appendChild( badge );
			} else if ( tileLink ) {
				// Course hub tiles are colour-coded by result (pass/fail/locked);
				// an unexpired in-progress attempt takes visual priority over
				// whatever static result class the tile rendered with, and the
				// badge stacks inside the card itself rather than below it.
				tileLink.classList.remove( "btn-light", "btn-light-success", "btn-light-danger" );
				tileLink.classList.add( "btn-warning" );
				tileLink.appendChild( badge );
			} else {
				tile.appendChild( badge );
			}
		} );
	} );
})();
