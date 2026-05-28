<?php
/**
 * Homepage Pre-Results Module — AP.
 *
 * @package Newspack
 */

?>
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center"><a href="#"><?php _e( '[Our Community] Heads to the Polls!', 'newspack-plugin' ); ?></a></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"color":{"background":"#fff562"}}} -->
<p class="has-text-align-center has-background" style="background-color:#fff562"><?php _e( 'Update the above headline throughout election night. Set it to link to your primary election results story. Delete these yellow paragraphs before publishing. To find out more about how to use these patterns, see the <a target="_blank" href="https://help.newspack.com/newspack-election-results-patterns/">Help Site documentation</a>.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:columns {"className":"is-style-borders"} -->
<div class="wp-block-columns is-style-borders"><!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showDate":false,"showImage":false,"showAuthor":false,"mediaPosition":"right","typeScale":2} /-->

<!-- wp:paragraph {"style":{"color":{"background":"#fff562"}}} -->
<p class="has-background" style="background-color:#fff562"><?php _e( 'More space for election day  / voting stories.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"50%"} -->
<div class="wp-block-column" style="flex-basis:50%"><!-- wp:newspack-blocks/homepage-articles {"showExcerpt":false,"postsToShow":1} /-->

<!-- wp:paragraph {"style":{"color":{"background":"#fff562"}}} -->
<p class="has-background" style="background-color:#fff562"><?php _e( 'Use this column for election-day stories and to let readers know when polls close.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"25%"} -->
<div class="wp-block-column" style="flex-basis:25%"><!-- wp:html -->
<?php _e( 'AP RESULTS EMBED GOES HERE!', 'newspack-plugin' ); ?>
<!-- /wp:html -->

<!-- wp:newspack-blocks/homepage-articles {"showExcerpt":false,"showDate":false,"showImage":false,"showAuthor":false} /-->

<!-- wp:paragraph {"style":{"color":{"background":"#fff562"}}} -->
<p class="has-background" style="background-color:#fff562"><?php _e( 'Use this to point to your election-results pages. It\'s ok if they\'re not receiving data yet. It will be helpful for SEO if you link early in the day.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:paragraph {"align":"center","style":{"color":{"background":"#fff562"}}} -->
<p class="has-text-align-center has-background" style="background-color:#fff562"><?php _e( 'Update the copy on the below newsletter signup to tell people they\'ll find out when results start coming in, and when races are declared, if they sign up.', 'newspack-plugin' ); ?></p>
<!-- /wp:paragraph -->

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
