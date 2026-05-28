<?php
/**
 * Republication Tracker Tool Content.
 *
 * @since   1.0
 * @package Republication_Tracker_Tool
 */

/**
 * Republication Tracker Tool Content class.
 *
 * @since 1.0
 */
class Republication_Tracker_Tool_Content {
	/**
	 * Filter the content for the republication.
	 *
	 * @param string $content The post content.
	 * @param int    $post_id Optional. Current post ID by default.
	 * @param bool   $escape_html Optional. Whether to escape HTML for final output. Default true.
	 */
	public static function get_republishable_content( $content, $post_id = false, $escape_html = true ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		// Remove shortcodes from the content.
		$content = strip_shortcodes( $content );

		// Remove comments from the content (Lookin' at you, Gutenberg).
		$content = preg_replace( '/<!--(.|\s)*?-->/i', ' ', $content );

		// Remove scripts from the content.
		$content = preg_replace( '@<script\b[^>]*?>.*?</script>@si', '', $content );

		/**
		 * What tags do we want to keep in the embed?
		 * Not things from our server.
		 *
		 * Generally: wp_kses_post, but not allowing the terms listed below because:
		 * - they're referencing assets on our server: audio, figure, img, track, video
		 * - they're referencing the referenced asset: figure, figcaption
		 * - they're not likely to work: form, button
		 *
		 * @var array $allowed_tags_excerpt
		 * @link https://codex.wordpress.org/Function_Reference/wp_kses
		 */
		global $allowedposttags;
		$allowed_tags_excerpt = $allowedposttags;
		unset( $allowed_tags_excerpt['form'] );

		/**
		 * The article WP_Post object
		 *
		 * @var WP_Post $post the post object
		 */
		global $post;

		/**
		 * Allow sites to configure which tags are allowed to be output in the republication content
		 *
		 * Default value is the standard global $allowedposttags, except form elements.
		 *
		 * @link https://github.com/Automattic/republication-tracker-tool/issues/49
		 * @link https://developer.wordpress.org/reference/functions/wp_kses_allowed_html/
		 * @param Array $allowed_tags_excerpt an associative array of element tags that are allowed
		 */
		$allowed_tags_excerpt = apply_filters( 'republication_tracker_tool_allowed_tags_excerpt', $allowed_tags_excerpt, $post );

		// And finally, remove some tags.
		$content = wp_kses( $content, $allowed_tags_excerpt );

		// Remove spare p tags and clean up these paragraphs.
		$content = str_replace( '<p></p>', '', wpautop( $content ) );

		// Remove non-distributable images.
		$content = self::remove_non_distributable_images( $content, 'RTT removed image' );

		// Force the content to be UTF-8 escaped HTML only if requested.
		if ( $escape_html ) {
			$content = htmlspecialchars( $content, ENT_HTML5, 'UTF-8', true );
		}

		$post_object = get_post( $post_id );

		/**
		 * Filters the content of the republished post.
		 *
		 * @param string $content The content of the post.
		 * @param WP_Post $post_object The post object.
		 * @return string The filtered content.
		 */
		$content = apply_filters( 'republication_tracker_tool_republish_content', $content, $post_object );

		return $content;
	}

	/**
	 * Remove non-distributable images from the content.
	 *
	 * @param string $content The post content.
	 * @return string The content with non-distributable images removed.
	 */
	public static function remove_non_distributable_images( $content ) {
		if ( ! class_exists( 'Republication_Tracker_Tool_Media' ) ) {
			return $content;
		}

		// Handle media.
		preg_match_all( '/<img[^>]+class="wp-image-(\d+)"[^>]*>/', $content, $matches );
		$found_images = [];

		foreach ( $matches[1] as $key => $attachment_id ) {
			if ( ! Republication_Tracker_Tool_Media::can_distribute( $attachment_id ) ) {
				$found_images[] = [ $attachment_id, $matches[0][ $key ] ];
			}
		}

		// Remove the found images.
		foreach ( $found_images as [ $attachment_id, $found_image ] ) {
			// Remove the figure and figcaption of $found_image using regex.
			$pattern = '/<figure[^>]*>' . preg_quote( $found_image, '/' ) . '.*?<\/figure>/s';
			$content = preg_replace( $pattern, "<!-- RTT removed image ({$attachment_id}) -->", $content );
		}

		return $content;
	}

