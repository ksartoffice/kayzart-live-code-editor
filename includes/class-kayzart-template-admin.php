<?php
/**
 * Template selection and read-only preview in Add new.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders template controls and handles authenticated admin requests. */
class Template_Admin {

	const PREVIEW_ACTION = 'kayzart_template_preview';

	/** Register the read-only admin endpoint. */
	public static function init(): void {
		add_action( 'admin_action_' . self::PREVIEW_ACTION, array( __CLASS__, 'preview' ) );
	}

	/**
	 * Render a separate form so AI instructions cannot be submitted with it.
	 *
	 * @param bool $ai_available Whether the existing AI path is available.
	 */
	public static function render( bool $ai_available ): void {
		$reason = Template_Library::unavailable_reason();
		$retry  = get_transient( 'kayzart_template_retry_' . get_current_user_id() );
		delete_transient( 'kayzart_template_retry_' . get_current_user_id() );
		$retry   = is_array( $retry ) ? $retry : array();
		$default = $retry || ( ! $ai_available && '' === $reason ) ? 'template' : ( $ai_available ? 'ai' : 'blank' );
		echo '<div class="kayzart-start-methods" aria-label="' . esc_attr__( 'Choose how to start', 'kayzart-live-code-editor' ) . '" data-default-start="' . esc_attr( $default ) . '" data-blank-title="' . esc_attr__( 'Page details', 'kayzart-live-code-editor' ) . '" data-blank-description="' . esc_attr__( 'Name the page, then open the standard HTML/CSS/JS editor.', 'kayzart-live-code-editor' ) . '">';
		foreach ( array(
			'template' => __( 'From a template', 'kayzart-live-code-editor' ),
			'ai'       => __( 'Create with AI', 'kayzart-live-code-editor' ),
			'blank'    => __( 'Start blank', 'kayzart-live-code-editor' ),
		) as $mode => $label ) {
			if ( 'ai' === $mode && ! current_user_can( Ai_Setup::CAPABILITY ) ) {
				continue;
			}
			echo '<button class="button" type="button" data-kayzart-start="' . esc_attr( $mode ) . '" aria-pressed="false">' . esc_html( $label ) . '</button>';
		}
		echo '</div><section id="kayzart-template-panel" aria-label="' . esc_attr__( 'Page templates', 'kayzart-live-code-editor' ) . '">';
		echo '<p id="kayzart-template-kept-input" hidden>' . esc_html__( 'Your AI instruction is kept. Switch back to Create with AI to continue it.', 'kayzart-live-code-editor' ) . '</p>';
		if ( ! empty( $retry['message'] ) ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( $retry['message'] ) . '</p></div>';
		}
		if ( '' !== $reason ) {
			echo '<p class="notice notice-warning inline">' . esc_html( $reason ) . '</p>';
		}
		foreach ( Template_Library::catalog() as $id => $summary ) {
			$preview_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'      => self::PREVIEW_ACTION,
						'template_id' => $id,
					),
					admin_url( 'admin.php' )
				),
				self::PREVIEW_ACTION
			);
			echo '<article class="kayzart-template-card">';
			echo '<div class="kayzart-template-art"><img src="' . esc_url( $summary['thumbnail'] ) . '" alt="' . esc_attr( $summary['title'] ) . '" width="1200" height="900"></div>';
			echo '<div class="kayzart-template-card__body"><p><span class="kayzart-create-badge">' . esc_html__( 'Free', 'kayzart-live-code-editor' ) . '</span> <span class="kayzart-template-language">' . esc_html( $summary['language'] ) . '</span></p>';
			echo '<h2>' . esc_html( $summary['title'] ) . '</h2><p>' . esc_html( $summary['description'] ) . '</p>';
			echo '<p class="kayzart-template-photo-note">' . esc_html__( 'Photos are examples only. Your page will contain image placeholders for you to replace.', 'kayzart-live-code-editor' ) . '</p>';
			echo '<button class="button" type="button" data-template-preview="' . esc_url( $preview_url ) . '"' . disabled( '' !== $reason, true, false ) . '>' . esc_html__( 'Preview template', 'kayzart-live-code-editor' ) . '</button>';
			echo '<form class="kayzart-template-form" method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Admin::NEW_PAGE_ACTION ) . '"><input type="hidden" name="start_mode" value="template"><input type="hidden" name="template_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="request_id" value="' . esc_attr( $retry['request_id'] ?? wp_generate_uuid4() ) . '">';
			echo '<input type="hidden" id="kayzart-template-nonce" name="_wpnonce" value="' . esc_attr( wp_create_nonce( Admin::NEW_PAGE_NONCE_ACTION ) ) . '">';
			echo '<label for="kayzart-template-title">' . esc_html__( 'Page title', 'kayzart-live-code-editor' ) . '</label><input id="kayzart-template-title" name="post_title" type="text" value="' . esc_attr( $retry['title'] ?? '' ) . '" placeholder="' . esc_attr__( 'Optional. You can rename the page later.', 'kayzart-live-code-editor' ) . '">';
			echo '<button class="button button-primary button-large" type="submit" data-loading-label="' . esc_attr__( 'Creating…', 'kayzart-live-code-editor' ) . '"' . disabled( '' !== $reason, true, false ) . '>' . esc_html__( 'Create from this template', 'kayzart-live-code-editor' ) . '</button></form></div></article>';
		}
		echo '</section><dialog id="kayzart-template-preview" aria-labelledby="kayzart-template-preview-title"><header><h2 id="kayzart-template-preview-title">' . esc_html__( 'Template preview', 'kayzart-live-code-editor' ) . '</h2><div><button type="button" class="button" data-template-width="desktop" aria-pressed="true">' . esc_html__( 'Desktop', 'kayzart-live-code-editor' ) . '</button> <button type="button" class="button" data-template-width="mobile" aria-pressed="false">' . esc_html__( 'Mobile', 'kayzart-live-code-editor' ) . '</button> <button type="button" class="button" data-template-close>' . esc_html__( 'Close', 'kayzart-live-code-editor' ) . '</button></div></header>';
		echo '<p>' . esc_html__( 'This is the page you will start with. Replace the image placeholders with your own photos.', 'kayzart-live-code-editor' ) . '</p><p data-template-preview-status role="status" data-loading="' . esc_attr__( 'Loading preview…', 'kayzart-live-code-editor' ) . '" data-error="' . esc_attr__( 'Could not load the preview. Please try again.', 'kayzart-live-code-editor' ) . '"></p><div class="kayzart-template-viewport"><iframe sandbox title="' . esc_attr__( 'Page template preview', 'kayzart-live-code-editor' ) . '"></iframe></div></dialog>';
	}

	/** Create from the nonce-verified form, preserving input on a recoverable error. */
	public static function create_from_request(): void {
		check_admin_referer( Admin::NEW_PAGE_NONCE_ACTION );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Invalid request method.', 'kayzart-live-code-editor' ) );
		}
		$id      = sanitize_key( self::post_value( 'template_id' ) );
		$request = self::post_value( 'request_id' );
		$title   = sanitize_text_field( self::post_value( 'post_title' ) );
		$result  = Template_Page_Creator::create( $id, $request, $title );
		if ( is_wp_error( $result ) ) {
			set_transient(
				'kayzart_template_retry_' . get_current_user_id(),
				array(
					'message'    => $result->get_error_message(),
					'title'      => $title,
					'request_id' => wp_is_uuid( $request, 4 ) ? $request : wp_generate_uuid4(),
				),
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( Admin::get_new_screen_url( 'page' ) );
		} else {
			wp_safe_redirect( add_query_arg( 'kayzart_entry', 'template', Post_Type::get_editor_url( $result ) ) );
		}
		exit;
	}

	/**
	 * Read an unslashed scalar from the verified form.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	private static function post_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Admin verifies the nonce; each consumer validates the value.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	/** Respond with an authenticated, read-only srcdoc document. */
	public static function preview(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'GET' !== $method ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request method.', 'kayzart-live-code-editor' ) ), 405 );
		}
		check_admin_referer( self::PREVIEW_ACTION );
		$reason = Template_Library::unavailable_reason();
		if ( '' !== $reason ) {
			wp_send_json_error( array( 'message' => $reason ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
		$id     = isset( $_GET['template_id'] ) && is_string( $_GET['template_id'] ) ? sanitize_key( wp_unslash( $_GET['template_id'] ) ) : '';
		$result = Template_Library::preview( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'document' => $result ) );
	}
}
