<?php
/**
 * Preview integration for the free AI editing UI.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Connects the AI preview actions to the editor. */
class Ai_Editor {
	const PREVIEW_ACTION_ID = 'kayzart-ai-edit-context';

	/** Register editor and preview hooks. */
	public static function init(): void {
		add_filter( 'kayzart_preview_payload', array( __CLASS__, 'filter_preview_payload' ), 10, 2 );
	}

	/**
	 * Add an AI context action to the preview overlay when it can be used.
	 *
	 * @param array $payload Preview payload.
	 * @param int   $post_id Preview post ID.
	 * @return array
	 */
	public static function filter_preview_payload( array $payload, int $post_id ): array {
		unset( $post_id );
		if ( ! current_user_can( Ai_Setup::CAPABILITY ) || ! Ai_Availability::is_available() ) {
			if ( self::PREVIEW_ACTION_ID === ( $payload['overlayAction']['actionId'] ?? '' ) ) {
				unset( $payload['overlayAction'] );
			}
			return $payload;
		}
		$payload['overlayAction'] = array(
			'actionId'                => self::PREVIEW_ACTION_ID,
			'ariaLabel'               => __( 'Add selected element to AI edit context', 'kayzart-live-code-editor' ),
			'background'              => '#2563eb',
			'showWhenElementsTabOpen' => true,
			'iconSvg'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.9 15.5A2 2 0 0 0 8.5 14l-6.1-1.5a.5.5 0 0 1 0-1L8.5 10A2 2 0 0 0 10 8.5l1.5-6.1a.5.5 0 0 1 1 0L14 8.5a2 2 0 0 0 1.5 1.5l6.1 1.5a.5.5 0 0 1 0 1L15.5 14a2 2 0 0 0-1.5 1.5l-1.5 6.1a.5.5 0 0 1-1 0z"/></svg>',
		);
		return $payload;
	}
}
