<?php
/**
 * Official Test course: three independent tracks (Street/POI/Route), each
 * with its own weeks, own per-driver enrollment date, and own unlock
 * pacing — content read from the database (inc/db-schema.php), written by
 * the Instructor Dashboard's Course Builder (inc/instructor.php).
 *
 * Street was the original, and its usermeta/test-key formats predate the
 * other two tracks, so it keeps its original unsuffixed keys
 * ('atk_course_started_at', 'atk_course_progress', 'course-w1-t1') for
 * backward compatibility with drivers already enrolled; POI and Route use
 * a $type-suffixed form of the same keys.
 */

defined( 'ABSPATH' ) || exit;

const ATK_COURSE_QUESTIONS_PER_TEST = 20;
const ATK_COURSE_TESTS_PER_WEEK     = 5;
const ATK_COURSE_MINUTES            = 20;
const ATK_COURSE_PASS_THRESHOLD     = 0.75;
const ATK_COURSE_NONCE_ACTION       = 'atk_course_complete';
const ATK_COURSE_ENROLL_NONCE_ACTION = 'atk_course_enroll';

function atk_course_types() {
	return array(
		'street' => array( 'label' => 'Street Test', 'question_table' => 'course_questions' ),
		'poi'    => array( 'label' => 'POI Test', 'question_table' => 'course_poi_questions' ),
		'route'  => array( 'label' => 'Route Test', 'question_table' => 'course_route_questions' ),
	);
}

function atk_course_type_or_default( $type ) {
	$types = atk_course_types();
	return isset( $types[ $type ] ) ? $type : 'street';
}

/**
 * Meta/test-key prefixes are unsuffixed for 'street' (its original form,
 * predating the other two tracks) and suffixed with the type for the rest.
 */
function atk_course_meta_key( $type, $base ) {
	return 'street' === $type ? $base : $base . '_' . $type;
}

function atk_course_test_key_prefix( $type ) {
	return 'street' === $type ? 'course' : 'course-' . $type;
}

/**
 * The plain track name — "Street", "Place of Interest", "Route" — as
 * opposed to atk_course_types()'s ['label'] which is "Street Test" etc.
 * Used wherever a track needs to read as its own thing rather than as one
 * more "X Test" (e.g. "Street Official Test" card headers).
 */
function atk_course_track_name( $type ) {
	$names = array( 'street' => 'Street', 'poi' => 'Place of Interest', 'route' => 'Route' );
	return $names[ $type ] ?? $names['street'];
}

/**
 * A driver's attempt history across an entire track (every week/test in
 * $type), merged from the per-test review lists atk_attempt_reviews()
 * actually stores keyed one-per-week/test, sorted newest first and capped
 * the same as any single test's history (ATK_REVIEW_MAX_PER_TEST). Powers
 * the "Previous Attempts" view on the Profile page and the Instructor
 * Dashboard's Driver Results — both show one row per track, not per
 * individual week/test (up to 40 of those per track, too granular there).
 */
function atk_course_track_attempts( $user_id, $type ) {
	$type    = atk_course_type_or_default( $type );
	$prefix  = atk_course_test_key_prefix( $type );
	$pattern = '/^' . preg_quote( $prefix, '/' ) . '-w(\d+)-t(\d+)$/';

	$merged = array();
	foreach ( atk_attempt_reviews( $user_id ) as $key => $attempts ) {
		if ( ! preg_match( $pattern, $key, $m ) ) {
			continue;
		}
		foreach ( $attempts as $attempt ) {
			$attempt['week'] = (int) $m[1];
			$attempt['test'] = (int) $m[2];
			$merged[]        = $attempt;
		}
	}

	usort( $merged, function ( $a, $b ) {
		return $b['timestamp'] <=> $a['timestamp'];
	} );

	return array_slice( $merged, 0, ATK_REVIEW_MAX_PER_TEST );
}

/**
 * Live course content for one track, read from the database. Each week has
 * 'id', 'neighbourhoods' (array of strings) and 'tests' (array of
 * ATK_COURSE_TESTS_PER_WEEK question lists). A question's shape depends on
 * $type: street => array('id','a','b','accepted'), poi => array('id','poi',
 * 'accepted'), route => array('id','from','to','options','correct').
 */
function atk_course_weeks( $type = 'street' ) {
	global $wpdb;
	$type = atk_course_type_or_default( $type );

	static $cache = array();
	if ( isset( $cache[ $type ] ) ) {
		return $cache[ $type ];
	}

	$weeks_table     = atk_table( 'course_weeks' );
	$questions_table = atk_table( atk_course_types()[ $type ]['question_table'] );

	$week_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, neighbourhoods FROM $weeks_table WHERE course_type = %s ORDER BY sort_order ASC, id ASC",
		$type
	) );
	$weeks = array();

	foreach ( $week_rows as $week_row ) {
		$tests = array_fill( 1, ATK_COURSE_TESTS_PER_WEEK, array() );

		if ( 'street' === $type ) {
			$question_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, test_number, road_a, road_b, accepted FROM $questions_table WHERE week_id = %d ORDER BY test_number ASC, sort_order ASC, id ASC",
				$week_row->id
			) );
			foreach ( $question_rows as $q ) {
				if ( ! isset( $tests[ (int) $q->test_number ] ) ) { continue; }
				$tests[ (int) $q->test_number ][] = array( 'id' => (int) $q->id, 'a' => $q->road_a, 'b' => $q->road_b, 'accepted' => json_decode( $q->accepted, true ) );
			}
		} elseif ( 'poi' === $type ) {
			$question_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, test_number, poi, accepted FROM $questions_table WHERE week_id = %d ORDER BY test_number ASC, sort_order ASC, id ASC",
				$week_row->id
			) );
			foreach ( $question_rows as $q ) {
				if ( ! isset( $tests[ (int) $q->test_number ] ) ) { continue; }
				$tests[ (int) $q->test_number ][] = array( 'id' => (int) $q->id, 'poi' => $q->poi, 'accepted' => json_decode( $q->accepted, true ) );
			}
		} else { // route
			$question_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, test_number, from_place, to_place, options, correct_index FROM $questions_table WHERE week_id = %d ORDER BY test_number ASC, sort_order ASC, id ASC",
				$week_row->id
			) );
			foreach ( $question_rows as $q ) {
				if ( ! isset( $tests[ (int) $q->test_number ] ) ) { continue; }
				$tests[ (int) $q->test_number ][] = array( 'id' => (int) $q->id, 'from' => $q->from_place, 'to' => $q->to_place, 'options' => json_decode( $q->options, true ), 'correct' => (int) $q->correct_index );
			}
		}

		$weeks[] = array(
			'id'             => (int) $week_row->id,
			'neighbourhoods' => array_map( 'trim', explode( ',', $week_row->neighbourhoods ) ),
			'tests'          => array_values( $tests ),
		);
	}

	$cache[ $type ] = $weeks;
	return $weeks;
}

