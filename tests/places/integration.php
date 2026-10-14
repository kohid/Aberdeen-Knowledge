<?php
/**
 * WordPress-dependent checks for Places of Interest (store, REST, dashboard, public page).
 * Run against a throwaway WordPress with the plugin active:
 *
 *     ATK_WP_LOAD=/path/to/wp-load.php php tests/places/integration.php
 *
 * It creates its own instructor and rows (all named "ZZ-IT ...") and removes them at the end.
 */
$load = getenv( 'ATK_WP_LOAD' );
if ( ! $load || ! file_exists( $load ) ) {
	fwrite( STDERR, "Set ATK_WP_LOAD to the path of wp-load.php.\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']      = getenv( 'ATK_WP_HOST' ) ? getenv( 'ATK_WP_HOST' ) : '127.0.0.1:8099';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $load;
require_once ABSPATH . 'wp-admin/includes/user.php';

$fail = 0;
$n    = 0;
function t( $label, $actual, $expected ) {
	global $fail, $n;
	$n++;
	if ( $actual === $expected ) {
		echo "ok    $label\n";
		return;
	}
	$fail++;
	echo "FAIL  $label\n      expected: " . json_encode( $expected ) . "\n      actual:   " . json_encode( $actual ) . "\n";
}
function it_clean() {
	global $wpdb;
	$wpdb->query( "DELETE FROM " . atk_table( 'poi_places' ) . " WHERE name LIKE 'ZZ-IT %'" );
	$wpdb->query( "DELETE FROM " . atk_table( 'nb_neighbourhoods' ) . " WHERE name LIKE 'ZZ-IT %'" );
	ATK_Poi_Store::flush_cache();
}
function it_row( $line, $name, $address, $postcode, $ward, $category = '', $status = '' ) {
	return array( 'line' => $line, 'name' => $name, 'address' => $address, 'postcode' => $postcode, 'ward' => $ward, 'category' => $category, 'status' => $status, 'note' => '' );
}

it_clean();
$user_id = wp_insert_user( array( 'user_login' => 'zz-it-instructor-' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'instructor' ) );
wp_set_current_user( $user_id );

/* ---- Task 4: table and store ---- */
t( 'the store lists nothing on a clean table', ATK_Poi_Store::all(), array() );

$empty = ATK_Poi_Store::save( array( 'name' => '   ' ) );
t( 'an empty name is rejected', is_wp_error( $empty ), true );

$club = ATK_Poi_Store::save( array( 'name' => 'ZZ-IT 22 CLUB', 'address' => '55 Rose street, AB10 1UB', 'postcode' => 'ab10  1ub', 'category' => 'Nonsense' ) );
t( 'save creates a place', is_array( $club ) && $club['id'] > 0, true );
t( 'the postcode is tidied', $club['postcode'], 'AB10 1UB' );
t( 'an unknown category becomes Other Amenities', $club['category'], 'Other Amenities' );
t( 'a new place has no pin', array( $club['lat'], $club['geo_state'] ), array( null, 'none' ) );

$pinned = ATK_Poi_Store::set_pin( $club['id'], 57.1488, -2.1019, 'manual' );
t( 'a hand-placed pin is stored', array( $pinned['lat'], $pinned['geo_state'] ), array( 57.1488, 'manual' ) );
$outside = ATK_Poi_Store::set_pin( $club['id'], 51.5, -0.12, 'looked_up' );
t( 'a pin outside the Aberdeen box is rejected', is_wp_error( $outside ), true );
$same = ATK_Poi_Store::set_pin( $club['id'], 57.2, -2.2, 'looked_up' );
t( 'a lookup never replaces a hand-placed pin', array( $same['lat'], $same['geo_state'] ), array( 57.1488, 'manual' ) );
$edited = ATK_Poi_Store::save( array( 'address' => '1 New street, AB10 1UB' ), $club['id'] );
t( 'changing the address keeps a hand-placed pin', array( $edited['lat'], $edited['geo_state'] ), array( 57.1488, 'manual' ) );

// Import: commit twice.
$rows = array(
	it_row( 2, 'ZZ-IT Alpha', '55 Rose street, AB10 9ZA', 'AB10 9ZA', 'ZZ-IT Ward One' ),
	it_row( 3, 'ZZ-IT Beta', '9 Alford Place, Aberdeen', 'AB10 9ZB', 'ZZ-IT Ward One', 'hotels' ),
	it_row( 4, 'ZZ-IT Gamma', '1 Rose street, AB10 9ZC', 'AB10 9ZC', '' ),
);
$first = ATK_Poi_Store::commit( $rows, array( 2 ), array( 4 => 'Typed Street' ) );
t( 'commit adds the rows', array( $first['added'], $first['updated'], $first['skipped'] ), array( 3, 0, 0 ) );
t( 'commit creates the ward asked for, once', $first['wards_created'], 1 );
t( 'every new place needs a lookup', count( $first['lookups'] ), 3 );
$second = ATK_Poi_Store::commit( $rows, array( 2 ), array() );
t( 'a second commit updates instead of adding', array( $second['added'], $second['updated'] ), array( 0, 3 ) );
t( 'a second commit creates no ward', $second['wards_created'], 0 );

$all = array();
foreach ( ATK_Poi_Store::all() as $p ) {
	$all[ $p['name'] ] = $p;
}
t( 'the street override was stored', $all['ZZ-IT Gamma']['street_answer'], 'Typed Street' );
t( 'a blank street on re-import keeps the override', $all['ZZ-IT Gamma']['street_answer'], 'Typed Street' );
t( 'the category came from the sheet', $all['ZZ-IT Beta']['category'], 'Hotels' );
t( 'the ward is linked', $all['ZZ-IT Alpha']['ward_id'] > 0, true );

// Blank cells never erase.
ATK_Poi_Store::commit( array( it_row( 2, 'ZZ-IT Beta', '', 'AB10 9ZB', '' ) ), array(), array() );
$beta = null;
foreach ( ATK_Poi_Store::all() as $p ) {
	if ( 'ZZ-IT Beta' === $p['name'] ) {
		$beta = $p;
	}
}
t( 'blank address, ward and category leave saved values', array( $beta['address'], $beta['ward_id'] > 0, $beta['category'] ), array( '9 Alford Place, Aberdeen', true, 'Hotels' ) );

// Sections and deleted wards.
$sections = ATK_Poi_Store::sections();
$names    = array_map( function ( $s ) { return $s['ward']; }, $sections );
t( 'the new ward has its own section', in_array( 'ZZ-IT Ward One', $names, true ), true );
t( 'places with no ward land in No ward yet', end( $names ), 'No ward yet' );
$ward_id = $all['ZZ-IT Alpha']['ward_id'];
ATK_Nb_Store::delete_neighbourhood( $ward_id );
$after = array_map( function ( $s ) { return $s['ward']; }, ATK_Poi_Store::sections() );
t( 'a deleted ward no longer has a section', in_array( 'ZZ-IT Ward One', $after, true ), false );
$last = end( $sections );
$lastNow = ATK_Poi_Store::sections();
$lastNow = end( $lastNow );
t( 'places of a deleted ward move to No ward yet', array( $lastNow['ward'], count( $lastNow['places'] ) >= 3 ), array( 'No ward yet', true ) );

// Venues for the public map.
$a = ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Twin', 'postcode' => 'AB10 1AA' ) );
$b = ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Twin', 'postcode' => 'AB11 2BB' ) );
ATK_Poi_Store::set_pin( $a['id'], 57.15, -2.1, 'manual' );
ATK_Poi_Store::set_pin( $b['id'], 57.16, -2.11, 'manual' );
$venue_names = array_map( function ( $v ) { return $v['name']; }, ATK_Poi_Store::venues() );
t( 'unpinned places stay off the map', in_array( 'ZZ-IT Alpha', $venue_names, true ), false );
t( 'repeated names get their postcode', in_array( 'ZZ-IT Twin (AB10 1AA)', $venue_names, true ) && in_array( 'ZZ-IT Twin (AB11 2BB)', $venue_names, true ), true );
t( 'delete removes a place', ATK_Poi_Store::delete( $a['id'] ), true );

/* ---- Task 5: REST routes ---- */
function it_rest( $method, $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/atk/v1/' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}
rest_get_server();

wp_set_current_user( 0 );
$anon = it_rest( 'GET', 'poi-places' );
t( 'a logged-out visitor cannot read the places list', in_array( $anon->get_status(), array( 401, 403 ), true ), true );
$anon_write = it_rest( 'POST', 'poi-places', array( 'name' => 'ZZ-IT Anon' ) );
t( 'a logged-out visitor cannot create a place', in_array( $anon_write->get_status(), array( 401, 403 ), true ), true );
$public = it_rest( 'GET', 'poi-venues' );
t( 'the public venues route answers without login', $public->get_status(), 200 );
$headers = $public->get_headers();
t( 'the public venues route is cached for one minute', isset( $headers['Cache-Control'] ) ? $headers['Cache-Control'] : '', 'public, max-age=60' );
t( 'the public venues route returns a venues list', is_array( $public->get_data()['venues'] ), true );
wp_set_current_user( $user_id );

$made = it_rest( 'POST', 'poi-places', array( 'name' => 'ZZ-IT Rest', 'address' => '1 Rose street, AB10 1AA', 'postcode' => 'ab10 1aa' ) );
t( 'create answers 200 with the place', array( $made->get_status(), $made->get_data()['postcode'] ), array( 200, 'AB10 1AA' ) );
$rid     = $made->get_data()['id'];
$updated = it_rest( 'POST', 'poi-places/' . $rid, array( 'category' => 'Hotels', 'street_answer' => 'ROSE STREET' ) );
t( 'update changes only what was sent', array( $updated->get_data()['category'], $updated->get_data()['name'] ), array( 'Hotels', 'ZZ-IT Rest' ) );
$bad = it_rest( 'POST', 'poi-places', array( 'name' => '  ' ) );
t( 'create without a name is a 400', $bad->get_status(), 400 );
$missing = it_rest( 'POST', 'poi-places/99999999', array( 'name' => 'x' ) );
t( 'updating a missing place is a 404', $missing->get_status(), 404 );

$list = it_rest( 'GET', 'poi-places' );
$data = $list->get_data();
t( 'the list has sections, wards, categories and counts', array_keys( $data ), array( 'sections', 'wards', 'categories', 'counts' ) );
t( 'counts add up', $data['counts']['total'], $data['counts']['pinned'] + $data['counts']['no_pin'] );
t( 'the list offers eleven categories', count( $data['categories'] ), 11 );

$pin = it_rest( 'POST', 'poi-places/' . $rid . '/pin', array( 'lat' => 57.15, 'lng' => -2.1, 'state' => 'looked_up' ) );
t( 'pin stores a lookup result', array( $pin->get_status(), $pin->get_data()['geo_state'] ), array( 200, 'looked_up' ) );
$far = it_rest( 'POST', 'poi-places/' . $rid . '/pin', array( 'lat' => 51.5, 'lng' => -0.12, 'state' => 'manual' ) );
t( 'pin outside Aberdeen is a 400', $far->get_status(), 400 );
$venues_after = it_rest( 'GET', 'poi-venues' )->get_data()['venues'];
t( 'a pinned place shows on the public route', in_array( 'ZZ-IT Rest', array_column( $venues_after, 'name' ), true ), true );

$sheet = array(
	array( 'Place of interest', 'Street', 'Postcode', 'Ward' ),
	array( '22 CLUB', '55 Rose street, AB10 1UB', 'AB10 1UB', '' ),
	array( 'Babylon', '9 Alford Place, Aberdeen', 'AB10 1YD', '' ),
	array( 'Aberdeen Blood Donor Centre', 'Foresterhill, AB25 2ZW', 'AB25 2ZW', '' ),
);
$preview = it_rest( 'POST', 'poi-places/preview', array( 'rows' => $sheet ) )->get_data();
t( 'preview lists the three rows', count( $preview['rows'] ), 3 );
t( 'preview counts add up', $preview['counts'], array( 'add' => 3, 'update' => 0, 'skip' => 0, 'needs_street' => 1, 'new_ward' => 0 ) );
t( 'preview has no error', $preview['error'], null );
$pasted = it_rest( 'POST', 'poi-places/preview', array( 'text' => "Place of interest\tStreet\tPostcode\tWard\nBabylon\t9 Alford Place\tAB10 1YD\t\n" ) )->get_data();
t( 'pasted tab text is read the same way', count( $pasted['rows'] ), 1 );
$noname = it_rest( 'POST', 'poi-places/preview', array( 'rows' => array( array( 'Foo', 'Bar' ), array( 'a', 'b' ) ) ) )->get_data();
t( 'a sheet without a place column returns an error and no rows', array( $noname['error'], $noname['rows'] ), array( 'Could not find a "Place of interest" column.', array() ) );
$huge = array( array( 'Place of interest', 'Street' ), array( str_repeat( 'x', 900 ), 'Rose street' ) );
$cut  = it_rest( 'POST', 'poi-places/preview', array( 'rows' => $huge ) )->get_data();
t( 'a very long cell is cut to 500 characters', mb_strlen( $cut['rows'][0]['name'] ), 500 );

$commit_sheet = array(
	array( 'Place of interest', 'Street', 'Postcode', 'Ward' ),
	array( 'ZZ-IT Bare', 'Foresterhill, AB25 2ZW', 'AB25 2ZW', '' ),
);
$done = it_rest( 'POST', 'poi-places/commit', array( 'rows' => $commit_sheet, 'create_wards' => array(), 'street_overrides' => array( 2 => 'Typed Road' ) ) );
t( 'commit adds the row', array( $done->get_status(), $done->get_data()['added'] ), array( 200, 1 ) );
$bare = null;
foreach ( ATK_Poi_Store::all() as $p ) {
	if ( 'ZZ-IT Bare' === $p['name'] ) {
		$bare = $p;
	}
}
t( 'commit stores the street typed in the review', $bare ? $bare['street_answer'] : null, 'Typed Road' );

$deleted = it_rest( 'DELETE', 'poi-places/' . $rid );
t( 'delete answers deleted true', array( $deleted->get_status(), $deleted->get_data() ), array( 200, array( 'deleted' => true ) ) );
$gone = it_rest( 'DELETE', 'poi-places/' . $rid );
t( 'deleting again is a 404', $gone->get_status(), 404 );


/* ---- Task 6: Study Tools tab ---- */
function it_dashboard( array $get ) {
	$_GET = $get;
	$html = do_shortcode( '[atk_instructor_dashboard]' );
	$_GET = array();
	return $html;
}
$old = it_dashboard( array( 'tab' => 'roads' ) );
t( 'the nav shows Study Tools', strpos( $old, '>Study Tools</a>' ) !== false, true );
preg_match( '#<ul class="nav nav-tabs nav-line-tabs nav-line-tabs-2x.*?</ul>#s', $old, $top );
t( 'there is no top-level Major Streets nav link', ! empty( $top ) && strpos( $top[0], 'Major Streets' ) === false, true );
t( 'the old roads address opens the Major Streets editor', strpos( $old, 'name="roads_search"' ) !== false, true );
t( 'the old roads address shows the Study Tools sub-tabs', strpos( $old, 'tool=poi' ) !== false && strpos( $old, 'Places of Interest' ) !== false, true );
t( 'the roads search form stays on Study Tools', strpos( $old, 'name="tab" value="study-tools"' ) !== false && strpos( $old, 'name="tool" value="roads"' ) !== false, true );
$new = it_dashboard( array( 'tab' => 'study-tools' ) );
t( 'study-tools defaults to Major Streets', strpos( $new, 'name="roads_search"' ) !== false, true );
$poi = it_dashboard( array( 'tab' => 'study-tools', 'tool' => 'poi' ) );
t( 'tool=poi does not show the roads editor', strpos( $poi, 'name="roads_search"' ) === false, true );
t( 'tool=poi marks Places of Interest active', (bool) preg_match( '/class="nav-link active"[^>]*>Places of Interest</', $poi ), true );
$junk = it_dashboard( array( 'tab' => 'study-tools', 'tool' => 'nonsense' ) );
t( 'an unknown tool falls back to Major Streets', strpos( $junk, 'name="roads_search"' ) !== false, true );


/* ---- Task 7: Places of Interest screen ---- */
$poi_html = it_dashboard( array( 'tab' => 'study-tools', 'tool' => 'poi' ) );
t( 'the Places screen has its root element', strpos( $poi_html, 'id="atk-poi-admin-root"' ) !== false, true );
t( 'the Places script is enqueued', wp_script_is( 'atk-instructor-poi-places', 'enqueued' ), true );
$before = wp_scripts()->get_data( 'atk-instructor-poi-places', 'before' );
$before = is_array( $before ) ? implode( '', $before ) : (string) $before;
t( 'the config carries the REST address, a nonce and the categories', strpos( $before, 'var ATK_POI_ADMIN' ) !== false && strpos( $before, 'restUrl' ) !== false && strpos( $before, 'nonce' ) !== false && strpos( $before, 'Other Amenities' ) !== false, true );
t( 'the Coming soon placeholder is gone', strpos( $poi_html, 'coming soon' ) === false, true );


/* ---- Task 8: import flow as the browser sends it ---- */
$imp_sheet = array(
	array( 'Place of interest', 'Street', 'Postcode', 'Ward' ),
	array( 'ZZ-IT Imp One', '55 Rose street, AB10 9YA', 'AB10 9YA', 'ZZ-IT Import Ward' ),
	array( 'ZZ-IT Imp Two', 'Foresterhill, AB25 9YB', 'AB25 9YB', 'ZZ-IT Import Ward' ),
);
$imp_prev = it_rest( 'POST', 'poi-places/preview', array( 'rows' => $imp_sheet ) )->get_data();
t( 'import preview flags the new ward twice and one missing street', array( $imp_prev['counts']['new_ward'], $imp_prev['counts']['needs_street'] ), array( 2, 1 ) );
$imp_done = it_rest( 'POST', 'poi-places/commit', array( 'rows' => $imp_sheet, 'create_wards' => array( 2 ), 'street_overrides' => array( 3 => 'Typed Import Road' ) ) )->get_data();
t( 'import commit adds both and creates the ward once', array( $imp_done['added'], $imp_done['wards_created'] ), array( 2, 1 ) );
$imp_two = null;
$imp_one = null;
foreach ( ATK_Poi_Store::all() as $p ) {
	if ( 'ZZ-IT Imp Two' === $p['name'] ) {
		$imp_two = $p;
	}
	if ( 'ZZ-IT Imp One' === $p['name'] ) {
		$imp_one = $p;
	}
}
t( 'both rows share the created ward', $imp_one && $imp_two && $imp_one['ward_id'] > 0 && $imp_one['ward_id'] === $imp_two['ward_id'], true );
t( 'the typed street was kept for the row that needed one', $imp_two ? $imp_two['street_answer'] : null, 'Typed Import Road' );
$imp_again = it_rest( 'POST', 'poi-places/preview', array( 'rows' => $imp_sheet ) )->get_data();
t( 'a second preview of the same sheet is all updates, no new ward', array( $imp_again['counts']['update'], $imp_again['counts']['new_ward'] ), array( 2, 0 ) );


/* ---- Task 9: pin states ---- */
$pin_a = ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Pin A', 'address' => '1 Rose street' ) );
$pin_b = ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Pin B', 'address' => '2 Rose street' ) );
$nf = it_rest( 'POST', 'poi-places/' . $pin_a['id'] . '/pin', array( 'state' => 'not_found' ) );
t( 'the pin route stores not_found with no coordinates', array( $nf->get_status(), $nf->get_data()['geo_state'], $nf->get_data()['lat'] ), array( 200, 'not_found', null ) );
$lk = it_rest( 'POST', 'poi-places/' . $pin_b['id'] . '/pin', array( 'lat' => 57.14, 'lng' => -2.09, 'state' => 'looked_up' ) );
t( 'the pin route stores looked_up', array( $lk->get_status(), $lk->get_data()['geo_state'] ), array( 200, 'looked_up' ) );
$out = it_rest( 'POST', 'poi-places/' . $pin_b['id'] . '/pin', array( 'lat' => 0, 'lng' => 0, 'state' => 'looked_up' ) );
t( 'an outside-box coordinate is a 400 and leaves the pin alone', array( $out->get_status(), ATK_Poi_Store::get( $pin_b['id'] )['lat'] ), array( 400, 57.14 ) );
$waiting = array();
foreach ( it_rest( 'GET', 'poi-places' )->get_data()['sections'] as $sec ) {
	foreach ( $sec['places'] as $pl ) {
		if ( 'none' === $pl['geo_state'] && 0 === strpos( $pl['name'], 'ZZ-IT Pin' ) ) {
			$waiting[] = $pl['name'];
		}
	}
}
t( 'a not_found place is not waiting for a lookup', $waiting, array() );
$bad_state = it_rest( 'POST', 'poi-places/' . $pin_b['id'] . '/pin', array( 'lat' => 57.14, 'lng' => -2.09, 'state' => 'bogus' ) );
t( 'an unknown pin state is a 400', $bad_state->get_status(), 400 );


/* ---- Task 10: public page ---- */
it_clean();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . atk_table( 'poi_places' ) . " WHERE lat IS NOT NULL AND name LIKE 'ZZ-IT %'" );
ATK_Poi_Store::flush_cache();
$real_pinned = array_filter( ATK_Poi_Store::all(), function ( $p ) { return null !== $p['lat']; } );
if ( ! $real_pinned ) {
	t( 'with no pinned places the venues list is empty', atk_poi_venues(), array() );
	ob_start();
	atk_render_poi_map();
	$empty_html = ob_get_clean();
	t( 'the empty map shows the empty-state text', strpos( $empty_html, 'Places will appear here once an instructor imports them.' ) !== false, true );
	t( 'the empty-state card is visible when there are no places', (bool) preg_match( '/id="atk-poi-empty" class="card[^"]*"/', $empty_html, $m ) && strpos( $m[0], 'd-none' ) === false, true );
}
$mk = function ( $name, $status ) {
	$p = ATK_Poi_Store::save( array( 'name' => $name, 'address' => '1 Rose street', 'status' => $status ) );
	ATK_Poi_Store::set_pin( $p['id'], 57.15, -2.1, 'looked_up' );
};
$mk( 'ZZ-IT Open Place <b>x</b>', 'current' );
$mk( 'ZZ-IT Closed Place', 'closed' );
$names = array_column( ATK_Poi_Store::venues(), 'name' );
t( 'both pinned places are on the map list', array( in_array( 'ZZ-IT Open Place <b>x</b>', $names, true ), in_array( 'ZZ-IT Closed Place', $names, true ) ), array( true, true ) );
$zz_current = array_filter( ATK_Poi_Store::venues(), function ( $v ) { return 0 === strpos( $v['name'], 'ZZ-IT ' ) && 'closed' !== $v['status']; } );
t( 'only the open place counts as current', count( $zz_current ), 1 );
$src = file_get_contents( ATK_PLUGIN_DIR . '/inc/poi-map.php' );
t( 'the map no longer reads poi.json', strpos( $src, 'poi.json' ), false );
t( 'the map script reads the REST route', strpos( $src, "rest_url( 'atk/v1/poi-venues' )" ) !== false, true );
t( 'the old data files are gone', array( file_exists( ATK_PLUGIN_DIR . '/assets/data/poi.json' ), file_exists( ATK_PLUGIN_DIR . '/assets/data/poi-source.csv' ) ), array( false, false ) );


/* ---- Task 11: tests and Course Builder use the places ---- */
it_clean();
ob_start();
atk_render_quiz( 'poi' );
$empty_quiz = ob_get_clean();
t( 'the POI test with no places says so and has no Start button', strpos( $empty_quiz, 'once an instructor imports them' ) !== false && strpos( $empty_quiz, 'id="atk-quiz-start"' ) === false, true );
$cache11 = null;
$wa = ATK_Nb_Store::ensure_neighbourhood( 'ZZ-IT Ward A', $cache11 )[0];
$wb = ATK_Nb_Store::ensure_neighbourhood( 'ZZ-IT Ward B', $cache11 )[0];
ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Q Good', 'street_answer' => 'ROSE STREET', 'ward_id' => $wa ) );
ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Q No Street', 'street_answer' => '', 'ward_id' => $wa ) );
ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Q Closed', 'street_answer' => 'KING STREET', 'status' => 'closed', 'ward_id' => $wb ) );
ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Q Other Ward', 'street_answer' => 'UNION STREET', 'ward_id' => $wb ) );
$qs = array_values( array_filter( atk_poi_test_questions(), function ( $q ) { return 0 === strpos( $q['poi'], 'ZZ-IT ' ); } ) );
$qnames = array_column( $qs, 'poi' );
t( 'test questions use only current places with a street answer', $qnames, array( 'ZZ-IT Q Good', 'ZZ-IT Q Other Ward' ) );
t( 'accepted holds exactly the street answer', $qs[0]['accepted'], array( 'ROSE STREET' ) );
t( 'the old typed questions no longer feed the test', in_array( 'Marischal College', array_column( atk_poi_test_questions(), 'poi' ), true ), false );
$known = atk_instructor_known_poi_names();
t( 'known POI names are the saved places', array( in_array( 'ZZ-IT Q Good', $known, true ), in_array( 'Marischal College', $known, true ) ), array( true, false ) );
$wp = ATK_Poi_Store::week_places( array(
	array( 'id' => 901, 'neighbourhoods' => array( 'ZZ-IT Ward A' ) ),
	array( 'id' => 902, 'neighbourhoods' => array( 'ZZ-IT Ward A', 'ZZ-IT Ward B' ) ),
	array( 'id' => 903, 'neighbourhoods' => array( 'No Such Ward' ) ),
) );
t( 'week_places lists only that week\'s ward places', array_column( $wp[901], 'name' ), array( 'ZZ-IT Q Good', 'ZZ-IT Q No Street' ) );
t( 'a week with two wards gets both wards\' places', count( $wp[902] ), 4 );
t( 'a week whose ward holds nothing is left out', isset( $wp[903] ), false );
t( 'week_places carries the street', $wp[901][0]['street'], 'ROSE STREET' );
ob_start();
atk_render_instructor_poi_questions();
$bank = ob_get_clean();
ob_start();
atk_render_quiz( 'poi' );
$some_quiz = ob_get_clean();
t( 'the intro never promises more questions than the bank holds', strpos( $some_quiz, '2 questions' ) !== false, true );
t( 'the Question Bank POI tab links to Study Tools', strpos( $bank, 'tool=poi' ) !== false, true );
t( 'the old typed question list and bulk editor are gone from the page', strpos( $bank, 'atk-poi-bulk-form' ) === false && strpos( $bank, 'atk-poi-modal' ) === false, true );

/* ---- review fixes ---- */
it_clean();
$wards_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . atk_table( 'nb_neighbourhoods' ) );
ATK_Poi_Store::commit( array(
	it_row( 2, 'ZZ-IT Spell One', '', 'AB10 1AA', 'ZZ-IT Mid / Ros' ),
	it_row( 3, 'ZZ-IT Spell Two', '', 'AB10 1AB', 'ZZ-IT Mid/Ros' ),
), array( 2 ), array() );
t( 'two spellings of one new ward make one neighbourhood', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . atk_table( 'nb_neighbourhoods' ) . " WHERE name LIKE 'ZZ-IT Mid%'" ), 1 );
$spell = array_values( array_filter( ATK_Poi_Store::all(), function ( $p ) { return strpos( $p['name'], 'ZZ-IT Spell' ) === 0; } ) );
t( 'both places sit in that one ward', count( array_unique( array_column( $spell, 'ward_id' ) ) ) === 1 && $spell[0]['ward_id'] > 0, true );

