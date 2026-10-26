<?php
/**
 * Bundled, offline page templates.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads the allowlisted template package and builds its read-only preview. */
class Template_Library {

	/**
	 * Return translated summaries without compiling templates.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function catalog(): array {
		return array(
			'general-service-lp' => array(
				'title'       => __( 'General service landing page', 'kayzart-live-code-editor' ),
				'description' => __( 'A complete introduction to your products or services, with features, pricing, FAQs, and a contact section. Page content is in Japanese.', 'kayzart-live-code-editor' ),
				'language'    => __( 'Japanese', 'kayzart-live-code-editor' ),
				'thumbnail'   => KAYZART_URL . 'assets/templates/free/general-service-lp/thumbnail.webp',
			),
		);
	}

	/**
	 * Explain why the current user cannot create a template page.
	 *
	 * @return string Empty when creation is available.
	 */
	public static function unavailable_reason(): string {
		if ( ! Post_Type::is_post_type_enabled( 'page' ) ) {
			return __( 'Enable Pages in Kayzart settings to use page templates.', 'kayzart-live-code-editor' );
		}
		$type = get_post_type_object( 'page' );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			return __( 'You do not have permission to create pages.', 'kayzart-live-code-editor' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return __( 'You need permission to upload media to use this template.', 'kayzart-live-code-editor' );
		}
		return '';
	}

