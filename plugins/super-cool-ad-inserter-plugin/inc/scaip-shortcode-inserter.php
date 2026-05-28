<?php
/**
 * Functions for the automatic insertion of scaip shortcodes
 *
 * @package super-cool-ad-inserter-plugin
 */

/**
 * Adds the scaip shortcode to post after predetermined number of blocks.
 *
 * Borrows heavily from Newspack Campaigns.
 * https://github.com/Automattic/newspack-popups/blob/91d48a4ae09afb468367db9857eb425e33b668d1/includes/class-newspack-popups-inserter.php#L133-L138
 *
 * @since 0.1
 * @param String $content The post content.
 * @return String The post content, plus shortcodes.
 */
function scaip_insert_shortcode( $content = '' ) {
	$scaip_start          = (int) get_option( 'scaip_settings_start', 3 );
	$scaip_period         = (int) get_option( 'scaip_settings_period', 3 );
	$scaip_repetitions    = (int) get_option( 'scaip_settings_repetitions', 2 );
	$scaip_minimum_blocks = (int) get_option( 'scaip_settings_min_paragraphs', 6 );

	$inserted_shortcode_index = 0;
	$block_index              = 0;

	$parsed_blocks = parse_blocks( $content );

	// Turn classic content into HTML blocks.
	$parsed_blocks = array_reduce(
		$parsed_blocks,
		function( $blocks, $block ) {
			$is_classic_block = null === $block['blockName'] || 'core/freeform' === $block['blockName']; // Classic content results in a block without a block name.
			$is_empty         = empty( trim( $block['innerHTML'] ) );
			if ( $is_classic_block && ! $is_empty ) {
				$classic_content = force_balance_tags( wpautop( $block['innerHTML'] ) ); // Ensure we have paragraph tags and valid HTML.
				$dom             = new DomDocument();
				libxml_use_internal_errors( true );
				$dom->loadHTML( mb_convert_encoding( $classic_content, 'HTML-ENTITIES', get_bloginfo( 'charset' ) ) );
				$dom_body = $dom->getElementsByTagName( 'body' );
				if ( 0 < $dom_body->length ) {
					$dom_body_elements = $dom_body->item( 0 )->childNodes;
					foreach ( $dom_body_elements as $index => $entry ) {
						if ( ! $entry->hasChildNodes() ) {
							// Trim whitespace, including non-breaking space.
							$text_length = strlen( trim( $entry->textContent, "\xC2\xA0\n" ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							if ( 0 === $text_length ) {
								continue;
							}
						}
						$block_html = $dom->saveHtml( $entry );
						$blocks[]   = [
							'blockName'    => 'core/html',
							'attrs'        => [],
							'innerBlocks'  => [],
							'innerHTML'    => $block_html,
							'innerContent' => [
								$block_html,
							],
						];
					}
				}
			} else {
				$blocks[] = $block;
			}
			return $blocks;
		},
		[]
	);

	if ( $scaip_minimum_blocks > count( $parsed_blocks ) ) {
		return $content;
	}

	$output = '';

	$blocks_allowing_insertion = array_flip( apply_filters( 'scaip_allowing_insertion_blocks', [ 'core/paragraph' ] ) );

	foreach ( $parsed_blocks as $block ) {

		if ( 0 === $scaip_start && 0 === $inserted_shortcode_index ) {
			$output .= scaip_generate_shortcode( '1' );
			$inserted_shortcode_index++;
		}

		$output .= serialize_block( $block );

		/**
		 * Whether to skip `$blocks_allowing_insertion` check.
		 */
		$skip_blocks_allow_insertion = false;

		/**
		 * Force ad insertion for HTML blocks that are wrapped in a paragraph tag
		 * when 'core/paragraph' is allowed.
		 */
		if (
			isset( $blocks_allowing_insertion['core/paragraph'] ) &&
			! isset( $blocks_allowing_insertion['core/html'] ) &&
			'core/html' === $block['blockName'] &&
			'<p' === substr( $block['innerHTML'], 0, 2 )
		) {
			$skip_blocks_allow_insertion = true;
		}

		/**
		 * Skip insertion for empty paragraphs.
		 */
		if ( 'core/paragraph' === $block['blockName'] && empty( trim( $block['innerHTML'] ) ) ) {
			continue;
		}

		/**
		 * Skip insertion if the block is not on the allowing-insertion list.
		 */
		if ( false === $skip_blocks_allow_insertion && ! isset( $blocks_allowing_insertion[ $block['blockName'] ] ) ) {
			continue;
		}

		$block_index++;

		if (
			scaip_should_insert(
				$scaip_start,
				$block_index,
				$inserted_shortcode_index,
				$scaip_repetitions,
				$scaip_period
			)
		) {
			$output .= scaip_generate_shortcode( $inserted_shortcode_index + 1 );
			$inserted_shortcode_index++;
		}
	}

	return $output;
}

/**
 * Generate shortcode markup.
 *
 * @param string $index Shortcode index.
 */
function scaip_generate_shortcode( $index ) {
	return '<!-- wp:shortcode -->[ad number="' . $index . '"]<!-- /wp:shortcode -->';
}

/**
 * Should shortcode be inserted?
 *
 * @param int $start Min. index to insert at.
 * @param int $block_index Current block index.
 * @param int $insertion_index Current insertion index.
 * @param int $repetitions Max. no. of insertions.
 * @param int $period Period between insertions.
 */
function scaip_should_insert( $start, $block_index, $insertion_index, $repetitions, $period ) {
	return ( $start <= $block_index
	&& $insertion_index < $repetitions
	&& (
		// First insertion should not take period into account.
		0 === $insertion_index
		||
		0 === ( $block_index - $start ) % $period
	) );
}

/**
 * Function to determine whether to insert the shortcode
 *
 * @param string $content The post content.
 * @uses scaip_insert_shortcode
 * @since 0.2
 */
function scaip_maybe_insert_shortcode( $content = '' ) {
	// Abort if this is not being called In The Loop.
	if ( ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	// Abort if this is not a normal post.
	global $wp_query;
	if (
		! isset( $wp_query->queried_object )
		|| ! isset( $wp_query->queried_object->post_type )
		|| 'post' !== $wp_query->queried_object->post_type
	) {
		return $content;
	}

	/*
	 * Abort if this post has the option set to not add ads.
	 */
	$skip = get_post_meta( $wp_query->queried_object->ID, 'scaip_prevent_shortcode_addition', true );

	/*
	 * Usually the meta field won't exist unless the checkbox to skip ads on any given post is checked.
	 * An older version of the plugin saved it even when the box wasn't checked
	 * so we need an extra check here for backwards compatibility.
	 * the previous version saved the meta value as "on" if checked and empty if not.
	 */
	if ( ! empty( $skip ) || 'on' === $skip ) {
		return $content;
	}

	/*
	 * If we have a manual shortcode, bail.
	 * (scaip was the older shortcode, retained here for backwards compatibility)
	 */
	if ( has_shortcode( $content, 'ad' ) || has_shortcode( $content, 'scaip' ) ) {
		return $content;
	}

	/*
	 * Filter to determine whether to apply the shortcode to the given post content
	 *
	 * Filters on 'scaip_whether_insert' should accept three arguments, and return a boolean true or false. The default value is true: apply the filter. Use this filter to tell SCAIP to not programmatically insert the ad in this instance.
	 *
	 * @param Bool $whether Whether to insert ads programmatically in this instance.
	 * @param String $content The post content.
	 * @param Mixed $queried_object `$wp_query->queried_object` in the current context.
	 *
	 * @since 0.2
	 * @link https://github.com/Automattic/super-cool-ad-inserter-plugin/issues/25
	 */
	if ( true !== apply_filters( 'scaip_whether_insert', true, $content, $wp_query->queried_object ) ) {
		return $content;
	}

	return scaip_insert_shortcode( $content );
}
add_filter( 'the_content', 'scaip_maybe_insert_shortcode', 5 );

/**
 * Remove the scaip_maybe_insert_shortcode filter on the_content when there are blocks
 *
 * This is necessary because the filter scaip_maybe_insert_shortcode runs after do_blocks has run, which means there are no blocks left for has_blocks( $content ) to detect.
 * This action should run before Gutenberg's do_blocks filter, which runs at priority 7.
 *
 * @link https://github.com/WordPress/gutenberg/blob/a696e508ad5d3566b447fc48f355e91953a17c4a/lib/blocks.php#L266
 *
 * @since 0.2
 * @see scaip_maybe_insert_shortcode
 * @uses has_blocks
 * @param string $content The post content.
 */
function scaip_maybe_remove_shortcode_inserter( $content ) {
	if ( function_exists( 'has_block' ) && has_block( 'super-cool-ad-inserter-plugin/scaip-sidebar', $content ) ) {
		remove_filter( 'the_content', 'scaip_maybe_insert_shortcode', 5 );
	}

	return $content;
}
add_filter( 'the_content', 'scaip_maybe_remove_shortcode_inserter', 5 );
