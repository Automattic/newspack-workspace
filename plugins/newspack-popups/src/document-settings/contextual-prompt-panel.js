/**
 * Contextual Prompt editor panel — in-content prototype.
 *
 * The prompt is a synced-pattern instance living in the post content, so the
 * canvas is the source of truth: copy is edited inline, position is the block's
 * position, and the design belongs to the pattern. The panel's only jobs are
 * AI generation and managing that one block.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import {
	Button,
	Notice,
	Spinner,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { createPromptBlock } from '../blocks/contextual-prompt/edit';

const BLOCK_NAME = 'newspack-popups/contextual-prompt';

// The copy paragraph, wherever it sits in the prompt's structure.
const findCopyBlock = blocks => {
	for ( const block of blocks ) {
		if ( 'core/paragraph' === block.name ) {
			return block;
		}
		const found = findCopyBlock( block.innerBlocks || [] );
		if ( found ) {
			return found;
		}
	}
	return null;
};

const POST_TYPE_LABEL = window.newspackPopupsContextualPrompt?.postTypeLabel || __( 'post', 'newspack-popups' );

const FRAMING_LABELS = {
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	top: sprintf( __( 'Top of %s', 'newspack-popups' ), POST_TYPE_LABEL ),
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	mid: sprintf( __( 'Mid-%s', 'newspack-popups' ), POST_TYPE_LABEL ),
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	end: sprintf( __( 'End of %s', 'newspack-popups' ), POST_TYPE_LABEL ),
};

const ContextualPromptPanel = () => {
	const { postId, postType, content, blockCount, instance } = useSelect( select => {
		const editor = select( 'core/editor' );
		const blocks = select( 'core/block-editor' ).getBlocks() || [];
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			content: editor.getEditedPostContent(),
			blockCount: blocks.length,
			instance: blocks.find( block => BLOCK_NAME === block.name ) || null,
		};
	}, [] );

	const { insertBlock, removeBlock, updateBlockAttributes, selectBlock } = useDispatch( 'core/block-editor' );

	const [ candidates, setCandidates ] = useState( [] );
	const [ generating, setGenerating ] = useState( false );
	const [ error, setError ] = useState( '' );

	const optedIn = window.newspackPopupsContextualPrompt?.enabled;
	const isPrompt = 'newspack_popups_cpt' === postType;

	// Hidden until an administrator opts the site into AI use; never on a prompt.
	if ( ! optedIn || isPrompt ) {
		return null;
	}

	const generate = async () => {
		setGenerating( true );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: '/wp/v2/newspack-editorial-assistant/generate/donation',
				method: 'POST',
				data: { post_id: postId, content },
			} );
			const payload = response && response.data ? response.data : response;
			const list = ( payload && payload.candidates ) || [];
			setCandidates( list );
			if ( ! list.length ) {
				setError( __( 'No suggestions were returned. Try generating again.', 'newspack-popups' ) );
			}
		} catch ( e ) {
			setError( e.message || __( 'Could not generate suggestions.', 'newspack-popups' ) );
		} finally {
			setGenerating( false );
		}
	};

	// A framing implies where the prompt sits; only used when inserting fresh —
	// picking new copy for an existing prompt never moves it.
	const positionForFraming = framing => {
		if ( 'top' === framing ) {
			return 0;
		}
		if ( 'end' === framing ) {
			return blockCount;
		}
		return Math.max( 1, Math.floor( blockCount / 2 ) );
	};

	const applyCandidate = candidate => {
		if ( instance ) {
			const copyBlock = findCopyBlock( instance.innerBlocks );
			if ( copyBlock ) {
				updateBlockAttributes( copyBlock.clientId, { content: candidate.body } );
			}
			selectBlock( instance.clientId );
		} else {
			insertBlock( createPromptBlock( candidate.body ), positionForFraming( candidate.framing ) );
		}
		setCandidates( [] );
	};

	const generateLabel =
		candidates.length || instance ? __( 'Regenerate suggestions', 'newspack-popups' ) : __( 'Generate suggestions', 'newspack-popups' );

	return (
		<PluginDocumentSettingPanel name="newspack-contextual-prompt" title={ __( 'Contextual Prompt', 'newspack-popups' ) }>
			<VStack spacing={ 4 }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ instance ? (
					<>
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %1$s: the edited content's post type label, e.g. "post", "page". */
								__( 'This %1$s has a Contextual Prompt. Edit its copy directly in the %1$s.', 'newspack-popups' ),
								POST_TYPE_LABEL
							) }
						</p>
						<HStack justify="flex-start" spacing={ 2 } wrap>
							<Button variant="secondary" onClick={ () => selectBlock( instance.clientId ) }>
								{ __( 'Select prompt', 'newspack-popups' ) }
							</Button>
							<Button isDestructive variant="tertiary" onClick={ () => removeBlock( instance.clientId ) }>
								{ __( 'Remove', 'newspack-popups' ) }
							</Button>
						</HStack>
					</>
				) : (
					<p style={ { margin: 0 } }>
						{ sprintf(
							/* translators: %s: the edited content's post type label, e.g. "post", "page". */
							__( 'Generate a donation prompt specific to this %s.', 'newspack-popups' ),
							POST_TYPE_LABEL
						) }
					</p>
				) }

				<Button variant="secondary" onClick={ generate } disabled={ generating }>
					{ generating && <Spinner /> }
					{ generating ? __( 'Generating…', 'newspack-popups' ) : generateLabel }
				</Button>

				{ candidates.map( ( candidate, index ) => (
					<VStack key={ index } spacing={ 2 } className="newspack-contextual-prompt__candidate">
						<strong>{ FRAMING_LABELS[ candidate.framing ] || candidate.framing }</strong>
						<p style={ { margin: 0 } }>{ candidate.body }</p>
						<div>
							<Button variant="secondary" onClick={ () => applyCandidate( candidate ) }>
								{ __( 'Apply', 'newspack-popups' ) }
							</Button>
						</div>
					</VStack>
				) ) }
			</VStack>
		</PluginDocumentSettingPanel>
	);
};

export default ContextualPromptPanel;
