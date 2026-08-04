<?php
/**
 * Unit tests for AI font availability.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Fonts;

/**
 * Verify which font families are offered to the AI.
 */
class Test_Kayzart_Ai_Fonts extends WP_UnitTestCase {

	/**
	 * Stylesheet active before a test switched themes.
	 *
	 * @var string
	 */
	private $original_stylesheet = '';

	/**
	 * Record the active theme so a switch can be undone.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->original_stylesheet = get_stylesheet();
	}

	/**
	 * Remove any filter a test installed and restore the theme.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'kayzart_ai_available_fonts' );
		remove_all_filters( 'wp_theme_json_data_user' );
		if ( get_stylesheet() !== $this->original_stylesheet ) {
			switch_theme( $this->original_stylesheet );
		}
		$this->flush_theme_json_caches();
		parent::tearDown();
	}

	/**
	 * Activate a block theme, or skip when none ships with this install.
	 *
	 * Before WordPress 7.0 the custom origin was only merged when the active
	 * theme had a theme.json, so a block theme is needed to exercise it on the
	 * older cores this suite may run against.
	 */
	private function require_block_theme(): void {
		$theme = wp_get_theme( 'twentytwentyfive' );
		if ( ! $theme->exists() ) {
			$this->markTestSkipped( 'No block theme available to exercise the custom origin.' );
		}
		switch_theme( 'twentytwentyfive' );
		$this->flush_theme_json_caches();
		if ( ! wp_theme_has_theme_json() ) {
			$this->markTestSkipped( 'The active theme reports no theme.json.' );
		}
	}

	/**
	 * Drop every layer that caches resolved theme.json settings.
	 *
	 * WP_Theme_JSON_Resolver::clean_cached_data() only resets static properties.
	 * wp_get_global_settings() additionally caches in the 'theme_json' object
	 * cache group, so that has to be cleared for a filter to take effect.
	 */
	private function flush_theme_json_caches(): void {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
		wp_cache_delete( 'wp_get_global_settings_custom', 'theme_json' );
		wp_cache_delete( 'wp_get_global_settings_theme', 'theme_json' );
	}

	/**
	 * Register a font family as a Font Library (custom origin) install would.
	 *
	 * @param array $family Font family definition.
	 */
	private function register_user_font_family( array $family ): void {
		add_filter(
			'wp_theme_json_data_user',
			static function ( $theme_json ) use ( $family ) {
				return $theme_json->update_with(
					array(
						'version'  => 3,
						'settings' => array(
							'typography' => array(
								'fontFamilies' => array( $family ),
							),
						),
					)
				);
			}
		);
		$this->flush_theme_json_caches();
	}

	/**
	 * The payload carries both sources the prompt needs.
	 */
	public function test_resolve_for_payload_shape(): void {
		$payload = Ai_Fonts::resolve_for_payload();

		$this->assertArrayHasKey( 'registered', $payload );
		$this->assertArrayHasKey( 'systemStacks', $payload );
		$this->assertSame( Ai_Fonts::SYSTEM_STACKS, $payload['systemStacks'] );
	}

	/**
	 * Every system stack names a Japanese face so Japanese copy always renders.
	 */
	public function test_system_stacks_cover_japanese(): void {
		$this->assertSame( array( 'gothic', 'mincho', 'rounded' ), array_keys( Ai_Fonts::SYSTEM_STACKS ) );
		foreach ( Ai_Fonts::SYSTEM_STACKS as $name => $stack ) {
			$this->assertMatchesRegularExpression( '/Hiragino|Yu Gothic|Yu Mincho|YuMincho|Noto|Meiryo|Mincho/', $stack, $name );
			$this->assertMatchesRegularExpression( '/(sans-serif|serif)$/', $stack, $name );
		}
	}

	/**
	 * A Font Library install (custom origin) is offered to the AI.
	 */
	public function test_custom_origin_family_is_registered(): void {
		$this->require_block_theme();
		$this->register_user_font_family(
			array(
				'name'       => 'Test Sans JP',
				'slug'       => 'test-sans-jp',
				'fontFamily' => '"Test Sans JP", sans-serif',
				'fontFace'   => array(
					array(
						'fontFamily' => 'Test Sans JP',
						'fontStyle'  => 'normal',
						'fontWeight' => '400',
						'src'        => array( 'file:./fonts/test-sans-jp.woff2' ),
					),
				),
			)
		);

		$names = wp_list_pluck( Ai_Fonts::registered_families(), 'name' );
		$this->assertContains( 'Test Sans JP', $names );
	}

