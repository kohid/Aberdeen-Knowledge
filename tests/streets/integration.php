<?php
/**
 * WordPress-dependent checks for the Streets tab and the Official Route test.
 * Run against a throwaway WordPress with the plugin active:
 *
 *     ATK_WP_LOAD=/path/to/wp-load.php php tests/streets/integration.php
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
	$ids = $wpdb->get_col( "SELECT id FROM " . atk_table( 'nb_streets' ) . " WHERE name LIKE 'ZZ-IT %' OR name LIKE 'Zz-It %'" );
	if ( $ids ) {
		$list = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM " . atk_table( 'nb_links' ) . " WHERE street_id IN ($list)" );
		$wpdb->query( "DELETE FROM " . atk_table( 'nb_streets' ) . " WHERE id IN ($list)" );
	}
	$wpdb->query( "DELETE q FROM " . atk_table( 'course_route_questions' ) . " q WHERE q.street LIKE 'ZZ-IT %' OR q.street LIKE 'Zz-It %'" );
	$wpdb->query( "DELETE FROM " . atk_table( 'nb_neighbourhoods' ) . " WHERE name LIKE 'ZZ-IT %'" );
}
function it_rest( $method, $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/atk/v1/' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

it_clean();
global $wpdb;
$user_id = wp_insert_user( array( 'user_login' => 'zz-it-instructor-' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'instructor' ) );
wp_set_current_user( $user_id );

/* ---- Storage ---- */
$cols = $wpdb->get_col( 'DESCRIBE ' . atk_table( 'nb_streets' ), 0 );
t( 'the street table has the new columns', count( array_intersect( array( 'listed', 'flagged', 'routes' ), $cols ) ), 3 );
$cols = $wpdb->get_col( 'DESCRIBE ' . atk_table( 'course_route_questions' ), 0 );
t( 'the route question table has street and pairs', count( array_intersect( array( 'street', 'pairs' ), $cols ) ), 2 );

$ward = ATK_Nb_Store::ensure_neighbourhood( 'ZZ-IT Ward' )[0];
$ward2 = ATK_Nb_Store::ensure_neighbourhood( 'ZZ-IT Ward Two' )[0];

$empty = ATK_Street_Store::save( array( 'name' => '   ' ) );
t( 'an empty name is rejected', is_wp_error( $empty ), true );

$a = ATK_Street_Store::save( array(
	'name' => 'ZZ-IT ALPHA ROAD', 'wards' => array( $ward, $ward2, 999999 ), 'postcodes' => 'ab10  1aa, AB10 1AB', 'note' => 'Check the east end',
	'pairs' => array( array( 'Union Street', 'Skene Road' ), array( 'union st', 'skene rd' ), array( 'Dyce', '' ) ),
) );
t( 'save creates a street with a tidy name', is_array( $a ) && $a['id'] > 0 ? $a['name'] : $a, 'Zz-It Alpha Road' );
t( 'only real wards are kept', $a['wards'], array_values( array_unique( array( min( $ward, $ward2 ), max( $ward, $ward2 ) ) ) ) );
t( 'postcodes are tidied and the outcode set', array( $a['postcodes'], $a['outcode'] ), array( array( 'AB10 1AA', 'AB10 1AB' ), 'AB10' ) );
t( 'repeat and half-filled answers are dropped', $a['pairs'], array( array( 'Union Street', 'Skene Road' ) ) );
t( 'a street with an answer is ready', $a['status'], 'ready' );

$dupe = ATK_Street_Store::save( array( 'name' => 'zz-it alpha road' ) );
t( 'the same name is not added twice', is_wp_error( $dupe ), true );

$flag = ATK_Street_Store::save( array( 'flagged' => true ), $a['id'] );
t( 'the flag changes the status and keeps the rest', array( $flag['status'], count( $flag['pairs'] ), count( $flag['wards'] ) ), array( 'clarify', 1, 2 ) );
$unflag = ATK_Street_Store::save( array( 'flagged' => false ), $a['id'] );
t( 'clearing the flag makes it ready again', $unflag['status'], 'ready' );

