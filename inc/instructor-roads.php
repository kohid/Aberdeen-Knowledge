<?php
/**
 * Major Streets road management: an Instructor Dashboard tab for renaming
 * or hiding (from the quiz) roads already in the real OpenStreetMap data,
 * and for drawing brand-new roads that aren't in that data at all.
 *
 * assets/data/major-roads.json stays exactly as it is — real, git-tracked
 * OSM data, never written to. Every instructor change lives in two tables
 * (inc/db-schema.php): atk_road_overrides (renames/findable changes to an
 * *existing* road, keyed by its permanent original name) and
 * atk_road_additions (brand-new drawn roads). The Major Streets map reads
 * the merge of all three sources via the atk/v1/major-roads REST route
 * below, not the static file directly — see atk_render_arteries_map()
 * (inc/arteries-map.php), which points there instead of at the JSON file.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------- */
/* Data sources                                                          */
/* -------------------------------------------------------------------- */

/**
 * The real OSM segments, unmodified — assets/data/major-roads.json, cached
 * for the request since nothing here writes to it.
 */
function atk_major_roads_static_segments() {
	static $segments = null;
	if ( null === $segments ) {
		$path      = ATK_PLUGIN_DIR . '/assets/data/major-roads.json';
		$data      = file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : array();
		$segments  = isset( $data['segments'] ) && is_array( $data['segments'] ) ? $data['segments'] : array();
	}
	return $segments;
}

/**
 * Instructor overrides to existing roads, keyed by original_name (the
 * permanent lookup key — never the currently-displayed name, so renaming
 * a road twice updates one row instead of chaining).
 */
function atk_road_overrides_map() {
	static $map = null;
	if ( null === $map ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT original_name, new_name, findable FROM ' . atk_table( 'road_overrides' ), ARRAY_A );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ $row['original_name'] ] = array(
				'new_name' => $row['new_name'],
				'findable' => null === $row['findable'] ? null : (bool) $row['findable'],
			);
		}
	}
	return $map;
}

/**
 * Brand-new instructor-drawn roads, oldest first.
 */
function atk_road_additions() {
	static $rows = null;
	if ( null === $rows ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, name, points, findable, created_at FROM ' . atk_table( 'road_additions' ) . ' ORDER BY created_at ASC', ARRAY_A );
	}
	return $rows;
}

/**
 * The static segments with overrides applied, plus one segment per
 * addition row — same shape the frontend already expects
 * ({name, points, findable}), so major-streets-map.js needs no changes.
 */
function atk_merged_major_roads_segments() {
	$overrides = atk_road_overrides_map();
	$merged    = array();

	foreach ( atk_major_roads_static_segments() as $segment ) {
		$name = isset( $segment['name'] ) ? $segment['name'] : null;
		if ( null !== $name && isset( $overrides[ $name ] ) ) {
			$override = $overrides[ $name ];
			if ( ! empty( $override['new_name'] ) ) {
				$segment['name'] = $override['new_name'];
			}
			if ( null !== $override['findable'] ) {
				$segment['findable'] = $override['findable'];
			}
		}
		$merged[] = $segment;
	}

	foreach ( atk_road_additions() as $addition ) {
		$points = json_decode( $addition['points'], true );
		if ( is_array( $points ) ) {
			$merged[] = array(
				'name'     => $addition['name'],
				'points'   => $points,
				'findable' => (bool) $addition['findable'],
			);
		}
	}

	return $merged;
}

const ATK_MAJOR_ROADS_CACHE_KEY = 'atk_major_roads_merged';

function atk_invalidate_major_roads_cache() {
	delete_transient( ATK_MAJOR_ROADS_CACHE_KEY );
}

function atk_get_major_roads_data_cached() {
	$cached = get_transient( ATK_MAJOR_ROADS_CACHE_KEY );
	if ( false !== $cached ) {
		return $cached;
	}
	$data = array( 'segments' => atk_merged_major_roads_segments() );
	set_transient( ATK_MAJOR_ROADS_CACHE_KEY, $data, DAY_IN_SECONDS );
	return $data;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'atk/v1', '/major-roads', array(
		'methods'             => 'GET',
		'callback'            => 'atk_handle_major_roads_rest',
		'permission_callback' => '__return_true', // Same public data the static file already was.
	) );
} );
function atk_handle_major_roads_rest() {
	return new WP_REST_Response( atk_get_major_roads_data_cached(), 200 );
}