function atk_course_total_weeks( $type = 'street' ) {
	return count( atk_course_weeks( $type ) );
}

/**
 * Whether this learner has actually enrolled in this track yet — via the
 * Course catalog page's enrollment wizard (atk_handle_course_enroll()),
 * not just by visiting a URL. Check this before atk_course_enrollment_timestamp(),
 * which stamps the meta if it's missing — the hub and test pages use this
 * to send anyone who hasn't enrolled back to the catalog instead of
 * silently starting their clock for them.
 */
function atk_course_is_enrolled( $user_id, $type = 'street' ) {
	$type = atk_course_type_or_default( $type );
	return (bool) get_user_meta( $user_id, atk_course_meta_key( $type, 'atk_course_started_at' ), true );
}

/**
 * When this learner started this track — stamped the moment they complete
 * the enrollment wizard for it (atk_handle_course_enroll()), never changed
 * after that. Only call this for a track atk_course_is_enrolled() already
 * confirmed, or on enrollment itself — calling it blind stamps a start
 * date for someone who never explicitly enrolled.
 */
function atk_course_enrollment_timestamp( $user_id, $type = 'street' ) {
	$type    = atk_course_type_or_default( $type );
	$meta_key = atk_course_meta_key( $type, 'atk_course_started_at' );
	$started = get_user_meta( $user_id, $meta_key, true );
	if ( ! $started ) {
		$started = time();
		update_user_meta( $user_id, $meta_key, $started );
	}
	return (int) $started;
}

/**
 * How many weeks are unlocked for this learner right now, based purely on
 * elapsed calendar time since enrollment in this track (not on completing
 * prior weeks).
 */
function atk_course_unlocked_weeks( $user_id, $type = 'street' ) {
	$started       = atk_course_enrollment_timestamp( $user_id, $type );
	$elapsed_weeks = (int) floor( ( time() - $started ) / WEEK_IN_SECONDS );
	return min( atk_course_total_weeks( $type ), $elapsed_weeks + 1 );
}

/**
 * Seconds until this track's next week unlocks, or 0 if everything is.
 */
function atk_course_seconds_until_next_unlock( $user_id, $type = 'street' ) {
	if ( atk_course_unlocked_weeks( $user_id, $type ) >= atk_course_total_weeks( $type ) ) {
		return 0;
	}
	$started = atk_course_enrollment_timestamp( $user_id, $type );
	$elapsed = time() - $started;
	return WEEK_IN_SECONDS - ( $elapsed % WEEK_IN_SECONDS );
}

function atk_course_progress_key( $week_num, $test_num ) {
	return 'w' . (int) $week_num . 't' . (int) $test_num;
}

function atk_course_progress( $user_id, $type = 'street' ) {
	$type     = atk_course_type_or_default( $type );
	$progress = get_user_meta( $user_id, atk_course_meta_key( $type, 'atk_course_progress' ), true );
	return is_array( $progress ) ? $progress : array();
}

function atk_course_record_result( $user_id, $type, $week_num, $test_num, $score, $total ) {
	$type     = atk_course_type_or_default( $type );
	$progress = atk_course_progress( $user_id, $type );
	$progress[ atk_course_progress_key( $week_num, $test_num ) ] = array(
		'score'       => (int) $score,
		'total'       => (int) $total,
		'passed'      => ( $total > 0 && ( $score / $total ) >= ATK_COURSE_PASS_THRESHOLD ),
		'completedAt' => time(),
	);
	update_user_meta( $user_id, atk_course_meta_key( $type, 'atk_course_progress' ), $progress );
}

