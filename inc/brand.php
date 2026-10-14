<?php
/**
 * Brand colour: the one setting that recolours the whole UI.
 *
 * The colour is stored in the option akn_brand_color (default is the original
 * orange). inc/brand-engine.php turns it into a full accessible palette, which
 * is printed as CSS variables next to the stylesheet (inc/assets.php). The
 * stylesheet and the map scripts only ever read those variables, so nothing
 * else in the plugin carries a hard-coded brand hex.
 */

defined( 'ABSPATH' ) || exit;

require_once ATK_PLUGIN_DIR . '/inc/brand-engine.php';

const AKN_BRAND_OPTION        = 'akn_brand_color';
const AKN_LOAD_EVERYWHERE_OPTION = 'akn_load_everywhere';

/** The saved brand colour as #RRGGBB. */
function akn_brand_color() {
	return AKN_Brand::sanitize( get_option( AKN_BRAND_OPTION, AKN_Brand::DEFAULT_COLOR ) );
}

/** The CSS variables for the saved colour (light :root block and dark block). */
function akn_brand_inline_css() {
	return AKN_Brand::css( akn_brand_color() );
}

/** Whether the plugin's styles load on every front-end page (default) or only where a plugin page or shortcode needs them. */
function akn_load_everywhere() {
	return (bool) get_option( AKN_LOAD_EVERYWHERE_OPTION, 1 );
}

/* ---------------------------------------------------------------------- */
/* Settings screen: Settings > Aberdeen Knowledge                          */
/* ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_options_page( 'Aberdeen Knowledge', 'Aberdeen Knowledge', 'manage_options', 'aberdeen-knowledge', 'akn_render_settings_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'akn_appearance', AKN_BRAND_OPTION, array(
		'type'              => 'string',
		'default'           => AKN_Brand::DEFAULT_COLOR,
		'sanitize_callback' => 'akn_sanitize_brand_setting',
	) );
	register_setting( 'akn_appearance', AKN_LOAD_EVERYWHERE_OPTION, array(
		'type'              => 'integer',
		'default'           => 1,
		'sanitize_callback' => function ( $value ) {
			return empty( $value ) ? 0 : 1;
		},
	) );
} );

function akn_sanitize_brand_setting( $value ) {
	$clean = AKN_Brand::sanitize( $value );
	$given = is_string( $value ) ? strtoupper( ltrim( trim( $value ), '#' ) ) : '';
	if ( '' !== $given && AKN_Brand::sanitize( $value ) === AKN_Brand::DEFAULT_COLOR && AKN_Brand::DEFAULT_COLOR !== '#' . $given ) {
		add_settings_error( AKN_BRAND_OPTION, 'akn_brand_invalid', 'That is not a colour. Use a hex value such as #F69216. The default colour was kept.', 'error' );
	}
	return $clean;
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'settings_page_aberdeen-knowledge' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'wp-color-picker' );
	wp_register_style( 'akn-brand-admin', false, array(), ATK_VERSION );
	wp_enqueue_style( 'akn-brand-admin' );
	wp_add_inline_style( 'akn-brand-admin', akn_font_face_css() . '
		.akn-preview-host{border:1px solid #dcdcde;border-radius:6px;overflow:hidden;margin:0 0 16px}
		.akn-preview-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;max-width:900px}
		#akn-contrast-note{margin-top:6px}
	' );

	wp_enqueue_script( 'akn-brand', ATK_PLUGIN_URI . '/assets/js/akn-brand.js', array(), filemtime( ATK_PLUGIN_DIR . '/assets/js/akn-brand.js' ), true );
	wp_enqueue_script( 'akn-brand-admin', ATK_PLUGIN_URI . '/assets/js/akn-brand-admin.js', array( 'wp-color-picker', 'akn-brand' ), filemtime( ATK_PLUGIN_DIR . '/assets/js/akn-brand-admin.js' ), true );
	wp_localize_script( 'akn-brand-admin', 'AKN_BRAND_ADMIN', array(
		'defaultColor' => AKN_Brand::DEFAULT_COLOR,
		'cssUrls'      => array(
			ATK_PLUGIN_URI . '/assets/vendor/bootstrap/bootstrap.min.css?ver=5.3.3',
			ATK_PLUGIN_URI . '/assets/css/akn.css?ver=' . filemtime( ATK_PLUGIN_DIR . '/assets/css/akn.css' ),
		),
	) );
} );

function akn_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$color = akn_brand_color();
	?>
	<div class="wrap">
		<h1>Aberdeen Knowledge</h1>
		<p>Appearance settings for the study tools, tests, course pages, sign-in pages and Instructor Dashboard.</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'akn_appearance' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="akn_brand_color">Brand colour</label></th>
					<td>
						<input type="text" id="akn_brand_color" name="<?php echo esc_attr( AKN_BRAND_OPTION ); ?>" value="<?php echo esc_attr( $color ); ?>" data-default-color="<?php echo esc_attr( AKN_Brand::DEFAULT_COLOR ); ?>" />
						<p class="description">Buttons, links, active tabs, badges, progress bars and the map lines and markers all follow this colour.</p>
						<p id="akn-contrast-note" class="description" aria-live="polite"></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Where styles load</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( AKN_LOAD_EVERYWHERE_OPTION ); ?>" value="1" <?php checked( akn_load_everywhere() ); ?> />
							Load the plugin's styles on every page
						</label>
						<p class="description">Keep this on if you place the account menu or any plugin shortcode in an Elementor header or footer. Turn it off to load styles only on pages that use the plugin.</p>
					</td>
				</tr>
			</table>

			<h2>Preview</h2>
			<div class="akn-preview-grid">
				<div><div class="akn-preview-host" id="akn-preview-light" aria-label="Light mode preview"></div></div>
				<div><div class="akn-preview-host" id="akn-preview-dark" aria-label="Dark mode preview"></div></div>
			</div>

			<?php submit_button( 'Save colour' ); ?>
		</form>
	</div>
	<?php
}

/** @font-face for the preview, with absolute URLs (fonts declared inside a shadow root do not apply, so the admin page declares them). */
function akn_font_face_css() {
	$css = '';
	foreach ( array( 300, 400, 500, 600, 700 ) as $weight ) {
		$css .= '@font-face{font-family:Poppins;font-style:normal;font-weight:' . $weight . ';font-display:swap;src:url("' . esc_url( ATK_PLUGIN_URI . '/assets/vendor/fonts/poppins-latin-' . $weight . '-normal.woff2' ) . '") format("woff2")}';
	}
	return $css;
}
