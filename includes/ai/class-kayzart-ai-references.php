<?php
/**
 * Reference pages fetched from URLs in an AI instruction.
 *
 * The model cannot open a URL. Given one with "use this site as a reference",
 * it used to build from the URL's wording and whatever it remembered about the
 * brand, and the result read as though the page had been consulted. Fetching
 * the page here, before the first model call, gives it the real text instead.
 *
 * This runs no model and makes no decisions: the URLs are the ones the person
 * typed, fetched once each, so the request stays one the person explicitly
 * made. Nothing is crawled and no link on a fetched page is followed.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts URLs from an instruction and fetches them as plain-text references.
 */
class Ai_References {

	/** Default number of URLs fetched per request. */
	const MAX_URLS = 3;

	/**
	 * Characters kept across all pages of one request, shared among them.
	 *
	 * Everything a page contributes to the prompt counts -- title, description,
	 * text and image entries -- so no field of a fetched page can grow the
	 * request past this, however the page is written.
	 */
	const MAX_TOTAL_CHARS = 12000;

	/** Image URLs listed per page. */
	const MAX_IMAGES = 10;

	/** Characters kept from a page title. */
	const MAX_TITLE_CHARS = 200;

	/** Characters kept from a page description. */
	const MAX_DESCRIPTION_CHARS = 500;

	/** Longest image URL listed; longer ones are skipped rather than cut, since a cut URL is broken. */
	const MAX_IMAGE_URL_CHARS = 500;

	/** Characters kept from an image's alt text. */
	const MAX_IMAGE_ALT_CHARS = 120;

	/** Seconds allowed for one fetch. */
	const FETCH_TIMEOUT_SECONDS = 10;

	/** Bytes read from one response. */
	const MAX_RESPONSE_BYTES = 2097152;

	/** Content types that can carry readable text. */
	const TEXT_CONTENT_TYPES = array( 'text/html', 'application/xhtml+xml', 'text/plain' );

	/**
	 * Elements whose content is never reference text.
	 *
	 * <header> is kept: inside an article it holds the title, and the site
	 * header's menu is already dropped with its <nav>.
	 */
	const SKIPPED_TAGS = array( 'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed', 'form', 'select', 'button', 'nav', 'aside' );

	/** Elements that start a new line of text. */
	const BLOCK_TAGS = array( 'address', 'article', 'blockquote', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'li', 'main', 'ol', 'p', 'pre', 'section', 'table', 'td', 'th', 'tr', 'ul' );

	/**
	 * Extract the URLs an instruction refers to.
	 *
	 * URLs are matched as ASCII only, so one typed straight into Japanese text,
	 * as in "https://example.com/aboutを参考に", ends where the URL does.
	 *
	 * @param string $prompt User instruction.
	 * @return array<int,string> Distinct http(s) URLs in order of appearance.
	 */
	public static function extract_urls( string $prompt ): array {
		/**
		 * Filter how many URLs one AI request may fetch.
		 *
		 * @param int $max Maximum number of URLs.
		 */
		$max = (int) apply_filters( 'kayzart_ai_reference_max_urls', self::MAX_URLS );
		if ( $max <= 0 || ! preg_match_all( '~https?://[A-Za-z0-9\-._\~:/?#\[\]@!$&\'()*+,;=%]+~i', $prompt, $matches ) ) {
			return array();
		}

		$urls = array();
		foreach ( $matches[0] as $candidate ) {
			$url = self::trim_trailing_punctuation( $candidate );
			if ( '' === (string) wp_parse_url( $url, PHP_URL_HOST ) || in_array( $url, $urls, true ) ) {
				continue;
			}
			$urls[] = $url;
			if ( count( $urls ) >= $max ) {
				break;
			}
		}
		return $urls;
	}

	/**
	 * Fetch one URL and reduce it to reference text.
	 *
	 * Failures are returned rather than thrown: an unreadable page is still
	 * something the model must be told about, so it does not guess the content.
	 *
	 * The budget covers everything the page contributes, in order of value:
	 * title and description first, then the text, then as many image entries as
	 * still fit. Self::size() measures the result the same way.
	 *
	 * @param string $url       URL typed by the user.
	 * @param int    $max_chars Character budget for this page.
	 * @return array{url:string,status:string,title:string,description:string,text:string,images:array,truncated:bool,error:string}
	 */
	public static function fetch( string $url, int $max_chars = self::MAX_TOTAL_CHARS ): array {
		$reference = self::empty_reference( $url );
		if ( $max_chars <= 0 ) {
			$reference['error'] = 'Not read: the reference text limit for this request was already reached.';
			return $reference;
		}

		// wp_safe_remote_get() refuses private and loopback addresses, so a URL
		// typed into an instruction cannot reach the site's internal network.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::FETCH_TIMEOUT_SECONDS,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'user-agent'          => 'Kayzart/' . ( defined( 'KAYZART_VERSION' ) ? KAYZART_VERSION : '0' ) . ' (+https://kayzart.com/)',
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.8' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$reference['error'] = 'The page could not be fetched.';
			return $reference;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$reference['error'] = 'The server answered HTTP ' . $code . '.';
			return $reference;
		}

		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$media_type   = trim( explode( ';', $content_type )[0] );
		if ( '' !== $media_type && ! in_array( $media_type, self::TEXT_CONTENT_TYPES, true ) ) {
			$reference['error'] = 'The URL is not a web page (' . $media_type . ').';
			return $reference;
		}

