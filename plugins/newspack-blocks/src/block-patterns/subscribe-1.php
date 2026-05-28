<?php
/**
 * Subscribe Pattern 1.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => __( 'Subscribe Pattern 1', 'newspack-blocks' ),
	'content'       => "<!-- wp:media-text {\"align\":\"wide\",\"mediaType\":\"image\",\"verticalAlignment\":\"center\",\"imageFill\":true,\"style\":{\"color\":{\"background\":\"#f0f0f0\"}}} -->\n<div class=\"wp-block-media-text alignwide is-stacked-on-mobile is-vertically-aligned-center is-image-fill has-background\" style=\"background-color:#f0f0f0\"><figure class=\"wp-block-media-text__media\" style=\"background-image:url(" . $subscribe_image . ');background-position:50% 50%"><img src="' . $subscribe_image . "\" alt=\"\"/></figure><div class=\"wp-block-media-text__content\"><!-- wp:group {\"metadata\":{\"name\":\"Text\"},\"style\":{\"spacing\":{\"blockGap\":\"var:preset|spacing|40\",\"padding\":{\"top\":\"var:preset|spacing|80\",\"bottom\":\"var:preset|spacing|80\"}}},\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\",\"justifyContent\":\"stretch\"}} -->\n<div class=\"wp-block-group\" style=\"padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)\"><!-- wp:group {\"metadata\":{\"name\":\"Intro\"},\"style\":{\"spacing\":{\"blockGap\":\"var:preset|spacing|20\"}},\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading {\"level\":4,\"metadata\":{\"name\":\"Title\"}} -->\n<h4 class=\"wp-block-heading\">" . $subscribe_title . "</h4>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph {\"metadata\":{\"name\":\"Description\"}} -->\n<p>" . $subscribe_desc . "</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n<!-- wp:newspack-newsletters/subscribe {\"lists\":[],\"className\":\"is-style-modern\",\"backgroundColor\":\"#1e1e1e\"} /--></div>\n<!-- /wp:group --></div></div>\n<!-- /wp:media-text -->",
	'viewportWidth' => 910,
	'categories'    => array( 'newspack-subscribe' ),
);
