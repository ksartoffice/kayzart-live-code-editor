<?php
/**
 * Manual end-to-end PoC for Ai_Client_Wp and Ai_Agent.
 *
 * Run from a WordPress installation with an AI provider configured:
 * wp eval-file wp-content/plugins/kayzart-live-code-editor/tests/manual/ai-agent-poc.php
 *
 * @package KayzArt
 */

use KayzArt\Ai_Agent;
use KayzArt\Ai_Client_Wp;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$client = new Ai_Client_Wp();
if ( ! $client->is_available() ) {
	throw new RuntimeException( 'Kayzart WordPress AI Client adapter is unavailable.' );
}

$events = array();
$agent  = new Ai_Agent(
	$client,
	array(
		'emit' => static function ( array $event ) use ( &$events ) {
			$events[] = $event;
		},
	)
);
$result = $agent->run(
	array(
		'editorMode' => 'normal',
		'prompt'     => 'Change the exact heading text Hello to World. Make no other changes.',
		'html'       => '<h1>Hello</h1>',
		'customHead' => '',
		'css'        => '',
		'js'         => '',
		'jsMode'     => 'classic',
	)
);

if ( '<h1>World</h1>' !== $result['snapshot']['html'] ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only diagnostic text.
	throw new RuntimeException( 'Unexpected edited HTML: ' . $result['snapshot']['html'] );
}

$output = array(
	'ok'       => true,
	'snapshot' => $result['snapshot'],
	'summary'  => $result['summary'],
	'usage'    => $result['usage'],
	'events'   => $events,
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only JSON diagnostics.
echo wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
