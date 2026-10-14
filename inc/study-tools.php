<?php
/**
 * Study Tool hub. Major Streets, Neighbourhoods, and Places of Interest are
 * built natively and link internally; the rest are shown as "Coming Soon"
 * previews — no external links out to the reference site anymore.
 */

defined( 'ABSPATH' ) || exit;

function atk_study_tools() {
	return array(
		array(
			'title'       => 'Major Streets',
			'description' => 'Study and practice recall of major streets in Aberdeen',
			'icon'        => 'map009',
			'stats'       => array( (string) atk_major_roads_count(), (string) ATK_ARTERIES_SESSION_SIZE, (string) ATK_ARTERIES_MINUTES ),
			'internal'    => 'major-streets',
		),
		array(
			'title'       => 'Neighbourhoods',
			'description' => 'Bitesized study for memorising streets - broken down by neighbourhood',
			'icon'        => 'gen001',
			'stats'       => array( (string) count( atk_neighbourhood_areas() ), (string) ATK_ARTERIES_SESSION_SIZE, (string) ATK_ARTERIES_MINUTES ),
			'internal'    => 'neighbourhoods',
		),
		array(
			'title'       => 'Places of Interest',
			'description' => "Exercise your memory and recall of Aberdeen's many places of interest",
			'icon'        => 'map008',
			'stats'       => array( (string) count( atk_poi_venues() ), (string) ATK_POI_SESSION_SIZE, (string) ATK_POI_MINUTES ),
			'internal'    => 'places-of-interest',
		),
		array(
			'title'       => 'Route Practice',
			'description' => 'Build your own route, beat the distance budget, solve the sequence',
			'icon'        => 'map003',
			'stats'       => array( '+1M', '–', '20' ),
		),
		array(
			'title'       => 'Quays to the City',
			'description' => 'Just a bit of fun, but useful for travel and tourism',
			'icon'        => 'map004',
			'stats'       => array( '20', '20', '2' ),
		),
		array(
			'title'       => 'POI Pindrop',
			'description' => '"Pin the tail on the donkey" - how close can you get?',
			'icon'        => 'map010',
			'stats'       => array( '250', '20', '20' ),
		),
		array(
			'title'       => 'Multiple Choice POI',
			'description' => 'Choose the correct POI highlighted on the map',
			'icon'        => 'gen008',
			'stats'       => array( '250', '20', '20' ),
		),
	);
}

/**
 * The Study Tool hub's card grid (Major Streets, Neighbourhoods, ...) —
 * pulled out of template-study-tool.php into its own function, and
 * registered as the [atk_study_tools] shortcode below, on the same
 * pattern as atk_render_user_menu(): one real function used by both the
 * theme's own template and any page-builder page that shortcodes it in,
 * so there's only ever one copy of this markup to keep correct.
 */
