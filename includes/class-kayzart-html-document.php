<?php
/**
 * HTML document helpers for KayzArt editor content.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles display-only body wrappers and persisted body attributes.
 */
class Html_Document {

	const BODY_ATTRS_META_KEY = '_kayzart_body_attrs';

	/**
	 * Build HTML shown in the editor from stored content and body attrs.
	 *
	 * @param string $post_content Stored post content.
	 * @param string $body_attrs   Stored body attributes.
	 * @return string
	 */
	public static function build_editor_html( string $post_content, string $body_attrs ): string {
		$body_attrs = self::sanitize_body_attrs_string( $body_attrs );
		if ( '' === $body_attrs ) {
			return $post_content;
		}

		return '<body ' . $body_attrs . '>' . $post_content . '</body>';
	}

	/**
	 * Split editor HTML into body inner content and serialized body attrs.
	 *
	 * @param string $html Editor HTML.
	 * @return array{content:string,body_attrs:string,has_body:bool}
	 */
	public static function split_editor_html( string $html ): array {
		if ( false === stripos( $html, '<body' ) ) {
			return array(
				'content'    => $html,
				'body_attrs' => '',
				'has_body'   => false,
			);
		}

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array(
				'content'    => $html,
				'body_attrs' => '',
				'has_body'   => false,
			);
		}

		$bodies = $document->getElementsByTagName( 'body' );
		if ( 0 >= $bodies->length ) {
			return array(
				'content'    => $html,
				'body_attrs' => '',
				'has_body'   => false,
			);
		}