		$body = self::to_utf8( (string) wp_remote_retrieve_body( $response ), $content_type );
		if ( 'text/plain' === $media_type ) {
			$extracted = array(
				'title'       => '',
				'description' => '',
				'text'        => trim( (string) preg_replace( '/[ \t]+/', ' ', $body ) ),
				'images'      => array(),
			);
		} else {
			$extracted = self::extract( $body, self::final_url( $response, $url ) );
		}
		// A title alone is what a page rendered by JavaScript in the browser
		// serves, and it says nothing about the content the person pointed at.
		// Reporting it as read would let the model fill the page in from the
		// title, so only body text counts.
		if ( '' === $extracted['text'] ) {
			$reference['error'] = 'The page has no readable text. It may be built by JavaScript in the browser.';
			return $reference;
		}

		$reference['title']       = $extracted['title'];
		$reference['description'] = $extracted['description'];
		$remaining                = $max_chars - mb_strlen( $reference['title'] ) - mb_strlen( $reference['description'] );
		if ( $remaining <= 0 ) {
			$reference['title']       = '';
			$reference['description'] = '';
			$reference['error']       = 'Not read: the reference text limit for this request was already reached.';
			return $reference;
		}
		if ( mb_strlen( $extracted['text'] ) > $remaining ) {
			$reference['text']      = rtrim( mb_substr( $extracted['text'], 0, $remaining ) );
			$reference['truncated'] = true;
		} else {
			$reference['text'] = $extracted['text'];
		}
		$remaining -= mb_strlen( $reference['text'] );
		foreach ( $extracted['images'] as $image ) {
			$length = self::image_size( $image );
			if ( $length > $remaining ) {
				break;
			}
			$reference['images'][] = $image;
			$remaining            -= $length;
		}
		$reference['status'] = 'ok';
		return $reference;
	}

	/**
	 * Count the characters a reference contributes to the prompt.
	 *
	 * @param array $reference Reference from self::fetch().
	 * @return int
	 */
	public static function size( array $reference ): int {
		$size = 0;
		foreach ( array( 'title', 'description', 'text' ) as $key ) {
			$size += isset( $reference[ $key ] ) ? mb_strlen( (string) $reference[ $key ] ) : 0;
		}
		$images = isset( $reference['images'] ) && is_array( $reference['images'] ) ? $reference['images'] : array();
		foreach ( $images as $image ) {
			$size += self::image_size( is_array( $image ) ? $image : array() );
		}
		return $size;
	}

	/**
	 * Count the characters one image entry contributes.
	 *
	 * @param array $image Image entry with url and alt.
	 * @return int
	 */
	private static function image_size( array $image ): int {
		return mb_strlen( isset( $image['url'] ) ? (string) $image['url'] : '' ) + mb_strlen( isset( $image['alt'] ) ? (string) $image['alt'] : '' );
	}

	/**
	 * Reduce an HTML document to its title, description, readable text and images.
	 *
	 * Text is read from <main> or <article> when the page has one, so menus and
	 * site chrome around the content do not eat the text budget. Headings keep a
	 * Markdown-style marker and list items a dash, which is enough structure for
	 * the model to follow the page's sections.
	 *
	 * @param string $html     UTF-8 HTML document.
	 * @param string $base_url URL the document was fetched from, for resolving image paths.
	 * @return array{title:string,description:string,text:string,images:array<int,array{url:string,alt:string}>}
	 */
	public static function extract( string $html, string $base_url ): array {
		$result = array(
			'title'       => '',
			'description' => '',
			'text'        => '',
			'images'      => array(),
		);
		if ( '' === trim( $html ) ) {
			return $result;
		}

		// libxml ends a <script> at the first "</" it meets, so a script holding
		// markup in a string -- prevArrow: '<button></button>' -- leaks the rest
		// of its code into the text. Browsers end it only at </script>, so cut
		// scripts and styles out that way before parsing.
		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html );

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML declaration is the documented way to make loadHTML() read the
		// bytes as UTF-8 instead of ISO-8859-1.
		$loaded = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return $result;
		}

		$titles = $document->getElementsByTagName( 'title' );
		if ( $titles->length > 0 ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode uses textContent.
			$result['title'] = self::clip( self::squash( (string) $titles->item( 0 )->textContent ), self::MAX_TITLE_CHARS );
		}
		foreach ( $document->getElementsByTagName( 'meta' ) as $meta ) {
			$name = strtolower( $meta->getAttribute( 'name' ) . $meta->getAttribute( 'property' ) );
			if ( in_array( $name, array( 'description', 'og:description' ), true ) && '' === $result['description'] ) {
				$result['description'] = self::clip( self::squash( $meta->getAttribute( 'content' ) ), self::MAX_DESCRIPTION_CHARS );
			}
		}

		$root = null;
		foreach ( array( 'main', 'article', 'body' ) as $tag ) {
			$nodes = $document->getElementsByTagName( $tag );
			if ( $nodes->length > 0 ) {
				$root = $nodes->item( 0 );
				break;
			}
		}
		if ( null === $root ) {
			return $result;
		}

		$lines   = array();
		$current = '';
		self::collect_text( $root, $lines, $current );
		self::flush_line( $lines, $current );
		// Pages without <main> repeat their chrome -- a login link in the header
		// and again in a drawer -- and each copy spends the text budget. A line
		// seen once already tells the model nothing new.
		$result['text']   = implode( "\n", array_values( array_unique( $lines ) ) );
		$result['images'] = self::collect_images( $root, $base_url );
		return $result;
	}

	/**
	 * Walk a subtree, turning block elements into lines of text.
	 *
	 * @param \DOMNode          $node    Current node.
	 * @param array<int,string> $lines   Collected lines.
	 * @param string            $current Text of the line being built.
	 * @return void
	 */
	private static function collect_text( \DOMNode $node, array &$lines, string &$current ): void {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode uses camelCase property names.
		$children = $node->childNodes;
		foreach ( $children as $child ) {
			$type = $child->nodeType;
			if ( XML_TEXT_NODE === $type || XML_CDATA_SECTION_NODE === $type ) {
				$current .= $child->nodeValue;
				continue;
			}
			if ( XML_ELEMENT_NODE !== $type ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( in_array( $tag, self::SKIPPED_TAGS, true ) ) {
				continue;
			}
			if ( 'br' === $tag ) {
				self::flush_line( $lines, $current );
				continue;
			}
			if ( ! in_array( $tag, self::BLOCK_TAGS, true ) ) {
				// No space is added around inline elements: Japanese text has no
				// word spaces, so one would split a sentence at every <strong>.
				self::collect_text( $child, $lines, $current );
				continue;
			}

			self::flush_line( $lines, $current );
			$first = count( $lines );
			self::collect_text( $child, $lines, $current );
			self::flush_line( $lines, $current );
			$marker = '';
			if ( preg_match( '/^h([1-6])$/', $tag, $level ) ) {
				$marker = str_repeat( '#', min( 3, (int) $level[1] ) ) . ' ';
			} elseif ( 'li' === $tag ) {
				$marker = '- ';
			}
			if ( '' !== $marker && isset( $lines[ $first ] ) ) {
				$lines[ $first ] = $marker . $lines[ $first ];
			}
		}
	}

	/**
	 * Close the line being built, dropping it when it holds only whitespace.
	 *
	 * @param array<int,string> $lines   Collected lines.
	 * @param string            $current Text of the line being built.
	 * @return void
	 */
	private static function flush_line( array &$lines, string &$current ): void {
		$text    = self::squash( $current );
		$current = '';
		if ( '' !== $text ) {
			$lines[] = $text;
		}
	}

	/**
	 * List the images in a subtree as absolute URLs.
	 *
	 * @param \DOMNode $root     Content root.
	 * @param string   $base_url Document URL.
	 * @return array<int,array{url:string,alt:string}>
	 */
	private static function collect_images( \DOMNode $root, string $base_url ): array {
		if ( ! $root instanceof \DOMElement ) {
			return array();
		}
		$images = array();
		$seen   = array();
		foreach ( $root->getElementsByTagName( 'img' ) as $image ) {
			// Lazy loaders keep the real address in data-src and a placeholder in src.
			$source = '' !== $image->getAttribute( 'data-src' ) ? $image->getAttribute( 'data-src' ) : $image->getAttribute( 'src' );
			$source = trim( $source );
			if ( '' === $source || 0 === strpos( $source, 'data:' ) ) {
				continue;
			}
			$url = \WP_Http::make_absolute_url( $source, $base_url );
			// SVGs on a page are almost always icons -- phone, mail, arrows -- and
			// would fill the short list before any photograph.
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( ! preg_match( '~^https?://~i', $url ) || isset( $seen[ $url ] ) || preg_match( '/\.svg$/i', $path ) || mb_strlen( $url ) > self::MAX_IMAGE_URL_CHARS ) {
				continue;
			}
			$seen[ $url ] = true;
			$images[]     = array(
				'url' => $url,
				'alt' => self::clip( self::squash( $image->getAttribute( 'alt' ) ), self::MAX_IMAGE_ALT_CHARS ),
			);
			if ( count( $images ) >= self::MAX_IMAGES ) {
				break;
			}
		}
		return $images;
	}

	/**
	 * Convert a response body to UTF-8.
	 *
	 * Plenty of Japanese sites still serve Shift_JIS or EUC-JP, declared either
	 * in the Content-Type header or only in a <meta> tag.
	 *
	 * @param string $body         Raw response body.
	 * @param string $content_type Lower-cased Content-Type header.
	 * @return string
	 */
	private static function to_utf8( string $body, string $content_type ): string {
		$charset = '';
		if ( preg_match( '/charset\s*=\s*["\']?([A-Za-z0-9_\-]+)/', $content_type, $match ) ) {
			$charset = $match[1];
		} elseif ( preg_match( '/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9_\-]+)/i', substr( $body, 0, 4096 ), $match ) ) {
			$charset = $match[1];
		}
		$charset = strtolower( $charset );
		$aliases = array(
			'shift_jis'   => 'SJIS-win',
			'shift-jis'   => 'SJIS-win',
			'sjis'        => 'SJIS-win',
			'x-sjis'      => 'SJIS-win',
			'windows-31j' => 'SJIS-win',
			'cp932'       => 'SJIS-win',
			'euc-jp'      => 'eucJP-win',
			'x-euc-jp'    => 'eucJP-win',
		);
		if ( isset( $aliases[ $charset ] ) ) {
			$charset = $aliases[ $charset ];
		}
		if ( '' !== $charset && 'utf-8' !== $charset && 'utf8' !== $charset && in_array( strtolower( $charset ), array_map( 'strtolower', mb_list_encodings() ), true ) ) {
			$converted = mb_convert_encoding( $body, 'UTF-8', $charset );
			$body      = is_string( $converted ) ? $converted : $body;
			// The document still declares its old charset, which would make
			// loadHTML() decode the converted bytes a second time.
			$body = (string) preg_replace( '/(<meta[^>]+charset\s*=\s*["\']?)[A-Za-z0-9_\-]+/i', '${1}utf-8', $body, 1 );
		}
		return wp_check_invalid_utf8( $body, true );
	}

	/**
	 * Return the URL a response finally came from, after redirects.
	 *
	 * @param array  $response     HTTP API response.
	 * @param string $original_url Requested URL.
	 * @return string
	 */
	private static function final_url( array $response, string $original_url ): string {
		$http = isset( $response['http_response'] ) ? $response['http_response'] : null;
		if ( is_object( $http ) && method_exists( $http, 'get_response_object' ) ) {
			$object = $http->get_response_object();
			if ( is_object( $object ) && isset( $object->url ) && is_string( $object->url ) && '' !== $object->url ) {
				return $object->url;
			}
		}
		return $original_url;
	}

	/**
	 * Strip punctuation a sentence put after a URL.
	 *
	 * @param string $url Matched URL.
	 * @return string
	 */
	private static function trim_trailing_punctuation( string $url ): string {
		while ( '' !== $url ) {
			$last = substr( $url, -1 );
			if ( false !== strpos( '.,;:!?\'"', $last ) ) {
				$url = substr( $url, 0, -1 );
				continue;
			}
			// A closing bracket belongs to the URL only when the URL opened it.
			if ( ')' === $last && substr_count( $url, '(' ) < substr_count( $url, ')' ) ) {
				$url = substr( $url, 0, -1 );
				continue;
			}
			if ( ']' === $last && substr_count( $url, '[' ) < substr_count( $url, ']' ) ) {
				$url = substr( $url, 0, -1 );
				continue;
			}
			break;
		}
		return $url;
	}

	/**
	 * Cut text to a character limit.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private static function clip( string $text, int $limit ): string {
		return mb_strlen( $text ) > $limit ? rtrim( mb_substr( $text, 0, $limit ) ) : $text;
	}

	/**
	 * Collapse whitespace runs into single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function squash( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * A failed reference for a URL, filled in as the fetch succeeds.
	 *
	 * @param string $url Requested URL.
	 * @return array
	 */
	private static function empty_reference( string $url ): array {
		return array(
			'url'         => $url,
			'status'      => 'error',
			'title'       => '',
			'description' => '',
			'text'        => '',
			'images'      => array(),
			'truncated'   => false,
			'error'       => '',
		);
	}
}
