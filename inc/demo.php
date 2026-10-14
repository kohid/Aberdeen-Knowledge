<?php
/**
 * Instructor Dashboard "Demo" tab: generates realistic-looking sample
 * activity (demo driver accounts with mockup test history + Official Test
 * Course enrollment/progress/attempts) so every dashboard and profile view
 * has something real to show without waiting for actual drivers to sign up.
 *
 * Demo drivers get the real 'driver' role and mix into the real Driver
 * Results and everywhere else driver data is shown — tagged via the
 * 'atk_is_demo_driver' user meta so "Clear Demo Data" can find and remove
 * exactly them again, and so a small "Demo" badge can mark them wherever
 * driver names appear.
 */

defined( 'ABSPATH' ) || exit;

const ATK_DEMO_NONCE_ACTION = 'atk_demo_data';

/**
 * Fixed set of demo drivers to create — realistic names, nothing invented
 * beyond that. Kept fixed (not random count) so "Generate" is
 * idempotent-ish and predictable to reason about.
 */
function atk_demo_driver_names() {
	return array(
		'Amy Reid', 'Jack Simpson', 'Priya Sharma', 'Connor Wallace', 'Zara Ahmed',
		'Fraser Duncan', 'Nadia Hussain', 'Ryan Forbes', 'Emily Watt', 'Callum Fraser',
		'Olivia Grant', 'Daniel Kelly', 'Sophie Mearns', 'Lewis Bruce', 'Grace Stewart',
		'Adam Chalmers', 'Hannah Reid', 'Liam Cruickshank', 'Megan Petrie', 'Ewan Sinclair',
	);
}

function atk_demo_drivers() {
	return get_users( array(
		'role'       => 'driver',
		'meta_key'   => 'atk_is_demo_driver',
		'meta_value' => '1',
		'orderby'    => 'display_name',
		'order'      => 'ASC',
	) );
}

function atk_is_demo_driver( $user_id ) {
	return (bool) get_user_meta( $user_id, 'atk_is_demo_driver', true );
}

/**
 * A synthetic `answers` list shaped like a real attempt review's, so the
 * Previous Attempts modal's question-by-question cards render correctly —
 * placeholder prompts, not real question bank content.
 */
function atk_demo_answers( $total, $correct ) {
	$answers = array();
	for ( $i = 0; $i < $total; $i++ ) {
		$is_correct = $i < $correct;
		$answers[]  = array(
			'prompt'      => 'Demo question ' . ( $i + 1 ),
			'given'       => $is_correct ? 'Correct demo answer' : 'A wrong demo answer',
			'correctText' => 'Correct demo answer',
			'correct'     => $is_correct,
		);
	}
	shuffle( $answers ); // don't bunch every wrong answer at the end — looks more real
	return $answers;
}

/**
 * Creates the demo drivers (skips any that already exist by login) and
 * seeds each with mockup test attempts across all three tests, plus full
 * enrollment + progress + attempt history across all three Official Test
 * Course tracks.
 */
function atk_generate_demo_data() {
	$mockup_tests = atk_quiz_tests();
	$course_types = atk_course_types();

	foreach ( atk_demo_driver_names() as $name ) {
		$login = 'demo-' . sanitize_title( $name );
		$user  = get_user_by( 'login', $login );

		if ( ! $user ) {
			$user_id = wp_insert_user( array(
				'user_login'   => $login,
				'user_email'   => $login . '@example.test',
				'user_pass'    => wp_generate_password( 20 ),
				'display_name' => $name,
				'first_name'   => strtok( $name, ' ' ),
				'role'         => 'driver',
			) );
			if ( is_wp_error( $user_id ) ) {
				continue;
			}
			update_user_meta( $user_id, 'atk_is_demo_driver', 1 );
		} else {
			$user_id = $user->ID;
		}

		// Mockup tests: one to three attempts each, varied score.
		foreach ( $mockup_tests as $key => $test ) {
			$attempts = wp_rand( 1, 3 );
			for ( $a = 0; $a < $attempts; $a++ ) {
				$total   = 20;
				$correct = wp_rand( 10, 20 );
				atk_record_attempt_review( $user_id, $key, $correct, $total, atk_demo_answers( $total, $correct ), wp_rand( 300, 1100 ) );
			}
		}

		// Official Test Course: enrolled in every track, with a couple of
		// completed weeks (progress) and matching attempt history so the
		// Profile page's per-track "Previous Attempts" has real data too.
		foreach ( $course_types as $type_key => $type_info ) {
			update_user_meta( $user_id, atk_course_meta_key( $type_key, 'atk_course_started_at' ), time() - wp_rand( 1, 20 ) * DAY_IN_SECONDS );

			$prefix = atk_course_test_key_prefix( $type_key );
			for ( $week = 1; $week <= 2; $week++ ) {
				for ( $test = 1; $test <= 2; $test++ ) {
					$total   = ATK_COURSE_QUESTIONS_PER_TEST;
					$correct = wp_rand( 15, ATK_COURSE_QUESTIONS_PER_TEST );
					$duration = wp_rand( 400, ATK_QUIZ_MINUTES * 60 );

					atk_course_record_result( $user_id, $type_key, $week, $test, $correct, $total );
					atk_record_attempt_review( $user_id, $prefix . '-w' . $week . '-t' . $test, $correct, $total, atk_demo_answers( $total, $correct ), $duration );
				}
			}
		}
	}
}

