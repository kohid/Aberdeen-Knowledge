<?php
/**
 * Full-document shell for the sign-in, sign-up, forgot- and reset-password
 * pages: a centred card on the page canvas, with the dark-mode switch in the
 * corner. The page output is already buffered (inc/templates.php), so a
 * redirect inside the form's own gates still works after wp_head() has run.
 */
defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( atk_body_classes( 'bg-body akn-auth' ) ); ?>>
<?php wp_body_open(); ?>
<div class="d-flex flex-column flex-root">
	<div class="position-fixed top-0 end-0 p-5"><?php atk_render_theme_mode_toggle(); ?></div>
	<div class="d-flex flex-column flex-column-fluid flex-lg-row-fluid align-items-center justify-content-center min-vh-100 p-8">
		<div class="d-flex flex-center flex-column flex-column-fluid">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="mb-8 text-decoration-none">
				<span class="fs-1 fw-bolder text-gray-900"><?php bloginfo( 'name' ); ?></span>
			</a>
			<div class="w-lg-500px bg-body rounded shadow-sm p-10 p-lg-15 mx-auto">
