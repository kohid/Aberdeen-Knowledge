"use strict";

/**
 * "Previous Attempts" modals with 2+ attempts (inc/attempts.php,
 * atk_render_previous_attempts_modal()) show one attempt's full review at a
 * time behind a pagination control instead of an accordion. These modals
 * can appear on many different pages (Study Tool, Course, Instructor
 * Dashboard, test result screens), so this listens on the document rather
 * than assuming a specific container exists.
 *
 * The pagination control lives in the modal-footer (always visible, no
 * scrolling needed) while the attempt content lives in the scrollable
 * modal-body — siblings, not nested — so both are looked up relative to
 * their shared .modal ancestor rather than one being inside the other.
 */
(function () {
	document.addEventListener( "click", function ( e ) {
		var link = e.target.closest( ".atk-attempt-page-link" );
		if ( ! link ) {
			return;
		}

		var modal = link.closest( ".modal" );
		if ( ! modal ) {
			return;
		}
		var page = link.dataset.page;

		modal.querySelectorAll( ".page-item" ).forEach( function ( item ) {
			item.classList.remove( "active" );
		} );
		link.closest( ".page-item" ).classList.add( "active" );

		modal.querySelectorAll( ".atk-attempt-page" ).forEach( function ( pageEl ) {
			pageEl.classList.toggle( "d-none", pageEl.dataset.page !== page );
		} );
	} );
})();
