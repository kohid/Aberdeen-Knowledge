<?php
/**
 * "Main Arteries" full-screen map study tool.
 *
 * Road geometry in assets/data/major-roads.json is real OpenStreetMap data
 * (major/trunk roads within the Aberdeen City boundary, fetched via the
 * Overpass API), not hand-authored sample content like the other tools.
 *
 * UI chrome uses Bootstrap components (modals, buttons, badges,
 * cards) throughout, not a one-off custom theme — the map fills the full
 * viewport since it needs the space, but everything floating on top of it
 * is standard Bootstrap, matching the rest of the site.
 */

defined( 'ABSPATH' ) || exit;

const ATK_ARTERIES_SESSION_SIZE = 5;
const ATK_ARTERIES_MINUTES      = 30;

/**
 * Count of unique *findable* streets — the real OSM data plus whatever
 * instructors have added/renamed/hidden via the Instructor Dashboard's
 * Major Streets tab (inc/instructor-roads.php), same merged data the map
 * itself loads, cached for the request.
 */
function atk_major_roads_count() {
	static $count = null;

	if ( null === $count ) {
		$data  = atk_get_major_roads_data_cached();
		$names = array();

		foreach ( ( isset( $data['segments'] ) ? $data['segments'] : array() ) as $segment ) {
			$name = isset( $segment['name'] ) ? $segment['name'] : null;
			if ( null !== $name && false !== ( isset( $segment['findable'] ) ? $segment['findable'] : true ) ) {
				$names[ $name ] = true;
			}
		}

		$count = count( $names );
	}

	return $count;
}

function atk_render_arteries_map() {
	atk_enqueue_leaflet();

	wp_enqueue_script( 'atk-arteries-map', ATK_PLUGIN_URI . '/assets/js/major-streets-map.js', array( 'leaflet', 'atk-plugins-bundle' ), filemtime( ATK_PLUGIN_DIR . '/assets/js/major-streets-map.js' ), true );
	wp_add_inline_script( 'atk-arteries-map', 'var ATK_ARTERIES = ' . wp_json_encode( array(
		'dataUrl'      => rest_url( 'atk/v1/major-roads' ),
		'sessionSize'  => ATK_ARTERIES_SESSION_SIZE,
		'minutes'      => ATK_ARTERIES_MINUTES,
		'backUrl'      => atk_get_page_url( 'study-tools' ),
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'attemptNonce' => wp_create_nonce( ATK_ATTEMPT_NONCE_ACTION ),
	) ) . ';', 'before' );
	?>
	<div id="atk-arteries-app">
		<div id="atk-arteries-map-el"></div>

		<a href="<?php echo esc_url( atk_get_page_url( 'study-tools' ) ); ?>" class="btn btn-sm btn-light shadow-sm position-absolute" style="top: 16px; left: 16px; z-index: 1000;">&larr; Back to Study Tools</a>

		<div class="d-flex flex-wrap gap-2 justify-content-end position-absolute" style="top: 16px; right: 16px; z-index: 1000; max-width: 320px;">
			<button type="button" id="atk-map-btn-splash" class="btn btn-sm btn-light shadow-sm">See Splash Screen</button>
			<button type="button" id="atk-map-btn-study" class="btn btn-sm btn-light shadow-sm">Study Mode</button>
			<button type="button" id="atk-map-btn-test" class="btn btn-sm btn-primary shadow-sm">Take Test</button>
			<button type="button" id="atk-map-btn-review" class="btn btn-sm btn-light shadow-sm">Review Last Test</button>
		</div>

		<div class="card shadow-sm position-absolute top-0 start-50 translate-middle-x mt-4" style="z-index: 1000;">
			<div class="card-body py-2 px-4 d-flex flex-column align-items-center gap-1">
				<div class="d-flex align-items-center gap-2">
					<i class="bi bi-signpost-split text-primary fs-3"></i>
					<span class="fw-bold text-gray-800" id="atk-map-status-text">Hover over street to view name</span>
				</div>
				<span class="text-gray-500 fs-7" id="atk-map-hover-text"></span>
			</div>
		</div>

		<div id="atk-map-checklist" class="card shadow-sm position-absolute d-none" style="top: 70px; left: 16px; z-index: 999; width: 260px; max-height: calc(100vh - 100px); overflow-y: auto;">
			<div class="card-body p-4">
				<h3 class="fs-6 fw-bolder text-gray-800 mb-4">Test Progress</h3>
				<div id="atk-map-checklist-body"></div>
			</div>
		</div>

		<div id="atk-map-timer-pill" class="position-absolute bottom-0 start-50 translate-middle-x mb-4 d-none" style="z-index: 1000;">
			<span class="badge badge-light-danger fs-5 px-4 py-3" id="atk-map-timer-text"></span>
		</div>
	</div>

	<?php
	/**
	 * Bootstrap modals must NOT be nested inside a `position: fixed`
	 * ancestor (like #atk-arteries-app above) — fixed positioning always
	 * creates its own CSS stacking context, which traps the modal's z-index
	 * inside it. The backdrop Bootstrap appends to <body> then renders
	 * above the whole trapped context regardless of the modal's own
	 * z-index, blocking every click including the modal's own Close button.
	 * Keeping these as siblings of the app container (i.e. direct body
	 * children) avoids that trap.
	 */
	?>

	<div class="modal fade" id="atk-map-intro" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900">Main Arteries: Mapping the Lifelines of the City</h2>
				</div>
				<div class="modal-body">
					<p class="text-gray-700 fs-6 mb-0">
						Study the city's major arterial routes and learn where each one runs. Hover any street in Study Mode to see its name. When you're ready, use <strong>Take Test</strong> to be prompted to find <?php echo esc_html( ATK_ARTERIES_SESSION_SIZE ); ?> of the <?php echo esc_html( atk_major_roads_count() ); ?> major roads in this data set.
					</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-map-complete" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900">Session Complete</h2>
				</div>
				<div class="modal-body">
					<p class="text-gray-700 fs-6 mb-0" id="atk-map-complete-body"></p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade" id="atk-map-review" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title fs-3 fw-bolder text-gray-900">Review Last Test</h2>
				</div>
				<div class="modal-body" id="atk-map-review-body"></div>
				<div class="modal-footer">
					<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>
	<?php
}
