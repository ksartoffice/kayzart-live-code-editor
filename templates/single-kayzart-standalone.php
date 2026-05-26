<?php
/**
 * Standalone layout template for KayzArt.
 *
 * @package KayzArt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php wp_head(); ?>
	<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body <?php echo \KayzArt\Html_Document::build_standalone_body_attributes( (int) get_queried_object_id(), 'kayzart-layout-standalone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php wp_body_open(); ?>
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
	<?php wp_footer(); ?>
</body>
</html>
