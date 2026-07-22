<?php
/**
 * Server-side safety policy for model-produced editor snapshots.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rejects newly introduced executable or remotely loaded content. */
class Ai_Output_Policy {

	/** Tags whose contents must never be introduced or changed by AI. */
	const BLOCKED_TAGS = array( 'script', 'iframe', 'object', 'embed', 'base' );

	/** URL-bearing attributes that load a resource rather than navigate. */
	const RESOURCE_ATTRIBUTES = array( 'src', 'srcset', 'poster', 'data', 'xlink:href' );

	/** Attributes whose values are interpreted as URLs. */
	const URL_ATTRIBUTES = array( 'href', 'src', 'srcset', 'poster', 'data', 'xlink:href', 'action', 'formaction' );

	/**
	 * Assert that an AI transition preserves JS and adds no unsafe findings.
	 *
	 * Existing findings may remain byte-for-byte equivalent or be removed.
	 *
	 * @param array $before Snapshot before the model edit.
	 * @param array $after  Candidate snapshot after the model edit.
	 * @throws Ai_Tool_Error When the candidate violates the policy.
	 */
	public static function assert_safe_transition( array $before, array $after ): void {
		$violations = array();
		if ( (string) ( $before['js'] ?? '' ) !== (string) ( $after['js'] ?? '' ) ) {
			$violations[] = 'JavaScript source is read-only for AI edits.';
		}
		$before_mode = 'module' === ( $before['jsMode'] ?? 'classic' ) ? 'module' : 'classic';
		$after_mode  = 'module' === ( $after['jsMode'] ?? 'classic' ) ? 'module' : 'classic';
		if ( $before_mode !== $after_mode ) {
			$violations[] = 'JavaScript mode is read-only for AI edits.';
		}

		foreach (
			array(
				'html' => 'html',
				'head' => 'customHead',
			) as $label => $key
		) {
			$added = self::added_findings(
				self::html_findings( (string) ( $before[ $key ] ?? '' ) ),
				self::html_findings( (string) ( $after[ $key ] ?? '' ) )
			);
			foreach ( $added as $finding ) {
				$violations[] = sprintf( 'Unsafe %s content: %s', $label, $finding );
			}
		}

		$added_css = self::added_findings(
			self::css_findings( (string) ( $before['css'] ?? '' ) ),
			self::css_findings( (string) ( $after['css'] ?? '' ) )
		);
		foreach ( $added_css as $finding ) {
			$violations[] = 'Unsafe CSS content: ' . $finding;
		}

		if ( count( $violations ) > 0 ) {
			throw new Ai_Tool_Error(
				'The proposed edit violates the AI safety policy. Remove the unsafe change and use static HTML/CSS instead.',
				true,
				array(
					'code'       => 'unsafe_ai_output',
					'violations' => array_slice( $violations, 0, 10 ),
				)
			);
		}
	}

	/**
	 * Whether two snapshots have identical editable source and JS mode.
	 *
	 * @param array $left  First snapshot.
	 * @param array $right Second snapshot.
	 * @return bool
	 */
	public static function snapshots_equal( array $left, array $right ): bool {
		foreach ( array( 'html', 'customHead', 'css', 'js' ) as $key ) {
			if ( (string) ( $left[ $key ] ?? '' ) !== (string) ( $right[ $key ] ?? '' ) ) {
				return false;
			}
		}
		return ( 'module' === ( $left['jsMode'] ?? 'classic' ) ) === ( 'module' === ( $right['jsMode'] ?? 'classic' ) );
	}

	/**
	 * Return findings present more often in the candidate than the baseline.
	 *
	 * @param array $before Baseline findings.
	 * @param array $after  Candidate findings.
	 * @return array
	 */
	private static function added_findings( array $before, array $after ): array {
		$counts = array_count_values( $before );
		$added  = array();
		foreach ( $after as $finding ) {
			if ( ! empty( $counts[ $finding ] ) ) {
				--$counts[ $finding ];
				continue;
			}
			$added[] = $finding;
		}
		return $added;
	}

	/**
	 * Collect normalized unsafe constructs from an HTML fragment.
	 *
	 * @param string $source HTML fragment.
	 * @return array
	 */
	private static function html_findings( string $source ): array {
		if ( '' === trim( $source ) ) {
			return array();
		}
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<?xml encoding="utf-8" ?><div data-kayzart-ai-policy-root="1">' . $source . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return array( 'unparseable HTML fragment' );
		}

