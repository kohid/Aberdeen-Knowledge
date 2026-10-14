<?php
/**
 * Template Name: Study - Neighbourhoods
 */

defined( 'ABSPATH' ) || exit;

akn_shell( 'map', 'header' );
atk_render_neighbourhoods_map();
akn_shell( 'map', 'footer' );