$purged = array();
add_action( 'litespeed_purge_url', function ( $url ) use ( &$purged ) { $purged[] = $url; } );
ATK_Poi_Store::flush_cache();
t( 'a write purges the public venues address from the server cache', in_array( rest_url( 'atk/v1/poi-venues' ), $purged, true ), true );
wp_set_current_user( 0 );
$lh = it_rest( 'GET', 'poi-venues' )->get_headers();
t( 'the venues route tells LiteSpeed to keep it for one minute', isset( $lh['X-LiteSpeed-Cache-Control'] ) ? $lh['X-LiteSpeed-Cache-Control'] : '', 'public,max-age=60' );
wp_set_current_user( $user_id );

ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Dup', 'postcode' => 'AB10 1AA', 'street_answer' => 'AAA ROAD', 'status' => 'current' ) );
ATK_Poi_Store::save( array( 'name' => 'ZZ-IT Dup', 'postcode' => 'AB11 2BB', 'street_answer' => 'BBB ROAD', 'status' => 'current' ) );
$dq = array();
foreach ( atk_poi_test_questions() as $q ) {
	if ( strpos( $q['poi'], 'ZZ-IT Dup' ) === 0 ) {
		$dq[ $q['poi'] ] = $q['accepted'][0];
	}
}
t( 'places that share a name become two distinct test questions', $dq, array( 'ZZ-IT Dup (AB10 1AA)' => 'AAA ROAD', 'ZZ-IT Dup (AB11 2BB)' => 'BBB ROAD' ) );
t( 'the Course Builder picker lists both places apart', array_values( array_intersect( atk_instructor_known_poi_names(), array( 'ZZ-IT Dup (AB10 1AA)', 'ZZ-IT Dup (AB11 2BB)', 'ZZ-IT Dup' ) ) ), array( 'ZZ-IT Dup (AB10 1AA)', 'ZZ-IT Dup (AB11 2BB)' ) );