/**
 * One row per *effective* road name for the dashboard table — the static
 * data's ~9,700 segments grouped down to their unique names (2,717 of
 * them), with overrides and additions already folded in, so this reflects
 * exactly what the merged map data would show. A road with real OSM
 * origin gets 'original_name' set (enables rename + the OSM-side findable
 * toggle); a purely instructor-added road doesn't (enables delete
 * instead). A road with both shows the OSM actions and folds the added
 * segment(s) into the same count.
 */
function atk_effective_roads_list() {
	$overrides      = atk_road_overrides_map();
	$static_counts  = array();

	foreach ( atk_major_roads_static_segments() as $segment ) {
		$name = isset( $segment['name'] ) ? $segment['name'] : '';
		if ( '' === $name ) {
			continue;
		}
		$static_counts[ $name ] = ( isset( $static_counts[ $name ] ) ? $static_counts[ $name ] : 0 ) + 1;
	}

	$rows = array();
	foreach ( $static_counts as $original_name => $count ) {
		$override       = isset( $overrides[ $original_name ] ) ? $overrides[ $original_name ] : array( 'new_name' => null, 'findable' => null );
		$effective_name = ! empty( $override['new_name'] ) ? $override['new_name'] : $original_name;
		$rows[ $effective_name ] = array(
			'name'          => $effective_name,
			'original_name' => $original_name,
			'segment_count' => $count,
			'findable'      => null !== $override['findable'] ? $override['findable'] : true,
			'is_addition'   => false,
		);
	}

	$addition_counts = array();
	foreach ( atk_road_additions() as $addition ) {
		$name = $addition['name'];
		if ( ! isset( $addition_counts[ $name ] ) ) {
			$addition_counts[ $name ] = array( 'count' => 0, 'findable' => (bool) $addition['findable'] );
		}
		$addition_counts[ $name ]['count']++;
	}

	foreach ( $addition_counts as $name => $info ) {
		if ( isset( $rows[ $name ] ) ) {
			$rows[ $name ]['segment_count'] += $info['count'];
			$rows[ $name ]['has_additions']  = true;
		} else {
			$rows[ $name ] = array(
				'name'          => $name,
				'original_name' => null,
				'segment_count' => $info['count'],
				'findable'      => $info['findable'],
				'is_addition'   => true,
			);
		}
	}

	ksort( $rows, SORT_STRING | SORT_FLAG_CASE );
	return array_values( $rows );
}

/**
 * Every current effective road name, for the Add Road modal's "pick an
 * existing name to add another stretch of it" suggestions.
 */
function atk_effective_road_names_only() {
	return array_map( function ( $row ) { return $row['name']; }, atk_effective_roads_list() );
}

/* -------------------------------------------------------------------- */
/* Admin actions                                                         */
/* -------------------------------------------------------------------- */

function atk_instructor_roads_redirect_back() {
	$args = array( 'tab' => 'study-tools', 'tool' => 'roads' );
	if ( ! empty( $_POST['roads_search'] ) ) {
		$args['roads_search'] = sanitize_text_field( wp_unslash( $_POST['roads_search'] ) );
	}
	if ( ! empty( $_POST['roads_page'] ) ) {
		$args['roads_page'] = (int) $_POST['roads_page'];
	}
	wp_safe_redirect( add_query_arg( $args, atk_get_page_url( 'instructor-dashboard' ) ) );
	exit;
}

add_action( 'admin_post_atk_road_rename', 'atk_handle_road_rename' );
function atk_handle_road_rename() {
	atk_instructor_verify_request();

	$original_name = isset( $_POST['original_name'] ) ? sanitize_text_field( wp_unslash( $_POST['original_name'] ) ) : '';
	$new_name      = isset( $_POST['new_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['new_name'] ) ) ) : '';

	if ( '' !== $original_name && '' !== $new_name ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . atk_table( 'road_overrides' ) . ' (original_name, new_name) VALUES (%s, %s)
			 ON DUPLICATE KEY UPDATE new_name = VALUES(new_name)',
			$original_name,
			$new_name
		) );
		atk_invalidate_major_roads_cache();
	}

	atk_instructor_roads_redirect_back();
}

