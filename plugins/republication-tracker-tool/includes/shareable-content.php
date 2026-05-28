<?php
/**
 * This file provides an AJAX response containing the body of a post as well as some sharing information.
 *
 * This operates within The Loop. $post is set to a specific post.
 *
 * Expected URLs is something like /wp-content/plugins/republication-tracker-tool/includes/shareable-content.php?post=22078&_=1512494948576 or something
 * We aren't passing a NONCE; this isn't a form.
 *
 * @package Republication_Tracker_Tool
 */

/**
 * The article WP_Post object.
 *
 * @var WP_Post $post the post object
 */
global $post;

/**
 * The content of the aforementioned post.
 *
 * @var HTML $content
 */
$content = Republication_Tracker_Tool_Content::get_republishable_content( $post->post_content );

$content_footer = Republication_Tracker_Tool::create_content_footer( $post );

/**
 * The article title, byline, source site, and date
 *
 * @var HTML $article_info The article title, etc.
 */
$article_info = sprintf(
	// translators: %1$s is the post title, %2$s is the byline, %3$s is the site name, %4$s is the date in the format F j, Y.
	__( '<h1>%1$s</h1><p class="byline">by %2$s, %3$s <br />%4$s</p>', 'republication-tracker-tool' ),
	wp_kses_post( get_the_title( $post ) ),
	/**
	 * Allow filtering of the byline that is output in the share dialog and the copyable plaintext.
	 *
	 * This is to provide support for plugins that do not implement
	 * a filter on 'the_author', or in cases where the 'the_author'
	 * filter returns incomplete information.
	 *
	 * @link https://developer.wordpress.org/reference/functions/get_the_author/
	 * @link https://github.com/INN/republication-tracker-tool/issues/46
	 */
	wp_kses_post( apply_filters( 'republication_tracker_tool_byline', get_the_author() ) ),
	wp_kses_post( get_bloginfo( 'name' ) ),
	wp_kses_post( gmdate( 'F j, Y', strtotime( $post->post_date ) ) )
);
// strip empty tags after automatically applying p tags.
$article_info = str_replace( '<p></p>', '', wpautop( $article_info ) );

// Check if plain text feature is enabled.
$plain_text_enabled = Republication_Tracker_Tool_Settings::is_plain_text_enabled();
$plain_text_content = '';
if ( $plain_text_enabled ) {
	$canonical_tag            = sprintf( '<link rel="canonical" href="%s" />', esc_url( get_permalink( $post->ID ) ) );
	$plain_text_content       = Republication_Tracker_Tool_Content::get_republishable_plain_text_content( $post );
	$additional_tracking_html = Republication_Tracker_Tool::create_additional_tracking_code_markup( $post->ID );
	$pixel                    = Republication_Tracker_Tool::create_tracking_pixel_markup( $post->ID );
	$parsely_tracking         = Republication_Tracker_Tool::create_parsely_tracking( $post->ID );

	/**
	 * Filters the tracking code for the plain text snippet.
	 *
	 * @param string $plain_text_tracking_snippet The tracking code for the plain text snippet.
	 * @param WP_Post $post The post object.
	 */
	$plain_text_tracking_snippet = apply_filters( 'republication_tracker_tool_text_tracking_code', $pixel . $parsely_tracking . $additional_tracking_html, $post );
}

/**
 * The licensing statement from this plugin
 *
 * @var HTML $license_statement
 */
$license_statement = wp_kses_post( get_option( 'republication_tracker_tool_policy' ) );
$license_key = get_option( 'republication_tracker_tool_license', REPUBLICATION_TRACKER_TOOL_DEFAULT_LICENSE );
$using_license = isset( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ] );

echo '<div id="republication-tracker-tool-modal-content" ' . ( $is_amp ? '' : 'style="display:none;"' ) . '>';
	echo '<button ' . ( $is_amp ? 'on="tap:republication-tracker-tool-modal.close"' : '' ) . ' class="republication-tracker-tool-close">';
	echo '<span class="screen-reader-text">' . esc_html__( 'Close window', 'republication-tracker-tool' ) . '</span><span class="republication-tracker-tool-close-icon"></span></button>';
	printf( '<h2 id="republish-modal-label">%s</h2>', esc_html__( 'Republish this article', 'republication-tracker-tool' ) );

	// Explain Creative Commons.
	echo '<div class="cc-policy">';
	echo '<div class="cc-license">';