add_action( 'wp_ajax_atk_course_complete', 'atk_handle_course_complete' );
function atk_handle_course_complete() {
	check_ajax_referer( ATK_COURSE_NONCE_ACTION, 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	$user_id  = get_current_user_id();
	$type     = isset( $_POST['course_type'] ) ? atk_course_type_or_default( sanitize_key( $_POST['course_type'] ) ) : 'street';
	$weeks    = atk_course_weeks( $type );
	$week_num = isset( $_POST['week'] ) ? (int) $_POST['week'] : 0;
	$test_num = isset( $_POST['test'] ) ? (int) $_POST['test'] : 0;

	if ( $week_num < 1 || $week_num > count( $weeks ) || $test_num < 1 || $test_num > ATK_COURSE_TESTS_PER_WEEK ) {
		wp_send_json_error( array( 'message' => 'Invalid week/test.' ), 400 );
	}
	if ( $week_num > atk_course_unlocked_weeks( $user_id, $type ) ) {
		wp_send_json_error( array( 'message' => 'Week not unlocked yet.' ), 403 );
	}

	$total = isset( $_POST['total'] ) ? (int) $_POST['total'] : 0;
	$score = isset( $_POST['score'] ) ? (int) $_POST['score'] : 0;
	$total = max( 0, min( ATK_COURSE_QUESTIONS_PER_TEST, $total ) );
	$score = max( 0, min( $total, $score ) );

	atk_course_record_result( $user_id, $type, $week_num, $test_num, $score, $total );

	wp_send_json_success();
}

/**
 * Build the quiz-engine question payload for one course test, using the
 * same prompt phrasing as the equivalent mockup test (inc/quiz.php).
 */
function atk_course_test_payload( $type, $week_num, $test_num ) {
	$type  = atk_course_type_or_default( $type );
	$weeks = atk_course_weeks( $type );
	$rows  = $weeks[ $week_num - 1 ]['tests'][ $test_num - 1 ];

	if ( 'street' === $type ) {
		return array_map( function ( $q ) {
			return array(
				'prompt'    => sprintf( 'Name a road that connects <strong>%s</strong> and <strong>%s</strong>.', esc_html( $q['a'] ), esc_html( $q['b'] ) ),
				'accepted'  => $q['accepted'],
				'locations' => array(
					array( 'label' => 'A', 'query' => $q['a'] ),
					array( 'label' => 'B', 'query' => $q['b'] ),
				),
			);
		}, $rows );
	}

	if ( 'poi' === $type ) {
		return array_map( function ( $q ) {
			return array(
				'prompt'    => sprintf( 'Which street would you find <strong>%s</strong> on?', esc_html( $q['poi'] ) ),
				'accepted'  => $q['accepted'],
				'locations' => array(
					array( 'label' => '', 'query' => $q['poi'] ),
				),
			);
		}, $rows );
	}

	// route
	return array_map( function ( $q ) {
		return array(
			'prompt'    => sprintf( 'Which route correctly connects <strong>%s</strong> to <strong>%s</strong>?', esc_html( $q['from'] ), esc_html( $q['to'] ) ),
			'options'   => array_map( function ( $route ) {
				return implode( ' &rarr; ', array_map( 'esc_html', $route ) );
			}, $q['options'] ),
			'correct'   => (int) $q['correct'],
			'locations' => array(
				array( 'label' => 'S', 'query' => $q['from'] ),
				array( 'label' => 'E', 'query' => $q['to'] ),
			),
		);
	}, $rows );
}

function atk_render_course_test( $type, $week_num, $test_num ) {
	if ( atk_is_elementor_editing() ) {
		echo '<div class="alert alert-info m-10">Course Test placeholder — the real test (live questions, timer, and scoring) only renders for a signed-in, enrolled driver with a valid test link.</div>';
		return;
	}

	atk_require_login();

	$type       = atk_course_type_or_default( $type );
	$user_id    = get_current_user_id();
	// Instructors own this content and need to be able to preview any test
	// (e.g. via a direct link, or Elementor's admin bar/"Edit Page" while
	// viewing it) regardless of their own enrollment/progress — the same
	// courtesy atk_is_instructor() already extends administrators elsewhere.
	$is_exempt  = atk_is_instructor();

	if ( ! $is_exempt && ! atk_course_is_enrolled( $user_id, $type ) ) {
		wp_safe_redirect( atk_get_page_url( 'course' ) );
		exit;
	}

	$weeks = atk_course_weeks( $type );

	if ( $week_num < 1 || $week_num > count( $weeks ) || $test_num < 1 || $test_num > ATK_COURSE_TESTS_PER_WEEK ) {
		wp_safe_redirect( add_query_arg( 'type', $type, atk_get_page_url( 'official-test-course' ) ) );
		exit;
	}
	if ( ! $is_exempt && $week_num > atk_course_unlocked_weeks( $user_id, $type ) ) {
		wp_safe_redirect( add_query_arg( 'type', $type, atk_get_page_url( 'official-test-course' ) ) );
		exit;
	}

	$labels       = atk_course_types();
	$instructions = array(
		'street' => 'Name a connecting road between two roads.',
		'poi'    => "Name the street where you'll find these places of interest.",
		'route'  => 'Pick the road sequence that forms the correct route.',
	);
	$modes = array( 'street' => 'text', 'poi' => 'text', 'route' => 'choice' );

	$test = array(
		'title'       => $labels[ $type ]['label'] . ' &mdash; Week ' . $week_num . ', Test ' . $test_num,
		'heading'     => atk_course_track_name( $type ) . ' Official Test',
		'instruction' => $instructions[ $type ],
		'mode'        => $modes[ $type ],
		'hub_key'     => 'official-test-course',
		'hub_label'   => 'Back to Course',
		'real_data'   => true,
		'week'        => $week_num,
		'test'        => $test_num,
	);

	atk_render_quiz(
		atk_course_test_key_prefix( $type ) . '-w' . $week_num . '-t' . $test_num,
		$test,
		atk_course_test_payload( $type, $week_num, $test_num ),
		array(
			'courseWeek'  => $week_num,
			'courseTest'  => $test_num,
			'courseType'  => $type,
			'courseNonce' => wp_create_nonce( ATK_COURSE_NONCE_ACTION ),
		)
	);
}

/**
 * Lets the Mock Test page be built in Elementor: drop this shortcode
 * where the test content should appear, same ?type=/&week=/&test= query
 * args as before.
 */
add_shortcode( 'atk_course_test', function () {
	ob_start();
	atk_render_course_test(
		isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'street',
		isset( $_GET['week'] ) ? (int) $_GET['week'] : 0,
		isset( $_GET['test'] ) ? (int) $_GET['test'] : 0
	);
	return ob_get_clean();
} );

/**
 * A heading naming which official test this is — "Street Official Test",
 * "Place of Interest Official Test", "Route Official Test" — read from the
 * current ?type=, for the Course Test page (replaces a plain "Official
 * Test" heading). An Elementor Heading widget's text field doesn't run
 * shortcodes at all (it prints them as literal text), so this has to go in
 * via Elementor's Shortcode widget instead — hence real <h2> markup here
 * rather than plain text, so it still looks like a heading once placed.
 */
add_shortcode( 'atk_course_test_type', function ( $atts ) {
	$atts = shortcode_atts( array( 'tag' => 'h2', 'class' => 'fs-2x fw-bolder text-gray-900' ), $atts );
	$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'street';
	$tag  = preg_match( '/^h[1-6]$/', $atts['tag'] ) ? $atts['tag'] : 'h2';

	return sprintf( '<%1$s class="%2$s">%3$s</%1$s>', esc_attr( $tag ), esc_attr( $atts['class'] ), esc_html( atk_course_track_name( $type ) . ' Official Test' ) );
} );

function atk_render_course_hub( $type = 'street' ) {
	if ( atk_is_elementor_editing() ) {
		echo '<div class="alert alert-info m-10">Official Test Course hub placeholder — the real hub (an enrolled driver\'s live progress) only renders for a signed-in, enrolled driver.</div>';
		return;
	}

	atk_require_login();

	$type    = atk_course_type_or_default( $type );
	$user_id = get_current_user_id();

	// Instructors need to preview the hub regardless of their own
	// enrollment — see the matching note in atk_render_course_test().
	if ( ! atk_is_instructor() && ! atk_course_is_enrolled( $user_id, $type ) ) {
		wp_safe_redirect( atk_get_page_url( 'course' ) );
		exit;
	}

	wp_enqueue_script( 'atk-quiz-active-notice', ATK_PLUGIN_URI . '/assets/js/quiz-active-notice.js', array(), filemtime( ATK_PLUGIN_DIR . '/assets/js/quiz-active-notice.js' ), true );

	$types        = atk_course_types();
	$weeks        = atk_course_weeks( $type );
	$unlocked     = atk_course_unlocked_weeks( $user_id, $type );
	$progress     = atk_course_progress( $user_id, $type );
	$next_unlock  = atk_course_seconds_until_next_unlock( $user_id, $type );
	$key_prefix   = atk_course_test_key_prefix( $type );
	$review_modals = '';
	?>
	<div class="container py-10 py-lg-15">
		<header class="text-center mb-8">
			<p class="fs-4 text-gray-600 mb-0">Real questions from the official course curriculum &mdash; <?php echo esc_html( ATK_COURSE_TESTS_PER_WEEK ); ?> tests a week, per track.</p>
		</header>

		<div class="d-flex justify-content-center mb-8">
			<div class="btn-group" role="group" aria-label="Test track">
				<?php foreach ( $types as $tkey => $tinfo ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'type', $tkey, atk_get_page_url( 'official-test-course' ) ) ); ?>" class="btn <?php echo $type === $tkey ? 'btn-primary' : 'btn-light'; ?>" aria-pressed="<?php echo $type === $tkey ? 'true' : 'false'; ?>"><?php echo esc_html( $tinfo['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( empty( $weeks ) ) : ?>
			<div class="alert alert-info text-center"><?php echo esc_html( $types[ $type ]['label'] ); ?> course content is coming soon.</div>
		<?php else : ?>

			<?php if ( $next_unlock > 0 ) : ?>
				<p class="fs-6 text-gray-500 text-center mb-8">Next week unlocks in <?php echo esc_html( ceil( $next_unlock / DAY_IN_SECONDS ) ); ?> day(s).</p>
			<?php endif; ?>

			<?php
			// Weeks are a genuine sequence (unlock order carries real meaning),
			// so this uses the timeline component from assets/css/akn.css (modelled
			// on the demo2 reference) as the page's one signature element: a node
			// per week (locked/in-progress/completed) on a vertical line.
			?>
			<div class="timeline-label">
				<?php foreach ( $weeks as $i => $week ) :
					$week_num    = $i + 1;
					$is_unlocked = $week_num <= $unlocked;

					$week_results = array();
					$week_passed  = 0;
					for ( $t = 1; $t <= ATK_COURSE_TESTS_PER_WEEK; $t++ ) {
						$r = isset( $progress[ atk_course_progress_key( $week_num, $t ) ] ) ? $progress[ atk_course_progress_key( $week_num, $t ) ] : null;
						$week_results[ $t ] = $r;
						if ( $r && $r['passed'] ) {
							$week_passed++;
						}
					}
					$week_completed = $is_unlocked && ATK_COURSE_TESTS_PER_WEEK === $week_passed;

					if ( ! $is_unlocked ) {
						$node_icon   = 'bi-lock-fill';
						$node_color  = 'text-gray-400';
						$state_badge = '<span class="badge badge-light"><i class="bi bi-lock-fill me-1"></i>Locked</span>';
					} elseif ( $week_completed ) {
						$node_icon   = 'bi-check-circle-fill';
						$node_color  = 'text-success';
						$state_badge = '<span class="badge badge-light-success">Completed</span>';
					} else {
						$node_icon   = 'bi-record-circle-fill';
						$node_color  = 'text-primary';
						$state_badge = '<span class="badge badge-light-primary">In Progress</span>';
					}
					?>
					<div class="timeline-item">
						<div class="timeline-label fw-bolder fs-6 <?php echo esc_attr( $is_unlocked ? 'text-gray-800' : 'text-gray-400' ); ?>">Wk <?php echo esc_html( $week_num ); ?></div>
						<div class="timeline-badge">
							<i class="bi <?php echo esc_attr( $node_icon ); ?> <?php echo esc_attr( $node_color ); ?> fs-1"></i>
						</div>
						<div class="timeline-content ps-3">
							<div class="card<?php echo $is_unlocked ? '' : ' bg-light'; ?>">
								<div class="card-header">
									<div class="card-title flex-column align-items-start">
										<h2 class="fs-4 mb-1"><?php echo esc_html( implode( ', ', $week['neighbourhoods'] ) ); ?></h2>
										<div class="fs-7 text-gray-500">
											<?php echo esc_html( atk_course_track_name( $type ) . ' Official Test' ); ?>
											<?php if ( $is_unlocked ) : ?>
												&middot; <?php echo esc_html( $week_passed ); ?>/<?php echo esc_html( ATK_COURSE_TESTS_PER_WEEK ); ?> passed
											<?php endif; ?>
										</div>
									</div>
									<div class="card-toolbar"><?php echo $state_badge; // phpcs:ignore -- built from static markup above, no user input ?></div>
								</div>
								<div class="card-body">
									<div class="row row-cols-2 row-cols-md-5 g-3">
										<?php for ( $t = 1; $t <= ATK_COURSE_TESTS_PER_WEEK; $t++ ) :
											$test_key = $key_prefix . '-w' . $week_num . '-t' . $t;
											$result   = $week_results[ $t ];
											// Positioned bottom-center via the same symbol-badge/top-100 pair as
											// the pass/fail badge below, so it reads as one floating "review"
											// button on the tile's lower edge rather than a row underneath it.
											$previous_trigger = atk_render_previous_attempts_trigger( $test_key, 'symbol-badge badge-circle btn btn-sm btn-icon btn-light top-100', true );
											$review_modals   .= atk_render_previous_attempts_modal( $test_key, 'Week ' . $week_num . ', Test ' . $t );

											$tile_class  = 'btn-light';
											$badge_color = '';
											$badge_icon  = '';
											if ( $result ) {
												$tile_class  = $result['passed'] ? 'btn-light-success' : 'btn-light-danger';
												$badge_color = $result['passed'] ? 'text-bg-success' : 'text-bg-danger';
												$badge_icon  = $result['passed'] ? 'bi-check-lg' : 'bi-x-lg';
											}
											?>
											<div class="col">
												<div class="position-relative h-100 atk-test-tile" data-test-key="<?php echo esc_attr( $test_key ); ?>">
													<?php if ( $is_unlocked ) : ?>
														<a href="<?php echo esc_url( add_query_arg( array( 'type' => $type, 'week' => $week_num, 'test' => $t ), atk_get_page_url( 'course-test' ) ) ); ?>" class="btn <?php echo esc_attr( $tile_class ); ?> w-100 h-100 d-flex flex-column align-items-center justify-content-center py-4">
															<span class="fw-bold">Test <?php echo esc_html( $t ); ?></span>
															<?php if ( $result ) : ?>
																<span class="fs-8"><?php echo esc_html( $result['score'] . '/' . $result['total'] ); ?></span>
															<?php endif; ?>
														</a>
													<?php else : ?>
														<button type="button" class="btn btn-light w-100 h-100 py-4" disabled>
															<i class="bi bi-lock-fill me-1"></i>Test <?php echo esc_html( $t ); ?>
														</button>
													<?php endif; ?>
													<?php if ( $badge_icon ) : ?>
														<span class="symbol-badge badge badge-circle <?php echo esc_attr( $badge_color ); ?>"><i class="bi <?php echo esc_attr( $badge_icon ); ?>"></i></span>
													<?php endif; ?>
													<?php echo $previous_trigger; // phpcs:ignore -- built from escaped pieces in atk_render_previous_attempts_trigger() ?>
												</div>
											</div>
										<?php endfor; ?>
									</div>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php echo $review_modals; ?>
	<?php
}

