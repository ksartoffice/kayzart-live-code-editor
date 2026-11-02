<?php
/**
 * Explicit local template registrations, independent of the library UI.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps translated catalog metadata separate from local package locations. */
class Template_Registry {

	/**
	 * Registrations cached per administrator locale for this request.
	 *
	 * @var array
	 */
	private static $entries = array();

	/**
	 * Return trusted registrations. Files and versions belong to each manifest.
	 *
	 * @return array Template ID to registration, including retired packages.
	 */
	public static function entries(): array {
		$locale = determine_locale();
		if ( ! isset( self::$entries[ $locale ] ) ) {
			self::$entries[ $locale ] = array(
				'business-motion-ja' => array(
					'title'       => __( 'Business Motion — Japanese', 'kayzart-live-code-editor' ),
					'description' => __( 'A bold business service page with expressive typography and motion you can adjust through HTML attributes.', 'kayzart-live-code-editor' ),
					'directory'   => KAYZART_PATH . 'assets/templates/free/business-motion-ja',
					'baseUrl'     => KAYZART_URL . 'assets/templates/free/business-motion-ja/',
					'locales'     => array( 'ja' => '日本語' ),
					'source'      => 'bundled',
					'tier'        => 'free',
					'sortOrder'   => 10,
					'retired'     => false,
				),
				'business-motion-en' => array(
					'title'       => __( 'Business Motion — English', 'kayzart-live-code-editor' ),
					'description' => __( 'A bold English business service page with editorial typography and motion you can adjust through HTML attributes.', 'kayzart-live-code-editor' ),
					'directory'   => KAYZART_PATH . 'assets/templates/free/business-motion-en',
					'baseUrl'     => KAYZART_URL . 'assets/templates/free/business-motion-en/',
					'locales'     => array( 'en' => 'English' ),
					'source'      => 'bundled',
					'tier'        => 'free',
					'sortOrder'   => 20,
					'retired'     => false,
				),
				'general-service-lp' => array(
					'title'       => __( 'General service landing page', 'kayzart-live-code-editor' ),
					'description' => __( 'A complete introduction to your products or services, with features, pricing, FAQs, and a contact section.', 'kayzart-live-code-editor' ),
					'directory'   => KAYZART_PATH . 'assets/templates/free/general-service-lp',
					'baseUrl'     => KAYZART_URL . 'assets/templates/free/general-service-lp/',
					'locales'     => array(
						'ja' => '日本語',
						'en' => 'English',
					),
					'source'      => 'bundled',
					'tier'        => 'free',
					'sortOrder'   => 30,
					'retired'     => true,
				),
			);
		}
		return self::$entries[ $locale ];
	}
}