/* ---- Official POI course: weeks 1 to 13 ---- */
it_clean();
$created_wards = array();
$ward_ids      = array();
foreach ( array( 'Bridge of Don', 'George Street/Harbour' ) as $w ) {
	$cache = null;
	$made  = ATK_Nb_Store::ensure_neighbourhood( $w, $cache );
	$ward_ids[ $w ] = (int) $made[0];
	if ( $made[1] ) {
		$created_wards[] = (int) $made[0];
	}
}
$mk = array();
foreach ( array(
	array( 'name' => 'ZZ-IT Course A', 'postcode' => 'AB23 1AA', 'street_answer' => 'ALPHA ROAD', 'ward_id' => $ward_ids['Bridge of Don'], 'status' => 'current' ),
	array( 'name' => 'ZZ-IT Course B', 'postcode' => 'AB23 1AB', 'street_answer' => 'BETA ROAD', 'ward_id' => $ward_ids['Bridge of Don'], 'status' => 'current' ),
	array( 'name' => 'ZZ-IT Course Closed', 'postcode' => 'AB23 1AC', 'street_answer' => 'GAMMA ROAD', 'ward_id' => $ward_ids['Bridge of Don'], 'status' => 'closed' ),
	array( 'name' => 'ZZ-IT Course No Street', 'postcode' => 'AB23 1AD', 'street_answer' => '', 'ward_id' => $ward_ids['Bridge of Don'], 'status' => 'current' ),
	array( 'name' => 'Old Blackfriars', 'postcode' => 'AB11 5BB', 'street_answer' => 'Castlegate', 'ward_id' => $ward_ids['George Street/Harbour'], 'status' => 'current' ),
) as $fields ) {
	$mk[] = ATK_Poi_Store::save( $fields )['id'];
}
$before_weeks = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . atk_table( 'course_weeks' ) . " WHERE course_type = 'poi'" );

