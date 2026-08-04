/**
 * Contextual Prompt editor panel.
 *
 * The prompt is an instance of the site's synced pattern, living in the post
 * content: copy is edited inline as a pattern override, position is the
 * instance's position, and the design comes from the pattern. The panel's only
 * jobs are AI generation and managing that one instance. Generation and
 * candidate presentation are shared with the instance inspector (see the
 * candidates module).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useSelect, useDispatch, select as coreSelect } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import {
	Notice,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { buildOverrideAttrs, getBoundName, isPromptInstance, PATTERN_ID } from '../blocks/contextual-prompt/instance';
import { POST_TYPE_LABEL, framingForPosition, generateCandidates, GenerateButton, CandidateList } from '../blocks/contextual-prompt/candidates';

const ContextualPromptPanel = () => {
	const { postId, postType, blockCount, instance, instanceFraming, patternContent, patternResolved } = useSelect( select => {
		const editor = select( 'core/editor' );
		const blockEditor = select( 'core/block-editor' );
		const blocks = blockEditor.getBlocks() || [];
		// The prompt can sit anywhere, including nested inside a group or columns.
		const promptClientId = blockEditor
			.getClientIdsWithDescendants()
			.find( clientId => isPromptInstance( blockEditor.getBlockName( clientId ), blockEditor.getBlockAttributes( clientId ) ) );
		const topLevelIndex = promptClientId ? blocks.findIndex( block => promptClientId === block.clientId ) : -1;
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			blockCount: blocks.length,
			instance: promptClientId ? blockEditor.getBlock( promptClientId ) : null,
			// Once the prompt is placed, its position decides the framing — the
			// top/mid/end choice is only on offer before the first insert. A nested
			// prompt can't be bucketed, matching get_placement()'s 'unknown'.
			instanceFraming: -1 === topLevelIndex ? null : framingForPosition( topLevelIndex, blocks.length ),
			// Copy is stored under the key the pattern names.
			patternContent: PATTERN_ID ? select( 'core' ).getEntityRecord( 'postType', 'wp_block', PATTERN_ID )?.content?.raw ?? '' : '',
			patternResolved: PATTERN_ID
				? Boolean( select( 'core' ).hasFinishedResolution( 'getEntityRecord', [ 'postType', 'wp_block', PATTERN_ID ] ) )
				: false,
		};
	}, [] );

	const { insertBlock, updateBlockAttributes, selectBlock } = useDispatch( 'core/block-editor' );

	const [ candidates, setCandidates ] = useState( [] );
	const [ generating, setGenerating ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Whether a generation attempt has completed in the current framing context,
	// whatever it returned — a cached empty response must not be replayed on retry.
	const hasGenerated = useRef( false );

	// Requests dispatched before the busy state re-renders can overlap; only
	// the most recently dispatched one may touch state.
	const requestIdRef = useRef( 0 );

	// The block can be moved after a request is in flight; a request framed for
	// the old position must not overwrite the current one's candidates.
	const framingRef = useRef( instanceFraming );
	useEffect( () => {
		framingRef.current = instanceFraming;
	} );

	// Candidates are framed for a specific position, so a move to a different
	// bucket invalidates any already listed.
	useEffect( () => {
		setCandidates( [] );
		setError( '' );
		hasGenerated.current = false;
	}, [ instanceFraming ] );

	const optedIn = window.newspackPopupsContextualPrompt?.enabled;
	const isPrompt = 'newspack_popups_cpt' === postType;

	// Hidden until an administrator opts the site into AI use; never on a prompt,
	// and never without the pattern every instance references.
	if ( ! optedIn || isPrompt || ! PATTERN_ID ) {
		return null;
	}

	// Asking again is a rejection of what came back, so whenever the button reads
	// "Regenerate" the request must bypass the cached response. Only the very
	// first Generate in a fresh context is served from cache.
	const isRegenerate = Boolean( candidates.length || instance || hasGenerated.current );

	const generate = async () => {
		const requestId = ++requestIdRef.current;
		const isCurrent = () => requestId === requestIdRef.current;
		setGenerating( true );
		setError( '' );
		const requestedFraming = instanceFraming || undefined;
		try {
			const list = await generateCandidates( {
				postId,
				content: coreSelect( 'core/editor' ).getEditedPostContent(),
				framing: requestedFraming,
				regenerate: isRegenerate,
			} );
			// The request was superseded, or the block moved to a different framing
			// bucket while it was in flight — the response is stale, so drop it.
			if ( ! isCurrent() || ( framingRef.current || undefined ) !== requestedFraming ) {
				return;
			}
			setCandidates( list );
			if ( ! list.length ) {
				setError( __( 'No suggestions were returned. Try generating again.', 'newspack-popups' ) );
			}
		} catch ( e ) {
			// Mirror the success-path guard: an error from a request framed for a
			// previous position must not surface in the current framing context.
			if ( isCurrent() && ( framingRef.current || undefined ) === requestedFraming ) {
				setError( e.message || __( 'Could not generate suggestions.', 'newspack-popups' ) );
			}
		} finally {
			if ( isCurrent() ) {
				// A stale response belongs to a framing context this ref was already
				// reset for; only a settled attempt for the current one counts.
				if ( ( framingRef.current || undefined ) === requestedFraming ) {
					hasGenerated.current = true;
				}
				setGenerating( false );
			}
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
		const overrideAttrs = buildOverrideAttrs( getBoundName( patternContent ), candidate.body );
		if ( instance ) {
			updateBlockAttributes( instance.clientId, overrideAttrs );
			selectBlock( instance.clientId );
		} else {
			insertBlock( createBlock( 'core/block', { ref: PATTERN_ID, ...overrideAttrs } ), positionForFraming( candidate.framing ) );
		}
		setCandidates( [] );
	};

	const generateLabel = isRegenerate ? __( 'Regenerate Suggestions', 'newspack-popups' ) : __( 'Generate Suggestions', 'newspack-popups' );

	return (
		<PluginDocumentSettingPanel name="newspack-contextual-prompt" title={ __( 'Contextual Prompt', 'newspack-popups' ) }>
			<VStack spacing={ 4 }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				<p style={ { margin: 0 } }>
					{ instance
						? sprintf(
								/* translators: %1$s: the edited content's post type label, e.g. "post", "page". */
								__( 'This %1$s has a Contextual Prompt. Edit its copy directly in the %1$s.', 'newspack-popups' ),
								POST_TYPE_LABEL
						  )
						: sprintf(
								/* translators: %s: the edited content's post type label, e.g. "post", "page". */
								__( 'Generate a donation prompt specific to this %s.', 'newspack-popups' ),
								POST_TYPE_LABEL
						  ) }
				</p>

				<GenerateButton busy={ generating } onClick={ generate }>
					{ generateLabel }
				</GenerateButton>
			</VStack>

			{ /* Copy is keyed by the name the pattern binds, read off its record:
			     nothing may be applied until that record has resolved, or a
			     renamed pattern gets an override under a key it does not bind. */ }
			<CandidateList candidates={ patternResolved ? candidates : [] } onApply={ applyCandidate } />
		</PluginDocumentSettingPanel>
	);
};

export default ContextualPromptPanel;
