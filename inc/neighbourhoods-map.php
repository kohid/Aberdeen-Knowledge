<?php
/**
 * "Neighbourhoods" full-screen map study tool. Two views on one map:
 * an overview of real Aberdeen community council areas (assets/data/
 * neighbourhoods.json, real OSM administrative boundaries) as coloured,
 * clickable regions, and — once one is chosen — a zoomed-in street quiz for
 * that area only, reusing the same hover/Take Test mechanic as Major
 * Streets but filtered to streets inside the chosen boundary (assets/data/
 * major-roads.json, the same full-city street data Major Streets uses).
 *
 * UI chrome uses Bootstrap components (see assets/css/akn.css) throughout, same as
 * arteries-map.php.
 */

defined( 'ABSPATH' ) || exit;

const ATK_NEIGHBOURHOODS_SESSION_SIZE = 5;
const ATK_NEIGHBOURHOODS_MINUTES      = 15;

/**
 * The list of real neighbourhood areas (name + boundary), cached for the
 * request. Used both by the map itself and to show a count on the Study
 * Tool hub card.
 */
function atk_neighbourhood_areas() {
	static $areas = null;

	if ( null === $areas ) {
		$path   = ATK_PLUGIN_DIR . '/assets/data/neighbourhoods.json';
		$data   = file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
		$areas  = $data['areas'] ?? array();
	}

	return $areas;
}

function atk_render_neighbourhoods_map() {
	atk_enqueue_leaflet();

	wp_enqueue_script( 'atk-neighbourhoods-map', ATK_PLUGIN_URI . '/assets/js/neighbourhoods-map.js', array( 'leaflet', 'atk-plugins-bundle' ), filemtime( ATK_PLUGIN_DIR . '/assets/js/neighbourhoods-map.js' ), true );
	wp_add_inline_script( 'atk-neighbourhoods-map', 'var ATK_NEIGHBOURHOODS = ' . wp_json_encode( array(
		'areasUrl'     => ATK_PLUGIN_URI . '/assets/data/neighbourhoods.json',
		'roadsUrl'     => ATK_PLUGIN_URI . '/assets/data/major-roads.json',
		'sessionSize'  => ATK_NEIGHBOURHOODS_SESSION_SIZE,
		'minutes'      => ATK_NEIGHBOURHOODS_MINUTES,
		'backUrl'      => atk_get_page_url( 'study-tools' ),
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'attemptNonce' => wp_create_nonce( ATK_ATTEMPT_NONCE_ACTION ),
	) ) . ';', 'before' );
	?>
	<div id="atk-neigh-app">
		<div id="atk-neigh-map-el"></div>

		<a href="<?php echo esc_url( atk_get_page_url( 'study-tools' ) ); ?>" class="btn btn-sm btn-light shadow-sm position-absolute" style="top: 16px; left: 16px; z-index: 1000;">&larr; Back to Study Tools</a>

		<button type="button" id="atk-neigh-btn-back" class="btn btn-sm btn-light shadow-sm position-absolute d-none" style="top: 16px; left: 190px; z-index: 1000;">&larr; All Neighbourhoods</button>

		<div class="d-flex flex-wrap gap-2 justify-content-end position-absolute d-none" id="atk-neigh-controls" style="top: 16px; right: 16px; z-index: 1000; max-width: 320px;">
			<button type="button" id="atk-neigh-btn-splash" class="btn btn-sm btn-light shadow-sm">See Splash Screen</button>
			<button type="button" id="atk-neigh-btn-study" class="btn btn-sm btn-light shadow-sm">Study Mode</button>
			<button type="button" id="atk-neigh-btn-test" class="btn btn-sm btn-primary shadow-sm">Take Test</button>
			<button type="button" id="atk-neigh-btn-review" class="btn btn-sm btn-light shadow-sm">Review Last Test</button>
		</div>

		<div class="card shadow-sm position-absolute top-0 start-50 translate-middle-x mt-4" style="z-index: 1000;">
			<div class="card-body py-2 px-4 d-flex flex-column align-items-center gap-1">
				<div class="d-flex align-items-center gap-2">
					<i class="bi bi-building text-primary fs-3"></i>
					<span class="fw-bold text-gray-800" id="atk-neigh-status-text">Choose a neighbourhood to explore its streets</span>
				</div>
				<span class="text-gray-500 fs-7" id="atk-neigh-hover-text"></span>
			</div>
		</div>

		<div id="atk-neigh-checklist" class="card shadow-sm position-absolute d-none" style="top: 70px; left: 16px; z-index: 999; width: 260px; max-height: calc(100vh - 100px); overflow-y: auto;">
			<div class="card-body p-4">
				<h3 class="fs-6 fw-bolder text-gray-800 mb-4">Test Progress</h3>
				<div id="atk-neigh-checklist-body"></div>
			</div>
		</div>

		<div id="atk-neigh-timer-pill" class="position-absolute bottom-0 start-50 translate-middle-x mb-4 d-none" style="z-index: 1000;">
			<span class="badge badge-light-danger fs-5 px-4 py-3" id="atk-neigh-timer-text"></span>
		</div>
	</div>

	<div class="modal fade" id="atk-neigh-intro" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900" id="atk-neigh-intro-title">Neighbourhood</h2>
				</div>
				<div class="modal-body">
					<p class="text-gray-700 fs-6 mb-0" id="atk-neigh-intro-body"></p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-neigh-complete" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900">Session Complete</h2>
				</div>
				<div class="modal-body">
					<p class="text-gray-700 fs-6 mb-0" id="atk-neigh-complete-body"></p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-neigh-review" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900">Review Last Test</h2>
				</div>
				<div class="modal-body" id="atk-neigh-review-body"></div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>
	<?php
}