/**
 * Lets the Official Test Course page be built in Elementor: drop this
 * shortcode where the course hub should appear, same ?type= query arg
 * as before. No page title/breadcrumb here — that's chrome the page
 * itself adds if wanted, not baked into the shortcode's own content.
 */
add_shortcode( 'atk_course_hub', function () {
	ob_start();
	atk_render_course_hub( isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'street' );
	return ob_get_clean();
} );

/* -------------------------------------------------------------------- */
/* Course catalog + enrollment                                          */
/* -------------------------------------------------------------------- */

add_action( 'admin_post_atk_course_enroll', 'atk_handle_course_enroll' );
function atk_handle_course_enroll() {
	check_admin_referer( ATK_COURSE_ENROLL_NONCE_ACTION, 'atk_nonce' );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( add_query_arg( 'redirect_to', urlencode( atk_get_page_url( 'course' ) ), atk_get_page_url( 'signin' ) ) );
		exit;
	}

	$type    = isset( $_POST['course_type'] ) ? atk_course_type_or_default( sanitize_key( $_POST['course_type'] ) ) : 'street';
	$method  = isset( $_POST['payment_method'] ) ? sanitize_key( $_POST['payment_method'] ) : '';
	$user_id = get_current_user_id();

	// Re-submitting the wizard for an already-enrolled track is harmless —
	// nothing to charge for, just send them on to their existing progress.
	if ( atk_course_is_enrolled( $user_id, $type ) ) {
		wp_safe_redirect( add_query_arg( 'type', $type, atk_get_page_url( 'official-test-course' ) ) );
		exit;
	}

	$price = atk_course_effective_price( $type );

	// Free track, or neither gateway is currently enabled (not configured,
	// or held back by the Settings page's test-mode switch) — enroll
	// immediately, same as before this feature existed, rather than
	// blocking access.
	if ( $price <= 0 || ( ! atk_stripe_enabled() && ! atk_paypal_enabled() ) ) {
		atk_course_enrollment_timestamp( $user_id, $type );
		wp_safe_redirect( add_query_arg( 'type', $type, atk_get_page_url( 'official-test-course' ) ) );
		exit;
	}

	if ( 'paypal' === $method && atk_paypal_enabled() ) {
		$approve_url = atk_paypal_create_order( $user_id, $type, $price );

		if ( ! $approve_url ) {
			wp_safe_redirect( add_query_arg( 'notice', 'payment_error', atk_get_page_url( 'course' ) ) );
			exit;
		}

		wp_redirect( $approve_url ); // phpcs:ignore -- off-site redirect to PayPal, wp_safe_redirect() would block it
		exit;
	}

	if ( atk_stripe_enabled() ) {
		$checkout_url = atk_stripe_create_checkout_session( $user_id, $type, $price );

		if ( ! $checkout_url ) {
			wp_safe_redirect( add_query_arg( 'notice', 'payment_error', atk_get_page_url( 'course' ) ) );
			exit;
		}

		wp_redirect( $checkout_url ); // phpcs:ignore -- off-site redirect to Stripe, wp_safe_redirect() would block it
		exit;
	}

	// Requested gateway isn't configured and neither is the fallback.
	wp_safe_redirect( add_query_arg( 'notice', 'payment_error', atk_get_page_url( 'course' ) ) );
	exit;
}

