"use strict";

/**
 * Enrollment wizard (inc/course.php, atk_render_course_enroll_wizard_modal()).
 *
 * Drives the three steps with plain Bootstrap "d-none" toggling: easy to
 * verify and hard to get subtly wrong. The nav items get the state classes
 * current / completed / pending, which the stepper styles in
 * assets/css/akn.css key off.
 */
(function () {
	function initStepper( stepperEl ) {
		var steps = Array.prototype.slice.call( stepperEl.querySelectorAll( '[data-akn-stepper-element="content"]' ) );
		var navs  = Array.prototype.slice.call( stepperEl.querySelectorAll( '[data-akn-stepper-element="nav"]' ) );
		var current = 0;

		function render() {
			steps.forEach( function ( el, i ) {
				el.classList.toggle( "current", i === current );
				el.classList.toggle( "d-none", i !== current );
			} );
			navs.forEach( function ( el, i ) {
				el.classList.remove( "current", "completed", "pending" );
				el.classList.add( i === current ? "current" : ( i < current ? "completed" : "pending" ) );
			} );
		}

		stepperEl.addEventListener( "click", function ( e ) {
			if ( e.target.closest( '[data-akn-stepper-action="next"]' ) && current < steps.length - 1 ) {
				current++;
				render();
			} else if ( e.target.closest( '[data-akn-stepper-action="previous"]' ) && current > 0 ) {
				current--;
				render();
			}
		} );

		render();
	}

	/**
	 * Mirrors atk_course_price_html() (inc/course.php) client-side: the
	 * "What to Expect" and "Confirm" steps are generic markup, not
	 * per-track, so the price they show has to follow whichever track
	 * radio is currently checked. Price figures themselves are still
	 * server-rendered (as data-price-* on each radio) — this just picks
	 * which one to display and formats it the same way.
	 */
	function updateEnrollPriceDisplay() {
		var checked = document.querySelector( 'input[name="course_type"]:checked' );
		var displays = document.querySelectorAll( ".atk-enroll-price-display" );
		if ( ! checked || ! displays.length ) {
			return;
		}
		var regular   = checked.dataset.priceRegular;
		var effective = checked.dataset.priceEffective;
		var html;
		if ( parseFloat( effective ) <= 0 ) {
			html = '<span class="fw-bolder text-gray-900">Free</span>';
		} else if ( parseFloat( effective ) < parseFloat( regular ) ) {
			html = '<span class="text-gray-500 text-decoration-line-through me-2">£' + regular + '</span><span class="fw-bolder text-gray-900 fs-3">£' + effective + '</span>';
		} else {
			html = '<span class="fw-bolder text-gray-900 fs-3">£' + effective + '</span>';
		}
		displays.forEach( function ( el ) { el.innerHTML = html; } );
		updateEnrollPaymentButtons( parseFloat( effective ) );
	}

	/**
	 * Whether the Confirm step's "Enroll Now" (free) button or its Stripe/
	 * PayPal buttons show is decided by the selected track's actual price,
	 * not just whether a gateway is configured at all — a free track stays
	 * a one-click enrollment even when other tracks are paid. If no gateway
	 * is configured, there are no ".atk-enroll-paid-btn" elements to toggle
	 * at all, so the free button (rendered visible in that case) is left
	 * alone.
	 */
	function updateEnrollPaymentButtons( effective ) {
		var paidBtns = document.querySelectorAll( ".atk-enroll-paid-btn" );
		if ( ! paidBtns.length ) {
			return;
		}
		var isFree = ! ( effective > 0 );
		document.querySelectorAll( ".atk-enroll-free-btn" ).forEach( function ( btn ) { btn.classList.toggle( "d-none", ! isFree ); } );
		paidBtns.forEach( function ( btn ) { btn.classList.toggle( "d-none", isFree ); } );
	}

	document.addEventListener( "DOMContentLoaded", function () {
		var stepperEl = document.getElementById( "atk-enroll-stepper" );
		if ( stepperEl ) {
			initStepper( stepperEl );
		}
		updateEnrollPriceDisplay();
	} );

	document.addEventListener( "change", function ( e ) {
		if ( "course_type" === e.target.name ) {
			updateEnrollPriceDisplay();
		}
	} );

	/**
	 * Catalog "Enroll" buttons pre-select their track's radio in the wizard
	 * before it opens.
	 */
	document.addEventListener( "click", function ( e ) {
		var btn = e.target.closest( ".atk-enroll-btn" );
		if ( ! btn ) {
			return;
		}
		var radio = document.getElementById( "atk-enroll-track-" + btn.dataset.courseType );
		if ( radio ) {
			radio.checked = true;
			updateEnrollPriceDisplay();
		}
	} );
})();