$research = ATK_Poi_Store::course_research();
t( 'the researched answers file loads', isset( $research[ ATK_Poi_Planner::research_key( 'Old Blackfriars', 'AB11 5BB' ) ] ), true );
t( 'every researched entry names a source', count( array_filter( $research, function ( $r ) { return empty( $r['source'] ); } ) ), 0 );

$pv = ATK_Poi_Store::course_preview();
t( 'the preview lists 13 weeks', count( $pv['weeks'] ), 13 );
t( 'week 2 is Bridge of Don with two questions and no answer for one place', array( $pv['weeks'][1]['ward'], count( $pv['weeks'][1]['questions'] ), $pv['weeks'][1]['no_answer'] ), array( 'Bridge of Don', 2, array( 'ZZ-IT Course No Street' ) ) );
t( 'a week with no course week yet is marked new', $pv['weeks'][1]['state'], 'new' );
t( 'a ward with no places is marked nothing to ask', $pv['weeks'][12]['state'], 'nothing' );

$res = ATK_Poi_Store::course_apply( false );
$week = $wpdb->get_row( "SELECT * FROM " . atk_table( 'course_weeks' ) . " WHERE course_type = 'poi' AND neighbourhoods = 'Bridge of Don'" );
t( 'applying creates the Bridge of Don week as week 2', $week ? (int) $week->week_number : 0, 2 );
$qs = $wpdb->get_results( $wpdb->prepare( 'SELECT test_number, poi, accepted FROM ' . atk_table( 'course_poi_questions' ) . ' WHERE week_id = %d ORDER BY test_number, sort_order', $week->id ) );
t( 'each of the five tests holds both places', array_count_values( array_map( function ( $q ) { return (int) $q->test_number; }, $qs ) ), array( 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2 ) );
t( 'the closed place and the one with no street are not asked', count( array_unique( array_column( $qs, 'poi' ) ) ), 2 );
t( 'accepted answers are saved as JSON', json_decode( $qs[0]->accepted, true ), array( 'ALPHA ROAD' ) );
$bf = $wpdb->get_row( "SELECT q.accepted FROM " . atk_table( 'course_poi_questions' ) . " q WHERE q.poi = 'Old Blackfriars' LIMIT 1" );
t( 'the researched street is asked first and the saved one is also accepted', $bf ? json_decode( $bf->accepted, true ) : null, array( 'Castle Street', 'Castlegate' ) );
t( 'the result counts the weeks created', $res['weeks_created'] >= 2, true );

