<?php
/**
 * Shared config + rendering for the mockup knowledge tests and study tools.
 */

defined( 'ABSPATH' ) || exit;

function atk_quiz_tests() {
	return array(
		'street' => array(
			'title'       => 'Street Test',
			'instruction' => 'Name a connecting road between two roads.',
			'mode'        => 'text',
			'page_key'    => 'street-test',
		),
		'poi'    => array(
			'title'       => 'POI Test',
			'instruction' => "Name the street where you'll find these places of interest.",
			'mode'        => 'text',
			'page_key'    => 'poi-test',
		),
		'route'  => array(
			'title'       => 'Route Test',
			'instruction' => 'Pick the road sequence that forms the correct route.',
			'mode'        => 'choice',
			'page_key'    => 'route-test',
		),
	);
}

/**
 * Study tools: lower-stakes practice modules, separate from the three
 * timed knowledge tests above (kept out of atk_quiz_tests() so they don't
 * show up on the Knowledge Tests hub). Major Streets isn't listed here —
 * it's a bespoke full-screen map experience, see inc/arteries-map.php.
 */
function atk_study_quizzes() {
	return array();
}

/**
 * Look up a quiz's config by key across both registries.
 */
function atk_quiz_config( $key ) {
	$all = atk_quiz_tests() + atk_study_quizzes();
	return isset( $all[ $key ] ) ? $all[ $key ] : null;
}

const ATK_QUIZ_MINUTES        = 20;
const ATK_QUIZ_QUESTION_COUNT = 20;
const ATK_QUIZ_PASS_THRESHOLD = 0.75;

/**
 * Build the JS-ready question payload for a given test key.
 */
function atk_quiz_payload( $key ) {
	switch ( $key ) {
		case 'street':
			return array_map( function ( $q ) {
				return array(
					'prompt'    => sprintf( 'Name a road that connects <strong>%s</strong> and <strong>%s</strong>.', esc_html( $q['a'] ), esc_html( $q['b'] ) ),
					'accepted'  => $q['accepted'],
					'locations' => array(
						array( 'label' => 'A', 'query' => $q['a'] ),
						array( 'label' => 'B', 'query' => $q['b'] ),
					),
				);
			}, atk_street_test_questions() );

		case 'poi':
			return array_map( function ( $q ) {
				return array(
					'prompt'    => sprintf( 'Which street would you find <strong>%s</strong> on?', esc_html( $q['poi'] ) ),
					'accepted'  => $q['accepted'],
					'locations' => array(
						array( 'label' => '', 'query' => $q['poi'] ),
					),
				);
			}, atk_poi_test_questions() );

		case 'route':
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
			}, atk_route_test_questions() );
	}

	return array();
}

/**
 * Render the shared intro / question / results markup for one test,
 * and enqueue the quiz engine with its data.
 *
 * $test/$questions can be passed directly (e.g. the Course, which has too
 * many dynamic week/test combinations to fit the atk_quiz_tests() registry)
 * instead of being looked up by $key. $extra merges extra fields into the
 * JS config — the Course uses this for its completion-tracking nonce.
 */