	/**
	 * Families without a fontFace get no @font-face from core, so they are hidden.
	 */
	public function test_family_without_font_face_is_skipped(): void {
		$this->require_block_theme();
		$this->register_user_font_family(
			array(
				'name'       => 'Stack Only',
				'slug'       => 'stack-only',
				'fontFamily' => 'Georgia, serif',
			)
		);

		$names = wp_list_pluck( Ai_Fonts::registered_families(), 'name' );
		$this->assertNotContains( 'Stack Only', $names );
	}

	/**
	 * A family is offered to the AI exactly when core renders its font-face.
	 *
	 * This is the guarantee the feature rests on: never advertise a font that
	 * will not resolve on the visitor device. Which origins core merges is its
	 * own decision and has changed across releases (before WordPress 7.0 the
	 * custom origin was dropped for themes without a theme.json), so this
	 * asserts the equivalence rather than any one version's outcome.
	 */
	public function test_offered_families_are_exactly_what_core_renders(): void {
		$this->register_user_font_family(
			array(
				'name'       => 'Render Check JP',
				'slug'       => 'render-check-jp',
				'fontFamily' => '"Render Check JP", sans-serif',
				'fontFace'   => array(
					array(
						'fontFamily' => 'Render Check JP',
						'src'        => array( 'file:./fonts/render-check-jp.woff2' ),
					),
				),
			)
		);

		ob_start();
		wp_print_font_faces();
		$css = (string) ob_get_clean();

		$offered = wp_list_pluck( Ai_Fonts::registered_families(), 'name' );
		foreach ( $offered as $name ) {
			$this->assertStringContainsString( $name, $css, $name . ' is offered but core prints no font-face for it.' );
		}

		$this->assertSame(
			in_array( 'Render Check JP', $offered, true ),
			false !== strpos( $css, 'Render Check JP' ),
			'Offering a family and rendering it must agree on every WordPress version.'
		);

		// Whatever core decides, the system stacks always give the AI a choice.
		$this->assertNotEmpty( Ai_Fonts::resolve_for_payload()['systemStacks'] );
	}

	/**
	 * Sites can inject self-hosted fonts registered outside theme.json.
	 */
	public function test_filter_can_add_and_replace_families(): void {
		add_filter(
			'kayzart_ai_available_fonts',
			static function () {
				return array(
					array(
						'name'       => 'Filtered Font',
						'fontFamily' => '"Filtered Font", sans-serif',
					),
				);
			}
		);

		$families = Ai_Fonts::registered_families();
		$this->assertSame( 'Filtered Font', $families[0]['name'] );
		$this->assertSame( '"Filtered Font", sans-serif', $families[0]['fontFamily'] );
	}

	/**
	 * Malformed entries are dropped and duplicate names collapse.
	 */
	public function test_malformed_and_duplicate_entries_are_normalized(): void {
		add_filter(
			'kayzart_ai_available_fonts',
			static function () {
				return array(
					'not-an-array',
					array( 'fontFamily' => 'no name here' ),
					array( 'name' => '   ' ),
					array( 'name' => 'Solo' ),
					array(
						'name'       => 'Solo',
						'fontFamily' => 'ignored duplicate',
					),
				);
			}
		);

		$families = Ai_Fonts::registered_families();
		$this->assertCount( 1, $families );
		$this->assertSame( 'Solo', $families[0]['name'] );
		// A family with no fontFamily falls back to its own name.
		$this->assertSame( 'Solo', $families[0]['fontFamily'] );
	}

	/**
	 * A filter returning a non-array must not break job creation.
	 */
	public function test_non_array_filter_result_is_ignored(): void {
		add_filter( 'kayzart_ai_available_fonts', '__return_false' );
		$this->assertSame( array(), Ai_Fonts::registered_families() );
	}
}