$count_q = function () use ( $wpdb ) { return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . atk_table( 'course_poi_questions' ) ); };
$q_before = $count_q();
$again = ATK_Poi_Store::course_apply( false );
t( 'running it again keeps weeks that already have questions', array( $count_q() === $q_before, $again['weeks_kept'] >= 2, $again['weeks_created'] ), array( true, true, 0 ) );
$pv2 = ATK_Poi_Store::course_preview();
t( 'the preview then shows the week as already filled', $pv2['weeks'][1]['state'], 'filled' );
ATK_Poi_Store::course_apply( true );
t( 'replace rewrites the questions without doubling them', $count_q(), $q_before );

ob_start();
atk_render_instructor_poi_course_card();
$card = ob_get_clean();
t( 'the card names the 13 wards in order', strpos( $card, 'Dyce/Bucksburn/Danestone' ) < strpos( $card, 'Kincorth/Nigg/Cove' ), true );
t( 'the card has the fill button', strpos( $card, 'Fill weeks 1 to 13' ) !== false, true );
t( 'the card posts to the fill action with a nonce', strpos( $card, 'atk_instructor_fill_poi_course' ) !== false && strpos( $card, 'atk_nonce' ) !== false, true );

// Clean up what this block made.
$week_ids = $wpdb->get_col( "SELECT id FROM " . atk_table( 'course_weeks' ) . " WHERE course_type = 'poi' AND neighbourhoods IN ('Bridge of Don','George Street/Harbour')" );
foreach ( $week_ids as $wid ) {
	$wpdb->delete( atk_table( 'course_poi_questions' ), array( 'week_id' => (int) $wid ) );
	$wpdb->delete( atk_table( 'course_weeks' ), array( 'id' => (int) $wid ) );
}
foreach ( $mk as $pid ) {
	ATK_Poi_Store::delete( $pid );
}
foreach ( $created_wards as $wid ) {
	$wpdb->delete( atk_table( 'nb_neighbourhoods' ), array( 'id' => $wid ) );
}
ATK_Poi_Store::flush_cache();


/* ---- end ---- */
it_clean();
wp_delete_user( $user_id );
echo "\n$n checks, $fail failed\n";
exit( $fail ? 1 : 0 );
