<?php
/**
 * Donations Pattern 4.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => __( 'Donations Pattern 4', 'newspack-blocks' ),
	'content'       => '<!-- wp:cover {"url":"' . $donations_cover . "\",\"dimRatio\":20,\"overlayColor\":\"black\",\"isUserOverlayColor\":true,\"minHeight\":85,\"minHeightUnit\":\"vh\",\"align\":\"full\",\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|80\",\"bottom\":\"var:preset|spacing|80\",\"left\":\"var:preset|spacing|80\",\"right\":\"var:preset|spacing|80\"}}},\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-cover alignfull\" style=\"padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80);min-height:85vh\"><span aria-hidden=\"true\" class=\"wp-block-cover__background has-black-background-color has-background-dim-20 has-background-dim\"></span><img class=\"wp-block-cover__image-background\" alt=\"\" src=\"" . $donations_cover . "\" data-object-fit=\"cover\"/><div class=\"wp-block-cover__inner-container\"><!-- wp:columns {\"verticalAlignment\":\"center\",\"align\":\"wide\"} -->\n<div class=\"wp-block-columns alignwide are-vertically-aligned-center\"><!-- wp:column {\"verticalAlignment\":\"center\"} -->\n<div class=\"wp-block-column is-vertically-aligned-center\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . $donations_title . "</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>" . $donations_desc . "</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column {\"verticalAlignment\":\"center\"} -->\n<div class=\"wp-block-column is-vertically-aligned-center\"><!-- wp:newspack-blocks/donate {\"className\":\"is-style-modern\",\"buttonColor\":\"#6c6c6c\"} /--></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns --></div></div>\n<!-- /wp:cover -->",
	'viewportWidth' => 782,
	'categories'    => array( 'newspack-donations' ),
);
