<?php
/**
 * Donations Pattern 5.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => esc_html__( 'Donations Pattern 5', 'newspack-blocks' ),
	'content'       => "<!-- wp:media-text {\"align\":\"full\",\"mediaPosition\":\"right\",\"mediaType\":\"image\",\"verticalAlignment\":\"top\",\"imageFill\":true,\"className\":\"newspack-pattern donations__style-squeeze\"} -->\n<div class=\"wp-block-media-text alignfull has-media-on-the-right is-stacked-on-mobile is-vertically-aligned-top is-image-fill newspack-pattern donations__style-squeeze\"><div class=\"wp-block-media-text__content\"><!-- wp:group {\"metadata\":{\"name\":\"Text\"},\"style\":{\"dimensions\":{\"minHeight\":\"100vh\"}},\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\",\"verticalAlignment\":\"center\",\"justifyContent\":\"stretch\"}} -->\n<div class=\"wp-block-group\" style=\"min-height:100vh\"><!-- wp:group {\"metadata\":{\"name\":\"Donate\"},\"align\":\"full\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|80\",\"bottom\":\"var:preset|spacing|80\",\"left\":\"var:preset|spacing|80\",\"right\":\"var:preset|spacing|80\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group alignfull\" style=\"padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80)\"><!-- wp:group {\"metadata\":{\"name\":\"Intro\"},\"style\":{\"spacing\":{\"blockGap\":\"var:preset|spacing|20\"}},\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\",\"justifyContent\":\"stretch\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading {\"metadata\":{\"name\":\"Title\"}} -->\n<h2 class=\"wp-block-heading\">" . $donations_title . "</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph {\"metadata\":{\"name\":\"Description\"}} -->\n<p>" . $donations_desc . "</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n<!-- wp:newspack-blocks/donate {\"className\":\"is-style-modern\",\"buttonColor\":\"#6c6c6c\"} /--></div>\n<!-- /wp:group --></div>\n<!-- /wp:group --></div><figure class=\"wp-block-media-text__media\" style=\"background-image:url(" . $donations_image . ');background-position:50% 50%"><img src="' . $donations_image . "\" alt=\"\"/></figure></div>\n<!-- /wp:media-text -->",
	'viewportWidth' => 910,
	'categories'    => array( 'newspack-donations' ),
);
