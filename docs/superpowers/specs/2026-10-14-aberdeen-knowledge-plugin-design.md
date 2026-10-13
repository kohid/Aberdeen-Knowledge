# Aberdeen Knowledge plugin: design

Date: 2026-10-14. Status: approved in conversation by Jay; this file is the written record.

## Goal

Turn the Aberdeen Taxi Knowledge theme (`kohid/Aberdeen-Knowledge-Theme`, v1.2.0) into a plugin called
**Aberdeen Knowledge** that carries every feature of the theme and works on top of any base theme.
The site stops depending on the theme folder and on the compiled Metronic8 folder in `wp-content`.
Metronic8 demo2 stays only as a visual reference. The plugin gets a brand colour setting so anyone who
installs it can recolour the whole UI.

## What was decided

1. Everything lives in the plugin. Jay deactivates the old theme himself. The base theme becomes the
   Elementor starter theme already installed on the site, with Elementor Pro Theme Builder supplying
   the header and footer for ordinary pages.
2. No Metronic file is shipped. Metronic is a paid product and its license generally does not allow
   passing its files on to other people, so the plugin contains none of its CSS, JS, fonts or icon
   paths. The look is re-created from the demo2 reference (https://github.com/kohid/Metronic8/tree/main/html/theme/demo2/).
3. The plugin is self-contained. Open source libraries are bundled locally, with no CDN calls:
   Bootstrap 5.3 (MIT), Select2 4.0 with its Bootstrap 5 theme (MIT), Leaflet 1.9.4 (BSD-2),
   Bootstrap Icons (MIT), SheetJS 0.18.5 for xlsx import (Apache-2.0), Poppins (OFL).
4. Maps stay Leaflet with OpenStreetMap tiles. Real OpenStreetMap data only, never invented geometry.
5. Brand colour is a setting (Settings, Aberdeen Knowledge). Default is the current orange `#F69216`.
6. All internal names stay as they are (`atk_` functions, `atk_` tables, `atk_` options, `driver` and
   `instructor` roles, the 13 shortcodes, `atk/v1` REST namespace, `atk_nonce`). Existing data, pages and
   shortcode content keep working with no migration.
7. Releases come from this repo's GitHub releases, installed through the normal Plugins screen.

## Architecture

```
aberdeen-knowledge.php        plugin header, constants, dormant guard, module loader, activation
inc/                          the theme's modules, ported (course, quiz, auth, instructor, maps, ...)
inc/assets.php                registers/enqueues the bundled libraries and akn.css, only where needed
inc/brand.php                 brand colour option, derived palette, CSS variable output, admin screen
inc/templates.php             page templates for managed pages, shells, output buffering
inc/updater.php               plugin updater from GitHub releases
templates/                    full-document shells: auth, map
assets/css/akn.css            our own stylesheet (the Metronic-style helpers, written from scratch)
assets/vendor/                bundled open source libraries
assets/js, assets/data        ported scripts and the real OSM road data
tests/                        plain-PHP tests (no WordPress needed)
```

### Constants

`ATK_PLUGIN_FILE`, `ATK_PLUGIN_DIR`, `ATK_PLUGIN_URI`, `ATK_VERSION`. They replace `ATK_THEME_DIR` and
`ATK_THEME_URI`. `ATK_METRONIC_URI` is removed.

### Templates and shells

The theme's managed pages already store a template file name in `_wp_page_template`
(`template-signin.php`, `template-study-major-streets.php`, ...). The plugin keeps those exact values and
serves them from `template_include`, so existing pages need no change. The names are also added through
`theme_page_templates` so the page editor keeps showing them instead of resetting them.

- Auth pages (sign in, sign up, forgot, reset) use the auth shell: a complete HTML document with the
  centred card and the dark-mode switch.
- Map pages (Major Streets, Neighbourhoods, Places of Interest) use the map shell: a complete HTML
  document, full screen.
- Every other managed page (profile, course, instructor dashboard, settings, study tools, tests) uses
  the base theme's normal page template with the shortcode in its content. The plugin only adds assets.

The theme buffers a whole page so a shortcode can redirect after output has started (login, enrollment
and valid-week gates). The plugin starts an output buffer on `template_redirect` for front-end pages and
lets WordPress flush it at shutdown. `atk_is_elementor_editing()` is kept for the same gates.

### Assets

Assets load only on pages that need them: managed pages, and any page where a plugin shortcode runs
(each render function calls the enqueue helper, so Elementor-built pages that hold a shortcode work too).
Every handle is versioned with `filemtime()`, because a CDN in front of the site caches static files for
a week.

### Metronic runtime pieces that have to be replaced

- The account dropdown used Metronic's own menu script. It becomes a Bootstrap dropdown.
- Icons: the nine duotone SVGs copied from Metronic's icon set are replaced by Bootstrap Icons, wrapped in
  one `atk_render_icon()` helper. The existing `atk_render_duotone_icon()` name stays as a thin wrapper so
  callers do not change.
- Dark mode: Bootstrap 5.3 colour modes (`data-bs-theme`). The separate dark stylesheets and the swap
  script go away. The logged-out rule stays: the server always renders light, and the browser applies the
  stored choice before first paint, because logged-out HTML is cached by URL.

### The stylesheet

About 100 class names used by the ported markup are Metronic-style helpers that Bootstrap does not have
(`badge-light-primary`, `text-gray-600`, `btn-light-primary`, `fs-2x`, `w-100px`, `symbol`, `stepper`,
`timeline-*`, `separator-dashed`, `table-row-dashed`, `nav-line-tabs`, `pulse`, ...). `akn.css` defines
them from scratch on top of CSS variables. The markup keeps its class names so PHP and JS stay untouched.
A rename to `akn-` names can follow later, a few at a time.

Visual direction (frontend design plan): soft grey canvas, white cards with a thin border, dashed row
rules in tables, soft tinted badges, rounded corners of 0.475rem, Poppins. One memorable thing: the brand
colour runs through everything, including the map lines and markers. Everything else stays quiet.

| Token | Light | Dark |
|---|---|---|
| canvas | `#F5F8FA` | `#151521` |
| surface | `#FFFFFF` | `#1E1E2D` |
| border | `#E4E6EF` | `#323248` |
| ink | `#263238` | `#E4E6EF` |
| muted | `#7E8299` | `#9899AC` |
| success / info / warning / danger | `#50CD89` / `#7239EA` / `#FFC700` / `#F1416C` | same, softer tints |

## Brand colour

- Option `akn_brand_color`, default `#F69216`, validated as `#RRGGBB`.
- Derived in PHP (`AKN_Brand`, pure functions, unit tested): hover (lightness -8), pressed (-13), soft tint
  (brand at 12% light, 18% dark), focus ring (35%), text colour on brand (white or near black, whichever
  passes 4.5:1), and a link/text variant adjusted until it passes 4.5:1 on the surface, so a light colour
  such as yellow still gives readable links.
- Printed as CSS variables on `:root` and `[data-bs-theme=dark]` in an inline style. `akn.css` maps
  Bootstrap's primary variables, `.btn-primary`, links, active tabs, badges, focus rings and the dashed
  accents to them. Map scripts read the variable instead of a hard-coded hex.
- Admin screen: colour picker, live preview (button, badge, link, tab, in light and dark), contrast
  warning, reset to default. The JS preview mirrors the PHP derivation; a test compares the two.

## Lifecycle and cutover

- The plugin and the old theme define the same functions, so they cannot both run. If the old theme is
  active, the plugin stays dormant and shows an admin notice instead of a fatal error.
- Activation creates the `driver` and `instructor` roles and any missing managed pages, as
  `after_switch_theme` did. Nothing is deleted on deactivation.
- Cutover order on a live site: install and activate the plugin (dormant notice appears), switch the base
  theme to the Elementor starter theme, purge the page cache. The tables are created on first load.
- The old theme repo stays untouched until the plugin runs on live.
- The one-time Elementor Kit colour seed is not ported (it already ran, and a plugin that others install
  must not rewrite their Elementor settings).

## Updater

GitHub releases of `kohid/Aberdeen-Knowledge`. A tag `vX.Y.Z` must equal the plugin header `Version`; the
workflow checks that and creates the release. The updater adds the update to the plugin update list,
provides the "View details" box, downloads the zipball (with a token if the repo is private) and renames
the extracted folder to `aberdeen-knowledge`. Token: `AKN_GITHUB_TOKEN`, falling back to `ATK_GITHUB_TOKEN`
if defined. A fine-grained token is limited to one repository, so the token used for the theme repo will not
read this one. A public repo needs no token. `.gitattributes` keeps docs, tests and CI files out of the zip.

## Testing

- `php -l` on every PHP file, `node --check` on every script.
- The existing plain-PHP tests for the Neighbourhoods engine, plus new ones for `AKN_Brand`.
- A browser run against a throwaway WordPress with the plugin active under a plain base theme: sign in,
  dashboard tabs, Neighbourhoods import, Course Builder, a map page, dark mode, and a change of brand colour
  checked on buttons, badges and the map.
- Not testable here: the real site, the Elementor Theme Builder header and footer, the live CDN and cache
  layers. Jay checks those.

## Out of scope for 1.0.0

Renaming classes to `akn-`, a secondary brand colour, translations, a new design for any page.
