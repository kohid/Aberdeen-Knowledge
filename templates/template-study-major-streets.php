<?php
/**
 * Template Name: Study - Major Streets
 */

defined( 'ABSPATH' ) || exit;

akn_shell( 'map', 'header' );
atk_render_arteries_map();
akn_shell( 'map', 'footer' );
