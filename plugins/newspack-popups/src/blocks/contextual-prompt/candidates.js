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

export const FRAMING_LABELS = {
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	top: sprintf( __( 'Top of %s', 'newspack-popups' ), POST_TYPE_LABEL ),
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	mid: sprintf( __( 'Mid-%s', 'newspack-popups' ), POST_TYPE_LABEL ),
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	end: sprintf( __( 'End of %s', 'newspack-popups' ), POST_TYPE_LABEL ),
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
			<strong>{ FRAMING_LABELS[ candidate.framing ] || candidate.framing }</strong>
			<p style={ { margin: '4px 0 8px' } }>{ candidate.body }</p>
			<Button variant="primary" size="small" onClick={ () => onApply( candidate ) }>
				{ __( 'Apply', 'newspack-popups' ) }
			</Button>
		</div>
	) );
