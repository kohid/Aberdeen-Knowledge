<?php
/**
 * Full-document shell for the full-screen map tools (Major Streets,
 * Neighbourhoods, Places of Interest). The map fills the viewport; the
 * page itself has no header or footer.
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
<body <?php body_class( atk_body_classes( 'atk-map-body' ) ); ?>>
<?php wp_body_open(); ?>