		$body = $bodies->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return array(
				'content'    => $html,
				'body_attrs' => '',
				'has_body'   => false,
			);
		}

		return array(
			'content'    => self::serialize_children( $document, $body ),
			'body_attrs' => self::serialize_attributes( $body ),
			'has_body'   => true,
		);
	}

	/**
	 * Build a standalone template body attribute string.
	 *
	 * @param int    $post_id       KayzArt post ID.
	 * @param string $default_class Class passed to body_class().
	 * @return string
	 */
	public static function build_standalone_body_attributes( int $post_id, string $default_class ): string {
		$stored_attrs = self::parse_attrs_string( (string) get_post_meta( $post_id, self::BODY_ATTRS_META_KEY, true ) );
		$stored_class = isset( $stored_attrs['class'] ) ? $stored_attrs['class'] : '';
		unset( $stored_attrs['class'] );

		$classes = get_body_class( trim( $default_class . ' ' . $stored_class ) );
		$attrs   = array( 'class' => implode( ' ', array_unique( array_filter( $classes ) ) ) );

		foreach ( $stored_attrs as $name => $value ) {
			$attrs[ $name ] = $value;
		}

		return self::serialize_attrs_array_for_output( $attrs );
	}

	/**
	 * Extract body class tokens from stored attrs.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return array<int,string>
	 */
	public static function get_stored_body_classes( int $post_id ): array {
		$attrs = self::parse_attrs_string( (string) get_post_meta( $post_id, self::BODY_ATTRS_META_KEY, true ) );
		if ( empty( $attrs['class'] ) ) {
			return array();
		}

		$classes = preg_split( '/\s+/', trim( $attrs['class'] ) );
		if ( ! is_array( $classes ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_html_class', $classes ) ) );
	}

	/**
	 * Serialize element children.
	 *
	 * @param \DOMDocument $document Document instance.
	 * @param \DOMElement  $element  Parent element.
	 * @return string
	 */
	private static function serialize_children( \DOMDocument $document, \DOMElement $element ): string {
		$html = '';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument uses camelCase property names.
		foreach ( $element->childNodes as $child ) {
			$html .= $document->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Serialize attributes from a DOM element for storage.
	 *
	 * @param \DOMElement $element Element.
	 * @return string
	 */
	private static function serialize_attributes( \DOMElement $element ): string {
		$attrs = array();
		foreach ( $element->attributes as $attr ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument uses camelCase property names.
			$name = self::sanitize_attr_name( $attr->nodeName );
			if ( '' === $name ) {
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument uses camelCase property names.
			$attrs[ $name ] = (string) $attr->nodeValue;
		}

		$attrs = self::sanitize_body_attrs_array( $attrs );

		return self::normalize_attrs_string( self::serialize_attrs_array_for_storage( $attrs ) );
	}

	/**
	 * Parse a serialized attribute string through DOMDocument.
	 *
	 * @param string $attrs Attribute string.
	 * @return array<string,string>
	 */
	private static function parse_attrs_string( string $attrs ): array {
		$attrs = self::normalize_attrs_string( $attrs );
		if ( '' === $attrs ) {
			return array();
		}

		$split = self::split_editor_html( '<body ' . $attrs . '></body>' );
		if ( empty( $split['body_attrs'] ) || $split['body_attrs'] === $attrs ) {
			return self::parse_attrs_with_dom( $attrs );
		}

		return self::parse_attrs_with_dom( $split['body_attrs'] );
	}

	/**
	 * Parse attrs with DOMDocument without recursively normalizing.
	 *
	 * @param string $attrs Attribute string.
	 * @return array<string,string>
	 */
	private static function parse_attrs_with_dom( string $attrs ): array {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<!doctype html><html><body ' . $attrs . '></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array();
		}

		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return array();
		}

		$parsed = array();
		foreach ( $body->attributes as $attr ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument uses camelCase property names.
			$name = self::sanitize_attr_name( $attr->nodeName );
			if ( '' !== $name ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument uses camelCase property names.
				$parsed[ $name ] = (string) $attr->nodeValue;
			}
		}

		return self::sanitize_body_attrs_array( $parsed );
	}

	/**
	 * Sanitize serialized body attributes.
	 *
	 * @param string $attrs Attribute string.
	 * @return string
	 */
	private static function sanitize_body_attrs_string( string $attrs ): string {
		$parsed = self::parse_attrs_string( $attrs );
		if ( empty( $parsed ) ) {
			return '';
		}

		return self::normalize_attrs_string( self::serialize_attrs_array_for_storage( $parsed ) );
	}

	/**
	 * Keep only body attributes that are safe to persist and render.
	 *
	 * @param array<string,string> $attrs Attributes.
	 * @return array<string,string>
	 */
	private static function sanitize_body_attrs_array( array $attrs ): array {
		$allowed = array();
		foreach ( $attrs as $name => $value ) {
			$name = self::sanitize_attr_name( (string) $name );
			if ( '' === $name || ! self::is_allowed_body_attr_name( $name ) ) {
				continue;
			}
			$allowed[ $name ] = (string) $value;
		}

		return $allowed;
	}

	/**
	 * Check whether a body attribute name is safe for all users.
	 *
	 * @param string $name Attribute name.
	 * @return bool
	 */
	private static function is_allowed_body_attr_name( string $name ): bool {
		if ( in_array( $name, array( 'class', 'id', 'lang', 'dir', 'role', 'title' ), true ) ) {
			return true;
		}

		return 0 === strpos( $name, 'data-' ) || 0 === strpos( $name, 'aria-' );
	}

	/**
	 * Normalize a serialized attributes string.
	 *
	 * @param string $attrs Attribute string.
	 * @return string
	 */
	private static function normalize_attrs_string( string $attrs ): string {
		$normalized = preg_replace( '/\s+/', ' ', $attrs );
		return trim( false === $normalized ? '' : $normalized );
	}

	/**
	 * Sanitize an HTML attribute name.
	 *
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function sanitize_attr_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		return preg_match( '/^[a-z_:][a-z0-9_:.:-]*$/', $name ) ? $name : '';
	}

	/**
	 * Serialize attrs for storage.
	 *
	 * @param array<string,string> $attrs Attributes.
	 * @return string
	 */
	private static function serialize_attrs_array_for_storage( array $attrs ): string {
		$parts = array();
		foreach ( $attrs as $name => $value ) {
			$name = self::sanitize_attr_name( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$parts[] = $name . '="' . esc_attr( (string) $value ) . '"';
		}
		return implode( ' ', $parts );
	}

	/**
	 * Serialize attrs for template output.
	 *
	 * @param array<string,string> $attrs Attributes.
	 * @return string
	 */
	private static function serialize_attrs_array_for_output( array $attrs ): string {
		return self::serialize_attrs_array_for_storage( $attrs );
	}
}
