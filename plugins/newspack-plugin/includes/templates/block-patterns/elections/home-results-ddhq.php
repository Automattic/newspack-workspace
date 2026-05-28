<?php
/**
 * Homepage Results Module — DDHQ.
 *
 * @package Newspack
 */

?>
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center"><a href="#"><?php _e( 'Election Coverage Live Updated Heading', 'newspack-plugin' ); ?></a></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"color":{"background":"#fff562"}}} -->
<p class="has-text-align-center has-background" style="background-color:#fff562"><?php _e( 'Update the above headline throughout election night. Set it to link to your primary election results story. Delete these yellow paragraphs before publishing. To find out more about how to use these patterns, see the <a target="_blank" href="https://help.newspack.com/newspack-election-results-patterns/">Help Site documentation</a>.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:columns {"className":"is-style-borders"} -->
<div class="wp-block-columns is-style-borders"><!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showDate":false,"showImage":false,"showAuthor":false,"mediaPosition":"right","typeScale":2} /-->

<!-- wp:paragraph {"style":{"color":{"background":"#fff562"}}} -->
<p class="has-background" style="background-color:#fff562"><?php _e( 'Modify the above Posts block to contain election related stories (can use categories or tags for this).', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"50%"} -->
<div class="wp-block-column" style="flex-basis:50%"><!-- wp:heading -->
<h2 class="wp-block-heading"><?php _e( 'Decision 2024', 'newspack-plugin' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:html -->
<?php _e( 'DDHQ EMBED CODE GOES HERE!', 'newspack-plugin' ); ?>
<!-- /wp:html -->

<!-- wp:paragraph -->
<p><?php _e( 'Results are coming in for the 2024 elections! <a href="#">See all the results that matter to [our community]!', 'newspsack-plugin' ); ?></a></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"color":{"background":"#fff562"}}} -->
<p class="has-background" style="background-color:#fff562"><?php _e( 'Use this column for embedded live results of the most salient race for your audience.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:html -->
<?php _e( 'DDHQ EMBED CODE GOES HERE!', 'newspack-plugin' ); ?>
<!-- /wp:html -->

<!-- wp:paragraph {"style":{"color":{"background":"#fff562"}}} -->
<p class="has-background" style="background-color:#fff562"><?php _e( 'Use this column for embedded live results of a secondary key race for your audience.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:paragraph {"align":"center","style":{"color":{"background":"#fff562"}}} -->
<p class="has-text-align-center has-background" style="background-color:#fff562"><?php _e( 'Update the copy on the below newsletter signup to highlight politics / election coverage, and tell people they\'ll find out when races are declared if they sign up.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<?php _e( 'DDHQ JS SNIPPET GOES HERE! (Should be provided to you by DDHQ)', 'newspack-plugin' ); ?>
<!-- /wp:html -->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"backgroundColor":"light-gray","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-light-gray-background-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)"><!-- wp:heading {"textAlign":"center","level":3} -->
<h3 class="wp-block-heading has-text-align-center"><?php _e( 'Sign up to receive updates.', 'newspack-plugin' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"20%"} -->
<div class="wp-block-column" style="flex-basis:20%"></div>
<!-- /wp:column -->

<!-- wp:column {"width":"100%"} -->
<div class="wp-block-column" style="flex-basis:100%"><!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><?php _e( 'We\'ll be monitoring [our community]\'s key races until the results are in. Sign up for our mailing list to get the latest updates sent directly to your inbox.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"20%"} -->
<div class="wp-block-column" style="flex-basis:20%"></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"></div>
<!-- /wp:column -->

<!-- wp:column {"width":"50%"} -->
<div class="wp-block-column" style="flex-basis:50%"><!-- wp:newspack-newsletters/subscribe {"className":"is-style-modern"} /--></div>
<!-- /wp:column -->

<!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