function atk_render_study_tools_grid() {
	ob_start();
	?>
	<div class="row g-6">
		<?php foreach ( atk_study_tools() as $tool ) :
			$is_internal  = ! empty( $tool['internal'] );
			$card_class   = 'atk-study-card card h-100';
			$symbol_bg    = $is_internal ? 'bg-light-primary' : 'bg-light';
			$icon_color   = $is_internal ? 'svg-icon-primary' : 'svg-icon-gray-500';
			$status_badge = $is_internal
				? '<span class="badge badge-light-primary position-absolute top-0 end-0 m-4">Live</span>'
				: '<span class="badge badge-light position-absolute top-0 end-0 m-4">Coming Soon</span>';
			$card_body   = '
					<div class="card-body d-flex flex-column position-relative">
						' . $status_badge . '

						<div class="symbol symbol-60px mx-auto mb-5">
							<div class="symbol-label ' . $symbol_bg . '">
								' . atk_render_duotone_icon( $tool['icon'], 'svg-icon svg-icon-2tx ' . $icon_color ) . '
							</div>
						</div>

						<h2 class="fs-4 fw-bolder text-gray-900 text-center mb-3">' . esc_html( $tool['title'] ) . '</h2>
						<p class="fs-7 text-gray-600 text-center mb-6 flex-grow-1">' . esc_html( $tool['description'] ) . '</p>

						<div class="separator separator-dashed mb-4"></div>
						<div class="d-flex align-items-center justify-content-center gap-5 fs-7 text-gray-500">
							<span><i class="bi bi-collection me-1"></i>' . esc_html( $tool['stats'][0] ) . '</span>
							<span><i class="bi bi-card-text me-1"></i>' . esc_html( $tool['stats'][1] ) . '</span>
							<span><i class="bi bi-clock me-1"></i>' . esc_html( $tool['stats'][2] ) . '</span>
						</div>
					</div>';
			?>
			<div class="col-sm-6 col-lg-4 col-xl-3">
				<?php if ( $is_internal ) : ?>
					<a href="<?php echo esc_url( atk_get_page_url( $tool['internal'] ) ); ?>" class="<?php echo esc_attr( $card_class ); ?> text-decoration-none">
						<?php echo $card_body; // phpcs:ignore -- built from escaped pieces above ?>
					</a>
				<?php else : ?>
					<div class="<?php echo esc_attr( $card_class ); ?>">
						<?php echo $card_body; // phpcs:ignore -- built from escaped pieces above ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'atk_study_tools', 'atk_render_study_tools_grid' );

/**
 * The three mockup Knowledge Test cards (Street/POI/Route) — same
 * extraction pattern as atk_render_study_tools_grid(), registered as the
 * [atk_mockup_tests] shortcode. Includes its own script enqueue and the
 * "Previous Attempts" review modals, since both are tied specifically to
 * these cards, not to the page around them.
 */
function atk_render_mockup_tests_grid() {
	wp_enqueue_script( 'atk-quiz-active-notice', ATK_PLUGIN_URI . '/assets/js/quiz-active-notice.js', array(), filemtime( ATK_PLUGIN_DIR . '/assets/js/quiz-active-notice.js' ), true );

	$knowledge_test_cards = array(
		'street' => array( 'icon' => 'map001' ),
		'poi'    => array( 'icon' => 'map008' ),
		'route'  => array( 'icon' => 'map006' ),
	);
	$review_modals = '';

	ob_start();
	?>
	<div class="row g-6">
		<?php foreach ( atk_quiz_tests() as $key => $test ) :
			$previous_trigger = atk_render_previous_attempts_trigger( $key );
			$review_modals   .= atk_render_previous_attempts_modal( $key, $test['title'] );
			?>
			<div class="col-md-4">
				<div class="atk-study-card card h-100 text-center border-top border-4 border-warning">
					<div class="card-body d-flex flex-column">
						<div class="symbol symbol-60px mx-auto mb-5">
							<div class="symbol-label bg-light-warning">
								<?php echo atk_render_duotone_icon( $knowledge_test_cards[ $key ]['icon'], 'svg-icon svg-icon-2tx svg-icon-warning' ); ?>
							</div>
						</div>
						<h2 class="fs-3 fw-bolder text-gray-900 mb-3"><?php echo esc_html( $test['title'] ); ?></h2>
						<p class="fs-6 text-gray-600 mb-6 flex-grow-1"><?php echo esc_html( $test['instruction'] ); ?></p>
						<div class="fs-7 text-gray-500 mb-6"><i class="bi bi-card-text me-1"></i>20 questions &middot; <i class="bi bi-clock ms-1 me-1"></i>20 minutes</div>
						<div class="d-flex flex-column gap-2 atk-test-tile" data-test-key="<?php echo esc_attr( $key ); ?>">
							<a href="<?php echo esc_url( atk_get_page_url( $test['page_key'] ) ); ?>" class="btn btn-primary atk-start-test-btn">Start Test</a>
							<?php if ( $previous_trigger ) : ?>
								<?php echo $previous_trigger; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php echo $review_modals; // phpcs:ignore -- built from atk_render_previous_attempts_modal(), already escaped internally ?>
	<?php
	return ob_get_clean();
}
add_shortcode( 'atk_mockup_tests', 'atk_render_mockup_tests_grid' );
