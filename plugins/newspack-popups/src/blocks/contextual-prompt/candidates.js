/**
 * Shared Contextual Prompt generation UI.
 *
 * Single source of truth for everything the block's Copy panel and the
 * document-settings panel both need: the post-type label, framing labels, the
 * generation request, and the generate-button / candidate-list presentation.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import { escapeHTML } from '@wordpress/escape-html';

// The block editor and the document-settings panel are separate entries with
// separate localized objects; either may be the one present.
export const POST_TYPE_LABEL =
	window.newspack_popups_blocks_data?.post_type_label || window.newspackPopupsContextualPrompt?.postTypeLabel || __( 'post', 'newspack-popups' );

// The post type's singular label as it declares it, for the framing headings.
// Recasing the lowercased sentence label here would mis-case some locales.
const POST_TYPE_HEADING =
	window.newspack_popups_blocks_data?.post_type_heading ||
	window.newspackPopupsContextualPrompt?.postTypeHeading ||
	__( 'Post', 'newspack-popups' );

export const FRAMING_LABELS = {
	/* translators: %s: the edited content's post type label, e.g. "Post", "Page". */
	top: sprintf( __( 'Top of %s', 'newspack-popups' ), POST_TYPE_HEADING ),
	/* translators: %s: the edited content's post type label, e.g. "Post", "Page". */
	mid: sprintf( __( 'Mid-%s', 'newspack-popups' ), POST_TYPE_HEADING ),
	/* translators: %s: the edited content's post type label, e.g. "Post", "Page". */
	end: sprintf( __( 'End of %s', 'newspack-popups' ), POST_TYPE_HEADING ),
};

/**
 * The framing implied by a block's position among the article's top-level
 * blocks, as a coarse ratio-based bucket: top / mid / end.
 *
 * Mirrors get_placement() in class-newspack-popups-contextual-prompt-block.php,
 * which computes the server-side analytics placement. Keep the two in sync: if
 * they diverge, the generated copy is framed for a different position than the
 * one analytics reports.
 *
 * @param {number} index Block index.
 * @param {number} total Top-level block count.
 * @return {string} One of 'top' | 'mid' | 'end'.
 */
export const framingForPosition = ( index, total ) => {
	if ( total <= 1 ) {
		return 'top';
	}
	const ratio = index / ( total - 1 );
	if ( ratio <= 1 / 3 ) {
		return 'top';
	}
	if ( ratio >= 2 / 3 ) {
		return 'end';
	}
	return 'mid';
};

/**
 * Model output is stored in a RichText attribute, which serializes strings as
 * raw HTML. The manager sanitizes server-side; encoding here too means nothing
 * a model returns can reach the post as markup.
 *
 * @param {string} body The candidate copy.
 * @return {string} The copy, encoded as plain text.
 */
export const toRichTextContent = body => escapeHTML( String( body ?? '' ) );

/**
 * Request donation prompt candidates for a post.
 *
 * @param {Object}  args              Request arguments.
 * @param {number}  args.postId       The post being edited.
 * @param {string}  args.content      The edited post content.
 * @param {string}  [args.framing]    Optional framing; when set, candidates are variants of it.
 * @param {boolean} [args.regenerate] Whether this is an explicit re-run, which bypasses the cached response.
 * @return {Promise<Array>} The candidate list (possibly empty). Rejects on a malformed response.
 */
export const generateCandidates = async ( { postId, content, framing, regenerate } ) => {
	const response = await apiFetch( {
		path: '/wp/v2/newspack-editorial-assistant/generate/donation',
		method: 'POST',
		data: { post_id: postId, content, ...( framing ? { framing } : {} ), ...( regenerate ? { regenerate: true } : {} ) },
	} );
	const payload = response && response.data ? response.data : response;
	// A malformed or version-skewed response must land in the caller's error
	// state, not crash the candidate list; entries the UI can't render are
	// dropped rather than trusted.
	if ( ! Array.isArray( payload?.candidates ) ) {
		throw new Error( __( 'Could not generate suggestions.', 'newspack-popups' ) );
	}
	return payload.candidates.filter(
		candidate =>
			candidate &&
			'object' === typeof candidate &&
			! Array.isArray( candidate ) &&
			'string' === typeof candidate.body &&
			candidate.body.trim() &&
			( undefined === candidate.framing || Boolean( FRAMING_LABELS[ candidate.framing ] ) )
	);
};

export const GenerateButton = ( { busy, onClick, children } ) => (
	<Button
		variant="secondary"
		onClick={ onClick }
		disabled={ busy }
		isBusy={ busy }
		__next40pxDefaultSize
		className="newspack-popups__contextual-prompt-generate"
	>
		{ busy ? __( 'Generating…', 'newspack-popups' ) : children }
	</Button>
);

export const CandidateList = ( { candidates, onApply } ) =>
	candidates.map( ( candidate, index ) => {
		const framingLabel = FRAMING_LABELS[ candidate.framing ] || candidate.framing;
		return (
			<div key={ index } className="newspack-popups__contextual-prompt-candidate">
				{ index > 0 && <hr className="newspack-popups__contextual-prompt-candidate-divider" /> }
				<strong>{ framingLabel }</strong>
				<p className="newspack-popups__contextual-prompt-candidate-body">{ candidate.body }</p>
				<Button
					variant="primary"
					size="small"
					onClick={ () => onApply( candidate ) }
					aria-label={
						framingLabel
							? sprintf(
									/* translators: %s: the suggestion's framing label, e.g. "Top of Post". */
									__( 'Apply suggestion: %s', 'newspack-popups' ),
									framingLabel
							  )
							: undefined
					}
				>
					{ __( 'Apply', 'newspack-popups' ) }
				</Button>
			</div>
		);
	} );