		$findings = array();
		$elements = $document->getElementsByTagName( '*' );
		foreach ( $elements as $element ) {
			if ( ! $element instanceof \DOMElement || $element->hasAttribute( 'data-kayzart-ai-policy-root' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement uses tagName.
			$tag = strtolower( $element->tagName );
			if ( in_array( $tag, self::BLOCKED_TAGS, true ) ) {
				$findings[] = 'blocked element <' . $tag . '> ' . self::node_fingerprint( $document, $element );
			}
			if ( 'meta' === $tag && 'refresh' === strtolower( trim( $element->getAttribute( 'http-equiv' ) ) ) ) {
				$findings[] = 'meta refresh ' . self::node_fingerprint( $document, $element );
			}
			if ( 'style' === $tag ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement uses textContent.
				foreach ( self::css_findings( (string) $element->textContent ) as $finding ) {
					$findings[] = 'style element ' . $finding;
				}
			}

			$attributes = array();
			foreach ( $element->attributes as $attribute ) {
				$attributes[] = array( strtolower( $attribute->name ), (string) $attribute->value );
			}
			foreach ( $attributes as $attribute ) {
				$name  = $attribute[0];
				$value = $attribute[1];
				if ( 0 === strpos( $name, 'on' ) ) {
					$findings[] = sprintf( 'inline event attribute <%s %s="%s">', $tag, $name, self::compact( $value ) );
				}
				if ( 'style' === $name ) {
					foreach ( self::css_findings( $value ) as $finding ) {
						$findings[] = sprintf( 'style attribute on <%s>: %s', $tag, $finding );
					}
				}
				if ( in_array( $name, self::URL_ATTRIBUTES, true ) && self::attribute_value_is_executable( $name, $value ) ) {
					$findings[] = sprintf( 'executable URL in <%s %s>', $tag, $name );
				}
				if ( in_array( $name, self::RESOURCE_ATTRIBUTES, true ) && self::resource_value_is_remote( $name, $value ) ) {
					$findings[] = sprintf( 'remote resource in <%s %s="%s">', $tag, $name, self::compact( $value ) );
				}
				if ( ( 'action' === $name || 'formaction' === $name ) && self::is_remote_url( $value ) ) {
					$findings[] = sprintf( 'external form action in <%s %s="%s">', $tag, $name, self::compact( $value ) );
				}
				if ( 'link' === $tag && 'href' === $name && self::is_remote_url( $value ) ) {
					$findings[] = 'remote link resource ' . self::compact( $value );
				}
			}
		}
		return $findings;
	}

	/**
	 * Collect unsafe CSS imports and resource URLs.
	 *
	 * @param string $source CSS source.
	 * @return array
	 */
	private static function css_findings( string $source ): array {
		$normalized = self::normalize_css( $source );
		$findings   = array();
		if ( preg_match_all( '/@import\b[^;]*(?:;|$)/i', $normalized, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				$findings[] = 'CSS @import ' . self::compact( $match );
			}
		}
		if ( preg_match_all( '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^\)]*))\s*\)/i', $normalized, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$url = '' !== ( $match[1] ?? '' ) ? $match[1] : ( '' !== ( $match[2] ?? '' ) ? $match[2] : ( $match[3] ?? '' ) );
				if ( self::is_remote_url( $url ) || self::is_executable_url( $url ) ) {
					$findings[] = 'CSS url() ' . self::compact( $url );
				}
			}
		}
		return $findings;
	}

	/**
	 * Normalize comments and ASCII CSS escapes used to obscure schemes.
	 *
	 * @param string $source CSS source.
	 * @return string
	 */
	private static function normalize_css( string $source ): string {
		$source = preg_replace( '!/\*.*?\*/!s', '', $source );
		$source = preg_replace_callback(
			'/\\\\([0-9a-fA-F]{1,6})\s?/',
			static function ( $escape ) {
				$code = hexdec( $escape[1] );
				return $code > 0 && $code < 128 ? chr( $code ) : $escape[0];
			},
			(string) $source
		);
		return (string) preg_replace( '/\\\\([^\r\n0-9a-fA-F])/', '$1', (string) $source );
	}

	/**
	 * Whether a URL uses an executable/data scheme.
	 *
	 * @param string $url URL value.
	 * @return bool
	 */
	private static function is_executable_url( string $url ): bool {
		$normalized = strtolower( preg_replace( '/[\x00-\x20\x7f]+/', '', html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return 1 === preg_match( '/^(?:javascript|vbscript|data):/', $normalized );
	}

	/**
	 * Whether a URL references a remote origin.
	 *
	 * @param string $url URL value.
	 * @return bool
	 */
	private static function is_remote_url( string $url ): bool {
		$normalized = strtolower( preg_replace( '/[\x00-\x20\x7f]+/', '', html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return 0 === strpos( $normalized, '//' ) || 1 === preg_match( '/^[a-z][a-z0-9+.-]*:/', $normalized );
	}

	/**
	 * Check a resource attribute, including each candidate in srcset.
	 *
	 * @param string $name  Attribute name.
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private static function resource_value_is_remote( string $name, string $value ): bool {
		if ( 'srcset' !== $name ) {
			return self::is_remote_url( $value );
		}
		foreach ( explode( ',', $value ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ) );
			if ( ! empty( $parts[0] ) && self::is_remote_url( (string) $parts[0] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check URL attributes for executable schemes, including srcset entries.
	 *
	 * @param string $name  Attribute name.
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private static function attribute_value_is_executable( string $name, string $value ): bool {
		if ( 'srcset' !== $name ) {
			return self::is_executable_url( $value );
		}
		foreach ( explode( ',', $value ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ) );
			if ( ! empty( $parts[0] ) && self::is_executable_url( (string) $parts[0] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stable compact fingerprint for a prohibited element including contents.
	 *
	 * @param \DOMDocument $document Parsed document.
	 * @param \DOMElement  $element  Prohibited element.
	 * @return string
	 */
	private static function node_fingerprint( \DOMDocument $document, \DOMElement $element ): string {
		return hash( 'sha256', (string) $document->saveHTML( $element ) );
	}

	/**
	 * Compact a value for non-secret policy diagnostics.
	 *
	 * @param string $value Diagnostic value.
	 * @return string
	 */
	private static function compact( string $value ): string {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		return mb_strlen( $value ) > 120 ? mb_substr( $value, 0, 117 ) . '...' : $value;
	}
}
