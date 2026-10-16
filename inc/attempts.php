<?php
/**
 * Site-wide attempt counters for the mockup tests: how many times (across
 * every visitor) each test has been started, including retries.
 *
 * Also holds per-user, per-test attempt review history (question-by-question
 * breakdowns) for logged-in users, shared by both the mockup tests and the
 * Course — a "Previous Attempts" feature layered on top of the counters
 * above, which stay anonymous/global by design.
 */

defined( 'ABSPATH' ) || exit;

const ATK_ATTEMPT_NONCE_ACTION = 'atk_track_attempt';
const ATK_REVIEW_NONCE_ACTION  = 'atk_record_review';
const ATK_REVIEW_MAX_PER_TEST  = 5;

function atk_attempt_option_key( $test_key ) {
	return 'atk_attempts_' . $test_key;
}

function atk_attempt_count( $test_key ) {
	return (int) get_option( atk_attempt_option_key( $test_key ), 0 );
}

/**
 * Whether $test_key refers to a real test: a mockup/study quiz, a Course
 * test (course-w{n}-t{n}), or one of the other non-quiz-engine tools that
 * also report attempts (e.g. the Major Streets map).
 */
function atk_is_valid_attempt_key( $test_key ) {
	$other_valid_keys = array( 'major-streets', 'neighbourhoods', 'places-of-interest' );
	$is_course_key    = (bool) preg_match( '/^course(-poi|-route)?-w\d+-t\d+$/', $test_key );
	return $is_course_key || atk_quiz_config( $test_key ) || in_array( $test_key, $other_valid_keys, true );
}

/**
 * The most questions and minutes a recorded attempt of this test can honestly have. Every
 * test is 30 questions at most and ATK_QUIZ_MINUTES long, except the Official Route test,
 * whose tests are as long as the streets saved in them (see atk_course_test_max_questions()).
 *
 * @return array{questions:int,points:int,minutes:int}
 */
function atk_attempt_limits( $test_key ) {
	$limits = array( 'questions' => 30, 'points' => 30, 'minutes' => ATK_QUIZ_MINUTES );
	if ( preg_match( '/^course-route-w(\d+)-t(\d+)$/', (string) $test_key, $m ) && function_exists( 'atk_course_test_max_questions' ) ) {
		$count = atk_course_test_max_questions( 'route', (int) $m[1], (int) $m[2] );
		$limits['questions'] = max( 30, $count );
		$limits['points']    = max( 30, atk_course_test_max_points( 'route', (int) $m[1], (int) $m[2] ) ); // the Official Route test scores a point per answer
		$limits['minutes']   = atk_course_test_minutes( 'route', (int) $m[1], (int) $m[2] );
	}
	return $limits;
}

add_action( 'wp_ajax_atk_track_attempt', 'atk_handle_track_attempt' );
add_action( 'wp_ajax_nopriv_atk_track_attempt', 'atk_handle_track_attempt' );
function atk_handle_track_attempt() {
	check_ajax_referer( ATK_ATTEMPT_NONCE_ACTION, 'nonce' );

	$test_key = isset( $_POST['test'] ) ? sanitize_key( wp_unslash( $_POST['test'] ) ) : '';
	if ( ! atk_is_valid_attempt_key( $test_key ) ) {
		wp_send_json_error( array( 'message' => 'Unknown test.' ), 400 );
	}

	$count = atk_attempt_count( $test_key ) + 1;
	update_option( atk_attempt_option_key( $test_key ), $count, false );

	wp_send_json_success( array( 'count' => $count ) );
}

/* -------------------------------------------------------------------- */
/* Previous Attempts: per-user question-by-question review history      */
/* -------------------------------------------------------------------- */

/**
 * This user's stored reviews, keyed by test key, each an array of up to
 * ATK_REVIEW_MAX_PER_TEST attempts (newest first).
 */
function atk_attempt_reviews( $user_id ) {
	$reviews = get_user_meta( $user_id, 'atk_attempt_reviews', true );
	return is_array( $reviews ) ? $reviews : array();
}

