<?php
/**
 * Subscribe Pattern 3.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => __( 'Subscribe Pattern 3', 'newspack-blocks' ),
	'content'       => '<!-- wp:cover {"url":"' . $subscribe_cover . "\",\"dimRatio\":20,\"overlayColor\":\"black\",\"isUserOverlayColor\":true,\"minHeight\":85,\"minHeightUnit\":\"vh\",\"metadata\":{\"name\":\"Subscribe\"},\"align\":\"full\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|80\",\"bottom\":\"var:preset|spacing|80\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-cover alignfull\" style=\"padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);min-height:85vh\"><span aria-hidden=\"true\" class=\"wp-block-cover__background has-black-background-color has-background-dim-20 has-background-dim\"></span><img class=\"wp-block-cover__image-background\" alt=\"\" src=\"" . $subscribe_cover . "\" data-object-fit=\"cover\"/><div class=\"wp-block-cover__inner-container\"><!-- wp:columns {\"verticalAlignment\":\"center\",\"align\":\"wide\",\"className\":\"newspack-grid\"} -->\n<div class=\"wp-block-columns alignwide are-vertically-aligned-center newspack-grid\"><!-- wp:column {\"verticalAlignment\":\"center\",\"width\":\"66.66%\",\"metadata\":{\"name\":\"Intro\"},\"style\":{\"spacing\":{\"blockGap\":\"var:preset|spacing|20\"}}} -->\n<div class=\"wp-block-column is-vertically-aligned-center\" style=\"flex-basis:66.66%\"><!-- wp:heading {\"level\":3,\"metadata\":{\"name\":\"Title\"}} -->\n<h3 class=\"wp-block-heading\">" . $subscribe_title . "</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph {\"metadata\":{\"name\":\"Description\"}} -->\n<p>" . $subscribe_desc . "</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column {\"verticalAlignment\":\"center\",\"width\":\"33.33%\",\"metadata\":{\"name\":\"Form\"}} -->\n<div class=\"wp-block-column is-vertically-aligned-center\" style=\"flex-basis:33.33%\"><!-- wp:newspack-newsletters/subscribe {\"lists\":[],\"className\":\"is-style-modern\"} /--></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns --></div></div>\n<!-- /wp:cover -->",
	'viewportWidth' => 1280,
	'categories'    => array( 'newspack-subscribe' ),
);
