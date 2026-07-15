<?php
/**
 * Signals that the agent loop was canceled.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a cancellation was requested while the agent loop was running.
 */
class Ai_Agent_Canceled extends \Exception {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'The AI edit job was canceled.' );
	}
}
