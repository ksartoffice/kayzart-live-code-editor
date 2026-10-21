<?php
/**
 * Post-save checks reported back to external AI agents.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds problems a saved page has that an agent cannot see in its own output.
 *
 * These are advisory: the page is already saved, and each finding is phrased
 * so the agent can decide whether to fix it. They catch the defects that a
 * screenshot shows poorly or not at all -- a misspelled Tailwind class simply
 * has no effect, and a dead anchor or missing alt text looks fine.
 */
class Agent_Checks {

	/** Maximum items listed per finding. */
	const MAX_ITEMS = 10;

	/**
	 * Tailwind classes that style nothing by design: they mark a parent or
	 * sibling for group-* and peer-* variants.
	 */
	const TAILWIND_MARKER_PATTERN = '/^(group|peer)(\/[\w-]+)?$/';

	/**
	 * Inspect a saved page.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,string> Findings.
	 */
	public static function inspect( int $post_id ): array {
		$snapshot = Snapshot::for_post( $post_id );
		$html     = (string) $snapshot['html'];
		$findings = array();

		if ( 'tailwind' === $snapshot['editorMode'] ) {
			$unknown = self::unknown_tailwind_classes( $html, (string) get_post_meta( $post_id, '_kayzart_generated_css', true ) );
			if ( ! empty( $unknown ) ) {
				$findings[] = sprintf(
					/* translators: %s: class names. */
					__( 'These classes generated no CSS, so they have no effect. Check for typos or values missing from @theme (ignore any you use only as hooks): %s', 'kayzart-live-code-editor' ),
					self::join( $unknown )
				);
			}
		}

		$document = self::load( $html );
		if ( null === $document ) {
			return $findings;
		}

		$missing_alt = self::images_without_alt( $document );
		if ( ! empty( $missing_alt ) ) {
			$findings[] = sprintf(
				/* translators: %s: image URLs. */
				__( 'These images have no alt attribute. Add a description, or alt="" if the image is decorative: %s', 'kayzart-live-code-editor' ),
				self::join( $missing_alt )
			);
		}

		$dead_anchors = self::dead_anchors( $document );
		if ( ! empty( $dead_anchors ) ) {
			$findings[] = sprintf(
				/* translators: %s: link targets such as #pricing. */
				__( 'These in-page links point to an id that does not exist on the page: %s', 'kayzart-live-code-editor' ),
				self::join( $dead_anchors )
			);
		}

		$missing_media = self::missing_local_media( $document );
		if ( ! empty( $missing_media ) ) {
			$findings[] = sprintf(
				/* translators: %s: media URLs. */
				__( 'These media files point to this site\'s uploads folder but do not exist. Use kayzart/list-media to find real URLs: %s', 'kayzart-live-code-editor' ),
				self::join( $missing_media )
			);
		}

		return $findings;
	}

	/**
	 * Class names in the HTML that the compiled Tailwind CSS has no rule for.
	 *
	 * @param string $html          Page HTML.
	 * @param string $generated_css Compiled CSS.
	 * @return array<int,string>
	 */
	public static function unknown_tailwind_classes( string $html, string $generated_css ): array {
		$candidates = Tailwind_Compiler::extract_candidates( $html );
		if ( is_wp_error( $candidates ) ) {
			return array();
		}
		$unknown = array();
		foreach ( $candidates as $candidate ) {
			if ( preg_match( self::TAILWIND_MARKER_PATTERN, $candidate ) ) {
				continue;
			}
			$selector = '.' . self::css_escape( $candidate );
			if ( ! preg_match( '/' . preg_quote( $selector, '/' ) . '(?![\w\-\\\\]|[^\x00-\x7F])/', $generated_css ) ) {
				$unknown[] = $candidate;
			}
		}
		return $unknown;
	}

	/**
	 * Escape a class name the way CSS.escape() and Tailwind do.
	 *
	 * @param string $value Class name.
	 * @return string
	 */
	public static function css_escape( string $value ): string {
		$chars  = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		$result = '';
		if ( false === $chars ) {
			return $value;
		}
		foreach ( $chars as $index => $char ) {
			if ( strlen( $char ) > 1 || preg_match( '/^[A-Za-z_\-]$/', $char ) ) {
				$result .= $char;
			} elseif ( ctype_digit( $char ) ) {
				$leading = 0 === $index || ( 1 === $index && '-' === $chars[0] );
				$result .= $leading ? '\\3' . $char . ' ' : $char;
			} else {
				$result .= '\\' . $char;
			}
		}
		return $result;
	}

