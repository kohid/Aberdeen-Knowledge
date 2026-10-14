<?php
/**
 * "Places of Interest" full-screen map study tool — same Study Mode/Take
 * Test/Review pattern as Major Streets (inc/arteries-map.php), but pins
 * instead of street lines.
 *
 * assets/data/poi.json is real, git-tracked data: geocoded once from
 * assets/data/poi-source.csv via a one-off script (not committed — see the
 * theme's CLAUDE.md), never written to at runtime. A venue only made it
 * into poi.json if Nominatim resolved it to a specific, confidently-typed
 * location (a real amenity/tourism/etc. match, not just a postcode-area
 * centroid) — venues that couldn't be confidently located were left out
 * rather than guessed at.
 *
 * 'closed'/'relocated' venues are still included (visible, hoverable, with
 * their status shown) but excluded from the Take Test pool — quizzing a
 * driver on a location that's no longer valid would teach the wrong thing.
 */

defined( 'ABSPATH' ) || exit;

const ATK_POI_SESSION_SIZE = 5;
const ATK_POI_MINUTES      = 15;

/**
 * The real Places of Interest data, cached for the request. Used by the
 * map itself and to show a count on the Study Tool hub card.
 */
function atk_poi_venues() {
	static $venues = null;

	if ( null === $venues ) {
		$path   = ATK_PLUGIN_DIR . '/assets/data/poi.json';
		$data   = file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
		$venues = $data['venues'] ?? array();
	}

	return $venues;
}

function atk_render_poi_map() {
	atk_enqueue_leaflet();

	wp_enqueue_script( 'atk-poi-map', ATK_PLUGIN_URI . '/assets/js/poi-map.js', array( 'leaflet', 'atk-plugins-bundle' ), filemtime( ATK_PLUGIN_DIR . '/assets/js/poi-map.js' ), true );
	wp_add_inline_script( 'atk-poi-map', 'var ATK_POI = ' . wp_json_encode( array(
		'dataUrl'      => ATK_PLUGIN_URI . '/assets/data/poi.json',
		'sessionSize'  => ATK_POI_SESSION_SIZE,
		'minutes'      => ATK_POI_MINUTES,
		'backUrl'      => atk_get_page_url( 'study-tools' ),
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'attemptNonce' => wp_create_nonce( ATK_ATTEMPT_NONCE_ACTION ),
	) ) . ';', 'before' );
	?>
	<div id="atk-poi-app">
		<div id="atk-poi-map-el"></div>

		<a href="<?php echo esc_url( atk_get_page_url( 'study-tools' ) ); ?>" class="btn btn-sm btn-light shadow-sm position-absolute" style="top: 16px; left: 16px; z-index: 1000;">&larr; Back to Study Tools</a>

		<div class="d-flex flex-wrap gap-2 justify-content-end position-absolute" style="top: 16px; right: 16px; z-index: 1000; max-width: 320px;">
			<button type="button" id="atk-poi-btn-splash" class="btn btn-sm btn-light shadow-sm">See Splash Screen</button>
			<button type="button" id="atk-poi-btn-legend" class="btn btn-sm btn-light shadow-sm">Categories</button>
			<button type="button" id="atk-poi-btn-study" class="btn btn-sm btn-light shadow-sm">Study Mode</button>
			<button type="button" id="atk-poi-btn-test" class="btn btn-sm btn-primary shadow-sm">Take Test</button>
			<button type="button" id="atk-poi-btn-review" class="btn btn-sm btn-light shadow-sm">Review Last Test</button>
		</div>

		<div class="card shadow-sm position-absolute top-0 start-50 translate-middle-x mt-4" style="z-index: 1000;">
			<div class="card-body py-2 px-4 d-flex flex-column align-items-center gap-1">
				<div class="d-flex align-items-center gap-2">
					<i class="bi bi-geo-alt-fill text-primary fs-3"></i>
					<span class="fw-bold text-gray-800" id="atk-poi-status-text">Hover over a pin to view its name</span>
				</div>
				<span class="text-gray-500 fs-7" id="atk-poi-hover-text"></span>
			</div>
		</div>

		<div id="atk-poi-legend" class="card shadow-sm position-absolute d-none" style="top: 70px; right: 16px; z-index: 999; width: 260px; max-height: calc(100vh - 100px); overflow-y: auto;">
			<div class="card-body p-4" id="atk-poi-legend-body"></div>
		</div>

		<div id="atk-poi-checklist" class="card shadow-sm position-absolute d-none" style="top: 70px; left: 16px; z-index: 999; width: 260px; max-height: calc(100vh - 100px); overflow-y: auto;">
			<div class="card-body p-4">
				<h3 class="fs-6 fw-bolder text-gray-800 mb-4">Test Progress</h3>
				<div id="atk-poi-checklist-body"></div>
			</div>
		</div>

		<div id="atk-poi-timer-pill" class="position-absolute bottom-0 start-50 translate-middle-x mb-4 d-none" style="z-index: 1000;">
			<span class="badge badge-light-danger fs-5 px-4 py-3" id="atk-poi-timer-text"></span>
		</div>
	</div>

	<div class="modal fade" id="atk-poi-intro" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900">Places of Interest</h2>
				</div>
				<div class="modal-body">
					<p class="text-gray-700 fs-6 mb-0">
						Explore Aberdeen's notable venues — hotels, restaurants, museums, sports facilities and more — colour-coded by category. Hover any pin in Study Mode to see its name. When you're ready, use <strong>Take Test</strong> to be prompted to find <?php echo esc_html( ATK_POI_SESSION_SIZE ); ?> of the <?php echo esc_html( count( array_filter( atk_poi_venues(), function ( $v ) { return ! in_array( $v['status'], array( 'closed', 'relocated' ), true ); } ) ) ); ?> current places of interest.
					</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-poi-complete" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900">Session Complete</h2>
				</div>
				<div class="modal-body">
					<p class="text-gray-700 fs-6 mb-0" id="atk-poi-complete-body"></p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-poi-review" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900">Review Last Test</h2>
				</div>
				<div class="modal-body" id="atk-poi-review-body"></div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>
	<?php
}