$b = ATK_Street_Store::save( array( 'name' => 'ZZ-IT BETA LANE', 'wards' => array( $ward ) ) );
t( 'a street without an answer needs one', $b['status'], 'needs_answer' );
$c = ATK_Street_Store::save( array( 'name' => 'ZZ-IT GAMMA STREET' ) );
t( 'a street with no ward is still on the list', $c['listed'] && ! $c['wards'], true );

/* ---- The Neighbourhoods tab sees the same wards ---- */
$in_ward = array_column( ATK_Nb_Store::streets_in_neighbourhood( $ward ), 'title' );
t( 'a ward saved here shows in the Neighbourhoods tab', in_array( 'Zz-It Alpha Road', $in_ward, true ), true );
$nb_only = ATK_Nb_Store::ensure_street( 'ZZ-IT DELTA AVENUE' );
$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO " . atk_table( 'nb_links' ) . " (neighbourhood_id, street_id) VALUES (%d, %d)", $ward, $nb_only ) );
$names = array_column( ATK_Street_Store::all(), 'name' );
t( 'a street given a ward on the Neighbourhoods tab appears here', in_array( 'Zz-It Delta Avenue', $names, true ), true );
$plain = ATK_Nb_Store::ensure_street( 'ZZ-IT EPSILON WAY' );
$names = array_column( ATK_Street_Store::all(), 'name' );
t( 'a registry street with no ward and not listed stays off the list', in_array( 'Zz-It Epsilon Way', $names, true ), false );
$added = ATK_Street_Store::save( array( 'name' => 'ZZ-IT EPSILON WAY' ) );
t( 'adding a registry street puts it on the list instead of duplicating it', is_array( $added ) && (int) $added['id'] === (int) $plain, true );

/* ---- Remove ---- */
t( 'remove takes a street off the list', ATK_Street_Store::remove( $added['id'] ), true );
$names = array_column( ATK_Street_Store::all(), 'name' );
t( 'a removed street is gone from the list', in_array( 'Zz-It Epsilon Way', $names, true ), false );
t( 'the registry row stays', (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . atk_table( 'nb_streets' ) . ' WHERE id = %d', $plain ) ), true );

/* ---- Import ---- */
$parsed = ATK_Street_Parser::parse_rows( array(
	array( 'Road', 'Ward', 'Clarify', 'From', 'To', 'Note' ),
	array( 'ZZ-IT BETA LANE', 'ZZ-IT Ward Two', '', 'Dyce', 'Torry', '' ),
	array( 'ZZ-IT BETA LANE', '', '', 'Bridge of Don', 'City Centre', 'Both ends differ' ),
	array( 'ZZ-IT ALPHA ROAD', 'ZZ-IT Nowhere', 'yes', '', '', '' ),
	array( 'ZZ-IT NEWBIE CLOSE', 'ZZ-IT Ward', '', 'Aaa', 'Bbb', '' ),
) );
$preview = ATK_Street_Store::import_preview( $parsed );
t( 'the preview counts what is new and what changes', array( $preview['summary']['new'], $preview['summary']['changed'] ), array( 1, 2 ) );
t( 'the preview names the new streets', $preview['new_names'], array( 'Zz-It Newbie Close' ) );
t( 'the preview reports a ward that is not on the site', $preview['summary']['unknown_wards'], array( 'ZZ-IT Nowhere' ) );
t( 'the preview writes nothing', (bool) $wpdb->get_var( "SELECT id FROM " . atk_table( 'nb_streets' ) . " WHERE name = 'Zz-It Newbie Close'" ), false );