/**
 * Deletes every demo driver outright (and, with them, all their user meta —
 * mockup/course attempt history, progress, enrollment) via WordPress's own
 * user deletion, which cleans up meta automatically. Only ever touches
 * users tagged atk_is_demo_driver — never a real driver.
 */
function atk_clear_demo_data() {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( atk_demo_drivers() as $driver ) {
		wp_delete_user( $driver->ID );
	}
}

add_action( 'admin_post_atk_generate_demo_data', 'atk_handle_generate_demo_data' );
function atk_handle_generate_demo_data() {
	check_admin_referer( ATK_DEMO_NONCE_ACTION, 'atk_nonce' );
	atk_require_instructor();
	atk_generate_demo_data();
	wp_safe_redirect( add_query_arg( array( 'tab' => 'demo', 'notice' => 'demo_generated' ), atk_get_page_url( 'instructor-dashboard' ) ) );
	exit;
}

add_action( 'admin_post_atk_clear_demo_data', 'atk_handle_clear_demo_data' );
function atk_handle_clear_demo_data() {
	check_admin_referer( ATK_DEMO_NONCE_ACTION, 'atk_nonce' );
	atk_require_instructor();
	atk_clear_demo_data();
	wp_safe_redirect( add_query_arg( array( 'tab' => 'demo', 'notice' => 'demo_cleared' ), atk_get_page_url( 'instructor-dashboard' ) ) );
	exit;
}

/**
 * The Demo tab itself: current demo driver count/list, Generate and Clear
 * buttons, and a quick summary of what each demo driver has (mockup
 * attempts tried, tracks enrolled) so it's obvious at a glance the data is
 * really there.
 */
function atk_render_instructor_demo_tab() {
	$drivers      = atk_demo_drivers();
	$mockup_tests = atk_quiz_tests();
	$course_types = atk_course_types();
	?>
	<div class="card mb-6">
		<div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-4">
			<div>
				<h2 class="card-title mb-2">Demo Data</h2>
				<p class="text-gray-600 mb-0">Creates <?php echo esc_html( count( atk_demo_driver_names() ) ); ?> sample driver accounts with realistic mockup test attempts and Official Test Course activity, so Driver Results and profiles have something to show. Demo drivers are tagged internally and mix into the real dashboard views — use Clear to remove them again.</p>
			</div>
			<div class="d-flex gap-2 flex-shrink-0">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="atk_generate_demo_data" />
					<?php wp_nonce_field( ATK_DEMO_NONCE_ACTION, 'atk_nonce' ); ?>
					<button type="submit" class="btn btn-primary">Generate Demo Data</button>
				</form>
				<?php if ( ! empty( $drivers ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="atk_clear_demo_data" />
						<?php wp_nonce_field( ATK_DEMO_NONCE_ACTION, 'atk_nonce' ); ?>
						<button type="submit" class="btn btn-light-danger">Clear Demo Data</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php if ( isset( $_GET['notice'] ) && 'demo_generated' === $_GET['notice'] ) : ?>
		<div class="alert alert-success d-flex align-items-center p-5 mb-6"><span>Demo data generated.</span></div>
	<?php elseif ( isset( $_GET['notice'] ) && 'demo_cleared' === $_GET['notice'] ) : ?>
		<div class="alert alert-success d-flex align-items-center p-5 mb-6"><span>Demo data cleared.</span></div>
	<?php endif; ?>

	<div class="card">
		<div class="card-body">
			<?php if ( empty( $drivers ) ) : ?>
				<p class="text-gray-600 mb-0">No demo data yet — click Generate Demo Data above.</p>
			<?php else : ?>
				<div class="table-responsive">
					<table class="table table-row-dashed align-middle mb-0">
						<thead>
							<tr class="text-gray-500 fs-7 text-uppercase">
								<th>Driver</th>
								<th>Mockup Tests Tried</th>
								<th>Tracks Enrolled</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $drivers as $driver ) :
								$reviews        = atk_attempt_reviews( $driver->ID );
								$mockup_tried   = count( array_filter( $mockup_tests, function ( $test, $key ) use ( $reviews ) {
									return ! empty( $reviews[ $key ] );
								}, ARRAY_FILTER_USE_BOTH ) );
								$enrolled_count = count( array_filter( array_keys( $course_types ), function ( $type_key ) use ( $driver ) {
									return atk_course_is_enrolled( $driver->ID, $type_key );
								} ) );
								?>
								<tr>
									<td>
										<div class="d-flex align-items-center gap-3">
											<?php echo atk_get_avatar_html( $driver->ID, 35 ); ?>
											<div>
												<div class="fw-bold text-gray-900"><?php echo esc_html( $driver->display_name ); ?> <span class="badge badge-light fs-8">Demo</span></div>
												<div class="text-gray-500 fs-8"><?php echo esc_html( $driver->user_email ); ?></div>
											</div>
										</div>
									</td>
									<td><?php echo esc_html( $mockup_tried ); ?>/<?php echo esc_html( count( $mockup_tests ) ); ?></td>
									<td><?php echo esc_html( $enrolled_count ); ?>/<?php echo esc_html( count( $course_types ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