/**
 * Creates a Stripe Checkout Session for one track's enrollment price and
 * returns its hosted checkout URL, or null on failure. Calls Stripe's REST
 * API directly via wp_remote_post() rather than pulling in the stripe-php
 * SDK — this theme has no Composer/dependency-management setup, and the
 * one call this needs (plus webhook signature verification, below) is
 * simple enough not to need it.
 */
function atk_stripe_create_checkout_session( $user_id, $type, $price ) {
	$types = atk_course_types();
	$label = isset( $types[ $type ]['label'] ) ? $types[ $type ]['label'] : $type;

	$params = array(
		'mode'                 => 'payment',
		'success_url'          => add_query_arg( 'type', $type, atk_get_page_url( 'official-test-course' ) ),
		'cancel_url'           => add_query_arg( 'notice', 'payment_cancelled', atk_get_page_url( 'course' ) ),
		'client_reference_id'  => $user_id,
		'metadata'             => array(
			'user_id'     => $user_id,
			'course_type' => $type,
		),
		'line_items'           => array(
			array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => 'gbp',
					'unit_amount'  => (int) round( $price * 100 ), // Stripe wants the smallest currency unit (pence).
					'product_data' => array(
						'name' => 'Official Test Course — ' . $label,
					),
				),
			),
		),
	);

	$response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
		'timeout' => 15,
		'headers' => array(
			'Authorization' => 'Bearer ' . atk_stripe_secret_key(),
			'Content-Type'  => 'application/x-www-form-urlencoded',
		),
		'body'    => http_build_query( $params ),
	) );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return isset( $data['url'] ) ? $data['url'] : null;
}

/**
 * The Stripe webhook: the ONLY place a track's enrollment is actually
 * stamped once payment is required. A driver landing back on success_url
 * proves nothing by itself (that URL can be typed directly without paying)
 * — only a signature-verified "checkout.session.completed" event does.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'atk/v1', '/stripe-webhook', array(
		'methods'             => 'POST',
		'callback'            => 'atk_handle_stripe_webhook',
		'permission_callback' => '__return_true', // Stripe can't authenticate as a WP user; verified via signature below instead.
	) );
} );

function atk_handle_stripe_webhook( WP_REST_Request $request ) {
	$payload   = $request->get_body();
	$signature = $request->get_header( 'stripe_signature' );
	$secret    = atk_stripe_webhook_secret();

	if ( ! $secret || ! $signature || ! atk_stripe_verify_signature( $payload, $signature, $secret ) ) {
		return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
	}

	$event = json_decode( $payload, true );

	if ( isset( $event['type'] ) && 'checkout.session.completed' === $event['type'] ) {
		$session     = isset( $event['data']['object'] ) ? $event['data']['object'] : array();
		$user_id     = isset( $session['metadata']['user_id'] ) ? (int) $session['metadata']['user_id'] : 0;
		$course_type = isset( $session['metadata']['course_type'] ) ? sanitize_key( $session['metadata']['course_type'] ) : '';
		$paid        = isset( $session['payment_status'] ) && 'paid' === $session['payment_status'];

		if ( $user_id && $course_type && $paid ) {
			atk_course_enrollment_timestamp( $user_id, atk_course_type_or_default( $course_type ) );
		}
	}

	return new WP_REST_Response( array( 'received' => true ), 200 );
}

/**
 * Stripe's documented webhook verification algorithm, done by hand: the
 * Stripe-Signature header is "t=<timestamp>,v1=<hmac>"; the HMAC is
 * SHA-256 of "{timestamp}.{raw payload}" keyed with the webhook secret.
 */
