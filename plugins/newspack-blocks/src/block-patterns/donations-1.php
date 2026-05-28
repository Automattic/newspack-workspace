<?php
/**
 * Donations Pattern 1.
 *
 * @package Newspack_Blocks
 */

return array(
	'title'         => __( 'Donations Pattern 1', 'newspack-blocks' ),
	'content'       => "<!-- wp:columns {\"align\":\"wide\"} -->\n<div class=\"wp-block-columns alignwide\"><!-- wp:column {\"verticalAlignment\":\"center\",\"width\":25} -->\n<div class=\"wp-block-column is-vertically-aligned-center\" style=\"flex-basis:25%\"><!-- wp:heading -->\n<h2>" . $donations_title . "</h2>\n<!-- /wp:heading --></div>\n<!-- /wp:column -->\n\n<!-- wp:column {\"width\":75} -->\n<div class=\"wp-block-column\" style=\"flex-basis:75%\"><!-- wp:newspack-blocks/donate {\"className\":\"is-style-modern\",\"buttonColor\":\"#6c6c6c\"} /--></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->",
	'viewportWidth' => 910,
	'categories'    => array( 'newspack-donations' ),
);
