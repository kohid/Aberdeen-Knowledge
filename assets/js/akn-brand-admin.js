/**
 * Settings > Aberdeen Knowledge: colour picker with a live preview.
 *
 * The preview lives in two shadow roots (light and dark) that load the
 * plugin's own stylesheets, so the real components are shown without the
 * stylesheet touching the rest of wp-admin. Variables are set on the
 * wrapper element from AKNBrand.palette(), the same maths as the PHP side.
 */
( function ( $ ) {
	"use strict";

	var Brand = window.AKNBrand;
	var cfg = window.AKN_BRAND_ADMIN;
	if ( ! $ || ! Brand || ! cfg ) {
		return;
	}

	var previewHtml =
		'<div class="akn-surface akn-preview" style="padding:1.25rem">' +
			'<div class="d-flex align-items-center gap-3 mb-4">' +
				'<span class="fw-bolder fs-4 text-gray-900">Study tools</span>' +
				'<span class="badge badge-light-primary">Live</span>' +
			'</div>' +
			'<div class="d-flex gap-2 flex-wrap mb-4">' +
				'<button type="button" class="btn btn-primary btn-sm">Start test</button>' +
				'<button type="button" class="btn btn-light-primary btn-sm">Review</button>' +
				'<button type="button" class="btn btn-outline-primary btn-sm">Outline</button>' +
			'</div>' +
			'<ul class="nav nav-line-tabs mb-4">' +
				'<li class="nav-item"><a class="nav-link active" href="#">Streets</a></li>' +
				'<li class="nav-item"><a class="nav-link" href="#">Routes</a></li>' +
			'</ul>' +
			'<p class="text-gray-700 mb-3">Body text with a <a href="#">link in the brand colour</a>.</p>' +
			'<div class="progress" style="height:8px"><div class="progress-bar" style="width:62%"></div></div>' +
		'</div>';

	function buildPreview( hostId, mode ) {
		var host = document.getElementById( hostId );
		if ( ! host || ! host.attachShadow ) {
			return null;
		}
		var root = host.attachShadow( { mode: "open" } );
		cfg.cssUrls.forEach( function ( url ) {
			var link = document.createElement( "link" );
			link.rel = "stylesheet";
			link.href = url;
			root.appendChild( link );
		} );
		var wrap = document.createElement( "div" );
		wrap.setAttribute( "data-bs-theme", mode );
		wrap.innerHTML = previewHtml;
		wrap.addEventListener( "click", function ( e ) {
			e.preventDefault();
		} );
		if ( "dark" === mode ) {
			// Bootstrap declares its base variables (borders, radii) on :root and
			// [data-bs-theme=light] only, and :root does not exist inside a shadow
			// root, so the dark preview sits inside a light element that carries them.
			var outer = document.createElement( "div" );
			outer.setAttribute( "data-bs-theme", "light" );
			outer.appendChild( wrap );
			root.appendChild( outer );
		} else {
			root.appendChild( wrap );
		}
		return wrap;
	}

	var wraps = { light: buildPreview( "akn-preview-light", "light" ), dark: buildPreview( "akn-preview-dark", "dark" ) };

	function setVars( el, vars ) {
		Object.keys( vars ).forEach( function ( name ) {
			el.style.setProperty( name, vars[ name ] );
		} );
	}

	function ratio( n ) {
		return ( Math.round( n * 10 ) / 10 ).toFixed( 1 ) + ":1";
	}

	function apply( color ) {
		var hex = Brand.sanitize( color );
		var p = Brand.palette( hex );
		if ( wraps.light ) {
			setVars( wraps.light, p.light );
		}
		if ( wraps.dark ) {
			setVars( wraps.dark, p.light );
			setVars( wraps.dark, p.dark );
		}

		var on = p.light[ "--akn-brand-on" ];
		var onRatio = Brand.contrast( hex, on );
		var ink = p.light[ "--akn-brand-ink" ];
		var note = "Text on this colour is " + ( on === "#FFFFFF" ? "white" : "dark" ) + " (" + ratio( onRatio ) + ").";
		if ( ink !== hex ) {
			note += " Links and outline buttons use a slightly darker shade, " + ink + ", so they stay readable on white (" + ratio( Brand.contrast( ink, Brand.SURFACE_LIGHT ) ) + ").";
		}
		if ( onRatio < 3 ) {
			note += " Warning: this colour is hard to put text on. Pick a darker or lighter colour.";
		}
		$( "#akn-contrast-note" ).text( note );
	}

	var $field = $( "#akn_brand_color" );
	$field.wpColorPicker( {
		defaultColor: cfg.defaultColor,
		change: function ( event, ui ) {
			apply( ui.color.toString() );
		},
		clear: function () {
			apply( cfg.defaultColor );
		}
	} );
	apply( $field.val() );
} )( window.jQuery );
