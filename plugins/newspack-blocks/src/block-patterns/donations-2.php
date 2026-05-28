<?php
/**
 * Donations Pattern 2.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => __( 'Donations Pattern 2', 'newspack-blocks' ),
	'content'       => "<!-- wp:heading {\"align\":\"center\"} -->\n<h2 class=\"has-text-align-center\">" . $donations_title . "</h2>\n<!-- /wp:heading -->\n\n<!-- wp:columns {\"align\":\"wide\"} -->\n<div class=\"wp-block-columns alignwide\"><!-- wp:column {\"width\":33.33} -->\n<div class=\"wp-block-column\" style=\"flex-basis:33.33%\"><!-- wp:paragraph -->\n<p>" . $donations_desc . "</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column {\"width\":66.66} -->\n<div class=\"wp-block-column\" style=\"flex-basis:66.66%\"><!-- wp:newspack-blocks/donate {\"className\":\"is-style-modern\",\"buttonColor\":\"#6c6c6c\"} /--></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->",
	'viewportWidth' => 910,
	'categories'    => array( 'newspack-donations' ),
);
