<?php
/**
 * Republish page template.
 *
 * @since 1.6.0
 *
 * @package Republication_Tracker_Tool
 */

get_header();

// Get post ID from query var.
$republish_post_id = get_query_var( 'republish_post_id' );

// Get post object.
$post_object = get_post( $republish_post_id );

// Check if the post object is valid.
if ( ! $post_object instanceof WP_Post ) {
	wp_die( esc_html__( 'Invalid post ID.', 'republication-tracker-tool' ) );
}

$content = Republication_Tracker_Tool_Content::get_republishable_content( $post_object->post_content, $republish_post_id );

$license_statement = get_option( 'republication_tracker_tool_policy' );
$license_key = get_option( 'republication_tracker_tool_license', REPUBLICATION_TRACKER_TOOL_DEFAULT_LICENSE );

$using_license = isset( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ] );

if ( $using_license ) {
	$license_badge = sprintf(
		'<a rel="noreferrer license" target="_blank" href="%s"><img alt="%s" style="border-width:0" src="%s" /></a>',
		REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'],
		REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['description'],
		REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['badge']
	);
}

// Article title.
$article_title = get_the_title( $republish_post_id );

// Get article subtitle.
$article_subtitle = get_post_meta( $republish_post_id, 'newspack_post_subtitle', true );

// Get article author byline.
$author_byline = apply_filters(
	'republication_tracker_tool_author_byline',
	sprintf(
		'%1$s, %2$s',
		__( 'By ', 'republication-tracker-tool' ) . get_the_author_meta( 'display_name', $post_object->post_author ),
		get_bloginfo( 'name' )
	)
);

// Get article date and time in current timezone.
$article_date_gmt = get_post_time( 'U', true, $republish_post_id ); // Get post date in GMT.

// Convert GMT date to the current timezone.
$time_zone_string = get_option( 'timezone_string', 'UTC' );

if ( empty( $time_zone_string ) ) {
	$time_zone_string = 'UTC';
}

$current_timezone = new DateTimeZone( $time_zone_string ); // Get current timezone.
$article_date     = new DateTime();
$article_date->setTimestamp( $article_date_gmt );
$article_date->setTimezone( $current_timezone );
$article_date = $article_date->format( 'M j g:ia T' );

// Featured image.
if ( Republication_Tracker_Tool_Media::can_distribute( get_post_thumbnail_id( $republish_post_id ) ) ) {
	$featured_image = get_the_post_thumbnail( $republish_post_id, 'full' );
} else {
	$featured_image = '';
}

// Canonical URL.
$canonical_url = get_permalink( $republish_post_id );
$canonical_tag = sprintf(
	'<link rel="canonical" href="%s" />',
	esc_url( $canonical_url )
);

// Get the content footer.
$content_footer = Republication_Tracker_Tool::create_content_footer( $post_object );

// Republish content.
$republish_content = '';

// Add the article title to the republish content.
if ( ! empty( $article_title ) ) {
	$republish_content .= sprintf(
		'<h1>%s</h1>',
		esc_html( $article_title )
	);
}

// Add the article subtitle to the republish content. Retrievable via Newspack's newspack_post_subtitle meta key.
if ( ! empty( $article_subtitle ) ) {
	$republish_content .= sprintf(
		"\n\n<h2>%s</h2>",
		esc_html( $article_subtitle )
	);
}

// Add the article author to the republish content.
if ( ! empty( $author_byline ) ) {
	$republish_content .= sprintf(
		"\n\n<p class='byline'>%s</p>",
		$author_byline
	);
}

// Add the article date to the republish content.
if ( ! empty( $article_date ) ) {
	$republish_content .= sprintf(
		"\n\n<time>%s</time>",
		esc_html( $article_date )
	);
}

// Add the featured image to the republish content.
if ( ! empty( $featured_image ) ) {
	$republish_content .= sprintf(
		"\n\n%s",
		$featured_image
	);
}

// Add the content to the republish content.
if ( ! empty( $content ) ) {
	$republish_content .= sprintf(
		"\n%s",
		$content
	);
}

// Add the canonical URL to the republish content.
if ( ! empty( $canonical_tag ) ) {
	$republish_content .= sprintf(
		"\n\n%s",
		$canonical_tag
	);
}

// Add the content footer to the republish content.
if ( ! empty( $content_footer ) ) {
	$republish_content .= sprintf(
		"\n\n%s",
		html_entity_decode( $content_footer )
	);
}

// Remove css classes from content.
$republish_content = preg_replace( '/ class=".*?"/', '', $republish_content );

// Remove srcset attribute from images.
$republish_content = preg_replace( '/ srcset=".*?"/', '', $republish_content );

// Remove sizes attribute from images.
$republish_content = preg_replace( '/ sizes=".*?"/', '', $republish_content );

// Filter the republish content.
$republish_content = apply_filters( 'republication_tracker_tool_republish_article_markup', $republish_content, $post_object );

