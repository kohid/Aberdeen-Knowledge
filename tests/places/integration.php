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

/* ---- end ---- */
it_clean();
wp_delete_user( $user_id );
echo "\n$n checks, $fail failed\n";
exit( $fail ? 1 : 0 );
