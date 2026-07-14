<?php
/**
 * Full-page snapshot revision tests.
 *
 * @package KayzArt
 */

use KayzArt\Html_Document;
use KayzArt\Post_Type;
use KayzArt\Rest_Revisions;
use KayzArt\Rest_Save;
use KayzArt\Snapshot;

/**
 * Verify complete revision behavior and version gating.
 */
class Test_Kayzart_Snapshot_Revisions extends WP_UnitTestCase {

	/**
	 * Re-register revisioned meta because the WordPress test suite resets it between tests.
	 */
	public function set_up(): void {
		parent::set_up();
		Snapshot::register_revision_support();
	}

	/**
	 * Create a Kayzart-managed page owned by an administrator.
	 *
	 * @return array{post_id:int,user_id:int}
	 */
	private function create_page(): array {
		$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_author'  => $user_id,
				'post_content' => '<main>Before</main>',
			)
		);
		update_post_meta( $post_id, Post_Type::ENABLED_META, '1' );
		wp_set_current_user( $user_id );

		return array(
			'post_id' => $post_id,
			'user_id' => $user_id,
		);
	}

	/**
	 * Build a save request.
	 *
	 * @param int        $post_id Post ID.
	 * @param string     $html    HTML input.
	 * @param string     $css     CSS input.
	 * @param string     $js      JavaScript input.
	 * @param string     $head    Custom head input.
	 * @param array|null $settings_updates Optional settings updates.
	 */
	private function save( int $post_id, string $html, string $css, string $js = '', string $head = '', ?array $settings_updates = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/kayzart/v1/save' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'html', $html );
		$request->set_param( 'customHead', $head );
		$request->set_param( 'css', $css );
		$request->set_param( 'js', $js );
		$request->set_param( 'jsMode', 'module' );
		$request->set_param( 'tailwindEnabled', false );
		if ( null !== $settings_updates ) {
			$request->set_param( 'settingsUpdates', $settings_updates );
		}
		return Rest_Save::save( $request );
	}

	/**
	 * Skip revision assertions on WordPress versions before 6.4.
	 */
	private function require_snapshot_support(): void {
		if ( ! Snapshot::is_supported() ) {
			$this->markTestSkipped( 'Full-page snapshots require WordPress 6.4 or later.' );
		}
	}

	/**
	 * Return IDs for complete Kayzart snapshots only.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,int>
	 */
	private function get_complete_revision_ids( int $post_id ): array {
		$ids = array();
		foreach ( wp_get_post_revisions( $post_id ) as $revision ) {
			if ( Snapshot::SCHEMA_VERSION === (string) get_metadata( 'post', $revision->ID, Snapshot::SCHEMA_META_KEY, true ) ) {
				$ids[] = (int) $revision->ID;
			}
		}
		return $ids;
	}

	/**
	 * A save creates exactly one complete snapshot.
	 */
	public function test_save_creates_one_complete_snapshot(): void {
		$this->require_snapshot_support();
		$this->assertContains( Snapshot::SCHEMA_META_KEY, wp_post_revision_meta_keys( 'page' ) );
		$page     = $this->create_page();
		$response = $this->save(
			$page['post_id'],
			'<body class="landing"><main>After</main></body>',
			'body{color:red;}',
			'console.log("saved");',
			'<meta name="description" content="Snapshot">'
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['revisionsSupported'] );
		$this->assertTrue( $data['revisionsEnabled'] );
		$this->assertIsArray( $data['revision'] );

		$revisions = array_filter(
			wp_get_post_revisions( $page['post_id'] ),
			static function ( $revision ) {
				return Snapshot::SCHEMA_VERSION === (string) get_metadata( 'post', $revision->ID, Snapshot::SCHEMA_META_KEY, true );
			}
		);
		$this->assertCount( 1, $revisions );
		$revision_id = (int) array_key_first( $revisions );
		$snapshot    = Snapshot::for_revision( $revision_id );

		$this->assertIsArray( $snapshot );
		$this->assertSame( '<body class="landing"><main>After</main></body>', $snapshot['html'] );
		$this->assertSame( 'body{color:red;}', $snapshot['css'] );
		$this->assertSame( 'console.log("saved");', $snapshot['js'] );
		$this->assertSame( 'module', $snapshot['jsMode'] );
		$this->assertSame( '<meta name="description" content="Snapshot">', $snapshot['customHead'] );
		$this->assertSame( 'class="landing"', get_metadata( 'post', $revision_id, Html_Document::BODY_ATTRS_META_KEY, true ) );
	}

	/**
	 * Meta-only changes are revisioned while identical saves are ignored.
	 */
	public function test_meta_only_change_creates_revision_and_identical_save_does_not(): void {
		$this->require_snapshot_support();
		$page = $this->create_page();

		$this->save( $page['post_id'], '<main>Same</main>', '.first{}' );
		$this->save( $page['post_id'], '<main>Same</main>', '.second{}' );
		$this->assertCount( 2, $this->get_complete_revision_ids( $page['post_id'] ) );

		$response = $this->save( $page['post_id'], '<main>Same</main>', '.second{}' );
		$data     = $response->get_data();
		$this->assertNull( $data['revision'] );
		$this->assertCount( 2, $this->get_complete_revision_ids( $page['post_id'] ) );
	}

	/**
	 * Persisted editor changes receive a snapshot when a later settings update fails.
	 */
	public function test_settings_failure_keeps_snapshot_for_persisted_editor_changes(): void {
		$this->require_snapshot_support();
		$page         = $this->create_page();
		$update_count = 0;
		$fail_second  = static function ( $maybe_empty, $postarr ) use ( $page, &$update_count ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $page['post_id'] ) {
				++$update_count;
				if ( 2 === $update_count ) {
					return true;
				}
			}
			return $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail_second, 10, 2 );
		try {
			$response = $this->save(
				$page['post_id'],
				'<main>Persisted despite settings error</main>',
				'.persisted{}',
				'',
				'',
				array( 'title' => 'Settings update fails' )
			);
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $fail_second, 10 );
		}

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '<main>Persisted despite settings error</main>', get_post( $page['post_id'] )->post_content );
		$revision_ids = $this->get_complete_revision_ids( $page['post_id'] );
		$this->assertCount( 1, $revision_ids );
		$snapshot = Snapshot::for_revision( $revision_ids[0] );
		$this->assertIsArray( $snapshot );
		$this->assertSame( '<main>Persisted despite settings error</main>', $snapshot['html'] );
		$this->assertSame( '.persisted{}', $snapshot['css'] );
		$this->assertSame( 9, has_action( 'wp_after_insert_post', 'wp_save_post_revision_on_insert' ) );
		$this->assertSame( 10, has_action( 'post_updated', 'wp_save_post_revision' ) );

		$retry = $this->save(
			$page['post_id'],
			'<main>Persisted despite settings error</main>',
			'.persisted{}',
			'',
			'',
			array( 'title' => 'Settings retry succeeds' )
		);
		$this->assertSame( 200, $retry->get_status() );
		$this->assertCount( 1, $this->get_complete_revision_ids( $page['post_id'] ) );
	}

	/**
	 * History endpoints list and return complete snapshots.
	 */
	public function test_history_api_lists_and_reads_complete_snapshots(): void {
		$this->require_snapshot_support();
		$page = $this->create_page();
		$this->save( $page['post_id'], '<main>One</main>', '.one{}' );
		$this->save( $page['post_id'], '<main>Two</main>', '.two{}' );
		$this->assertCount( 2, $this->get_complete_revision_ids( $page['post_id'] ) );

		$list_request = new WP_REST_Request( 'GET', '/kayzart/v1/revisions' );
		$list_request->set_param( 'post_id', $page['post_id'] );
		$list_response = Rest_Revisions::index( $list_request );
		$list          = $list_response->get_data();

		$this->assertTrue( $list['supported'] );
		$this->assertTrue( $list['canLoad'] );
		$this->assertCount( 2, $list['revisions'] );
		$this->assertContains( 'html', $list['revisions'][0]['changedSections'] );
		$this->assertContains( 'css', $list['revisions'][0]['changedSections'] );

		$revision_id    = (int) $list['revisions'][1]['id'];
		$detail_request = new WP_REST_Request( 'GET', '/kayzart/v1/revisions/' . $revision_id );
		$detail_request->set_param( 'post_id', $page['post_id'] );
		$detail_request->set_param( 'revision_id', $revision_id );
		$detail = Rest_Revisions::show( $detail_request )->get_data();

		$this->assertTrue( $detail['ok'] );
		$this->assertSame( '<main>One</main>', $detail['revision']['snapshot']['html'] );
		$this->assertSame( '.one{}', $detail['revision']['snapshot']['css'] );
	}

	/**
	 * WordPress 6.3 receives the non-fatal unsupported response.
	 */
	public function test_wordpress_63_reports_feature_as_unsupported(): void {
		global $wp_version;
		$original   = $wp_version;
		$wp_version = '6.3';
		try {
			$this->assertFalse( Snapshot::is_supported() );
			$page          = $this->create_page();
			$save_response = $this->save( $page['post_id'], '<main>Still saves</main>', '.legacy{}' );
			$save_data     = $save_response->get_data();
			$this->assertSame( 200, $save_response->get_status() );
			$this->assertFalse( $save_data['revisionsSupported'] );
			$this->assertFalse( $save_data['revisionsEnabled'] );
			$this->assertNull( $save_data['revision'] );
			$this->assertSame( '<main>Still saves</main>', get_post( $page['post_id'] )->post_content );

			$request = new WP_REST_Request( 'GET', '/kayzart/v1/revisions' );
			$request->set_param( 'post_id', $page['post_id'] );
			$data = Rest_Revisions::index( $request )->get_data();
			$this->assertFalse( $data['supported'] );
			$this->assertSame( '6.4', $data['minVersion'] );
			$this->assertSame( '6.3', $data['currentVersion'] );
			$this->assertSame( array(), $data['revisions'] );

			$detail_request = new WP_REST_Request( 'GET', '/kayzart/v1/revisions/1' );
			$detail_request->set_param( 'post_id', $page['post_id'] );
			$detail_request->set_param( 'revision_id', 1 );
			$detail_response = Rest_Revisions::show( $detail_request );
			$detail_data     = $detail_response->get_data();
			$this->assertSame( 409, $detail_response->get_status() );
			$this->assertSame( 'kayzart_revisions_unsupported', $detail_data['code'] );
		} finally {
			$wp_version = $original;
		}
	}
}