if ( $using_license ) {
	printf( '<a rel="noreferrer license" target="_blank" href="%s"><img alt="%s" style="border-width:0" src="%s" /></a>', esc_url( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'] ), esc_html( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['description'] ), esc_url( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['badge'] ) );
	echo wp_kses_post(
		wpautop(
			sprintf(
				// translators: %1$s is the URL to the particular Creative Commons license.
				__( 'This work is licensed under a <a rel="noreferrer license" target="_blank" href="%1$s">%2$s</a>.', 'republication-tracker-tool' ),
				REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'],
				REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['description']
			)
		)
	);
}
	echo '</div>'; // .cc-license
	echo '<p>' . wp_kses_post( $license_statement ) . '</p>';
	echo '</div>'; // .cc-policy

			// What we display to the embedder.
			echo '<div class="article-info">';
			echo wp_kses_post( $article_info );
			echo '</div>'; // .article-info

			// The text area that is copyable.
?>
			<div class="republication-content-section">
				<?php if ( $plain_text_enabled ) : ?>
					<div class="republish-format-tabs">
						<button class="republish-format-tabs__button republish-format-tabs__button--active" data-tab="html" aria-label="<?php esc_attr_e( 'HTML format', 'republication-tracker-tool' ); ?>">
							<?php esc_html_e( 'HTML', 'republication-tracker-tool' ); ?>
						</button>
						<button class="republish-format-tabs__button" data-tab="plain-text" aria-label="<?php esc_attr_e( 'Plain text format', 'republication-tracker-tool' ); ?>">
							<?php esc_html_e( 'Plain Text', 'republication-tracker-tool' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<div class="republish-content-container">
					<textarea
						id="republication-tracker-tool-shareable-content"
						class="republish-content__textarea <?php echo $plain_text_enabled ? 'republish-content republish-content--active' : ''; ?>"
						data-tab-content="html"
						readonly
						rows="5"
						aria-label="<?php esc_attr_e( 'Republish this article (HTML format)', 'republication-tracker-tool' ); ?>"
					><?php echo esc_html( $article_info ); ?><?php echo wp_kses_post( $content ); ?><?php echo wp_kses_post( htmlspecialchars( $content_footer, ENT_QUOTES, 'UTF-8' ) ); ?></textarea>

					<?php if ( $plain_text_enabled ) : ?>
						<div class="republish-content" data-tab-content="plain-text">
							<div class="plain-text-field">
								<label class="plain-text-field__label" for="republication-tracker-tool-canonical-url">
									<strong><?php esc_html_e( 'Canonical Tag:', 'republication-tracker-tool' ); ?></strong>
								</label>
								<input
									type="text"
									id="republication-tracker-tool-canonical-url"
									class="plain-text-field__input"
									readonly
									value="<?php echo esc_attr( $canonical_tag ); ?>"
									aria-label="<?php esc_attr_e( 'Canonical tag for this article', 'republication-tracker-tool' ); ?>"
								/>
								<button class="plain-text-field__button" data-target="#republication-tracker-tool-canonical-url" aria-label="<?php esc_attr_e( 'Copy canonical URL', 'republication-tracker-tool' ); ?>">
									<?php esc_html_e( 'Copy Tag', 'republication-tracker-tool' ); ?>
								</button>
							</div>

							<div class="plain-text-field">
								<label class="plain-text-field__label" for="republication-tracker-tool-plain-text-content">
									<strong><?php esc_html_e( 'Article Content:', 'republication-tracker-tool' ); ?></strong>
								</label>
								<textarea
									id="republication-tracker-tool-plain-text-content"
									class="republish-content__textarea"
									readonly
									rows="8"
									aria-label="<?php esc_attr_e( 'Plain text article content', 'republication-tracker-tool' ); ?>"
								><?php echo wp_kses_post( $plain_text_content ); ?></textarea>
								<button class="plain-text-field__button" data-target="#republication-tracker-tool-plain-text-content" aria-label="<?php esc_attr_e( 'Copy article content', 'republication-tracker-tool' ); ?>">
									<?php esc_html_e( 'Copy Content', 'republication-tracker-tool' ); ?>
								</button>
							</div>

							<div class="plain-text-field">
								<label class="plain-text-field__label" for="republication-tracker-tool-tracking-snippet">
									<strong><?php esc_html_e( 'Tracking snippet:', 'republication-tracker-tool' ); ?></strong>
								</label>
								<input
									type="text"
									id="republication-tracker-tool-tracking-snippet"
									class="plain-text-field__input"
									readonly
									aria-label="<?php esc_attr_e( 'Tracking snippet', 'republication-tracker-tool' ); ?>"
									value="<?php echo esc_attr( $plain_text_tracking_snippet ); ?>"
								/>
								<button class="plain-text-field__button" data-target="#republication-tracker-tool-tracking-snippet" aria-label="<?php esc_attr_e( 'Copy tracking snippet', 'republication-tracker-tool' ); ?>">
									<?php esc_html_e( 'Copy Snippet', 'republication-tracker-tool' ); ?>
								</button>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
			if ( ! $is_amp ) {
				?>
			<button onclick="ClipboardUtils.copyFromElement( getActiveTextarea(), this )" class="republication-tracker-tool__copy-button republication-tracker-tool__copy-button--main show-for-html"><?php echo esc_html__( 'Copy to Clipboard', 'republication-tracker-tool' ); ?></button>
				<?php
			}

			echo '</div>'; // #republication-tracker-tool-modal-content
