<?php
/**
 * Bundled page templates with declared optional Google Fonts.
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
	 * Google Fonts a bundled template may declare, with the only axis values and
	 * fallback stack allowed for each. Manifests can never name other families.
	 */
	const GOOGLE_FONTS = array(
		'Noto Sans JP'     => array(
			'axis'   => 'wght',
			'values' => array( 400, 500, 700, 900 ),
			'stack'  => '"Noto Sans JP", "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif',
		),
		'Instrument Sans'  => array(
			'axis'   => 'wght',
			'values' => array( 400, 500, 600, 700 ),
			'stack'  => '"Instrument Sans", "Helvetica Neue", Arial, sans-serif',
		),
		'Instrument Serif' => array(
			'axis'   => 'ital',
			'values' => array( 0, 1 ),
			'stack'  => '"Instrument Serif", Georgia, "Times New Roman", serif',
		),
	);

	/**
	 * Return translated summaries without compiling templates.
	 *
	 * @param bool $include_retired Include read-only legacy packages.
	 * @return array
	 */
	public static function catalog( bool $include_retired = false ): array {
		$current = array(
			'business-motion-ja' => array(
				'title'       => __( 'Business Motion — Japanese', 'kayzart-live-code-editor' ),
				'description' => __( 'A bold business service page with expressive typography and motion you can adjust through HTML attributes.', 'kayzart-live-code-editor' ),
				'fonts'       => 'Noto Sans JP',
				'requiresJs'  => true,
				'variants'    => array(
					'ja' => array(
						'label'     => '日本語',
						'thumbnail' => KAYZART_URL . 'assets/templates/free/business-motion-ja/thumbnail.webp',
					),
				),
			),
			'business-motion-en' => array(
				'title'       => __( 'Business Motion — English', 'kayzart-live-code-editor' ),
				'description' => __( 'A bold English business service page with editorial typography and motion you can adjust through HTML attributes.', 'kayzart-live-code-editor' ),
				'fonts'       => 'Instrument Sans, Instrument Serif',
				'requiresJs'  => true,
				'variants'    => array(
					'en' => array(
						'label'     => 'English',
						'thumbnail' => KAYZART_URL . 'assets/templates/free/business-motion-en/thumbnail.webp',
					),
				),
			),
		);
		if ( ! $include_retired ) {
			return $current;
		}
		return $current + array(
			'general-service-lp' => array(
				'title'       => __( 'General service landing page', 'kayzart-live-code-editor' ),
				'description' => __( 'A complete introduction to your products or services, with features, pricing, FAQs, and a contact section.', 'kayzart-live-code-editor' ),
				'variants'    => array(
					'ja' => array(
						'label'     => '日本語',
						'thumbnail' => KAYZART_URL . 'assets/templates/free/general-service-lp/thumbnail.webp',
					),
					'en' => array(
						'label'     => 'English',
						'thumbnail' => KAYZART_URL . 'assets/templates/free/general-service-lp/thumbnail.en.webp',
					),
				),
			),
		);
	}

	/**
	 * List content languages actually declared by the bundled catalog.
	 *
	 * @return array Language code to native label.
	 */
	public static function available_locales(): array {
		$languages = array();
		foreach ( self::catalog() as $summary ) {
			foreach ( $summary['variants'] as $locale => $variant ) {
				$languages[ $locale ] = $variant['label'];
			}
		}
		return $languages;
	}

	/** Select the initial content language from the site, not the administrator.
	 *
	 * @return string
	 */
	public static function default_locale(): string {
		$languages = self::available_locales();
		$site      = str_replace( '-', '_', (string) get_option( 'WPLANG', '' ) );
		if ( isset( $languages[ $site ] ) ) {
			return $site;
		}
		$language = explode( '_', $site, 2 )[0];
		if ( isset( $languages[ $language ] ) ) {
			return $language;
		}
		return isset( $languages['en'] ) ? 'en' : ( array_key_first( $languages ) ?? 'en' );
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
	 * @param string $id     Template ID.
	 * @param string $locale Content language.
	 * @return array|\WP_Error
	 */
	public static function get( string $id, string $locale = 'ja' ) {
		if ( ! isset( self::catalog( true )[ $id ] ) ) {
			return new \WP_Error( 'unknown_template', __( 'This template is not available.', 'kayzart-live-code-editor' ) );
		}
		if ( ! isset( self::catalog( true )[ $id ]['variants'][ $locale ] ) ) {
			return new \WP_Error( 'unsupported_template_locale', __( 'Choose an available page language.', 'kayzart-live-code-editor' ) );
		}
		$directory = KAYZART_PATH . 'assets/templates/free/' . $id;
		$manifest  = self::resolve_file( $directory, 'manifest.json' );
		if ( is_wp_error( $manifest ) || filesize( $manifest ) > 32768 ) {
			return self::invalid_package();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read an allowlisted bundled file, never a remote resource.
		$data = json_decode( file_get_contents( $manifest ), true );
		if ( ! is_array( $data ) || ! in_array( $data['schemaVersion'] ?? null, array( 1, 2 ), true ) || ( $data['id'] ?? null ) !== $id || 'page' !== ( $data['kind'] ?? null ) || 'tailwind' !== ( $data['cssMode'] ?? null ) || 'standalone' !== ( $data['layoutMode'] ?? null ) || ! isset( self::catalog( true )[ $id ]['variants'][ $data['locale'] ?? '' ] ) || ! is_string( $data['version'] ?? null ) || ! preg_match( '/^\d+\.\d+\.\d+$/', $data['version'] ) || ! is_array( $data['files'] ?? null ) || ! is_array( $data['assets'] ?? null ) || count( $data['assets'] ) > 10 ) {
			return self::invalid_package();
		}
		if ( $locale !== $data['locale'] ) {
			$localized = $data['localizations'][ $locale ] ?? null;
			if ( ! is_array( $localized ) ) {
				return self::invalid_package();
			}
			$data['files']['html']      = $localized['html'] ?? null;
			$data['files']['thumbnail'] = $localized['thumbnail'] ?? null;
			$data['locale']             = $locale;
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
		$script_mode        = $data['jsMode'] ?? 'classic';
		$data['js']         = '';
		$data['jsMode']     = 'classic';
		$data['customHead'] = '';
		if ( 2 === $data['schemaVersion'] ) {
			if ( isset( $data['files']['js'] ) ) {
				$script = self::resolve_file( $directory, $data['files']['js'] );
				if ( is_wp_error( $script ) || filesize( $script ) > 65536 || 'module' !== $script_mode ) {
					return self::invalid_package();
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- An allowlisted, developer-authored bundled module.
				$data['js']     = file_get_contents( $script );
				$data['jsMode'] = 'module';
			}
			$head = self::google_fonts_head( $data['fonts'] ?? array() );
			if ( is_wp_error( $head ) ) {
				return $head;
			}
			$data['customHead'] = $head;
		}
		return $data;
	}

	/**
	 * Generate only the declared Google Fonts link, never arbitrary head HTML.
	 *
	 * @param mixed $fonts Manifest font declarations.
	 * @return string|\WP_Error
	 */
	public static function google_fonts_head( $fonts ) {
		if ( ! is_array( $fonts ) || count( $fonts ) > 2 ) {
			return self::invalid_package();
		}
		$families = array();
		foreach ( $fonts as $font ) {
			$allowed = is_array( $font ) && is_string( $font['family'] ?? null ) ? ( self::GOOGLE_FONTS[ $font['family'] ] ?? null ) : null;
			$key     = $allowed && 'ital' === $allowed['axis'] ? 'italic' : 'weights';
			if ( ! $allowed || 'google' !== ( $font['provider'] ?? null ) || isset( $families[ $font['family'] ] ) || ! is_array( $font[ $key ] ?? null ) || empty( $font[ $key ] ) || array_diff_key( $font, array_flip( array( 'provider', 'family', $key ) ) ) ) {
				return self::invalid_package();
			}
			foreach ( $font[ $key ] as $value ) {
				if ( ! in_array( $value, $allowed['values'], true ) ) {
					return self::invalid_package();
				}
			}
			$values = array_unique( $font[ $key ] );
			sort( $values, SORT_NUMERIC );
			$families[ $font['family'] ] = 'family=' . str_replace( ' ', '+', $font['family'] ) . ':' . $allowed['axis'] . '@' . implode( ';', $values );
		}
		if ( empty( $families ) ) {
			return '';
		}
		$url = 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap';
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Editable head source shared by saved pages and a sandbox srcdoc, not an admin enqueue.
		return '<link rel="stylesheet" href="' . esc_attr( esc_url_raw( $url ) ) . '" referrerpolicy="no-referrer">';
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
			'js'         => $template['js'] ?? '',
			'jsMode'     => $template['jsMode'] ?? 'classic',
			'customHead' => $template['customHead'] ?? '',
		);
	}

	/**
	 * Build an isolated, cacheable preview without creating a post.
	 *
	 * @param string $id     Template ID.
	 * @param string $locale Content language.
	 * @return string|\WP_Error
	 */
	public static function preview( string $id, string $locale = 'ja' ) {
		$template = self::get( $id, $locale );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$key    = 'kayzart_template_' . md5( KAYZART_VERSION . KAYZART_URL . determine_locale() . wp_json_encode( $template ) . filemtime( KAYZART_PATH . 'includes/runtime.js' ) );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$prepared = self::prepare( $template );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$image_source = KAYZART_URL . 'assets/templates/free/' . $id . '/images/';
		$nonce        = wp_generate_password( 24, false, false );
		$font_policy  = '' !== $prepared['customHead'] ? ' https://fonts.googleapis.com' : '';
		$policy       = "default-src 'none'; style-src 'unsafe-inline'" . $font_policy . '; font-src https://fonts.gstatic.com; img-src ' . $image_source . "; script-src 'nonce-" . $nonce . "' blob:; base-uri 'none'; form-action 'none'";
		$scripts      = '';
		if ( '' !== $prepared['js'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reuse the same local module runtime as published pages.
			$runtime = file_get_contents( KAYZART_PATH . 'includes/runtime.js' );
			$scripts = '<script type="application/json" data-kayzart-js="1" data-kayzart-js-mode="module">' . esc_html( rawurlencode( $prepared['js'] ) ) . '</script><script nonce="' . esc_attr( $nonce ) . '">' . $runtime . '</script>';
		}
		$document = '<!doctype html><html lang="' . esc_attr( $template['locale'] ) . '"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . esc_attr( $policy ) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( self::catalog( true )[ $id ]['title'] ) . '</title>' . $prepared['customHead'] . '<style>' . $prepared['compiled'] . '</style></head><body>' . $prepared['html'] . $scripts . '</body></html>';
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