add_action( 'admin_post_atk_road_toggle_findable', 'atk_handle_road_toggle_findable' );
function atk_handle_road_toggle_findable() {
	atk_instructor_verify_request();

	$findable = isset( $_POST['findable'] ) ? 1 : 0;
	global $wpdb;

	if ( ! empty( $_POST['original_name'] ) ) {
		$original_name = sanitize_text_field( wp_unslash( $_POST['original_name'] ) );
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . atk_table( 'road_overrides' ) . ' (original_name, findable) VALUES (%s, %d)
			 ON DUPLICATE KEY UPDATE findable = VALUES(findable)',
			$original_name,
			$findable
		) );
		atk_invalidate_major_roads_cache();
	} elseif ( ! empty( $_POST['addition_name'] ) ) {
		$name = sanitize_text_field( wp_unslash( $_POST['addition_name'] ) );
		$wpdb->update( atk_table( 'road_additions' ), array( 'findable' => $findable ), array( 'name' => $name ) );
		atk_invalidate_major_roads_cache();
	}

	atk_instructor_roads_redirect_back();
}

add_action( 'admin_post_atk_road_add', 'atk_handle_road_add' );
function atk_handle_road_add() {
	atk_instructor_verify_request();

	$name       = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
	$findable   = isset( $_POST['findable'] ) ? 1 : 0;
	$raw_points = isset( $_POST['points'] ) ? wp_unslash( $_POST['points'] ) : '';
	$points     = json_decode( $raw_points, true );

	$clean_points = array();
	if ( '' !== $name && is_array( $points ) ) {
		foreach ( $points as $point ) {
			if ( is_array( $point ) && 2 === count( $point ) && is_numeric( $point[0] ) && is_numeric( $point[1] ) ) {
				$clean_points[] = array( (float) $point[0], (float) $point[1] );
			}
		}
	}

	if ( '' !== $name && count( $clean_points ) >= 2 ) {
		global $wpdb;
		$wpdb->insert( atk_table( 'road_additions' ), array(
			'name'       => $name,
			'points'     => wp_json_encode( $clean_points ),
			'findable'   => $findable,
			'created_at' => current_time( 'mysql' ),
		) );
		atk_invalidate_major_roads_cache();
	}

	atk_instructor_roads_redirect_back();
}

/**
 * Snap-to-road lookup for the Add Road modal: given a clicked lat/lng,
 * fetches the real OpenStreetMap way nearest that point and returns its
 * exact geometry — the same "pull a named way's real points" technique
 * used to build the original 2,717-road dataset in the first place, just
 * on demand for one road instead of a bulk export. Genuinely async (the
 * instructor is mid-modal, drawing), so this is the one place in this
 * feature that uses a real AJAX round-trip rather than a plain form post.
 */