function atk_stripe_verify_signature( $payload, $signature_header, $secret ) {
	$parts = array();
	foreach ( explode( ',', $signature_header ) as $part ) {
		$kv = explode( '=', $part, 2 );
		if ( 2 === count( $kv ) ) {
			$parts[ $kv[0] ] = $kv[1];
		}
	}

	if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
		return false;
	}

	$expected = hash_hmac( 'sha256', $parts['t'] . '.' . $payload, $secret );

	return hash_equals( $expected, $parts['v1'] );
}

/**
 * PayPal REST API base — sandbox while testing; switch to
 * https://api-m.paypal.com once live Client ID/Secret are saved in Settings
 * and you're ready to take real payments.
 */
function atk_paypal_api_base() {
	return 'https://api-m.sandbox.paypal.com';
}

/**
 * An OAuth2 access token for PayPal's REST API (unlike Stripe, whose secret
 * key is usable directly as a Bearer token, PayPal requires exchanging
 * Client ID + Secret for a short-lived token first). Cached in a transient
 * for just under its real lifetime so a handful of enrollments in a row
 * don't each round-trip to the token endpoint.
 */
function atk_paypal_access_token() {
	$cached = get_transient( 'atk_paypal_access_token' );
	if ( $cached ) {
		return $cached;
	}

	$response = wp_remote_post( atk_paypal_api_base() . '/v1/oauth2/token', array(
		'timeout' => 15,
		'headers' => array(
			'Authorization' => 'Basic ' . base64_encode( atk_paypal_client_id() . ':' . atk_paypal_client_secret() ),
			'Content-Type'  => 'application/x-www-form-urlencoded',
		),
		'body'    => array( 'grant_type' => 'client_credentials' ),
	) );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['access_token'] ) ) {
		return null;
	}

	$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
	set_transient( 'atk_paypal_access_token', $data['access_token'], max( 60, $expires_in - 60 ) );

	return $data['access_token'];
}

/**
 * Creates a PayPal order (Orders API v2) for one track's enrollment price
 * and returns the "approve" link the driver is redirected to, or null on
 * failure. The order id PayPal returns is only referenced by its own
 * return_url query string here — atk_handle_paypal_return() reads it back
 * from there.
 */
function atk_paypal_create_order( $user_id, $type, $price ) {
	$token = atk_paypal_access_token();
	if ( ! $token ) {
		return null;
	}

	$types = atk_course_types();
	$label = isset( $types[ $type ]['label'] ) ? $types[ $type ]['label'] : $type;

	$body = array(
		'intent'         => 'CAPTURE',
		'purchase_units' => array(
			array(
				'custom_id'   => $user_id . ':' . $type,
				'description' => 'Official Test Course — ' . $label,
				'amount'      => array(
					'currency_code' => 'GBP',
					'value'         => number_format( $price, 2, '.', '' ),
				),
			),
		),
		'application_context' => array(
			'return_url' => add_query_arg( 'course_type', $type, admin_url( 'admin-post.php?action=atk_paypal_return' ) ),
			'cancel_url' => add_query_arg( 'notice', 'payment_cancelled', atk_get_page_url( 'course' ) ),
			'user_action' => 'PAY_NOW',
		),
	);

	$response = wp_remote_post( atk_paypal_api_base() . '/v2/checkout/orders', array(
		'timeout' => 15,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( $body ),
	) );

	if ( is_wp_error( $response ) || ! in_array( (int) wp_remote_retrieve_response_code( $response ), array( 200, 201 ), true ) ) {
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	foreach ( ( isset( $data['links'] ) ? $data['links'] : array() ) as $link ) {
		if ( isset( $link['rel'], $link['href'] ) && 'approve' === $link['rel'] ) {
			return $link['href'];
		}
	}

	return null;
}

/**
 * Where PayPal sends the driver back after they approve payment (?token=
 * the order id, PayPal's own query param name). Enrollment is granted here,
 * directly off this capture call's response — unlike Stripe Checkout,
 * PayPal's flow has the site itself make this authenticated server-to-server
 * call, so a successful "COMPLETED" response from it already IS trustworthy
 * proof of payment, with no separate webhook needed for the decision.
 */
add_action( 'admin_post_atk_paypal_return', 'atk_handle_paypal_return' );
add_action( 'admin_post_nopriv_atk_paypal_return', 'atk_handle_paypal_return' );
function atk_handle_paypal_return() {
	$order_id = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	$type     = isset( $_GET['course_type'] ) ? atk_course_type_or_default( sanitize_key( $_GET['course_type'] ) ) : 'street';

	if ( ! $order_id || ! is_user_logged_in() ) {
		wp_safe_redirect( add_query_arg( 'notice', 'payment_error', atk_get_page_url( 'course' ) ) );
		exit;
	}

	$token = atk_paypal_access_token();
	if ( ! $token ) {
		wp_safe_redirect( add_query_arg( 'notice', 'payment_error', atk_get_page_url( 'course' ) ) );
		exit;
	}

	$response = wp_remote_post( atk_paypal_api_base() . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture', array(
		'timeout' => 15,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
		'body'    => '{}',
	) );

	$data = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;

	if ( $data && isset( $data['status'] ) && 'COMPLETED' === $data['status'] ) {
		atk_course_enrollment_timestamp( get_current_user_id(), $type );
		wp_safe_redirect( add_query_arg( 'type', $type, atk_get_page_url( 'official-test-course' ) ) );
		exit;
	}

	wp_safe_redirect( add_query_arg( 'notice', 'payment_error', atk_get_page_url( 'course' ) ) );
	exit;
}

/**
 * The icon for each track, shared between the catalog cards and the
 * enrollment wizard's track-choice step.
 */
function atk_course_type_icon( $type ) {
	$icons = array( 'street' => 'map001', 'poi' => 'map008', 'route' => 'map006' );
	return isset( $icons[ $type ] ) ? $icons[ $type ] : '';
}

/**
 * Price display HTML for one track — struck-through regular price + sale
 * price when a genuine sale is active (sale price set and lower than
 * regular), just the regular price otherwise, or "Free" if no price has
 * been set on it yet. Shared between the catalog cards and (via JS mirror,
 * see assets/js/course-enroll.js) the enrollment wizard.
 */