$done = ATK_Street_Store::import_commit( $parsed, false );
t( 'without the tick, new streets are left out', array( $done['saved'], $done['left_out'] ), array( 2, 1 ) );
$beta = null;
foreach ( ATK_Street_Store::all() as $s ) {
	if ( 'Zz-It Beta Lane' === $s['name'] ) {
		$beta = $s;
	}
}
t( 'repeated rows added both answers, the ward and the note', array( count( $beta['pairs'] ), in_array( $ward2, $beta['wards'], true ), $beta['note'] ), array( 2, true, 'Both ends differ' ) );
$alpha = ATK_Street_Store::get( $a['id'] );
t( 'Clarify in a sheet flags the street, blank cells erased nothing', array( $alpha['flagged'], count( $alpha['pairs'] ), count( $alpha['wards'] ) ), array( true, 1, 2 ) );
$done = ATK_Street_Store::import_commit( $parsed, true );
t( 'with the tick, the new street is added', $done['saved'] >= 1 && (bool) $wpdb->get_var( "SELECT id FROM " . atk_table( 'nb_streets' ) . " WHERE name = 'Zz-It Newbie Close'" ), true );
$again = ATK_Street_Store::import_preview( $parsed );
t( 'importing the same sheet again changes nothing', array( $again['summary']['new'], $again['summary']['changed'], $again['summary']['pairs_added'] ), array( 0, 0, 0 ) );

// A street that is on the site already (only in the registry) is added to the list, never duplicated or renamed.
$q1 = ATK_Nb_Store::ensure_street( "ZZ-IT QUEEN'S ROAD" );
$q2 = ATK_Nb_Store::ensure_street( 'ZZ-IT Queens Road' );
$reg = ATK_Street_Parser::parse_rows( array( array( 'Road', 'Ward', 'From', 'To' ), array( "ZZ-IT QUEEN'S ROAD", 'ZZ-IT Ward', 'Aaa', 'Bbb' ) ) );
$rp  = ATK_Street_Store::import_preview( $reg );
t( 'a registry street counts as already on the site, not new', array( $rp['summary']['new'], $rp['summary']['changed'], $rp['new_names'] ), array( 0, 1, array() ) );
$rc = ATK_Street_Store::import_commit( $reg, false );
t( 'it is added to the list with no tick needed', array( $rc['saved'], $rc['failed'] ), array( 1, 0 ) );
$qs = ATK_Street_Store::get( $q1 );
t( 'the spelled-the-same registry row was used and kept its name', array( $qs['name'], $qs['wards'] === array( $ward ), count( $qs['pairs'] ) ), array( "Zz-It Queen's Road", true, 1 ) );
t( 'the lookalike row was left alone', ATK_Street_Store::get( $q2 )['listed'], false );

/* ---- REST ---- */
rest_get_server();
wp_set_current_user( 0 );
t( 'a logged-out visitor cannot read the streets', in_array( it_rest( 'GET', 'streets' )->get_status(), array( 401, 403 ), true ), true );
t( 'a logged-out visitor cannot add a street', in_array( it_rest( 'POST', 'streets', array( 'name' => 'ZZ-IT Anon' ) )->get_status(), array( 401, 403 ), true ), true );
wp_set_current_user( $user_id );
$list = it_rest( 'GET', 'streets' );
$data = $list->get_data();
t( 'an instructor reads the list, wards and counts', array( $list->get_status(), isset( $data['streets'], $data['wards'], $data['counts'] ) ), array( 200, true ) );
$post = it_rest( 'POST', 'streets', array( 'name' => 'ZZ-IT REST ROAD', 'wards' => array( $ward ), 'pairs' => array( array( 'x', 'y' ) ) ) );
t( 'an instructor adds a street over REST', array( $post->get_status(), $post->get_data()['name'] ), array( 200, 'Zz-It Rest Road' ) );
$del = it_rest( 'DELETE', 'streets/' . $post->get_data()['id'] );
t( 'an instructor removes a street over REST', $del->get_status(), 200 );

