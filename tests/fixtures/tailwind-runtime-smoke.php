<?php
/**
 * Compile without WordPress, PHPUnit, or development autoload files.
 *
 * @package KayzArt
 */

$root = isset( $argv[1] ) ? $argv[1] : dirname( __DIR__, 2 );
if ( in_array( '--without-native-mbstring', $argv, true ) && function_exists( 'mb_chr' ) ) {
	throw new RuntimeException( 'The smoke test must start without native mbstring functions.' );
}
if ( in_array( '--without-native-text', $argv, true ) && ( function_exists( 'mb_chr' ) || function_exists( 'ctype_digit' ) || function_exists( 'iconv' ) ) ) {
	throw new RuntimeException( 'The smoke test must start without native text functions.' );
}
require $root . '/vendor/autoload.php';

if ( '🚀' !== \TailwindPHP\Utils\unescape( '\\1f680' ) ) {
	throw new RuntimeException( 'Unicode CSS escapes must work without mbstring.' );
}
$compiled = \TailwindPHP\compile( '@import "tailwindcss"; @theme { --color-brand: #123456; }' );
$css      = $compiled['build']( array( 'has-data-[slot=icon]:pl-2', 'bg-brand', 'flex-grow', "font-['Noto_Sans_JP']" ) );
foreach ( array( ':has([data-slot="icon"])', '--color-brand: #123456;', 'flex-grow: 1;', 'Noto' ) as $expected ) {
	if ( false === strpos( $css, $expected ) ) {
		throw new RuntimeException( 'Compiled CSS omitted a required utility.' );
	}
}
$forms = \TailwindPHP\compile( '@plugin "@tailwindcss/forms"; @import "tailwindcss/utilities.css";' );
if ( false === strpos( $forms['build']( array() ), "input:where([type='text'])" ) ) {
	throw new RuntimeException( 'Forms base styles must compile without development dependencies.' );
}
echo "Tailwind production runtime OK\n";