function atk_course_price_html( $type ) {
	$price     = atk_course_get_price( $type );
	$effective = atk_course_effective_price( $type );

	if ( $effective <= 0 ) {
		return '<span class="fw-bolder text-gray-900">Free</span>';
	}

	$on_sale = '' !== $price['sale'] && (float) $price['sale'] > 0 && (float) $price['sale'] < $price['regular'];

	if ( $on_sale ) {
		return sprintf(
			'<span class="text-gray-500 text-decoration-line-through me-2">&pound;%1$s</span><span class="fw-bolder text-gray-900 fs-3">&pound;%2$s</span>',
			esc_html( number_format( $price['regular'], 2 ) ),
			esc_html( number_format( $effective, 2 ) )
		);
	}

	return sprintf( '<span class="fw-bolder text-gray-900 fs-3">&pound;%s</span>', esc_html( number_format( $effective, 2 ) ) );
}

/**
 * Course catalog: just the row of track cards (Street/POI/Route), each with
 * real details — enrolled drivers see progress + a "Continue" link, everyone
 * else sees price + an "Enroll"/"Sign In" button. No page-level chrome
 * (heading, intro paragraph, wrapping panel) — the page itself adds that if
 * it wants it, so the shortcode can drop cleanly into any layout.
 */
function atk_render_course_catalog() {
	$types         = atk_course_types();
	$test_labels   = atk_quiz_tests(); // reuses the real per-track instruction copy, not a duplicate
	$is_logged_in  = is_user_logged_in();
	$user_id       = $is_logged_in ? get_current_user_id() : 0;

	if ( $is_logged_in ) {
		wp_enqueue_script( 'atk-course-enroll', ATK_PLUGIN_URI . '/assets/js/course-enroll.js', array(), filemtime( ATK_PLUGIN_DIR . '/assets/js/course-enroll.js' ), true );
	}
	?>
	<div class="container py-10 py-lg-15">
		<?php if ( isset( $_GET['notice'] ) && 'payment_error' === $_GET['notice'] ) : ?>
			<div class="alert alert-danger d-flex align-items-center p-5 mb-8"><span>Something went wrong starting checkout. Please try enrolling again.</span></div>
		<?php elseif ( isset( $_GET['notice'] ) && 'payment_cancelled' === $_GET['notice'] ) : ?>
			<div class="alert alert-warning d-flex align-items-center p-5 mb-8"><span>Checkout was cancelled — you haven't been charged, and you're not enrolled yet.</span></div>
		<?php endif; ?>

		<div class="row g-6">
			<?php
			// Card backgrounds intentionally break from the usual white-card
			// convention: one soft tint per track, per an explicit design brief to
			// match a reference catalog layout. Every hue is one of the stylesheet's
			// existing role tints (bg-light-primary/info/warning), not a one-off colour.
			$card_roles = array( 'street' => 'primary', 'poi' => 'info', 'route' => 'warning' );
			foreach ( $types as $tkey => $tinfo ) :
				$enrolled    = $is_logged_in && atk_course_is_enrolled( $user_id, $tkey );
				$role        = $card_roles[ $tkey ];
				$total_tests = atk_course_total_weeks( $tkey ) * ATK_COURSE_TESTS_PER_WEEK;
				$total_qs    = $total_tests * ATK_COURSE_QUESTIONS_PER_TEST;
				?>
				<div class="col-md-4">
					<div class="atk-course-card bg-light-<?php echo esc_attr( $role ); ?> p-6 h-100 d-flex flex-column" style="border-radius:1.25rem;">
						<div class="d-flex align-items-start justify-content-between mb-5">
							<?php if ( $enrolled ) : ?>
								<span class="badge badge-light-success">Enrolled</span>
							<?php else : ?>
								<span></span>
							<?php endif; ?>
							<div class="symbol symbol-50px">
								<div class="symbol-label bg-white text-<?php echo esc_attr( $role ); ?> shadow-sm"><?php echo atk_render_duotone_icon( atk_course_type_icon( $tkey ), 'svg-icon svg-icon-2hx svg-icon-' . $role ); ?></div>
							</div>
						</div>

						<h3 class="fs-3 fw-boldest text-gray-900 mb-2"><?php echo esc_html( atk_course_track_name( $tkey ) . ' Official Test' ); ?></h3>
						<p class="fs-7 text-gray-700 mb-5 flex-grow-1"><?php echo esc_html( $test_labels[ $tkey ]['instruction'] ); ?></p>

						<div class="d-flex align-items-center gap-4 fs-8 text-gray-700 fw-semibold mb-5">
							<span><i class="bi bi-question-circle me-1"></i><?php echo esc_html( number_format_i18n( $total_qs ) ); ?> questions</span>
							<span><i class="bi bi-calendar-week me-1"></i><?php echo esc_html( $total_tests ); ?> tests &middot; <?php echo esc_html( atk_course_total_weeks( $tkey ) ); ?> wks</span>
						</div>

						<?php if ( $enrolled ) :
							$progress   = atk_course_progress( $user_id, $tkey );
							$passed     = count( array_filter( $progress, function ( $r ) { return ! empty( $r['passed'] ); } ) );
							$pct        = $total_tests > 0 ? round( ( $passed / $total_tests ) * 100 ) : 0;
							$started_at = atk_course_enrollment_timestamp( $user_id, $tkey );
							?>
							<div class="mb-5">
								<div class="d-flex justify-content-between fs-8 fw-bold text-gray-800 mb-2">
									<span>Progress</span>
									<span><?php echo esc_html( $passed ); ?>/<?php echo esc_html( $total_tests ); ?></span>
								</div>
								<div class="progress" style="height:8px;background-color:rgba(255,255,255,.6);">
									<div class="progress-bar bg-primary rounded-pill" role="progressbar" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
								</div>
							</div>

							<div class="d-flex align-items-center justify-content-between pt-4" style="border-top:1px solid rgba(38,50,56,.12);">
								<span class="fs-8 text-gray-700">Enrolled <?php echo esc_html( date_i18n( 'j M Y', $started_at ) ); ?></span>
								<a href="<?php echo esc_url( add_query_arg( 'type', $tkey, atk_get_page_url( 'official-test-course' ) ) ); ?>" class="btn btn-primary">Continue</a>
							</div>
						<?php elseif ( ! $is_logged_in ) : ?>
							<div class="d-flex align-items-center justify-content-between pt-4" style="border-top:1px solid rgba(38,50,56,.12);">
								<div><?php echo atk_course_price_html( $tkey ); // phpcs:ignore -- built from escaped pieces in atk_course_price_html() ?></div>
								<a href="<?php echo esc_url( add_query_arg( 'redirect_to', urlencode( atk_get_page_url( 'course' ) ), atk_get_page_url( 'signin' ) ) ); ?>" class="btn btn-primary">Sign In</a>
							</div>
						<?php else : ?>
							<div class="d-flex align-items-center justify-content-between pt-4" style="border-top:1px solid rgba(38,50,56,.12);">
								<div><?php echo atk_course_price_html( $tkey ); // phpcs:ignore -- built from escaped pieces in atk_course_price_html() ?></div>
								<button type="button" class="btn btn-primary atk-enroll-btn" data-course-type="<?php echo esc_attr( $tkey ); ?>" data-bs-toggle="modal" data-bs-target="#atk-enroll-modal">Enroll</button>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<?php if ( $is_logged_in ) : ?>
		<?php atk_render_course_enroll_wizard_modal(); ?>
	<?php endif; ?>
	<?php
}