	/**
	 * Resolve a package file inside its allowlisted directory.
	 *
	 * @param string $directory Package directory.
	 * @param mixed  $relative Relative filename.
	 * @return string|\WP_Error
	 */
	private static function resolve_file( string $directory, $relative ) {
		if ( ! is_string( $relative ) || '' === $relative || preg_match( '~(?:^[/\\\\]|\.\.|:|\x00)~', $relative ) ) {
			return self::invalid_package();
		}
		$path = realpath( $directory . '/' . $relative );
		$root = realpath( $directory );
		if ( false === $path || false === $root || ! is_file( $path ) || ! is_readable( $path ) || 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $root ) . '/' ) ) {
			return self::invalid_package();
		}
		return $path;
	}

	/**
	 * Load a package selected by ID, never a client-supplied path.
	 *
	 * @param string $id Template ID.
	 * @return array|\WP_Error
	 */
	public static function get( string $id ) {
		if ( ! isset( self::catalog()[ $id ] ) ) {
			return new \WP_Error( 'unknown_template', __( 'This template is not available.', 'kayzart-live-code-editor' ) );
		}
		$directory = KAYZART_PATH . 'assets/templates/free/' . $id;
		$manifest  = self::resolve_file( $directory, 'manifest.json' );
		if ( is_wp_error( $manifest ) || filesize( $manifest ) > 32768 ) {
			return self::invalid_package();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read an allowlisted bundled file, never a remote resource.
		$data = json_decode( file_get_contents( $manifest ), true );
		if ( ! is_array( $data ) || 1 !== ( $data['schemaVersion'] ?? null ) || ( $data['id'] ?? null ) !== $id || 'page' !== ( $data['kind'] ?? null ) || 'tailwind' !== ( $data['cssMode'] ?? null ) || 'standalone' !== ( $data['layoutMode'] ?? null ) || 'ja' !== ( $data['locale'] ?? null ) || ! is_string( $data['version'] ?? null ) || ! preg_match( '/^\d+\.\d+\.\d+$/', $data['version'] ) || ! is_array( $data['files'] ?? null ) || ! is_array( $data['assets'] ?? null ) || count( $data['assets'] ) > 10 ) {
			return self::invalid_package();
		}
		foreach ( array( 'html', 'css', 'thumbnail' ) as $kind ) {
			$file = self::resolve_file( $directory, $data['files'][ $kind ] ?? null );
			if ( is_wp_error( $file ) || filesize( $file ) > 262144 ) {
				return self::invalid_package();
			}
			if ( 'thumbnail' !== $kind ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read an allowlisted bundled file.
				$data[ $kind ] = file_get_contents( $file );
				if ( ! is_string( $data[ $kind ] ) || '' === trim( $data[ $kind ] ) ) {
					return self::invalid_package();
				}
			}
		}
		$seen = array();
		foreach ( $data['assets'] as &$asset ) {
			if ( ! is_array( $asset ) || ! is_string( $asset['id'] ?? null ) || ! preg_match( '/^[a-z0-9-]+$/', $asset['id'] ) || isset( $seen[ $asset['id'] ] ) || 'image/png' !== ( $asset['mimeType'] ?? null ) ) {
				return self::invalid_package();
			}
			$file = self::resolve_file( $directory, $asset['path'] ?? null );
			if ( is_wp_error( $file ) || filesize( $file ) > 524288 ) {
				return self::invalid_package();
			}
			$dimensions = wp_getimagesize( $file );
			if ( ! $dimensions || IMAGETYPE_PNG !== $dimensions[2] || ( $asset['width'] ?? null ) !== $dimensions[0] || ( $asset['height'] ?? null ) !== $dimensions[1] ) {
				return self::invalid_package();
			}
			$seen[ $asset['id'] ] = true;
			$asset['file']        = $file;
			$asset['url']         = KAYZART_URL . 'assets/templates/free/' . $id . '/' . $asset['path'];
		}
		unset( $asset );
		preg_match_all( '~<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~i', $data['html'], $image_sources );
		foreach ( $image_sources[2] as $source ) {
			if ( ! preg_match( '/^\{\{asset:([a-z0-9-]+)\}\}$/', $source, $match ) || ! isset( $seen[ $match[1] ] ) ) {
				return self::invalid_package();
			}
		}
		if ( preg_match( '~<(?:script|style|svg|form|input|iframe|object|link|video|audio)\b|\son\w+\s*=|\s(?:srcset|style)\s*=~i', $data['html'] ) || preg_match( '~</?style|url\s*\(|@import\s+(?!["\']tailwindcss["\']\s*;)~i', $data['css'] ) ) {
			return self::invalid_package();
		}
		return $data;
	}

	/**
	 * Resolve images, sanitize the final HTML, then compile its actual classes.
	 *
	 * @param array $template Loaded package.
	 * @param array $images   Optional image ID to local URL map.
	 * @return array|\WP_Error
	 */
	public static function prepare( array $template, array $images = array() ) {
		$replacements = array();
		foreach ( $template['assets'] as $asset ) {
			$replacements[ '{{asset:' . $asset['id'] . '}}' ] = esc_url( $images[ $asset['id'] ] ?? $asset['url'] );
		}
		$html = strtr( $template['html'], $replacements );
		if ( false !== strpos( $html, '{{asset:' ) ) {
			return self::invalid_package();
		}
		$html       = wp_kses_post( $html );
		$candidates = Tailwind_Compiler::extract_candidates( $html );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		$compiled = Tailwind_Compiler::generate( $candidates, $template['css'] );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		return array(
			'html'       => $html,
			'css'        => $template['css'],
			'candidates' => $candidates,
			'compiled'   => $compiled,
		);
	}

	/**
	 * Build an isolated, cacheable preview without creating a post.
	 *
	 * @param string $id Template ID.
	 * @return string|\WP_Error
	 */
	public static function preview( string $id ) {
		$template = self::get( $id );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$key    = 'kayzart_template_' . md5( KAYZART_VERSION . KAYZART_URL . determine_locale() . wp_json_encode( $template ) );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$prepared = self::prepare( $template );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$image_source = KAYZART_URL . 'assets/templates/free/' . $id . '/images/';
		$policy       = "default-src 'none'; style-src 'unsafe-inline'; img-src " . $image_source . "; base-uri 'none'; form-action 'none'";
		$document     = '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . esc_attr( $policy ) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( self::catalog()[ $id ]['title'] ) . '</title><style>' . $prepared['compiled'] . '</style></head><body>' . $prepared['html'] . '</body></html>';
		set_transient( $key, $document, DAY_IN_SECONDS );
		return $document;
	}

	/**
	 * Return a consistent package error.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_package(): \WP_Error {
		return new \WP_Error( 'invalid_template', __( 'The bundled template could not be loaded. Please reinstall the current version of Kayzart.', 'kayzart-live-code-editor' ) );
	}
}