function atk_render_quiz( $key, $test = null, $questions = null, $extra = array() ) {
	if ( null === $test ) {
		$test = atk_quiz_config( $key );
	}
	if ( ! $test ) {
		return;
	}
	if ( null === $questions ) {
		$questions = atk_quiz_payload( $key );
	}

	atk_enqueue_leaflet();

	wp_enqueue_script( 'atk-quiz', ATK_PLUGIN_URI . '/assets/js/quiz.js', array( 'atk-scripts-bundle', 'leaflet' ), filemtime( ATK_PLUGIN_DIR . '/assets/js/quiz.js' ), true );
	wp_add_inline_script( 'atk-quiz', 'var ATK_QUIZ = ' . wp_json_encode( array_merge( array(
		'mode'            => $test['mode'],
		'minutes'         => ATK_QUIZ_MINUTES,
		'questions'       => $questions,
		'passThreshold'   => ATK_QUIZ_PASS_THRESHOLD,
		'testKey'         => $key,
		'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
		'attemptNonce'    => wp_create_nonce( ATK_ATTEMPT_NONCE_ACTION ),
		'isLoggedIn'      => is_user_logged_in(),
		'reviewNonce'     => wp_create_nonce( ATK_REVIEW_NONCE_ACTION ),
		'instantFeedback' => ! empty( $test['instantFeedback'] ),
		'sessionSize'     => isset( $test['sessionSize'] ) ? (int) $test['sessionSize'] : null,
		// Real Aberdeen street names (inc/road-names-data.php), offered as
		// suggestions while typing a text-mode answer so a driver who knows
		// the right street isn't marked wrong for a stray typo — only sent
		// for text-mode tests (Street/POI), never for Route's multiple choice.
		'roadNames'       => 'text' === $test['mode'] ? atk_master_road_names() : array(),
	), $extra ) ) . ';', 'before' );

	$is_study        = ! empty( $test['instantFeedback'] );
	$question_count  = isset( $test['sessionSize'] ) ? (int) $test['sessionSize'] : ATK_QUIZ_QUESTION_COUNT;
	$hub_key         = isset( $test['hub_key'] ) ? $test['hub_key'] : 'study-tools';
	$hub_label       = isset( $test['hub_label'] ) ? $test['hub_label'] : 'Back to Study Tools';
	?>
	<div class="container py-10 py-lg-15" style="max-width: 800px;">

		<?php if ( empty( $test['real_data'] ) ) : ?>
			<div class="alert alert-warning d-flex align-items-center p-5 mb-8">
				<span>These are sample questions for demonstration purposes, modelled on real Aberdeen streets &mdash; not the verified official Aberdeen Street Knowledge Test.</span>
			</div>
		<?php endif; ?>

		<div id="atk-quiz-root">
			<div id="atk-quiz-intro" class="card">
				<div class="card-body text-center py-15">
					<h1 class="fs-2x fw-bolder text-gray-900 mb-3"><?php echo esc_html( $test['title'] ); ?></h1>
					<p class="fs-5 text-gray-600 mb-2"><?php echo esc_html( $test['instruction'] ); ?></p>
					<p class="fs-6 text-gray-500 mb-8"><?php echo esc_html( $question_count ); ?> questions &middot; <?php echo esc_html( ATK_QUIZ_MINUTES ); ?> minutes</p>
					<button type="button" class="btn btn-lg btn-primary" id="atk-quiz-start"><?php echo $is_study ? 'Start Studying' : 'Start Test'; ?></button>
				</div>
			</div>

			<div id="atk-quiz-question" class="card d-none">
				<?php if ( isset( $test['heading'] ) ) : ?>
					<div class="card-header d-flex align-items-center justify-content-between">
						<h2 class="card-title fs-2x fw-bolder text-gray-900 mb-0"><?php echo esc_html( $test['heading'] ); ?></h2>
						<?php if ( isset( $test['week'], $test['test'] ) ) : ?>
							<div class="fs-6 fw-bold text-gray-500">Week <?php echo esc_html( $test['week'] ); ?> | Test <?php echo esc_html( $test['test'] ); ?></div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="card-header d-flex align-items-center justify-content-between">
					<div class="fw-bolder text-gray-700" id="atk-quiz-progress"></div>
					<div class="badge badge-light-danger fs-6" id="atk-quiz-timer"></div>
				</div>
				<div class="card-body">
					<div class="progress mb-8" style="height: 6px;">
						<div class="progress-bar bg-primary" id="atk-quiz-progress-bar" role="progressbar" style="width: 0%"></div>
					</div>
					<h2 class="fs-3 fw-bold text-gray-900 mb-6" id="atk-quiz-prompt"></h2>
					<div class="position-relative mb-8">
						<div id="atk-quiz-map" class="rounded d-none" style="height: 280px;"></div>
						<div id="atk-quiz-map-loading" class="position-absolute top-50 start-50 translate-middle d-none">
							<span class="spinner-border spinner-border-sm text-primary"></span>
						</div>
					</div>
					<div id="atk-quiz-answer-area" class="mb-8"></div>
					<div id="atk-quiz-feedback" class="alert d-none mb-8"></div>
					<div class="d-flex justify-content-between">
						<button type="button" class="btn btn-light d-none" id="atk-quiz-back">Back</button>
						<button type="button" class="btn btn-primary ms-auto" id="atk-quiz-next">Next</button>
					</div>
				</div>
			</div>

			<div id="atk-quiz-results" class="d-none">
				<div class="card mb-6">
					<div class="card-body text-center py-15">
						<h1 class="fs-2x fw-bolder text-gray-900 mb-3"><?php echo $is_study ? 'Study Session Complete' : 'Test Complete'; ?></h1>
						<div class="fs-3x fw-bolder text-primary mb-2" id="atk-quiz-score"></div>
						<p class="fs-5 text-gray-600 mb-3">out of <?php echo esc_html( $question_count ); ?> correct</p>
						<div class="mb-8" id="atk-quiz-pass-fail-wrap">
							<span class="badge fs-5 px-4 py-2" id="atk-quiz-pass-fail"></span>
						</div>
						<button type="button" class="btn btn-light-primary me-3" id="atk-quiz-retry"><?php echo $is_study ? 'Study Again' : 'Retry Test'; ?></button>
						<a href="<?php echo esc_url( atk_get_page_url( $hub_key ) ); ?>" class="btn btn-light"><?php echo esc_html( $hub_label ); ?></a>
						<p class="fs-7 text-gray-500 mt-6 mb-0" id="atk-quiz-attempt-count"></p>
					</div>
				</div>
				<div id="atk-quiz-review"></div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Lets the Street/POI/Route Test pages be built in Elementor: drop this
 * shortcode where the quiz should appear, e.g. [atk_quiz type="street"].
 */
add_shortcode( 'atk_quiz', function ( $atts ) {
	$atts = shortcode_atts( array( 'type' => 'street' ), $atts );
	ob_start();
	atk_render_quiz( sanitize_key( $atts['type'] ) );
	return ob_get_clean();
} );
