<?php
/**
 * Subscribe Pattern 2.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => __( 'Subscribe Pattern 2', 'newspack-blocks' ),
	'content'       => "<!-- wp:group {\"metadata\":{\"name\":\"Subscribe\"},\"align\":\"full\",\"style\":{\"color\":{\"background\":\"#f0f0f0\"},\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|80\",\"bottom\":\"var:preset|spacing|80\",\"left\":\"var:preset|spacing|30\",\"right\":\"var:preset|spacing|30\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group alignfull has-background\" style=\"background-color:#f0f0f0;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--30)\"><!-- wp:columns {\"verticalAlignment\":\"center\",\"align\":\"wide\",\"className\":\"newspack-grid\"} -->\n<div class=\"wp-block-columns alignwide are-vertically-aligned-center newspack-grid\"><!-- wp:column {\"verticalAlignment\":\"center\",\"width\":\"66.66%\",\"metadata\":{\"name\":\"Intro\"},\"style\":{\"spacing\":{\"blockGap\":\"var:preset|spacing|20\"}}} -->\n<div class=\"wp-block-column is-vertically-aligned-center\" style=\"flex-basis:66.66%\"><!-- wp:heading {\"level\":3,\"metadata\":{\"name\":\"Title\"}} -->\n<h3 class=\"wp-block-heading\">" . $subscribe_title . "</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph {\"metadata\":{\"name\":\"Description\"}} -->\n<p>" . $subscribe_desc . "</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column {\"verticalAlignment\":\"center\",\"width\":\"33.33%\",\"metadata\":{\"name\":\"Form\"}} -->\n<div class=\"wp-block-column is-vertically-aligned-center\" style=\"flex-basis:33.33%\"><!-- wp:newspack-newsletters/subscribe {\"lists\":[],\"className\":\"is-style-modern\",\"backgroundColor\":\"#1e1e1e\"} /--></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns --></div>\n<!-- /wp:group -->",
	'viewportWidth' => 1280,
	'categories'    => array( 'newspack-subscribe' ),
);
