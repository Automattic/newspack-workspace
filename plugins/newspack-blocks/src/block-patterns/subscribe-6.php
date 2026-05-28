<?php
/**
 * Subscribe Pattern 6.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => esc_html__( 'Subscribe Pattern 6', 'newspack-blocks' ),
	'content'       => "<!-- wp:group {\"metadata\":{\"name\":\"Subscribe\"},\"style\":{\"spacing\":{\"blockGap\":\"0\"}},\"className\":\"newspack-pattern subscribe__style-6\",\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\",\"justifyContent\":\"stretch\"}} -->\n<div class=\"wp-block-group newspack-pattern subscribe__style-6\"><!-- wp:image {\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"" . $subscribe_image . "\" alt=\"\"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:group {\"metadata\":{\"name\":\"Card\"},\"style\":{\"color\":{\"background\":\"#f0f0f0\"},\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|80\",\"bottom\":\"var:preset|spacing|80\",\"left\":\"var:preset|spacing|80\",\"right\":\"var:preset|spacing|80\"},\"blockGap\":\"var:preset|spacing|40\"}},\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group has-background\" style=\"background-color:#f0f0f0;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80)\"><!-- wp:group {\"metadata\":{\"name\":\"Intro\"},\"style\":{\"spacing\":{\"blockGap\":\"var:preset|spacing|30\"}},\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\",\"justifyContent\":\"stretch\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading {\"level\":3,\"metadata\":{\"name\":\"Title\"}} -->\n<h3 class=\"wp-block-heading\">" . $subscribe_title . "</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph {\"metadata\":{\"name\":\"Description\"}} -->\n<p>" . $subscribe_desc . "</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n<!-- wp:newspack-newsletters/subscribe {\"lists\":[],\"className\":\"is-style-modern\",\"backgroundColor\":\"#1e1e1e\"} /--></div>\n<!-- /wp:group --></div>\n<!-- /wp:group -->",
	'viewportWidth' => 782,
	'categories'    => array( 'newspack-subscribe' ),
);