function atk_record_attempt_review( $user_id, $test_key, $score, $total, $answers, $duration = 0 ) {
	$reviews = atk_attempt_reviews( $user_id );
	if ( empty( $reviews[ $test_key ] ) || ! is_array( $reviews[ $test_key ] ) ) {
		$reviews[ $test_key ] = array();
	}

	array_unshift( $reviews[ $test_key ], array(
		'score'     => (int) $score,
		'total'     => (int) $total,
		'timestamp' => time(),
		'duration'  => (int) $duration,
		'answers'   => $answers,
	) );
	$reviews[ $test_key ] = array_slice( $reviews[ $test_key ], 0, ATK_REVIEW_MAX_PER_TEST );

	update_user_meta( $user_id, 'atk_attempt_reviews', $reviews );
}

/**
 * "12:34" (m:ss) — matches the quiz's own countdown timer format
 * (assets/js/quiz.js formatTime()), so an attempt's recorded time reads the
 * same way the driver saw it tick down live.
 */
function atk_format_duration( $seconds ) {
	$seconds = max( 0, (int) $seconds );
	return sprintf( '%d:%02d', (int) floor( $seconds / 60 ), $seconds % 60 );
}

add_action( 'wp_ajax_atk_record_review', 'atk_handle_record_review' );
function atk_handle_record_review() {
	check_ajax_referer( ATK_REVIEW_NONCE_ACTION, 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'Not logged in.' ), 401 );
	}

	$test_key = isset( $_POST['test'] ) ? sanitize_key( wp_unslash( $_POST['test'] ) ) : '';
	if ( ! atk_is_valid_attempt_key( $test_key ) ) {
		wp_send_json_error( array( 'message' => 'Unknown test.' ), 400 );
	}

	$total    = isset( $_POST['total'] ) ? (int) $_POST['total'] : 0;
	$score    = isset( $_POST['score'] ) ? (int) $_POST['score'] : 0;
	$limits   = atk_attempt_limits( $test_key );
	$total    = max( 0, min( $limits['points'], $total ) );
	$score    = max( 0, min( $total, $score ) );
	// Clamped to double the test's real allowance as a sanity ceiling — a
	// client-reported value, never trusted outright.
	$duration = isset( $_POST['duration'] ) ? (int) $_POST['duration'] : 0;
	$duration = max( 0, min( $limits['minutes'] * 60 * 2, $duration ) );

	$raw_answers = array();
	if ( isset( $_POST['answers'] ) ) {
		$decoded = json_decode( wp_unslash( $_POST['answers'] ), true );
		if ( is_array( $decoded ) ) {
			$raw_answers = $decoded;
		}
	}

	$answers = array();
	foreach ( array_slice( $raw_answers, 0, $limits['questions'] ) as $raw ) {
		if ( ! is_array( $raw ) ) {
			continue;
		}
		$answers[] = array(
			'prompt'      => wp_kses_post( isset( $raw['prompt'] ) ? (string) $raw['prompt'] : '' ),
			'given'       => sanitize_text_field( isset( $raw['given'] ) ? (string) $raw['given'] : '' ),
			'correctText' => sanitize_text_field( isset( $raw['correctText'] ) ? (string) $raw['correctText'] : '' ),
			'correct'     => ! empty( $raw['correct'] ),
		) + ATK_Route_Pairs::clean_result( $raw ); // the Official Route test also keeps points and max, see assets/js/quiz.js
	}

	atk_record_attempt_review( get_current_user_id(), $test_key, $score, $total, $answers, $duration );

	wp_send_json_success();
}

/**
 * "Previous Attempts" button for a test, or '' if this user has none —
 * e.g. `<button ... data-bs-toggle="modal" data-bs-target="#…">`. Pair
 * with atk_render_previous_attempts_modal() for the same $test_key and
 * $user_id. $icon_only drops the label for tight grid layouts (e.g. the
 * Course hub's 5-per-row test grid), keeping only the icon + a tooltip.
 * $user_id defaults to the current user; the Instructor Dashboard passes a
 * driver's ID explicitly to view their history instead of its own.
 *
 * $attempts_override, when given, is used as the attempt list directly
 * instead of looking $test_key up in atk_attempt_reviews() — for a track-
 * level history already merged across several real test keys (see
 * atk_course_track_attempts()). $test_key still names the modal in that
 * case, it just isn't a real stored key.
 */
