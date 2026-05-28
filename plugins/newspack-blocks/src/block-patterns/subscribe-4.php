<?php
/**
 * Subscribe Pattern 4.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => __( 'Subscribe Pattern 4', 'newspack-blocks' ),
	'content'       => "<!-- wp:group {\"metadata\":{\"name\":\"Subscribe\"},\"align\":\"full\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group alignfull\"><!-- wp:group {\"metadata\":{\"name\":\"Intro\"},\"style\":{\"spacing\":{\"blockGap\":\"var:preset|spacing|40\"}},\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\",\"justifyContent\":\"center\"}} -->\n<div class=\"wp-block-group\"><!-- wp:image {\"width\":\"72px\",\"height\":\"72px\",\"scale\":\"cover\",\"sizeSlug\":\"large\",\"linkDestination\":\"none\",\"align\":\"center\",\"className\":\"is-style-rounded\"} -->\n<figure class=\"wp-block-image aligncenter size-large is-resized is-style-rounded\"><img src=\"" . $subscribe_image . "\" alt=\"\" style=\"object-fit:cover;width:72px;height:72px\"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:group {\"metadata\":{\"name\":\"Title + Description\"},\"style\":{\"spacing\":{\"blockGap\":\"var:preset|spacing|20\"}},\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\",\"justifyContent\":\"stretch\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading {\"textAlign\":\"center\",\"metadata\":{\"name\":\"Title\"}} -->\n<h2 class=\"wp-block-heading has-text-align-center\">" . $subscribe_title . "</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph {\"align\":\"center\",\"metadata\":{\"name\":\"Description\"}} -->\n<p class=\"has-text-align-center\">" . $subscribe_desc . "</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group --></div>\n<!-- /wp:group -->\n\n<!-- wp:newspack-newsletters/subscribe {\"lists\":[],\"className\":\"is-style-modern\",\"backgroundColor\":\"#1e1e1e\"} /--></div>\n<!-- /wp:group -->",
	'viewportWidth' => 910,
	'categories'    => array( 'newspack-subscribe' ),
);
