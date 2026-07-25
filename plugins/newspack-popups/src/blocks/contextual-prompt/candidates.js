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

// The block editor and the document-settings panel are separate entries with
// separate localized objects; either may be the one present.
export const POST_TYPE_LABEL =
	window.newspack_popups_blocks_data?.post_type_label || window.newspackPopupsContextualPrompt?.postTypeLabel || __( 'post', 'newspack-popups' );

// Title Case variant for the framing headings.
const POST_TYPE_HEADING = POST_TYPE_LABEL.charAt( 0 ).toUpperCase() + POST_TYPE_LABEL.slice( 1 );

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
 * blocks, with a small buffer: a prompt sitting two paragraphs in still reads
 * as a top-of-story ask, and likewise near the end.
 *
 * @param {number} index Block index.
 * @param {number} total Top-level block count.
 * @return {string} One of 'top' | 'mid' | 'end'.
 */
export const framingForPosition = ( index, total ) => {
	const FRAMING_BUFFER = 3;
	if ( index < FRAMING_BUFFER ) {
		return 'top';
	}
	if ( index >= total - FRAMING_BUFFER ) {
		return 'end';
	}
	return 'mid';
};

/**
 * Request donation prompt candidates for a post.
 *
 * @param {Object} args           Request arguments.
 * @param {number} args.postId    The post being edited.
 * @param {string} args.content   The edited post content.
 * @param {string} [args.framing] Optional framing; when set, candidates are variants of it.
 * @return {Promise<Array>} The candidate list (possibly empty).
 */
export const generateCandidates = async ( { postId, content, framing } ) => {
	const response = await apiFetch( {
		path: '/wp/v2/newspack-editorial-assistant/generate/donation',
		method: 'POST',
		data: { post_id: postId, content, ...( framing ? { framing } : {} ) },
	} );
	const payload = response && response.data ? response.data : response;
	return payload?.candidates || [];
};

export const GenerateButton = ( { busy, onClick, children } ) => (
	<Button
		variant="secondary"
		onClick={ onClick }
		disabled={ busy }
		isBusy={ busy }
		__next40pxDefaultSize
		style={ { width: '100%', justifyContent: 'center' } }
	>
		{ busy ? __( 'Generating…', 'newspack-popups' ) : children }
	</Button>
);

export const CandidateList = ( { candidates, onApply } ) =>
	candidates.map( ( candidate, index ) => (
		<div key={ index } style={ { marginTop: '16px' } }>
			{ index > 0 && (
				<hr style={ { margin: '0 0 16px', border: 'none', borderTop: '1px solid var(--wpds-color-stroke-surface-neutral-weak, #f0f0f0)' } } />
			) }
			<strong>{ FRAMING_LABELS[ candidate.framing ] || candidate.framing }</strong>
			<p style={ { margin: '4px 0 8px' } }>{ candidate.body }</p>
			<Button variant="primary" size="small" onClick={ () => onApply( candidate ) }>
				{ __( 'Apply', 'newspack-popups' ) }
			</Button>
		</div>
	) );
