<?php
/**
 * Contextual Prompt render pipeline.
 *
 * Shapes every Contextual Prompt as it renders: the pattern's stored CTA is
 * fixed when it is written, so a change of donation platform can leave it
 * disagreeing with the site. Reconciling happens twice — once against the
 * stored pattern (repair, so the editor sees the truth too) and again in memory
 * for whatever the pattern's own markup happens to be at render.
 *
 * Normalization is scoped to blocks arriving from the pattern: a Group a
 * publisher detached and pasted into a post is theirs, not ours.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contextual Prompt render class.
 */
final class Newspack_Popups_Contextual_Prompt_Render {
	/**
	 * Whether the block being rendered came from the pattern.
	 *
	 * @var bool
	 */
	private static $in_instance = false;

	/**
	 * Whether the pattern has already been reconciled this request.
	 *
	 * @var bool
	 */
	private static $repaired = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'render_block_data', [ __CLASS__, 'open_instance_window' ], 10, 1 );
		add_filter( 'render_block_data', [ __CLASS__, 'normalize_group' ], 10, 1 );
		add_filter( 'render_block_core/block', [ __CLASS__, 'close_instance_window' ], 999, 2 );
	}

	/**
	 * Whether the block currently rendering came from the pattern.
	 *
	 * @return bool
	 */
	public static function is_in_instance() {
		return self::$in_instance;
	}

	/**
	 * Open the window at the pattern instance, so the blocks core renders from
	 * the pattern's markup are recognizable as ours. Reconciling the stored
	 * pattern with the site's platform rides along, once a request: a stale
	 * pattern would otherwise be normalized for every reader without the editor
	 * ever showing what they actually publish.
	 *
	 * The pattern record is read raw — a render must never seed.
	 *
	 * @param array $parsed_block The block being rendered.
	 * @return array
	 */
	public static function open_instance_window( $parsed_block ) {
		if ( 'core/block' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $parsed_block;
		}

		$ref = (int) ( $parsed_block['attrs']['ref'] ?? 0 );
		if ( ! $ref || $ref !== (int) get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID, 0 ) ) {
			return $parsed_block;
		}

		if ( ! self::$repaired ) {
			self::$repaired = true;
			Newspack_Popups_Contextual_Prompt_Pattern::repair();
		}

		self::$in_instance = true;

		return $parsed_block;
	}

	/**
	 * Close the window. Unconditional: a window left open would hand the rest of
	 * the page the pattern's normalization.
	 *
	 * @param string $block_content Rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string
	 */
	public static function close_instance_window( $block_content, $block = [] ) {
		self::$in_instance = false;

		return $block_content;
	}

	/**
	 * Normalize the prompt card's CTA, for blocks coming from the pattern only.
	 *
	 * @param array $parsed_block The block being rendered.
	 * @return array
	 */
	public static function normalize_group( $parsed_block ) {
		if (
			! self::$in_instance
			|| 'core/group' !== ( $parsed_block['blockName'] ?? '' )
			|| false === strpos( (string) ( $parsed_block['attrs']['className'] ?? '' ), Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS )
		) {
			return $parsed_block;
		}

		return self::normalize_cta( $parsed_block );
	}

	/**
	 * A prompt's CTA type is fixed when it is written, so after a change of
	 * donation platform the stored CTA can disagree with the site. Normalize:
	 * the native platform renders the donate form, off-site renders a button to
	 * the donor landing page — or copy only when none is configured. Matching
	 * CTAs pass through untouched, preserving publisher customization.
	 *
	 * @param array $parsed_block Parsed prompt card.
	 * @return array
	 */
	public static function normalize_cta( $parsed_block ) {
		$cta = self::find_cta( $parsed_block );
		if ( null === $cta ) {
			return $parsed_block;
		}

		if ( Newspack_Popups_Contextual_Prompt_Pattern::use_donate_block() ) {
			if ( 'core/buttons' === $cta['name'] ) {
				// Not recorded: this rebuild is thrown away with the request, and
				// only the stored pattern's stamp belongs in the record.
				$parsed_block['innerBlocks'][ $cta['index'] ] = Newspack_Popups_Contextual_Prompt_Pattern::build_donate_child( false );
			}
			return $parsed_block;
		}

		$needs_destination = 'newspack-blocks/donate' === $cta['name']
			// A plain-button CTA without a destination anywhere — written before a
			// donor landing page was configured — is a dead ask. Buttons carrying
			// any URL pass through untouched.
			|| ! self::buttons_have_destination( $parsed_block['innerBlocks'][ $cta['index'] ] );

		if ( $needs_destination ) {
			if ( '' === Newspack_Popups_Contextual_Prompt_Pattern::get_button_url() ) {
				// No destination to point a button at: render the copy alone
				// rather than a dead button or a form on a disabled platform.
				return self::remove_child( $parsed_block, $cta['index'] );
			}
			$parsed_block['innerBlocks'][ $cta['index'] ] = Newspack_Popups_Contextual_Prompt_Pattern::build_buttons_child();
		}

		return $parsed_block;
	}

	/**
	 * Locate the CTA child: the donate block or the buttons wrapper.
	 *
	 * @param array $parsed_block Parsed prompt card.
	 * @return array|null [ 'index' => int, 'name' => string ], or null.
	 */
	public static function find_cta( $parsed_block ) {
		foreach ( $parsed_block['innerBlocks'] ?? [] as $index => $child ) {
			if ( in_array( $child['blockName'] ?? '', [ 'newspack-blocks/donate', 'core/buttons' ], true ) ) {
				return [
					'index' => $index,
					'name'  => $child['blockName'],
				];
			}
		}
		return null;
	}

	/**
	 * Append a child, inserting its innerContent placeholder before the block's
	 * closing markup chunk.
	 *
	 * @param array $parsed_block Parsed block.
	 * @param array $child        Parsed child block.
	 * @return array
	 */
	public static function append_child( $parsed_block, $child ) {
		$parsed_block['innerBlocks'][] = $child;

		$last_chunk = count( $parsed_block['innerContent'] ) - 1;
		while ( $last_chunk >= 0 && null === $parsed_block['innerContent'][ $last_chunk ] ) {
			--$last_chunk;
		}
		array_splice( $parsed_block['innerContent'], max( 0, $last_chunk ), 0, [ null ] );

		return $parsed_block;
	}

	/**
	 * Whether a buttons wrapper contains at least one button with a destination,
	 * as a URL attribute or an href in its markup. The editor drops the attribute
	 * from a saved button, leaving the markup as the only record.
	 *
	 * @param array $buttons Parsed core/buttons child.
	 * @return bool
	 */
	private static function buttons_have_destination( $buttons ) {
		foreach ( $buttons['innerBlocks'] ?? [] as $child ) {
			if ( '' !== trim( (string) ( $child['attrs']['url'] ?? '' ) ) ) {
				return true;
			}
			if ( false !== strpos( (string) ( $child['innerHTML'] ?? '' ), 'href=' ) ) {
				return true;
			}
			if ( ! empty( $child['innerBlocks'] ) && self::buttons_have_destination( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove the Nth child and its innerContent placeholder.
	 *
	 * The innerContent array interleaves HTML chunks with one null placeholder
	 * per child, in order — the placeholder count must track the child count or
	 * the block renders misaligned.
	 *
	 * @param array $parsed_block Parsed block.
	 * @param int   $index        Child index to remove.
	 * @return array
	 */
	private static function remove_child( $parsed_block, $index ) {
		array_splice( $parsed_block['innerBlocks'], $index, 1 );

		$seen = 0;
		foreach ( $parsed_block['innerContent'] as $chunk_index => $chunk ) {
			if ( null !== $chunk ) {
				continue;
			}
			if ( $seen === $index ) {
				array_splice( $parsed_block['innerContent'], $chunk_index, 1 );
				break;
			}
			++$seen;
		}

		return $parsed_block;
	}
}