	/**
	 * Generate plain text version of republishable content.
	 *
	 * @param WP_Post $post_object The post object.
	 * @return string The plain text content.
	 */
	public static function get_republishable_plain_text_content( $post_object ) {
		if ( ! $post_object instanceof WP_Post ) {
			return '';
		}

		// Start building the plain text content.
		$plain_text_content = '';

		// Get article metadata.
		$article_title = get_the_title( $post_object );
		$author_byline = sprintf( '%1$s, %2$s', apply_filters( 'republication_tracker_tool_byline', __( 'By ', 'republication-tracker-tool' ) . get_the_author_meta( 'display_name', $post_object->post_author ) ), get_bloginfo( 'name' ) );
		$article_date  = gmdate( 'F j, Y', strtotime( $post_object->post_date ) );

		// Add the article title.
		if ( ! empty( $article_title ) ) {
			$plain_text_content .= $article_title . "\n\n";
		}

		// Add the author byline (strip HTML tags).
		if ( ! empty( $author_byline ) ) {
			$plain_text_content .= wp_strip_all_tags( $author_byline ) . "\n";
		}

		// Add the article date.
		if ( ! empty( $article_date ) ) {
			$plain_text_content .= $article_date . "\n\n";
		}

		// Process the main content.
		if ( ! empty( $post_object->post_content ) ) {
			// Get HTML content without escaping for plain text conversion.
			$html_content = self::get_republishable_content( $post_object->post_content, $post_object->ID, false );

			// Convert HTML to plain text.
			$plain_content = self::convert_html_to_plain_text( $html_content );

			$plain_text_content .= $plain_content . "\n\n";
		}

		// Add attribution.
		$display_attribution = get_option( 'republication_tracker_tool_display_attribution', 'on' );
		if ( 'on' === $display_attribution ) {
			$plain_text_content .= Republication_Tracker_Tool::get_attribution( $post_object, true ) . "\n\n";
		}

		/**
		 * Filters the plain text content of the republished post.
		 *
		 * @param string $plain_text_content The plain text content of the post.
		 * @param WP_Post $post_object The post object.
		 * @return string The filtered plain text content.
		 */
		$plain_text_content = apply_filters( 'republication_tracker_tool_republish_plain_text_content', $plain_text_content, $post_object );

		return trim( $plain_text_content );
	}

	/**
	 * Convert HTML content to formatted plain text.
	 *
	 * @param string $html_content The HTML content to convert.
	 * @return string The converted plain text content.
	 */
	private static function convert_html_to_plain_text( $html_content ) {
		$html_content = html_entity_decode( $html_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Filter tags and clean up the content.
		$html_content = preg_replace( '/<figure[^>]*>.*?<\/figure>/is', '', $html_content );
		$html_content = preg_replace( '/<img[^>]*>/i', '', $html_content );
		$html_content = preg_replace( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', "\n$1\n\n", $html_content );
		$html_content = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html_content );
		$html_content = preg_replace( '/<br\s*\/?>/i', "\n", $html_content );
		$html_content = preg_replace( '/<style\b[^>]*?>.*?<\/style>/si', '', $html_content );

		// ul to bullet points.
		$html_content = preg_replace_callback(
			'/<ul[^>]*>(.*?)<\/ul>/is',
			function( $matches ) {
				$list_content = $matches[1];
				// Convert each list item to a bullet point.
				$list_content = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "• $1\n", $list_content );
				return "\n" . $list_content . "\n";
			},
			$html_content
		);

		// ol to numbered points.
		$html_content = preg_replace_callback(
			'/<ol[^>]*>(.*?)<\/ol>/is',
			function( $matches ) {
				$list_content = $matches[1];
				$counter = 1;
				// Convert each list item to a numbered point.
				$list_content = preg_replace_callback(
					'/<li[^>]*>(.*?)<\/li>/is',
					function( $item_matches ) use ( &$counter ) {
						return ( $counter++ ) . '. ' . $item_matches[1] . "\n";
					},
					$list_content
				);
				return "\n" . $list_content . "\n";
			},
			$html_content
		);

		// Handle tags containing information.
		$html_content = preg_replace( '/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n> $1\n\n", $html_content );
		$html_content = preg_replace( '/<a[^>]*>(.*?)<\/a>/is', '$1', $html_content );
		$html_content = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/is', '$2', $html_content );
		$html_content = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/is', '$2', $html_content );

		// Remove all remaining HTML tags.
		$plain_text = wp_strip_all_tags( $html_content );
		$plain_text = preg_replace( '/\n\s*\n\s*\n/', "\n\n", $plain_text );
		$plain_text = preg_replace( '/[ \t]+/', ' ', $plain_text );

		return trim( $plain_text );
	}
}
