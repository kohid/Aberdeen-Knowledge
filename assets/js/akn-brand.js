/**
 * Brand colour maths in JavaScript: a line-for-line port of inc/brand-engine.php,
 * used for the live preview on the Settings screen. tests/brand/parity.mjs checks
 * that both produce the same palette. If you change one, change the other.
 */
( function ( root ) {
	"use strict";

	var DEFAULT_COLOR = "#F69216";
	var SURFACE_LIGHT = "#FFFFFF";
	var SURFACE_DARK = "#1E1E2D";
	var INK_DARK = "#1B1B29";

	function sanitize( value ) {
		value = typeof value === "string" ? value.trim() : "";
		var m = value.match( /^#?([0-9a-fA-F]{3})$/ );
		if ( m ) {
			var h = m[ 1 ];
			return ( "#" + h[ 0 ] + h[ 0 ] + h[ 1 ] + h[ 1 ] + h[ 2 ] + h[ 2 ] ).toUpperCase();
		}
		m = value.match( /^#?([0-9a-fA-F]{6})$/ );
		if ( m ) {
			return ( "#" + m[ 1 ] ).toUpperCase();
		}
		return DEFAULT_COLOR;
	}

	function hexToRgb( hex ) {
		hex = sanitize( hex ).replace( "#", "" );
		return [ parseInt( hex.substr( 0, 2 ), 16 ), parseInt( hex.substr( 2, 2 ), 16 ), parseInt( hex.substr( 4, 2 ), 16 ) ];
	}

	function pad( n ) {
		var s = n.toString( 16 ).toUpperCase();
		return s.length < 2 ? "0" + s : s;
	}

	function rgbToHex( rgb ) {
		return "#" + pad( rgb[ 0 ] ) + pad( rgb[ 1 ] ) + pad( rgb[ 2 ] );
	}

	function rgbToHsl( rgb ) {
		var r = rgb[ 0 ] / 255, g = rgb[ 1 ] / 255, b = rgb[ 2 ] / 255;
		var max = Math.max( r, g, b ), min = Math.min( r, g, b );
		var l = ( max + min ) / 2, h = 0, s = 0;
		if ( max !== min ) {
			var d = max - min;
			s = l > 0.5 ? d / ( 2 - max - min ) : d / ( max + min );
			if ( max === r ) {
				h = ( g - b ) / d + ( g < b ? 6 : 0 );
			} else if ( max === g ) {
				h = ( b - r ) / d + 2;
			} else {
				h = ( r - g ) / d + 4;
			}
			h *= 60;
		}
		return [ h, s, l ];
	}

	function hueToChannel( p, q, t ) {
		if ( t < 0 ) { t += 1; }
		if ( t > 1 ) { t -= 1; }
		if ( t < 1 / 6 ) { return p + ( q - p ) * 6 * t; }
		if ( t < 1 / 2 ) { return q; }
		if ( t < 2 / 3 ) { return p + ( q - p ) * ( 2 / 3 - t ) * 6; }
		return p;
	}

	function hslToRgb( hsl ) {
		var h = hsl[ 0 ] / 360, s = hsl[ 1 ], l = hsl[ 2 ];
		if ( s === 0 ) {
			var v = Math.round( l * 255 );
			return [ v, v, v ];
		}
		var q = l < 0.5 ? l * ( 1 + s ) : l + s - l * s;
		var p = 2 * l - q;
		return [
			Math.round( hueToChannel( p, q, h + 1 / 3 ) * 255 ),
			Math.round( hueToChannel( p, q, h ) * 255 ),
			Math.round( hueToChannel( p, q, h - 1 / 3 ) * 255 )
		];
	}

	function luminance( rgb ) {
		var out = rgb.map( function ( c ) {
			c = c / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * out[ 0 ] + 0.7152 * out[ 1 ] + 0.0722 * out[ 2 ];
	}

	function contrast( a, b ) {
		var la = luminance( hexToRgb( a ) ), lb = luminance( hexToRgb( b ) );
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function shiftLightness( hex, delta ) {
		var hsl = rgbToHsl( hexToRgb( hex ) );
		hsl[ 2 ] = Math.min( 1, Math.max( 0, hsl[ 2 ] + delta ) );
		return rgbToHex( hslToRgb( hsl ) );
	}

	function inkFor( hex, surface, min ) {
		min = min || 4.5;
		hex = sanitize( hex );
		if ( contrast( hex, surface ) >= min ) {
			return hex;
		}
		var onLight = luminance( hexToRgb( surface ) ) > 0.5;
		var hsl = rgbToHsl( hexToRgb( hex ) );
		var candidate = hex;
		for ( var i = 0; i < 100; i++ ) {
			hsl[ 2 ] = onLight ? hsl[ 2 ] - 0.01 : hsl[ 2 ] + 0.01;
			hsl[ 2 ] = Math.min( 1, Math.max( 0, hsl[ 2 ] ) );
			candidate = rgbToHex( hslToRgb( hsl ) );
			if ( contrast( candidate, surface ) >= min || hsl[ 2 ] <= 0 || hsl[ 2 ] >= 1 ) {
				break;
			}
		}
		return candidate;
	}

	function onColor( hex ) {
		var white = contrast( hex, "#FFFFFF" );
		if ( white >= 4.5 ) {
			return "#FFFFFF";
		}
		return white >= contrast( hex, INK_DARK ) ? "#FFFFFF" : INK_DARK;
	}

	function rgba( rgb, alpha ) {
		return "rgba(" + rgb[ 0 ] + ", " + rgb[ 1 ] + ", " + rgb[ 2 ] + ", " + alpha + ")";
	}

	function palette( hex ) {
		hex = sanitize( hex );
		var rgb = hexToRgb( hex );
		return {
			light: {
				"--akn-brand": hex,
				"--akn-brand-rgb": rgb[ 0 ] + ", " + rgb[ 1 ] + ", " + rgb[ 2 ],
				"--akn-brand-hover": shiftLightness( hex, -0.08 ),
				"--akn-brand-active": shiftLightness( hex, -0.13 ),
				"--akn-brand-soft": rgba( rgb, "0.12" ),
				"--akn-brand-on": onColor( hex ),
				"--akn-brand-ink": inkFor( hex, SURFACE_LIGHT ),
				"--akn-brand-ring": rgba( rgb, "0.35" )
			},
			dark: {
				"--akn-brand-soft": rgba( rgb, "0.2" ),
				"--akn-brand-ink": inkFor( hex, SURFACE_DARK )
			}
		};
	}

	var api = {
		DEFAULT_COLOR: DEFAULT_COLOR,
		SURFACE_LIGHT: SURFACE_LIGHT,
		SURFACE_DARK: SURFACE_DARK,
		INK_DARK: INK_DARK,
		sanitize: sanitize,
		contrast: contrast,
		palette: palette
	};

	root.AKNBrand = api;
	if ( typeof module !== "undefined" && module.exports ) {
		module.exports = api;
	}
} )( typeof window !== "undefined" ? window : globalThis );