function atk_render_previous_attempts_trigger( $test_key, $classes = 'btn btn-sm btn-light-primary', $icon_only = false, $user_id = null, $attempts_override = null ) {
	if ( null === $user_id ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user_id = get_current_user_id();
	}
	if ( null !== $attempts_override ) {
		if ( empty( $attempts_override ) ) {
			return '';
		}
	} else {
		$reviews = atk_attempt_reviews( $user_id );
		if ( empty( $reviews[ $test_key ] ) ) {
			return '';
		}
	}
	$modal_id = 'atk-review-' . $user_id . '-' . sanitize_html_class( $test_key );
	$label    = $icon_only ? '' : 'Previous Attempts';
	return sprintf(
		'<button type="button" class="%1$s" data-bs-toggle="modal" data-bs-target="#%2$s" title="Previous Attempts"><i class="bi bi-clock-history%3$s"></i>%4$s</button>',
		esc_attr( $classes ),
		esc_attr( $modal_id ),
		$icon_only ? '' : ' me-1',
		esc_html( $label )
	);
}

/**
 * The small meta line under an attempt's date/score header: "Attempt X of
 * Y · Time: 12:34", with a "Week W, Test T · " prefix when the attempt
 * carries that (only true for a merged track history — see
 * atk_course_track_attempts() — where attempts from several week/test
 * slots are shown together and it'd otherwise be ambiguous which was which).
 */
function atk_render_attempt_meta_line( $attempt, $position, $total_shown ) {
	$parts = array();
	if ( isset( $attempt['week'], $attempt['test'] ) ) {
		$parts[] = esc_html( sprintf( 'Week %d, Test %d', $attempt['week'], $attempt['test'] ) );
	}
	$parts[] = esc_html( sprintf( 'Attempt %d of %d', $position, $total_shown ) );
	$parts[] = 'Time: ' . esc_html( atk_format_duration( $attempt['duration'] ?? 0 ) );
	return implode( ' &middot; ', $parts );
}

/**
 * One attempt's question-by-question review cards, in the same right/wrong
 * styling as the live results screen (assets/js/quiz.js finish()) — shared
 * by both branches of atk_render_previous_attempts_modal() below.
 */
function atk_render_attempt_review_cards( $attempt ) {
	ob_start();
	foreach ( $attempt['answers'] as $qi => $a ) :
		$correct    = ! empty( $a['correct'] );
		// Official Route answers carry points: a part-right street shows amber with "2 / 3".
		$has_points = isset( $a['max'] );
		$partial    = $has_points && ! $correct && (int) $a['points'] > 0;
		$card_class = $correct ? 'bg-light-success' : ( $partial ? 'bg-light-warning' : 'bg-light-danger' );
		$badge_cls  = $correct ? 'badge-success' : ( $partial ? 'badge-warning' : 'badge-danger' );
		$badge_text = $has_points ? (int) $a['points'] . ' / ' . (int) $a['max'] : ( $correct ? 'Correct' : 'Incorrect' );
		?>
		<div class="rounded <?php echo esc_attr( $card_class ); ?> p-5 mb-4">
			<div class="d-flex align-items-center justify-content-between mb-2">
				<div class="fw-bold text-gray-700">Question <?php echo esc_html( $qi + 1 ); ?></div>
				<span class="badge <?php echo esc_attr( $badge_cls ); ?>"><?php echo esc_html( $badge_text ); ?></span>
			</div>
			<div class="fs-6 text-gray-900 mb-2"><?php echo wp_kses_post( $a['prompt'] ); ?></div>
			<div class="fs-7 text-gray-600<?php echo $correct ? '' : ' mb-3'; ?>">Your answer: <?php echo esc_html( $a['given'] ? $a['given'] : '(no answer)' ); ?></div>
			<?php if ( ! $correct ) : ?>
				<span class="atk-answer-pill bg-success fs-7"><?php echo $has_points ? 'Every answer: ' : 'Correct answer: '; ?><?php echo esc_html( $a['correctText'] ); ?></span>
			<?php endif; ?>
		</div>
	<?php endforeach;
	return ob_get_clean();
}

/**
 * The modal itself for a test's attempt history, showing the full
 * question-by-question review for each past attempt. Returns '' if this
 * user has no history — call alongside atk_render_previous_attempts_trigger()
 * and print once, outside any card/grid markup (Bootstrap modals shouldn't
 * be nested inside constrained ancestors — see inc/arteries-map.php for the
 * same caution with the Major Streets modals).
 *
 * A single attempt is shown directly, with nothing to page between. Once
 * there are 2+ attempts, they're shown one at a time (newest first) behind
 * a pagination control (assets/js/attempts-paginator.js) rather than an
 * accordion — a driver reviewing their own history cares about one attempt
 * at a time, not comparing several open at once.
 *
 * $attempts_override works the same as on atk_render_previous_attempts_trigger()
 * — pass a pre-merged attempt list (e.g. from atk_course_track_attempts())
 * instead of a real stored $test_key.
 */
