# Aberdeen Knowledge

A WordPress plugin for taxi knowledge test preparation: Major Streets, Neighbourhoods and Places of Interest map
tools, mockup tests, a paid Official Test course, driver and instructor accounts, and a front-end Instructor
Dashboard. Maps use Leaflet with OpenStreetMap. The look follows the Metronic8 demo2 reference, re-created in the
plugin's own stylesheet, and the brand colour is a setting.

## Install

Upload the release zip under Plugins, Add New, Upload Plugin, then activate. Use any base theme; the Elementor
starter theme with Elementor Pro's Theme Builder is what the original site runs.

## Moving a site off the old theme

1. Install and activate this plugin. While the Aberdeen Taxi Knowledge theme is still active, the plugin stays
   dormant and shows a notice.
2. Switch the base theme (Appearance, Themes). The plugin takes over. Existing pages, drivers, courses and
   neighbourhoods carry over unchanged.
3. Purge the page cache.

## Brand colour

Settings, Aberdeen Knowledge. Pick a colour, check the preview in light and dark, save. Everything recolours,
including the map lines and markers.

## Updates

Updates come from this repository's releases. A private repository needs `AKN_GITHUB_TOKEN` in `wp-config.php`.

See `CLAUDE.md` for development rules and `docs/superpowers/specs/` for the design.