add_action( 'wp_ajax_atk_road_snap_lookup', 'atk_handle_road_snap_lookup' );
function atk_handle_road_snap_lookup() {
	atk_instructor_verify_request();

	$lat = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : null;
	$lng = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : null;

	if ( null === $lat || null === $lng ) {
		wp_send_json_error( array( 'message' => 'Missing coordinates.' ) );
	}

	$query    = sprintf( '[out:json][timeout:20];way(around:20,%F,%F)["highway"]["name"];out geom;', $lat, $lng );
	$response = wp_remote_post( 'https://overpass-api.de/api/interpreter', array(
		'timeout' => 20,
		'body'    => array( 'data' => $query ),
	) );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		wp_send_json_error( array( 'message' => 'Could not reach OpenStreetMap right now. Try again, or trace manually.' ) );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$ways = isset( $data['elements'] ) && is_array( $data['elements'] ) ? $data['elements'] : array();

	// Several ways can fall within the search radius (e.g. near a
	// junction) — use whichever one actually has a node closest to the
	// click, not just the first result Overpass happens to return.
	$best          = null;
	$best_distance = null;
	foreach ( $ways as $way ) {
		if ( empty( $way['geometry'] ) ) {
			continue;
		}
		foreach ( $way['geometry'] as $node ) {
			if ( ! isset( $node['lat'], $node['lon'] ) ) {
				continue;
			}
			$distance = atk_haversine_distance_metres( $lat, $lng, $node['lat'], $node['lon'] );
			if ( null === $best_distance || $distance < $best_distance ) {
				$best_distance = $distance;
				$best          = $way;
			}
		}
	}

	if ( ! $best ) {
		wp_send_json_error( array( 'message' => 'No named road found that close. Try clicking closer to the road, or trace manually.' ) );
	}

	$points = array();
	foreach ( $best['geometry'] as $node ) {
		if ( isset( $node['lat'], $node['lon'] ) ) {
			$points[] = array( $node['lat'], $node['lon'] );
		}
	}

	wp_send_json_success( array(
		'name'   => isset( $best['tags']['name'] ) ? $best['tags']['name'] : '',
		'points' => $points,
	) );
}

function atk_haversine_distance_metres( $lat1, $lng1, $lat2, $lng2 ) {
	$earth_radius_m = 6371000;
	$delta_lat      = deg2rad( $lat2 - $lat1 );
	$delta_lng      = deg2rad( $lng2 - $lng1 );
	$a              = sin( $delta_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $delta_lng / 2 ) ** 2;
	return $earth_radius_m * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
}

add_action( 'admin_post_atk_road_delete_addition', 'atk_handle_road_delete_addition' );
function atk_handle_road_delete_addition() {
	atk_instructor_verify_request();

	$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	if ( '' !== $name ) {
		global $wpdb;
		$wpdb->delete( atk_table( 'road_additions' ), array( 'name' => $name ) );
		atk_invalidate_major_roads_cache();
	}

	atk_instructor_roads_redirect_back();
}

/* -------------------------------------------------------------------- */
/* Rendering                                                             */
/* -------------------------------------------------------------------- */

/**
 * With one row per named road (2,700+), this table can run past 270 pages —
 * far too many to list as one flat row of numbers (that's the "so messy"
 * bug report this fixes). Bootstrap pagination handles that scale with
 * a Prev/Next arrow pair plus a windowed page list — first page, a few
 * pages either side of the current one, last page, "…" filling any gap —
 * the same truncation DataTables' own generated pagination uses. The
 * previous/next arrow markup (<i class="previous">/<i class="next"> inside
 * a .page-link) is Bootstrap's real component, not a custom icon: the
 * chevron glyph and its hover/active/disabled states all come from the
 * vendored style.bundle.css.
 */
function atk_render_instructor_roads_pagination( $current_page, $total_pages, $search ) {
	if ( $total_pages <= 1 ) {
		return;
	}

	$url_for = function ( $page ) use ( $search ) {
		$args = array( 'tab' => 'study-tools', 'tool' => 'roads', 'roads_page' => $page );
		if ( '' !== $search ) {
			$args['roads_search'] = $search;
		}
		return esc_url( add_query_arg( $args, atk_get_page_url( 'instructor-dashboard' ) ) );
	};

	$window = 2;
	$start  = max( 2, $current_page - $window );
	$end    = min( $total_pages - 1, $current_page + $window );

	$pages = array( 1 );
	if ( $start > 2 ) {
		$pages[] = '…';
	}
	for ( $p = $start; $p <= $end; $p++ ) {
		$pages[] = $p;
	}
	if ( $end < $total_pages - 1 ) {
		$pages[] = '…';
	}
	$pages[] = $total_pages;
	?>
	<nav aria-label="Major Streets roads pages" class="mt-4 d-flex justify-content-center">
		<ul class="pagination pagination-sm mb-0">
			<li class="page-item previous<?php echo 1 === $current_page ? ' disabled' : ''; ?>">
				<a href="<?php echo 1 === $current_page ? '#' : $url_for( $current_page - 1 ); ?>" class="page-link"><i class="previous"></i></a>
			</li>
			<?php foreach ( $pages as $p ) : ?>
				<?php if ( '…' === $p ) : ?>
					<li class="page-item disabled"><a href="#" class="page-link">…</a></li>
				<?php else : ?>
					<li class="page-item<?php echo $p === $current_page ? ' active' : ''; ?>">
						<a class="page-link" href="<?php echo $url_for( $p ); ?>"><?php echo esc_html( $p ); ?></a>
					</li>
				<?php endif; ?>
			<?php endforeach; ?>
			<li class="page-item next<?php echo $current_page === $total_pages ? ' disabled' : ''; ?>">
				<a href="<?php echo $current_page === $total_pages ? '#' : $url_for( $current_page + 1 ); ?>" class="page-link"><i class="next"></i></a>
			</li>
		</ul>
	</nav>
	<?php
}

