<?php
/**
 * Legacy shortcode compatibility tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Frontend;

class Test_Shortcode_Permissions extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! shortcode_exists( 'kayzart' ) ) {
			Frontend::init();
		}
	}

	public function test_legacy_shortcode_returns_empty_output(): void {
		$output = do_shortcode( '[kayzart post_id="123"]' );

		$this->assertSame( '', $output );
	}
}