// Generate plain text content if the feature is enabled.
$plain_text_enabled = Republication_Tracker_Tool_Settings::is_plain_text_enabled();
$republish_plain_text_content = '';
if ( $plain_text_enabled ) {
	$republish_plain_text_content = Republication_Tracker_Tool_Content::get_republishable_plain_text_content( $post_object );
	$additional_tracking_html     = Republication_Tracker_Tool::create_additional_tracking_code_markup( $post_object->ID );
}
?>

<section id="primary" class="content-area">
		<main class="site-main">
			<article class="republish-article">
				<h3><?php esc_html_e( 'Republish this article', 'republication-tracker-tool' ); ?></h3>
				<h1><?php echo esc_html( $post_object->post_title ); ?></h1>

				<?php do_action( 'republication_tracker_tool_before_republish_content', $post_object ); ?>

				<div class="cc-policy">
					<?php if ( $using_license ) : ?>
						<div class="cc-license">
							<?php
							echo wp_kses_post( $license_badge );
							echo wp_kses_post(
								wpautop(
									sprintf(
									// translators: %1$s is the URL to the particular Creative Commons license, %2$s is the license description.
										__( 'This work is licensed under a <a rel="noreferrer license" target="_blank" href="%1$s">%2$s</a>.', 'republication-tracker-tool' ),
										REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'],
										REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['description']
									)
								)
							);
							?>
						</div>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $license_statement ) ) : ?>
					<section class="republish-article__license">
						<?php echo wp_kses_post( $license_statement ); ?>
					</section>
				<?php endif; ?>
				<div class="republish-article__content">
					<section class="republish-article__info">
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
								id="republish-html-content"
								class="republish-content__textarea <?php echo $plain_text_enabled ? 'republish-content republish-content--active' : ''; ?>"
								data-tab-content="html"
								rows="19"
								readonly
								aria-readonly="true"
								aria-label="<?php esc_attr_e( 'Republish this article (HTML format)', 'republication-tracker-tool' ); ?>"
							><?php echo esc_html( $republish_content ); ?></textarea>

							<?php if ( $plain_text_enabled ) : ?>
								<div class="republish-content" data-tab-content="plain-text">
									<div class="plain-text-field">
										<label class="plain-text-field__label" for="republish-canonical-url">
											<strong><?php esc_html_e( 'Canonical Tag:', 'republication-tracker-tool' ); ?></strong>
										</label>
										<input
											type="text"
											id="republish-canonical-url"
											class="republish-content__input"
											readonly
											value="<?php echo esc_attr( $canonical_tag ); ?>"
											aria-label="<?php esc_attr_e( 'Canonical Tag for this article', 'republication-tracker-tool' ); ?>"
										/>
										<button class="plain-text-field__button" data-target="#republish-canonical-url" aria-label="<?php esc_attr_e( 'Copy canonical URL', 'republication-tracker-tool' ); ?>">
											<?php esc_html_e( 'Copy Tag', 'republication-tracker-tool' ); ?>
										</button>
									</div>

									<div class="plain-text-field">
										<label class="plain-text-field__label" for="republish-plain-text-content">
											<strong><?php esc_html_e( 'Article Content:', 'republication-tracker-tool' ); ?></strong>
										</label>
										<textarea
											id="republish-plain-text-content"
											class="republish-content__textarea"
											readonly
											rows="12"
											aria-label="<?php esc_attr_e( 'Plain text article content', 'republication-tracker-tool' ); ?>"
										><?php echo wp_kses_post( $republish_plain_text_content ); ?></textarea>
										<button class="plain-text-field__button" data-target="#republish-plain-text-content" aria-label="<?php esc_attr_e( 'Copy article content', 'republication-tracker-tool' ); ?>">
											<?php esc_html_e( 'Copy Content', 'republication-tracker-tool' ); ?>
										</button>
									</div>

									<div class="plain-text-field">
										<label class="plain-text-field__label" for="republish-tracking-snippet">
											<strong><?php esc_html_e( 'Tracking Snippet:', 'republication-tracker-tool' ); ?></strong>
										</label>
										<input
											type="text"
											id="republish-tracking-snippet"
											class="republish-content__input"
											readonly
											aria-label="<?php esc_attr_e( 'Tracking snippet', 'republication-tracker-tool' ); ?>"
											value="<?php echo esc_attr( $additional_tracking_html ); ?>"
										/>
										<button class="plain-text-field__button" data-target="#republish-tracking-snippet" aria-label="<?php esc_attr_e( 'Copy tracking snippet', 'republication-tracker-tool' ); ?>">
											<?php esc_html_e( 'Copy Snippet', 'republication-tracker-tool' ); ?>
										</button>
									</div>
								</div>
							<?php endif; ?>
						</div>

						<button class="republish-article__copy-button republish-article__copy-button--main show-for-html" aria-label="<?php esc_attr_e( 'Copy to clipboard', 'republication-tracker-tool' ); ?>">
							<?php esc_html_e( 'Copy to clipboard', 'republication-tracker-tool' ); ?>
						</button>
					</section>
				</div>

				<?php do_action( 'republication_tracker_tool_after_republish_content', $post_object ); ?>
			</article>
		</main>
</section>

<?php

get_footer();