function atk_render_instructor_roads_tab() {
	atk_enqueue_leaflet();
	wp_enqueue_script( 'atk-instructor-roads', ATK_PLUGIN_URI . '/assets/js/instructor-roads.js', array( 'atk-scripts-bundle', 'leaflet' ), filemtime( ATK_PLUGIN_DIR . '/assets/js/instructor-roads.js' ), true );
	wp_add_inline_script( 'atk-instructor-roads', 'var ATK_INSTRUCTOR_ROADS = ' . wp_json_encode( array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
	) ) . ';', 'before' );

	$search = isset( $_GET['roads_search'] ) ? sanitize_text_field( wp_unslash( $_GET['roads_search'] ) ) : '';
	$rows   = atk_effective_roads_list();
	if ( '' !== $search ) {
		$rows = array_values( array_filter( $rows, function ( $row ) use ( $search ) {
			return false !== stripos( $row['name'], $search );
		} ) );
	}

	$current_page = isset( $_GET['roads_page'] ) ? max( 1, (int) $_GET['roads_page'] ) : 1;
	$total_pages  = max( 1, (int) ceil( count( $rows ) / ATK_RESULTS_PER_PAGE ) );
	$page_rows    = array_slice( $rows, ( $current_page - 1 ) * ATK_RESULTS_PER_PAGE, ATK_RESULTS_PER_PAGE );
	?>
	<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
		<form method="get" action="<?php echo esc_url( atk_get_page_url( 'instructor-dashboard' ) ); ?>" class="d-flex gap-2">
			<input type="hidden" name="tab" value="study-tools" />
			<input type="hidden" name="tool" value="roads" />
			<input type="text" name="roads_search" class="form-control form-control-sm" placeholder="Search roads&hellip;" value="<?php echo esc_attr( $search ); ?>" style="width: 240px;" />
			<button type="submit" class="btn btn-sm btn-light-primary">Search</button>
		</form>
		<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#atk-add-road-modal"><i class="bi bi-plus-lg me-1"></i>Add Road</button>
	</div>

	<div class="card">
		<div class="table-responsive">
			<table class="table table-row-dashed align-middle">
				<thead>
					<tr class="fw-bolder text-gray-500">
						<th>Road Name</th>
						<th class="w-125px">Segments</th>
						<th class="w-100px">Findable</th>
						<th class="w-100px"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $page_rows ) ) : ?>
						<tr><td colspan="4" class="text-gray-600">No roads found.</td></tr>
					<?php endif; ?>
					<?php foreach ( $page_rows as $row ) : ?>
						<tr>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="d-flex align-items-center gap-2">
									<input type="hidden" name="action" value="atk_road_rename" />
									<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
									<?php if ( $row['original_name'] ) : ?>
										<input type="hidden" name="original_name" value="<?php echo esc_attr( $row['original_name'] ); ?>" />
										<input type="text" name="new_name" value="<?php echo esc_attr( $row['name'] ); ?>" class="form-control form-control-sm flex-grow-1" style="min-width: 0; max-width: 420px;" />
										<button type="submit" class="btn btn-sm btn-light-primary flex-shrink-0">Save</button>
									<?php else : ?>
										<span class="fw-bold"><?php echo esc_html( $row['name'] ); ?></span>
									<?php endif; ?>
									<?php if ( $row['is_addition'] ) : ?>
										<span class="badge badge-light-info">Added<?php echo ! empty( $row['has_additions'] ) && $row['original_name'] ? ' (+ instructor segment)' : ''; ?></span>
									<?php elseif ( ! empty( $row['has_additions'] ) ) : ?>
										<span class="badge badge-light-info">+ instructor segment</span>
									<?php endif; ?>
								</form>
							</td>
							<td><?php echo esc_html( $row['segment_count'] ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="atk_road_toggle_findable" />
									<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
									<?php if ( $row['original_name'] ) : ?>
										<input type="hidden" name="original_name" value="<?php echo esc_attr( $row['original_name'] ); ?>" />
									<?php else : ?>
										<input type="hidden" name="addition_name" value="<?php echo esc_attr( $row['name'] ); ?>" />
									<?php endif; ?>
									<div class="form-check form-switch">
										<input class="form-check-input" type="checkbox" name="findable" value="1" onchange="this.form.submit()"<?php checked( $row['findable'] ); ?> />
									</div>
								</form>
							</td>
							<td>
								<?php if ( $row['is_addition'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete this road?');">
										<input type="hidden" name="action" value="atk_road_delete_addition" />
										<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
										<input type="hidden" name="name" value="<?php echo esc_attr( $row['name'] ); ?>" />
										<button type="submit" class="btn btn-sm btn-light-danger">Delete</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php atk_render_instructor_roads_pagination( $current_page, $total_pages, $search ); ?>

	<div class="modal fade" id="atk-add-road-modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title">Add a New Road</h3>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div class="btn-group mb-4" role="group">
						<input type="radio" class="btn-check" name="atk-add-road-mode" id="atk-add-road-mode-snap" checked />
						<label class="btn btn-sm btn-outline btn-outline-primary" for="atk-add-road-mode-snap">Snap to Road</label>
						<input type="radio" class="btn-check" name="atk-add-road-mode" id="atk-add-road-mode-manual" />
						<label class="btn btn-sm btn-outline btn-outline-primary" for="atk-add-road-mode-manual">Trace Manually</label>
						<input type="radio" class="btn-check" name="atk-add-road-mode" id="atk-add-road-mode-roundabout" />
						<label class="btn btn-sm btn-outline btn-outline-primary" for="atk-add-road-mode-roundabout">Roundabout</label>
					</div>
					<p class="text-gray-600 fs-7 mb-4" id="atk-add-road-instructions">Click once on a real road and its exact shape is fetched from OpenStreetMap automatically.</p>
					<div id="atk-add-road-map" style="height: 360px;" class="rounded mb-4"></div>
					<div class="d-flex align-items-center gap-2 mb-6">
						<button type="button" id="atk-add-road-undo" class="btn btn-sm btn-light" disabled>Undo Last Point</button>
						<button type="button" id="atk-add-road-clear" class="btn btn-sm btn-light" disabled>Clear</button>
						<span class="fs-7 text-gray-500" id="atk-add-road-point-count">0 points</span>
						<span class="fs-7 text-gray-500 d-none" id="atk-add-road-status"></span>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="atk-add-road-form">
						<input type="hidden" name="action" value="atk_road_add" />
						<?php wp_nonce_field( ATK_INSTRUCTOR_NONCE_ACTION, 'atk_nonce' ); ?>
						<input type="hidden" name="points" id="atk-add-road-points" value="" />
						<div class="mb-4">
							<label class="form-label">Road Name</label>
							<?php echo atk_render_single_select( 'atk-add-road-name', 'name', atk_effective_road_names_only() ); ?>
						</div>
						<div class="form-check form-switch mb-6">
							<input class="form-check-input" type="checkbox" name="findable" value="1" id="atk-add-road-findable" checked />
							<label class="form-check-label" for="atk-add-road-findable">Findable (counts as a quiz target)</label>
						</div>
						<button type="submit" class="btn btn-primary" id="atk-add-road-submit" disabled>Save Road</button>
					</form>
				</div>
			</div>
		</div>
	</div>
	<?php
}