function atk_render_previous_attempts_modal( $test_key, $title, $user_id = null, $attempts_override = null ) {
	if ( null === $user_id ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user_id = get_current_user_id();
	}
	if ( null !== $attempts_override ) {
		if ( empty( $attempts_override ) ) {
			return '';
		}
		$attempts = $attempts_override;
	} else {
		$reviews = atk_attempt_reviews( $user_id );
		if ( empty( $reviews[ $test_key ] ) ) {
			return '';
		}
		$attempts = $reviews[ $test_key ];
	}
	$modal_id = 'atk-review-' . $user_id . '-' . sanitize_html_class( $test_key );

	if ( count( $attempts ) > 1 ) {
		wp_enqueue_script( 'atk-attempts-paginator', ATK_PLUGIN_URI . '/assets/js/attempts-paginator.js', array(), filemtime( ATK_PLUGIN_DIR . '/assets/js/attempts-paginator.js' ), true );
	}

	ob_start();
	?>
	<div class="modal fade" id="<?php echo esc_attr( $modal_id ); ?>" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title"><?php echo esc_html( $title ); ?> &mdash; Previous Attempts</h3>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<?php $modal_user = get_userdata( $user_id ); ?>
					<?php if ( $modal_user ) : ?>
						<div class="d-flex align-items-center gap-3 mb-5">
							<?php echo atk_get_avatar_html( $user_id, 40 ); ?>
							<div class="fw-bold text-gray-900"><?php echo esc_html( $modal_user->display_name ); ?></div>
						</div>
					<?php endif; ?>
					<?php if ( 1 === count( $attempts ) ) :
						$attempt = $attempts[0];
						$passed  = $attempt['total'] > 0 && ( $attempt['score'] / $attempt['total'] ) >= 0.75;
						?>
						<div class="d-flex align-items-center justify-content-between mb-1">
							<span class="text-gray-700"><?php echo esc_html( date_i18n( 'j M Y, g:i a', $attempt['timestamp'] ) ); ?></span>
							<span class="badge <?php echo $passed ? 'badge-light-success' : 'badge-light-danger'; ?>"><?php echo esc_html( $attempt['score'] . '/' . $attempt['total'] ); ?></span>
						</div>
						<div class="fs-7 text-gray-500 mb-4"><?php echo atk_render_attempt_meta_line( $attempt, 1, 1 ); ?></div>
						<?php echo atk_render_attempt_review_cards( $attempt ); ?>
					<?php else : ?>
						<div class="atk-attempts-paginator">
							<?php foreach ( $attempts as $i => $attempt ) :
								$passed = $attempt['total'] > 0 && ( $attempt['score'] / $attempt['total'] ) >= 0.75;
								?>
								<div class="atk-attempt-page<?php echo 0 === $i ? '' : ' d-none'; ?>" data-page="<?php echo esc_attr( $i ); ?>">
									<div class="d-flex align-items-center justify-content-between mb-1">
										<span class="text-gray-700"><?php echo esc_html( date_i18n( 'j M Y, g:i a', $attempt['timestamp'] ) ); ?></span>
										<span class="badge <?php echo $passed ? 'badge-light-success' : 'badge-light-danger'; ?>"><?php echo esc_html( $attempt['score'] . '/' . $attempt['total'] ); ?></span>
									</div>
									<div class="fs-7 text-gray-500 mb-4"><?php echo atk_render_attempt_meta_line( $attempt, count( $attempts ) - $i, count( $attempts ) ); ?></div>
									<?php echo atk_render_attempt_review_cards( $attempt ); ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<?php if ( count( $attempts ) > 1 ) : ?>
					<div class="modal-footer justify-content-center">
						<nav aria-label="Previous attempts pages">
							<ul class="pagination pagination-sm mb-0">
								<?php foreach ( $attempts as $i => $attempt ) : ?>
									<li class="page-item<?php echo 0 === $i ? ' active' : ''; ?>">
										<button type="button" class="page-link atk-attempt-page-link" data-page="<?php echo esc_attr( $i ); ?>"><?php echo esc_html( $i + 1 ); ?></button>
									</li>
								<?php endforeach; ?>
							</ul>
						</nav>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
