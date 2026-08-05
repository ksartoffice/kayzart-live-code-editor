<?php
/**
 * Resolves the fonts an AI edit may reference.
 *
 * The AI cannot load remote fonts: Ai_Output_Policy rejects any stylesheet link,
 * CSS import, or url() pointing at a remote origin. Without a list of real
 * options the model invents family names that resolve nowhere, so the page
 * silently falls back to the browser default on the visitor's device.
 *
 * Two sources are offered:
 *   1. Families registered with WordPress (theme.json and the Font Library).
 *      WordPress core prints their font-face rules itself, including on Kayzart
 *      standalone pages, so referencing them by name is enough.
 *   2. System font stacks, which need no download and always resolve.
 *
 * Every core call is defensively guarded: on any failure the registered list is
 * empty and the caller still has the system stacks.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Enumerates fonts that an AI-authored page can actually render. */
class Ai_Fonts {

	/**
	 * Font stacks built from fonts already present on the visitor's device.
	 *
	 * Latin families come first so that Latin glyphs use the Latin font and
	 * Japanese text falls through to the Japanese family behind it.
	 *
	 * Note that Windows ships no rounded Japanese face, so `rounded` degrades to
	 * the gothic stack there. That is accepted: it stays readable everywhere.
	 *
	 * @var array<string,string>
	 */
	const SYSTEM_STACKS = array(
		'gothic'  => 'system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Hiragino Kaku Gothic ProN", "Yu Gothic UI", "Noto Sans JP", Meiryo, sans-serif',
		'mincho'  => 'Georgia, "Times New Roman", "Hiragino Mincho ProN", "Yu Mincho", YuMincho, "Noto Serif JP", "MS PMincho", serif',
		'rounded' => 'system-ui, "Hiragino Maru Gothic ProN", "Yu Gothic UI", "Noto Sans JP", sans-serif',
	);

	/**
	 * Build the font information stored on an AI job payload.
	 *
	 * @return array{registered:array<int,array{name:string,fontFamily:string}>,systemStacks:array<string,string>}
	 */
	public static function resolve_for_payload(): array {
		return array(
			'registered'   => self::registered_families(),
			'systemStacks' => self::SYSTEM_STACKS,
		);
	}

	/**
	 * List font families WordPress will emit a font-face rule for.
	 *
	 * @return array<int,array{name:string,fontFamily:string}>
	 */
	public static function registered_families(): array {
		$families = array();
		try {
			$families = self::read_families_from_global_settings();
		} catch ( \Throwable $error ) {
			$families = array();
		}

		/**
		 * Filter the font families offered to the AI.
		 *
		 * Lets sites add self-hosted fonts registered outside theme.json.
		 *
		 * @param array<int,array{name:string,fontFamily:string}> $families Discovered families.
		 */
		$families = apply_filters( 'kayzart_ai_available_fonts', $families );

		return self::normalize_list( is_array( $families ) ? $families : array() );
	}

	/**
	 * Read font families from the merged global settings.
	 *
	 * Reading wp_get_global_settings() without a path returns every origin
	 * merged, so this covers both theme.json families and Font Library installs.
	 *
	 * Which origins that includes is core's decision and it has changed between
	 * releases: before WordPress 7.0 the custom origin was dropped when the
	 * active theme had no theme.json. Deliberately no version check is made here.
	 * Core's wp_print_font_faces() reads this very same call, so whatever it
	 * decides, the families offered to the AI stay exactly the families that get
	 * a font-face rule. Never swap this for reading wp_font_family posts, which
	 * would let the two disagree.
	 *
	 * Families without a fontFace definition are skipped, mirroring
	 * WP_Font_Face_Resolver::parse_settings(). Core emits no font-face rule for
	 * those, so offering them would advertise a font that never renders.
	 *
	 * @return array<int,array{name:string,fontFamily:string}>
	 */
	private static function read_families_from_global_settings(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$settings = wp_get_global_settings();
		if ( ! is_array( $settings ) || ! isset( $settings['typography']['fontFamilies'] ) ) {
			return array();
		}

		$by_origin = $settings['typography']['fontFamilies'];
		if ( ! is_array( $by_origin ) ) {
			return array();
		}

		$families = array();
		foreach ( $by_origin as $origin_families ) {
			if ( ! is_array( $origin_families ) ) {
				continue;
			}
			foreach ( $origin_families as $definition ) {
				if ( ! is_array( $definition ) || empty( $definition['fontFace'] ) || empty( $definition['fontFamily'] ) ) {
					continue;
				}
				$families[] = array(
					'name'       => isset( $definition['name'] ) ? (string) $definition['name'] : (string) $definition['fontFamily'],
					'fontFamily' => (string) $definition['fontFamily'],
				);
			}
		}

		return $families;
	}

	/**
	 * Sanitize and de-duplicate a family list by name, preserving order.
	 *
	 * @param array $families Raw family list.
	 * @return array<int,array{name:string,fontFamily:string}>
	 */
	private static function normalize_list( array $families ): array {
		$seen   = array();
		$result = array();
		foreach ( $families as $family ) {
			if ( ! is_array( $family ) || empty( $family['name'] ) ) {
				continue;
			}
			$name = trim( (string) $family['name'] );
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$font_family   = isset( $family['fontFamily'] ) ? trim( (string) $family['fontFamily'] ) : '';
			$result[]      = array(
				'name'       => $name,
				'fontFamily' => '' !== $font_family ? $font_family : $name,
			);
		}
		return $result;
	}
}