/* ---- Official Route test ---- */
$official = 'Bridge of Don';
$bod      = ATK_Nb_Store::ensure_neighbourhood( $official )[0];
$weeks    = $wpdb->get_col( "SELECT id FROM " . atk_table( 'course_weeks' ) . " WHERE course_type = 'route'" );
foreach ( range( 1, 7 ) as $i ) {
	ATK_Street_Store::save( array( 'name' => 'ZZ-IT ROUTE STREET ' . $i, 'wards' => array( $bod ), 'pairs' => array( array( 'From ' . $i, 'To ' . $i ) ) ) );
}
ATK_Street_Store::save( array( 'name' => 'ZZ-IT ROUTE RED', 'wards' => array( $bod ), 'pairs' => array( array( 'a', 'b' ) ), 'flagged' => true ) );
$plan = ATK_Street_Store::course_preview();
$w2   = $plan['weeks'][1];
$mine = array_values( array_filter( array_column( $w2['questions'], 'street' ), function ( $s ) { return 0 === stripos( $s, 'ZZ-IT ' ); } ) );
t( 'the preview puts the ward in week 2', array( $w2['number'], $w2['ward'] ), array( 2, 'Bridge of Don' ) );
t( 'the preview asks the ready streets and not the flagged one', array( count( $mine ), in_array( 'Zz-It Route Red', $w2['flagged'], true ) ), array( 7, true ) );

$result = ATK_Street_Store::course_apply( false );
t( 'applying creates week 2 and saves its questions', $result['weeks_created'] >= 1 && $result['questions'] >= 7, true );
$week_id = (int) $wpdb->get_var( "SELECT id FROM " . atk_table( 'course_weeks' ) . " WHERE course_type = 'route' AND neighbourhoods = 'Bridge of Don'" );
$asked   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . atk_table( 'course_route_questions' ) . " WHERE week_id = %d AND street LIKE 'Zz-It Route Street %%'", $week_id ) );
t( 'every ready street was saved once', $asked, 7 );
$sizes = array();
foreach ( range( 1, 5 ) as $test ) {
	$sizes[] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . atk_table( 'course_route_questions' ) . " WHERE week_id = %d AND test_number = %d AND street LIKE 'Zz-It Route Street %%'", $week_id, $test ) );
}
t( 'the 7 streets are spread 2,2,1,1,1 over the five tests', $sizes, array( 2, 2, 1, 1, 1 ) );
$kept = ATK_Street_Store::course_apply( false );
t( 'a second fill keeps a week that already has questions', array( $kept['weeks_kept'] >= 1, $kept['questions'] ), array( true, 0 ) );
$count_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . atk_table( 'course_route_questions' ) . " WHERE week_id = %d AND street <> ''", $week_id ) );
$re = ATK_Street_Store::course_apply( true );
$count_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . atk_table( 'course_route_questions' ) . " WHERE week_id = %d AND street <> ''", $week_id ) );
t( 'rewrite replaces the street questions with the same number, no duplicates', array( $re['weeks_replaced'] >= 1, $count_after ), array( true, $count_before ) );

// Legacy multiple-choice rows survive a rewrite.
$wpdb->insert( atk_table( 'course_route_questions' ), array( 'week_id' => $week_id, 'test_number' => 1, 'from_place' => 'ZZ-IT Old From', 'to_place' => 'ZZ-IT Old To', 'options' => '[["a","b"],["c","d"]]', 'correct_index' => 0, 'street' => '', 'pairs' => null, 'sort_order' => 99 ) );
ATK_Street_Store::course_apply( true );
t( 'an old multiple-choice question is not deleted by a rewrite', (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . atk_table( 'course_route_questions' ) . " WHERE from_place = 'ZZ-IT Old From'" ), 1 );