/**
 * Lets the Course page be built in Elementor: drop this shortcode where
 * the course catalog (+ enrollment wizard) should appear. No page title/
 * breadcrumb here — that's chrome the page itself adds if wanted.
 */
add_shortcode( 'atk_course_catalog', function () {
	ob_start();
	atk_render_course_catalog();
	return ob_get_clean();
} );

/**
 * Enrollment wizard: a stepper-links wizard (the stepper styles are in
 * assets/css/akn.css, the step navigation is assets/js/course-enroll.js).
 * Each step's Next/Previous/Submit buttons live inside that step itself,
 * so there's no shared footer needing to show/hide based on the current
 * step. The final step is a genuine form submit (no stepper JS involved),
 * so it works even though this is a driver-facing site with no Ajax
 * validation layer to wire up.
 */
function atk_render_course_enroll_wizard_modal() {
	$types = atk_course_types();
	?>
	<div class="modal fade" id="atk-enroll-modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title">Enroll in a Course Track</h3>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div class="stepper stepper-links d-flex flex-column" id="atk-enroll-stepper">
						<div class="stepper-nav mb-8 justify-content-center">
							<div class="stepper-item current" data-akn-stepper-element="nav">
								<h3 class="stepper-title">Choose Track</h3>
							</div>
							<div class="stepper-item" data-akn-stepper-element="nav">
								<h3 class="stepper-title">What to Expect</h3>
							</div>
							<div class="stepper-item" data-akn-stepper-element="nav">
								<h3 class="stepper-title">Confirm</h3>
							</div>
						</div>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="w-100">
							<input type="hidden" name="action" value="atk_course_enroll" />
							<?php wp_nonce_field( ATK_COURSE_ENROLL_NONCE_ACTION, 'atk_nonce' ); ?>

							<?php /* Each step has exactly ONE direct child (a `w-100` wrapper), so its contents stack instead of sitting side by side. */ ?>
							<div class="current" data-akn-stepper-element="content">
								<div class="w-100">
									<div class="row g-4 mb-8">
										<?php foreach ( $types as $tkey => $tinfo ) :
											$price = atk_course_get_price( $tkey );
											?>
											<div class="col-md-4">
												<input type="radio" class="btn-check" name="course_type" value="<?php echo esc_attr( $tkey ); ?>" id="atk-enroll-track-<?php echo esc_attr( $tkey ); ?>"<?php checked( 'street' === $tkey ); ?> data-price-regular="<?php echo esc_attr( number_format( $price['regular'], 2 ) ); ?>" data-price-effective="<?php echo esc_attr( number_format( atk_course_effective_price( $tkey ), 2 ) ); ?>" />
												<label class="btn btn-outline btn-outline-dashed btn-outline-default p-6 d-flex flex-column align-items-center w-100 h-100" for="atk-enroll-track-<?php echo esc_attr( $tkey ); ?>">
													<div class="mb-3"><?php echo atk_render_duotone_icon( atk_course_type_icon( $tkey ), 'svg-icon svg-icon-2x svg-icon-primary' ); ?></div>
													<span class="fw-bolder"><?php echo esc_html( $tinfo['label'] ); ?></span>
												</label>
											</div>
										<?php endforeach; ?>
									</div>
									<div class="d-flex justify-content-end">
										<button type="button" class="btn btn-primary" data-akn-stepper-action="next">Continue</button>
									</div>
								</div>
							</div>

							<div data-akn-stepper-element="content">
								<div class="w-100">
									<div class="mb-8">
										<h4 class="fw-bolder text-gray-900 mb-4">What to Expect</h4>
										<div class="atk-enroll-price-display mb-4"></div>
										<ul class="fs-6 text-gray-700">
											<li class="mb-2"><?php echo esc_html( ATK_COURSE_TESTS_PER_WEEK ); ?> tests released each week, at your own pace.</li>
											<li class="mb-2">A new week unlocks every 7 days from the day you enroll.</li>
											<li class="mb-2"><?php echo esc_html( (int) ( ATK_COURSE_PASS_THRESHOLD * 100 ) ); ?>% needed to pass each test &mdash; you can retake any test as many times as you like.</li>
											<li>Your enrollment date is set the moment you confirm on the next step, and can't be changed afterwards.</li>
										</ul>
									</div>
									<div class="d-flex justify-content-between">
										<button type="button" class="btn btn-light-primary" data-akn-stepper-action="previous">Back</button>
										<button type="button" class="btn btn-primary" data-akn-stepper-action="next">Continue</button>
									</div>
								</div>
							</div>

							<?php
							$stripe_ready = atk_stripe_enabled();
							$paypal_ready = atk_paypal_enabled();
							?>
							<div data-akn-stepper-element="content">
								<div class="w-100">
									<div class="text-center mb-8">
										<i class="bi bi-check-circle text-success fs-3x mb-4 d-block"></i>
										<p class="fs-5 text-gray-700 mb-3">Ready to enroll? Confirm below to get started.</p>
										<div class="atk-enroll-price-display"></div>
									</div>
									<?php /* Price (and so free-vs-paid) is per track, but this step is shared by all three, so which button set applies is decided client-side (assets/js/course-enroll.js) from whichever track radio is checked — the free button starts hidden here only when a gateway button exists to hide it behind. */ ?>
									<div class="d-flex justify-content-between align-items-center">
										<button type="button" class="btn btn-light-primary" data-akn-stepper-action="previous">Back</button>
										<div class="d-flex gap-3">
											<button type="submit" class="btn btn-primary atk-enroll-free-btn<?php echo ( $stripe_ready || $paypal_ready ) ? ' d-none' : ''; ?>">Enroll Now</button>
											<?php if ( $stripe_ready ) : ?>
												<button type="submit" name="payment_method" value="stripe" class="btn btn-primary atk-enroll-paid-btn"><i class="bi bi-credit-card fs-4 me-2"></i>Pay with Stripe</button>
											<?php endif; ?>
											<?php if ( $paypal_ready ) : ?>
												<button type="submit" name="payment_method" value="paypal" class="btn btn-primary atk-enroll-paid-btn"><i class="bi bi-wallet2 fs-4 me-2"></i>Pay with PayPal</button>
											<?php endif; ?>
										</div>
									</div>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}
