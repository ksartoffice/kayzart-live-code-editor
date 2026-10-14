<?php
/**
 * Disposable-installation-only fixture: never contact feedback.kayzart.com.
 *
 * @package KayzArt
 */

if ( ! defined( 'KAYZART_DEACTIVATION_E2E' ) || true !== KAYZART_DEACTIVATION_E2E ) {
	return;
}
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 'feedback.kayzart.com' === wp_parse_url( $url, PHP_URL_HOST ) ) {
			$payload = json_decode( $args['body'] ?? '', true );
			update_option( 'kayzart_e2e_delivery_count', (int) get_option( 'kayzart_e2e_delivery_count', 0 ) + 1, false );
			update_option( 'kayzart_e2e_delivery_payload', $payload, false );
			$mode = $_COOKIE['kayzart_delivery_mode'] ?? 'success';
			return array(
				'response' => array( 'code' => 'closed' === $mode ? 410 : 201 ),
				'body'     => 'malformed' === $mode ? 'invalid JSON' : wp_json_encode( array( 'ok' => true, 'submissionId' => $payload['submission_id'] ?? '' ) ),
			);
		}
		return new WP_Error( 'e2e_external_request_blocked' );
	},
	10,
	3
);
add_action(
	'admin_footer-plugins.php',
	static function () {
		echo '<div id="kayzart-e2e-delivery" hidden data-count="' . esc_attr( (string) get_option( 'kayzart_e2e_delivery_count', 0 ) ) . '" data-payload="' . esc_attr( wp_json_encode( get_option( 'kayzart_e2e_delivery_payload', array() ) ) ) . '"></div>';
	}
);
