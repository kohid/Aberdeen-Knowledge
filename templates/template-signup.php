<?php
/**
 * Template Name: Sign Up
 */

defined( 'ABSPATH' ) || exit;

atk_redirect_if_logged_in();

akn_shell( 'auth', 'header' );
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;
akn_shell( 'auth', 'footer' );
