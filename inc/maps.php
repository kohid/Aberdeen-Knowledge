<?php
/**
 * Map integration for the knowledge test questions: Leaflet.js + OpenStreetMap
 * tiles, geocoded via OSM's Nominatim service. Free, no API key required.
 *
 * Note for production: the public tile.openstreetmap.org tile server and the
 * public Nominatim geocoder are both meant for light/evaluation use. Before
 * this sees real traffic, switch to a dedicated tile provider (e.g. MapTiler,
 * Stadia Maps) and/or a paid or self-hosted Nominatim instance.
 */

defined( 'ABSPATH' ) || exit;

const ATK_LEAFLET_VERSION = '1.9.4';

/**
 * Enqueue Leaflet. It ships inside the plugin (assets/vendor/leaflet, registered
 * in inc/assets.php), so maps need no CDN. Tiles still come from OpenStreetMap.
 */
function atk_enqueue_leaflet() {
	wp_enqueue_style( 'leaflet' );
	wp_enqueue_script( 'leaflet' );
}
