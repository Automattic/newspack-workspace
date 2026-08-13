/**
 * Contextual Prompt editor panel.
 *
 * The prompt is an instance of the site's synced pattern, living in the post
 * content: copy is edited inline as a pattern override, position is the
 * instance's position, and the design comes from the pattern. A card the
 * publisher has detached from the pattern is still the post's prompt — its copy
 * is just written to its own paragraph. The panel's only jobs are AI generation
 * and managing that one card. Generation and candidate presentation are shared
 * with the instance inspector (see the candidates module).
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
import {
	buildOverrideAttrs,
	findBoundName,
	findCopyClientId,
	findPromptCards,
	isPromptInstance,
	PATTERN_ID,
} from '../blocks/contextual-prompt/instance';
import {
	POST_TYPE_LABEL,
	framingForPosition,
	generateCandidates,
	toRichTextContent,
	GenerateButton,
	CandidateList,
} from '../blocks/contextual-prompt/candidates';

const ContextualPromptPanel = () => {
	// Flat values only: the panel re-renders whenever this mapping stops being
	// shallow-equal, and the editor's store ticks on every keystroke.
	const {
		postId,
		postType,
		blockCount,
		promptClientId,
		promptDetached,
		promptCopyClientId,
		promptFraming,
		patternContent,
		patternResolved,
		patternExists,
	} = useSelect( select => {
		const editor = select( 'core/editor' );
		const blockEditor = select( 'core/block-editor' );
		const blocks = blockEditor.getBlocks() || [];
		// The prompt can sit anywhere, including nested inside a group or columns,
		// and is the post's prompt whether it still references the pattern or has
		// been detached from it.
		const [ card = null ] = findPromptCards( blocks );
		const detached = Boolean( card ) && ! isPromptInstance( card.name, card.attributes );
		const topLevelIndex = card ? blocks.findIndex( block => card.clientId === block.clientId ) : -1;
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			blockCount: blocks.length,
			promptClientId: card?.clientId ?? null,
			promptDetached: detached,
			// Detached copy is the paragraph itself, not an override.
			promptCopyClientId: detached ? findCopyClientId( card ) : null,
			// Once the prompt is placed, its position decides the framing — the
			// top/mid/end choice is only on offer before the first insert. A nested
			// prompt can't be bucketed, matching get_placement()'s 'unknown'.
			promptFraming: -1 === topLevelIndex ? null : framingForPosition( topLevelIndex, blocks.length ),
			// Copy is stored under the key the pattern names.
			patternContent: PATTERN_ID ? select( 'core' ).getEntityRecord( 'postType', 'wp_block', PATTERN_ID )?.content?.raw ?? '' : '',
			patternExists: PATTERN_ID ? Boolean( select( 'core' ).getEntityRecord( 'postType', 'wp_block', PATTERN_ID ) ) : false,
			patternResolved: PATTERN_ID
				? Boolean( select( 'core' ).hasFinishedResolution( 'getEntityRecord', [ 'postType', 'wp_block', PATTERN_ID ] ) )
				: false,
		};
	}, [] );

	const { insertBlock, updateBlockAttributes, selectBlock } = useDispatch( 'core/block-editor' );

	const [ candidates, setCandidates ] = useState( [] );
	const [ generating, setGenerating ] = useState( false );
	const [ applying, setApplying ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Whether a generation attempt has completed in the current framing context,
	// whatever it returned — a cached empty response must not be replayed on retry.
	const hasGenerated = useRef( false );

	// Requests dispatched before the busy state re-renders can overlap; only
	// the most recently dispatched one may touch state.
	const requestIdRef = useRef( 0 );

	// The block can be moved after a request is in flight; a request framed for
	// the old position must not overwrite the current one's candidates.
	const framingRef = useRef( promptFraming );
	useEffect( () => {
		framingRef.current = promptFraming;
	} );

	// Candidates are framed for a specific position, so a move to a different
	// bucket invalidates any already listed.
	useEffect( () => {
		setCandidates( [] );
		setError( '' );
		hasGenerated.current = false;
	}, [ promptFraming ] );

	const optedIn = window.newspackPopupsContextualPrompt?.enabled;
	const isPrompt = 'newspack_popups_cpt' === postType;

	// Hidden until an administrator opts the site into AI use; never on a prompt,
	// and never without the pattern every instance references — including one
	// that has gone from under an editor left open across an opt-out, where the
	// panel would otherwise blame the pattern for having no copy field.
	if ( ! optedIn || isPrompt || ! PATTERN_ID || ( patternResolved && ! patternExists ) ) {
		return null;
	}

	// Copy is stored under the name the pattern binds, read off its record: a
	// record that resolved to nothing to bind — the fetch failed, or the binding
	// was removed — has nowhere to put copy, so nothing may be generated or
	// applied. hasFinishedResolution() is true for a failed fetch too, which is
	// why the name is read rather than assumed once it settles.
	const boundName = findBoundName( patternContent );
	// A detached card is no longer keyed by the pattern: its copy is written to
	// its own paragraph, so the pattern's binding neither gates nor keys it.
	const warnsNoCopyField = ! promptDetached && patternResolved && ! boundName;
	const canApply = promptDetached ? Boolean( promptCopyClientId ) : patternResolved && Boolean( boundName );

	// Asking again is a rejection of what came back, so whenever the button reads
	// "Regenerate" the request must bypass the cached response. Only the very
	// first Generate in a fresh context is served from cache.
	const isRegenerate = Boolean( candidates.length || promptClientId || hasGenerated.current );

	const generate = async () => {
		const requestId = ++requestIdRef.current;
		const isCurrent = () => requestId === requestIdRef.current;
		setGenerating( true );
		setError( '' );
		const requestedFraming = promptFraming || undefined;
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
		if ( ! canApply ) {
			return;
		}
		if ( promptDetached ) {
			updateBlockAttributes( promptCopyClientId, { content: toRichTextContent( candidate.body ) } );
		} else if ( promptClientId ) {
			updateBlockAttributes( promptClientId, buildOverrideAttrs( boundName, candidate.body ) );
		} else {
			insertBlock(
				createBlock( 'core/block', { ref: PATTERN_ID, ...buildOverrideAttrs( boundName, candidate.body ) } ),
				positionForFraming( candidate.framing )
			);
		}
		if ( promptClientId ) {
			selectBlock( promptClientId );
		}
		setCandidates( [] );
	};

	const generateLabel = isRegenerate ? __( 'Regenerate Suggestions', 'newspack-popups' ) : __( 'Generate Suggestions', 'newspack-popups' );
	const listedCandidates = canApply ? candidates : [];

	return (
		<PluginDocumentSettingPanel name="newspack-contextual-prompt" title={ __( 'Contextual Prompt', 'newspack-popups' ) }>
			<VStack spacing={ 4 }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				<p style={ { margin: 0 } }>
					{ promptClientId
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

				{ warnsNoCopyField ? (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'The Contextual Prompt pattern has no editable copy field, so generated copy cannot be applied.', 'newspack-popups' ) }
					</Notice>
				) : (
					/* Without a prompt in the post, applying inserts one rather than
					   replacing its copy: confirm what actually happened. */
					<CandidateList
						candidates={ listedCandidates }
						onApply={ applyCandidate }
						onApplyingChange={ setApplying }
						confirmation={ ! promptDetached && ! promptClientId ? __( 'Contextual Prompt added.', 'newspack-popups' ) : undefined }
						action={
							<GenerateButton
								variant={ listedCandidates.length ? 'tertiary' : 'secondary' }
								busy={ generating }
								disabled={ applying }
								onClick={ generate }
							>
								{ generateLabel }
							</GenerateButton>
						}
					/>
				) }
			</VStack>
		</PluginDocumentSettingPanel>
	);
};

export default ContextualPromptPanel;
