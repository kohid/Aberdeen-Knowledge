<?php
/**
 * Template Name: Study - Places of Interest
 */

defined( 'ABSPATH' ) || exit;

akn_shell( 'map', 'header' );
atk_render_poi_map();
akn_shell( 'map', 'footer' );