	/**
	 * Image sources without an alt attribute.
	 *
	 * @param \DOMDocument $document Document.
	 * @return array<int,string>
	 */
	private static function images_without_alt( \DOMDocument $document ): array {
		$found = array();
		foreach ( $document->getElementsByTagName( 'img' ) as $img ) {
			if ( $img instanceof \DOMElement && ! $img->hasAttribute( 'alt' ) ) {
				$found[] = '' !== $img->getAttribute( 'src' ) ? $img->getAttribute( 'src' ) : '<img>';
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * In-page links whose target id is missing.
	 *
	 * @param \DOMDocument $document Document.
	 * @return array<int,string>
	 */
	private static function dead_anchors( \DOMDocument $document ): array {
		$ids   = array();
		$xpath = new \DOMXPath( $document );
		foreach ( $xpath->query( '//*[@id]' ) as $element ) {
			if ( $element instanceof \DOMElement ) {
				$ids[ $element->getAttribute( 'id' ) ] = true;
			}
		}
		$dead = array();
		foreach ( $document->getElementsByTagName( 'a' ) as $link ) {
			if ( ! $link instanceof \DOMElement ) {
				continue;
			}
			$href = trim( $link->getAttribute( 'href' ) );
			if ( strlen( $href ) < 2 || '#' !== $href[0] ) {
				continue;
			}
			$id = rawurldecode( substr( $href, 1 ) );
			if ( 'top' !== $id && ! isset( $ids[ $id ] ) ) {
				$dead[] = $href;
			}
		}
		return array_values( array_unique( $dead ) );
	}

	/**
	 * Media URLs under this site's uploads directory with no file behind them.
	 *
	 * @param \DOMDocument $document Document.
	 * @return array<int,string>
	 */
	private static function missing_local_media( \DOMDocument $document ): array {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$dir     = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $base || '' === $dir ) {
			return array();
		}
		$base_path = (string) wp_parse_url( $base, PHP_URL_PATH );
		$base_host = (string) wp_parse_url( $base, PHP_URL_HOST );
		$missing   = array();
		foreach ( array( 'img', 'source', 'video', 'audio' ) as $tag ) {
			foreach ( $document->getElementsByTagName( $tag ) as $element ) {
				if ( ! $element instanceof \DOMElement ) {
					continue;
				}
				$url  = $element->getAttribute( 'src' );
				$host = (string) wp_parse_url( $url, PHP_URL_HOST );
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				if ( '' === $url || ( '' !== $host && $host !== $base_host ) || '' === $base_path || 0 !== strpos( $path, $base_path . '/' ) ) {
					continue;
				}
				$relative = rawurldecode( substr( $path, strlen( $base_path ) ) );
				if ( false !== strpos( $relative, '..' ) ) {
					continue;
				}
				if ( ! file_exists( $dir . $relative ) ) {
					$missing[] = $url;
				}
			}
		}
		return array_values( array_unique( $missing ) );
	}

	/**
	 * Parse a body fragment.
	 *
	 * @param string $html HTML.
	 * @return \DOMDocument|null
	 */
	private static function load( string $html ): ?\DOMDocument {
		if ( '' === trim( $html ) ) {
			return null;
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $loaded ? $document : null;
	}

	/**
	 * Join a capped list for a message.
	 *
	 * @param array<int,string> $items Items.
	 * @return string
	 */
	private static function join( array $items ): string {
		$shown = array_slice( $items, 0, self::MAX_ITEMS );
		$text  = implode( ', ', $shown );
		if ( count( $items ) > self::MAX_ITEMS ) {
			/* translators: %d: number of further items. */
			$text .= ' ' . sprintf( __( '(and %d more)', 'kayzart-live-code-editor' ), count( $items ) - self::MAX_ITEMS );
		}
		return $text;
	}
}
