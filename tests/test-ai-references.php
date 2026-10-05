<?php
/**
 * Unit tests for fetching the pages an AI instruction links to.
 *
 * @package KayzArt
 */

use KayzArt\Ai_References;

/**
 * Verify URL extraction, fetching and text extraction.
 */
class Test_Kayzart_Ai_References extends WP_UnitTestCase {

	/**
	 * Requests seen by the HTTP mock.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private $requests = array();

	/** Remove the HTTP mock. */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'kayzart_ai_reference_max_urls' );
		$this->requests = array();
		parent::tear_down();
	}

	/**
	 * Answer every HTTP request with one canned response.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type Content-Type header.
	 * @param int    $code         HTTP status code.
	 * @return void
	 */
	private function mock_http( string $body, string $content_type = 'text/html; charset=UTF-8', int $code = 200 ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body, $content_type, $code ) {
				unset( $pre );
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'headers'  => array( 'content-type' => $content_type ),
					'body'     => $body,
					'response' => array(
						'code'    => $code,
						'message' => 200 === $code ? 'OK' : 'Error',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/** URLs end where the ASCII ends, so Japanese text after them is not swallowed. */
	public function test_extract_urls_from_japanese_text(): void {
		$prompt = "以下のサイトを参考にしてください\nhttps://ovdgolf.com/user_data/miura-top\n「https://ovdgolf.com/user_data/ryoma-topを参考」\n下の記事をどこかに入れて https://ovdgolf.com/blog/649/。";

		$this->assertSame(
			array(
				'https://ovdgolf.com/user_data/miura-top',
				'https://ovdgolf.com/user_data/ryoma-top',
				'https://ovdgolf.com/blog/649/',
			),
			Ai_References::extract_urls( $prompt )
		);
	}

	/** Sentence punctuation and unmatched brackets are not part of a URL. */
	public function test_extract_urls_trims_punctuation_and_duplicates(): void {
		$prompt = 'See (https://example.com/a), https://example.com/a. Also https://example.com/wiki/Foo_(bar)! And http://example.org/x?y=1;';

		$this->assertSame(
			array( 'https://example.com/a', 'https://example.com/wiki/Foo_(bar)', 'http://example.org/x?y=1' ),
			Ai_References::extract_urls( $prompt )
		);
		$this->assertSame( array(), Ai_References::extract_urls( 'No links here, only example.com and ftp://example.com/file.' ) );
	}

	/** Only the first URLs up to the limit are fetched, and sites can change the limit. */
	public function test_extract_urls_respects_the_limit(): void {
		$prompt = 'https://a.example/ https://b.example/ https://c.example/ https://d.example/';

		$this->assertCount( Ai_References::MAX_URLS, Ai_References::extract_urls( $prompt ) );

		add_filter( 'kayzart_ai_reference_max_urls', static fn() => 1 );
		$this->assertSame( array( 'https://a.example/' ), Ai_References::extract_urls( $prompt ) );

		add_filter( 'kayzart_ai_reference_max_urls', '__return_zero', 20 );
		$this->assertSame( array(), Ai_References::extract_urls( $prompt ) );
	}

	/** The main content is reduced to structured lines, without menus or scripts. */
	public function test_fetch_reduces_a_page_to_its_main_text(): void {
		$this->mock_http(
			'<!doctype html><html><head><title> Miura | OVD GOLF </title><meta name="description" content="Custom clubs."></head><body>'
			. '<nav><a href="/">Home</a><a href="/shop">Shop</a></nav>'
			. '<p>Outside main.</p>'
			. '<main><header><h1>三浦技研</h1></header><script>track()</script><style>.a{}</style>'
			. '<h2>Lineup</h2><ul><li>TC-102</li><li>CB-302</li></ul>'
			. '<p>鍛造アイアンの<strong>決定版</strong>です。</p>'
			. '<img src="/img/tc102.jpg" alt="TC-102"><img data-src="https://cdn.example/cb302.jpg" src="data:image/gif;base64,R0lGOD" alt="CB-302">'
			. '<aside>Sidebar ad</aside><button>カートに入れる</button></main></body></html>'
		);

		$reference = Ai_References::fetch( 'https://ovdgolf.com/user_data/miura-top' );

		$this->assertSame( 'ok', $reference['status'] );
		$this->assertSame( 'Miura | OVD GOLF', $reference['title'] );
		$this->assertSame( 'Custom clubs.', $reference['description'] );
		$this->assertSame( "# 三浦技研\n## Lineup\n- TC-102\n- CB-302\n鍛造アイアンの決定版です。", $reference['text'] );
		$this->assertSame(
			array(
				array(
					'url' => 'https://ovdgolf.com/img/tc102.jpg',
					'alt' => 'TC-102',
				),
				array(
					'url' => 'https://cdn.example/cb302.jpg',
					'alt' => 'CB-302',
				),
			),
			$reference['images']
		);
		$this->assertFalse( $reference['truncated'] );
		// Private and loopback addresses stay out of reach.
		$this->assertTrue( $this->requests[0]['args']['reject_unsafe_urls'] );
		$this->assertSame( 3, $this->requests[0]['args']['redirection'] );
		$this->assertStringStartsWith( 'Kayzart/', $this->requests[0]['args']['user-agent'] );
	}

	/**
	 * Pages without <main> are read from <body>, where a script holding markup
	 * in a string, repeated chrome and icon images must not crowd out the text.
	 */
	public function test_extract_survives_a_page_without_main(): void {
		$html = '<html><body>'
			. '<div class="header"><img src="/icons/tel.svg" alt="tel"><p>ログイン</p></div>'
			. '<script>$(".slider").slick({ prevArrow: \'<button class="prev"></button>\', slidesToShow: 3 });</script>'
			. '<h2>【特集】リョーマ ゴルフ</h2><img src="/img/maxima.jpg" alt="">'
			. '<div class="drawer"><p>ログイン</p></div></body></html>';

		$extracted = Ai_References::extract( $html, 'https://ovdgolf.com/user_data/ryoma-top' );

		$this->assertSame( "ログイン\n## 【特集】リョーマ ゴルフ", $extracted['text'] );
		$this->assertSame(
			array(
				array(
					'url' => 'https://ovdgolf.com/img/maxima.jpg',
					'alt' => '',
				),
			),
			$extracted['images']
		);
	}

	/** Shift_JIS pages declared only in a meta tag are read as Japanese text. */
	public function test_fetch_converts_shift_jis(): void {
		$html = '<html><head><meta charset="Shift_JIS"><title>りんご農園</title></head><body><main><h1>樹上完熟</h1><p>甘いりんごです。</p></main></body></html>';
		$this->mock_http( (string) mb_convert_encoding( $html, 'SJIS-win', 'UTF-8' ), 'text/html' );

		$reference = Ai_References::fetch( 'https://example.jp/' );

		$this->assertSame( 'りんご農園', $reference['title'] );
		$this->assertSame( "# 樹上完熟\n甘いりんごです。", $reference['text'] );
	}

	/** Text beyond the budget is cut and the cut is recorded. */
	public function test_fetch_truncates_to_the_budget(): void {
		$this->mock_http( '<main><p>' . str_repeat( 'あ', 50 ) . '</p></main>' );

		$reference = Ai_References::fetch( 'https://example.jp/', 20 );

		$this->assertSame( str_repeat( 'あ', 20 ), $reference['text'] );
		$this->assertTrue( $reference['truncated'] );

		$skipped = Ai_References::fetch( 'https://example.jp/', 0 );
		$this->assertSame( 'error', $skipped['status'] );
		$this->assertCount( 1, $this->requests );
	}

	/** Failures come back as error references the model can be told about. */
	public function test_fetch_reports_failures(): void {
		$this->mock_http( 'Not found', 'text/html', 404 );
		$missing = Ai_References::fetch( 'https://example.com/missing' );
		$this->assertSame( 'error', $missing['status'] );
		$this->assertSame( 'The server answered HTTP 404.', $missing['error'] );
		remove_all_filters( 'pre_http_request' );

		$this->mock_http( '%PDF-1.7', 'application/pdf' );
		$pdf = Ai_References::fetch( 'https://example.com/file.pdf' );
		$this->assertSame( 'The URL is not a web page (application/pdf).', $pdf['error'] );
		remove_all_filters( 'pre_http_request' );

		$this->mock_http( '<html><body><div id="app"></div><script src="/app.js"></script></body></html>' ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Fixture HTML for a fetched page.
		$empty = Ai_References::fetch( 'https://example.com/spa' );
		$this->assertStringContainsString( 'no readable text', $empty['error'] );
		remove_all_filters( 'pre_http_request' );

		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'timeout' );
			}
		);
		$offline = Ai_References::fetch( 'https://example.com/' );
		$this->assertSame( 'The page could not be fetched.', $offline['error'] );
	}
}
