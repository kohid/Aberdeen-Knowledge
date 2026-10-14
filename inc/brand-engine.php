<?php
/**
 * Brand colour maths: one colour in, a complete accessible palette out.
 *
 * Pure PHP with no WordPress calls, so tests/brand/run-tests.php can run it
 * on its own. assets/js/akn-brand-admin.js is a line-for-line port used for
 * the live preview on the Settings screen; tests/brand/parity.mjs checks that
 * both produce the same palette. If you change the maths here, change it there.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'AKN_TESTING' ) ) {
	exit;
}

class AKN_Brand {

	const DEFAULT_COLOR = '#F69216';
	const SURFACE_LIGHT = '#FFFFFF';
	const SURFACE_DARK  = '#1E1E2D';
	const INK_DARK      = '#1B1B29';

	/** '#abc', 'ABCDEF' or '#AbCdEf' becomes '#AABBCC' / '#ABCDEF'; anything else becomes the default. */
	public static function sanitize( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^#?([0-9a-fA-F]{3})$/', $value, $m ) ) {
			$h = $m[1];
			return '#' . strtoupper( $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2] );
		}
		if ( preg_match( '/^#?([0-9a-fA-F]{6})$/', $value, $m ) ) {
			return '#' . strtoupper( $m[1] );
		}
		return self::DEFAULT_COLOR;
	}

	public static function hex_to_rgb( $hex ) {
		$hex = ltrim( self::sanitize( $hex ), '#' );
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	public static function rgb_to_hex( $rgb ) {
		return sprintf( '#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2] );
	}

	/** @return array [hue 0-360, saturation 0-1, lightness 0-1] */
	public static function rgb_to_hsl( $rgb ) {
		$r   = $rgb[0] / 255;
		$g   = $rgb[1] / 255;
		$b   = $rgb[2] / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$h   = 0.0;
		$s   = 0.0;
		if ( $max !== $min ) {
			$d = $max - $min;
			$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
			if ( $max === $r ) {
				$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
			} elseif ( $max === $g ) {
				$h = ( $b - $r ) / $d + 2;
			} else {
				$h = ( $r - $g ) / $d + 4;
			}
			$h *= 60;
		}
		return array( $h, $s, $l );
	}

	private static function hue_to_channel( $p, $q, $t ) {
		if ( $t < 0 ) {
			$t += 1;
		}
		if ( $t > 1 ) {
			$t -= 1;
		}
		if ( $t < 1 / 6 ) {
			return $p + ( $q - $p ) * 6 * $t;
		}
		if ( $t < 1 / 2 ) {
			return $q;
		}
		if ( $t < 2 / 3 ) {
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
		}
		return $p;
	}

	public static function hsl_to_rgb( $hsl ) {
		list( $h, $s, $l ) = $hsl;
		$h /= 360;
		if ( 0.0 === (float) $s ) {
			$v = (int) round( $l * 255 );
			return array( $v, $v, $v );
		}
		$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p = 2 * $l - $q;
		return array(
			(int) round( self::hue_to_channel( $p, $q, $h + 1 / 3 ) * 255 ),
			(int) round( self::hue_to_channel( $p, $q, $h ) * 255 ),
			(int) round( self::hue_to_channel( $p, $q, $h - 1 / 3 ) * 255 ),
		);
	}

	/** WCAG relative luminance. */
	public static function luminance( $rgb ) {
		$out = array();
		foreach ( $rgb as $c ) {
			$c     = $c / 255;
			$out[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $out[0] + 0.7152 * $out[1] + 0.0722 * $out[2];
	}

	/** WCAG contrast ratio, 1 to 21. */
	public static function contrast( $hex_a, $hex_b ) {
		$a = self::luminance( self::hex_to_rgb( $hex_a ) );
		$b = self::luminance( self::hex_to_rgb( $hex_b ) );
		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	/** The same hue and saturation with the lightness moved by $delta (-1 to 1), clamped. */
	public static function shift_lightness( $hex, $delta ) {
		$hsl    = self::rgb_to_hsl( self::hex_to_rgb( $hex ) );
		$hsl[2] = min( 1, max( 0, $hsl[2] + $delta ) );
		return self::rgb_to_hex( self::hsl_to_rgb( $hsl ) );
	}

	/**
	 * A text-safe version of the brand colour for use ON a surface (links,
	 * active tab text, outline buttons): the brand colour itself when it
	 * already reads well, otherwise the nearest shade with the same hue that
	 * reaches $min contrast. Darkened on light surfaces, lightened on dark.
	 */
	public static function ink_for( $hex, $surface, $min = 4.5 ) {
		$hex = self::sanitize( $hex );
		if ( self::contrast( $hex, $surface ) >= $min ) {
			return $hex;
		}
		$on_light = self::luminance( self::hex_to_rgb( $surface ) ) > 0.5;
		$hsl      = self::rgb_to_hsl( self::hex_to_rgb( $hex ) );
		$candidate = $hex;
		for ( $i = 0; $i < 100; $i++ ) {
			$hsl[2]    = $on_light ? $hsl[2] - 0.01 : $hsl[2] + 0.01;
			$hsl[2]    = min( 1, max( 0, $hsl[2] ) );
			$candidate = self::rgb_to_hex( self::hsl_to_rgb( $hsl ) );
			if ( self::contrast( $candidate, $surface ) >= $min || $hsl[2] <= 0 || $hsl[2] >= 1 ) {
				break;
			}
		}
		return $candidate;
	}

	/** White text on the brand colour when that reads (4.5:1), otherwise whichever of white or dark ink reads better. */
	public static function on_color( $hex ) {
		$white = self::contrast( $hex, '#FFFFFF' );
		if ( $white >= 4.5 ) {
			return '#FFFFFF';
		}
		return $white >= self::contrast( $hex, self::INK_DARK ) ? '#FFFFFF' : self::INK_DARK;
	}

	private static function rgba( $rgb, $alpha ) {
		return 'rgba(' . $rgb[0] . ', ' . $rgb[1] . ', ' . $rgb[2] . ', ' . $alpha . ')';
	}

	/**
	 * Every CSS variable the stylesheet needs, for light and dark.
	 *
	 * @return array [ 'light' => [ '--akn-brand' => ..., ... ], 'dark' => [ ... only what differs ... ] ]
	 */
	public static function palette( $hex ) {
		$hex = self::sanitize( $hex );
		$rgb = self::hex_to_rgb( $hex );
		return array(
			'light' => array(
				'--akn-brand'        => $hex,
				'--akn-brand-rgb'    => $rgb[0] . ', ' . $rgb[1] . ', ' . $rgb[2],
				'--akn-brand-hover'  => self::shift_lightness( $hex, -0.08 ),
				'--akn-brand-active' => self::shift_lightness( $hex, -0.13 ),
				'--akn-brand-soft'   => self::rgba( $rgb, '0.12' ),
				'--akn-brand-on'     => self::on_color( $hex ),
				'--akn-brand-ink'    => self::ink_for( $hex, self::SURFACE_LIGHT ),
				'--akn-brand-ring'   => self::rgba( $rgb, '0.35' ),
			),
			'dark'  => array(
				'--akn-brand-soft' => self::rgba( $rgb, '0.2' ),
				'--akn-brand-ink'  => self::ink_for( $hex, self::SURFACE_DARK ),
			),
		);
	}

	/** The :root and dark-mode rule sets as one CSS string. */
	public static function css( $hex ) {
		$palette = self::palette( $hex );
		$block   = function ( $selector, $vars ) {
			$lines = array();
			foreach ( $vars as $name => $value ) {
				$lines[] = $name . ':' . $value . ';';
			}
			return $selector . '{' . implode( '', $lines ) . '}';
		};
		return $block( ':root', $palette['light'] ) . $block( '[data-bs-theme=dark]', $palette['dark'] );
	}
}