/* ---- The Official Route test as the course reads it ---- */
// Make test 1 of the week longer than 20 questions, before the course reads it (it caches per request).
for ( $i = 1; $i <= 30; $i++ ) {
	$wpdb->insert( atk_table( 'course_route_questions' ), array( 'week_id' => $week_id, 'test_number' => 1, 'from_place' => '', 'to_place' => '', 'options' => '[]', 'correct_index' => 0, 'street' => sprintf( 'ZZ-IT Extra %02d', $i ), 'pairs' => wp_json_encode( array( array( 'Alpha Road', 'Beta Street' ) ) ), 'sort_order' => 100 + $i ) );
}
$weeks    = atk_course_weeks( 'route' );
$week_num = 0;
foreach ( $weeks as $wi => $w ) {
	if ( $w['id'] === $week_id ) {
		$week_num = $wi + 1;
	}
}
t( 'the course finds the week', $week_num > 0, true );
$first = $weeks[ $week_num - 1 ]['tests'][0];
$mine  = array_values( array_filter( $first, function ( $q ) { return 0 === strpos( $q['street'], 'ZZ-IT Extra' ) || 0 === stripos( $q['street'], 'Zz-It Route Street' ); } ) );
t( 'a route question is a street with its pairs', array_keys( $mine[0] ), array( 'id', 'street', 'pairs' ) );
t( 'the old multiple-choice row is not a question', count( array_filter( $first, function ( $q ) { return 'ZZ-IT Old From' === ( isset( $q['from'] ) ? $q['from'] : '' ) || '' === $q['street']; } ) ), 0 );
$payload = atk_course_test_payload( 'route', $week_num, 1 );
$extra   = array_values( array_filter( $payload, function ( $q ) { return false !== strpos( $q['prompt'], 'ZZ-IT Extra 01' ); } ) );
t( 'the quiz question asks where the street runs from and to', $extra[0]['prompt'], 'Where does <strong>ZZ-IT Extra 01</strong> run from and to?' );
t( 'the quiz question carries the pairs and no multiple choice', array( $extra[0]['pairs'], isset( $extra[0]['options'] ), isset( $extra[0]['locations'] ) ), array( array( array( 'Alpha Road', 'Beta Street' ) ), false, false ) );
t( 'a test with more than 20 questions may report that many', atk_course_test_max_questions( 'route', $week_num, 1 ), count( $first ) );
t( 'and lasts one minute a question', atk_course_test_minutes( 'route', $week_num, 1 ), count( $first ) );
t( 'a short test keeps 20 questions and 20 minutes', array( atk_course_test_max_questions( 'route', $week_num, 2 ), atk_course_test_minutes( 'route', $week_num, 2 ) ), array( 20, 20 ) );
t( 'the Street test keeps its 20 and 20', array( atk_course_test_max_questions( 'street', 1, 1 ), atk_course_test_minutes( 'street', 1, 1 ) ), array( 20, 20 ) );
t( 'a saved review of the long test may hold all its answers', atk_attempt_limits( 'course-route-w' . $week_num . '-t1' ), array( 'questions' => max( 30, count( $first ) ), 'minutes' => count( $first ) ) );
t( 'other tests keep 30 answers and 20 minutes', array( atk_attempt_limits( 'course-route-w' . $week_num . '-t2' ), atk_attempt_limits( 'course-w1-t1' ) ), array( array( 'questions' => 30, 'minutes' => 20 ), array( 'questions' => 30, 'minutes' => 20 ) ) );
t( 'the course total counts the real route questions', atk_course_total_questions( 'route' ) >= count( $first ), true );
ob_start();
atk_render_course_test( 'route', $week_num, 1 );
$html = ob_get_clean();
t( 'the intro states the real question count and minutes', false !== strpos( $html, count( $first ) . ' questions &middot; ' . count( $first ) . ' minutes' ), true );

// Cleanup.
$wpdb->query( "DELETE FROM " . atk_table( 'course_route_questions' ) . " WHERE from_place = 'ZZ-IT Old From'" );
$wpdb->query( "DELETE FROM " . atk_table( 'course_route_questions' ) . " WHERE week_id = $week_id AND street <> ''" );
if ( $week_id && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . atk_table( 'course_route_questions' ) . " WHERE week_id = $week_id" ) ) {
	$wpdb->delete( atk_table( 'course_weeks' ), array( 'id' => $week_id ) );
}
it_clean();
wp_delete_user( $user_id );
echo "\n$n checks, $fail failed\n";
exit( $fail ? 1 : 0 );
