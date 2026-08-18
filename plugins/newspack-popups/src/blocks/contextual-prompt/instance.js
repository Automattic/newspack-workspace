/**
 * Contextual Prompt pattern instances.
 *
 * A prompt is a synced pattern instance: a `core/block` referencing the pattern
 * post, carrying its story-specific copy as a pattern override. The design lives
 * in the pattern, so the only thing an instance needs from us is the copy —
 * generated on insertion and regenerable from the block inspector.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useSelect, useDispatch, select as coreSelect } from '@wordpress/data';
import { InspectorControls, store as blockEditorStore } from '@wordpress/block-editor';
import { parse } from '@wordpress/blocks';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { Notice, PanelBody, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import {
	POST_TYPE_LABEL,
	FRAMING_LABELS,
	framingForPosition,
	generateCandidates,
	toRichTextContent,
	GenerateButton,
	CandidateList,
} from './candidates';

// The block editor and the document-settings panel are separate entries with
// separate localized objects; either may be the one present. Both stringify the
// id, so it is compared as a number.
export const PATTERN_ID = Number(
	window.newspack_popups_blocks_data?.contextual_prompts_pattern_id || window.newspackPopupsContextualPrompt?.patternId || 0
);

/**
 * Whether a block is a Contextual Prompt: an instance of the site's pattern.
 *
 * @param {string} name       Block name.
 * @param {Object} attributes Block attributes.
 * @return {boolean} Whether the block is a prompt instance.
 */
export const isPromptInstance = ( name, attributes ) => 'core/block' === name && Boolean( PATTERN_ID ) && Number( attributes?.ref ) === PATTERN_ID;

// Mirrors MARKER_CLASS in class-newspack-popups-contextual-prompt-pattern.php:
// the class the pattern's card carries, and the only thing a copy detached from
// the pattern still has to identify it by.
export const MARKER_CLASS = 'newspack-contextual-prompt';

/**
 * Whether a block is a Contextual Prompt detached from the pattern: the card's
 * own markup, no longer referencing the pattern post.
 *
 * @param {string} name       Block name.
 * @param {Object} attributes Block attributes.
 * @return {boolean} Whether the block is a detached prompt card.
 */
export const isDetachedPromptCard = ( name, attributes ) =>
	'core/group' === name &&
	String( attributes?.className || '' )
		.split( /\s+/ )
		.includes( MARKER_CLASS );

/**
 * Whether a block is the post's prompt, however it is stored.
 *
 * @param {string} name       Block name.
 * @param {Object} attributes Block attributes.
 * @return {boolean} Whether the block is a prompt card.
 */
export const isPromptCard = ( name, attributes ) => isPromptInstance( name, attributes ) || isDetachedPromptCard( name, attributes );

/**
 * Every prompt card in a block tree, in document order.
 *
 * A card is never descended into: an instance carries the pattern's own
 * marker-classed Group as an inner block, which is the same card, not a second
 * one. Neither is any other synced pattern: its content is not this post's, and
 * a prompt is never nested inside one.
 *
 * @param {Object[]} blocks Block tree.
 * @return {Object[]} The prompt cards.
 */
export const findPromptCards = blocks => {
	const found = [];
	const walk = list => {
		for ( const block of list || [] ) {
			if ( isPromptCard( block?.name, block?.attributes ) ) {
				found.push( block );
				continue;
			}
			if ( 'core/block' === block?.name ) {
				continue;
			}
			walk( block?.innerBlocks );
		}
	};
	walk( blocks );

	return found;
};

/**
 * The first `core/paragraph` under a block: a detached card's copy, which is
 * written directly rather than as a pattern override.
 *
 * @param {Object} block Block to search.
 * @return {string|null} The paragraph's client id.
 */
export const findCopyClientId = block => {
	for ( const child of block?.innerBlocks || [] ) {
		if ( 'core/paragraph' === child.name ) {
			return child.clientId;
		}
		const found = findCopyClientId( child );
		if ( found ) {
			return found;
		}
	}

	return null;
};

/**
 * The name of the pattern's override-bound paragraph — the key an instance's
 * copy is stored under — or null when it has none. Null is the answer a caller
 * has to act on: an override under a name the pattern does not bind is copy
 * nothing will ever render.
 *
 * @param {string} patternContent The pattern's markup.
 * @return {string|null} The bound paragraph's name.
 */
export const findBoundName = patternContent => {
	const walk = blocks => {
		for ( const block of blocks ) {
			const metadata = block.attributes?.metadata;
			const isBound = Object.values( metadata?.bindings || {} ).some( binding => 'core/pattern-overrides' === binding?.source );
			if ( 'core/paragraph' === block.name && isBound && metadata?.name ) {
				return metadata.name;
			}
			const found = walk( block.innerBlocks || [] );
			if ( found ) {
				return found;
			}
		}
		return null;
	};

	try {
		return walk( parse( String( patternContent || '' ) ) );
	} catch ( e ) {
		return null;
	}
};

/**
 * The instance attributes carrying a piece of copy as a pattern override.
 *
 * @param {string} boundName The pattern's bound paragraph name.
 * @param {string} body      The copy.
 * @return {Object} Block attributes.
 */
export const buildOverrideAttrs = ( boundName, body ) => ( {
	content: { [ boundName ]: { content: toRichTextContent( body ) } },
} );

/**
 * Whether an instance is still waiting for its copy.
 *
 * @param {Object} content The instance's overrides.
 * @return {boolean} Whether the override is empty.
 */
const isOverrideEmpty = content => ! content || ! Object.values( content ).some( value => value?.content?.toString().trim() );

/**
 * Whether an instance should generate its own copy on sight.
 *
 * The pattern has to have resolved with a bound paragraph first: without one
 * there is nowhere to put the result, so generating would spend a request on
 * copy nothing can consume and dirty the post with an orphan override. Waiting
 * for the record also keeps the applied override keyed by the name the pattern
 * actually carries rather than the fallback.
 *
 * @param {Object}      args                 Guard inputs.
 * @param {boolean}     args.canGenerate     Whether the editor has a post to generate from.
 * @param {boolean}     args.overrideIsEmpty Whether the instance is still waiting for its copy.
 * @param {boolean}     args.patternResolved Whether the pattern record has finished resolving.
 * @param {string|null} args.boundName       The pattern's bound paragraph name, if it has one.
 * @param {boolean}     args.attempted       Whether this framing bucket has already been tried.
 * @return {boolean} Whether to generate.
 */
export const shouldAutoGenerate = ( { canGenerate, overrideIsEmpty, patternResolved, boundName, attempted } ) =>
	Boolean( canGenerate && overrideIsEmpty && patternResolved && boundName && ! attempted );

const PromptInstanceInspector = ( { clientId, attributes } ) => {
	const { postId, framing, patternContent, patternResolved, patternExists } = useSelect(
		select => {
			const blockEditor = select( blockEditorStore );
			const record = select( 'core' ).getEntityRecord( 'postType', 'wp_block', PATTERN_ID );
			return {
				// The core/editor store only exists in the post editor; elsewhere
				// (widgets editor) there is no post to generate from.
				postId: select( 'core/editor' )?.getCurrentPostId?.(),
				// Framing buckets the prompt's position among the top-level blocks; a
				// nested prompt can't be bucketed, matching get_placement()'s 'unknown'.
				framing:
					blockEditor.getBlockHierarchyRootClientId( clientId ) === clientId
						? framingForPosition( blockEditor.getBlockIndex( clientId ), blockEditor.getBlockCount() )
						: null,
				patternContent: record?.content?.raw ?? '',
				patternResolved: select( 'core' ).hasFinishedResolution( 'getEntityRecord', [ 'postType', 'wp_block', PATTERN_ID ] ),
				patternExists: Boolean( record ),
			};
		},
		[ clientId ]
	);
	// Generation needs a real post: in the Site Editor getCurrentPostId() is a
	// template id string, which the endpoint cannot use.
	const canGenerate = Number.isInteger( postId );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	const overrideIsEmpty = isOverrideEmpty( attributes.content );
	const boundName = findBoundName( patternContent );

	const [ generating, setGenerating ] = useState( false );
	const [ candidates, setCandidates ] = useState( [] );
	const [ applying, setApplying ] = useState( false );
	const [ error, setError ] = useState( '' );
	const autoAttempted = useRef( new Set() );

	// The block can be moved after a request is in flight; a request framed for
	// the old position must not overwrite the current one's candidates.
	const framingRef = useRef( framing );
	// The pattern record resolves after the first render, so an apply dispatched
	// from a request in flight reads the name it settled on rather than the one
	// the request started with.
	const boundNameRef = useRef( boundName );
	useEffect( () => {
		framingRef.current = framing;
		boundNameRef.current = boundName;
	} );

	// Candidates are framed for a specific position, so a move to a different
	// bucket invalidates any already listed.
	useEffect( () => {
		setCandidates( [] );
		setError( '' );
	}, [ framing ] );

	// Requests can overlap (an auto attempt racing an explicit regenerate);
	// only the most recently dispatched one may touch state — a stale
	// settlement must neither apply its result nor re-enable the UI early.
	const requestIdRef = useRef( 0 );

	const handleError = e => setError( e.message || __( 'Could not generate suggestions.', 'newspack-popups' ) );

	const fetchCandidates = async options => {
		const requestId = ++requestIdRef.current;
		const isCurrent = () => requestId === requestIdRef.current;
		const requestedFraming = framing;
		setGenerating( true );
		setError( '' );
		try {
			const list = await generateCandidates( {
				postId,
				content: coreSelect( 'core/editor' )?.getEditedPostContent?.(),
				framing,
				...options,
			} );
			return { list, isCurrent };
		} catch ( e ) {
			// Mirror the success-path guard: an error from a request framed for a
			// previous position must not surface in the current framing context.
			if ( isCurrent() && ( framingRef.current || undefined ) === ( requestedFraming || undefined ) ) {
				handleError( e );
			}
			return { list: null, isCurrent };
		} finally {
			if ( isCurrent() ) {
				setGenerating( false );
			}
		}
	};

	const apply = candidate => {
		// Nowhere to put the copy: writing an override under a name the pattern
		// does not bind would dirty the post with an attribute nothing reads.
		if ( ! boundNameRef.current ) {
			return;
		}
		updateBlockAttributes( clientId, buildOverrideAttrs( boundNameRef.current, candidate.body ) );
		setCandidates( [] );
	};

	// Asking again is a rejection of what came back, so it must not be served the
	// cached response.
	const regenerate = () => {
		const requestedFraming = framing;
		return fetchCandidates( { regenerate: true } ).then( ( { list, isCurrent } ) => {
			// The request errored, was superseded by a newer one, or the block
			// moved to a different framing bucket while it was in flight — the
			// response is stale, so drop it.
			if ( ! list || ! isCurrent() || ( framingRef.current || undefined ) !== ( requestedFraming || undefined ) ) {
				return;
			}
			setCandidates( list );
			if ( ! list.length ) {
				setError( __( 'No suggestions were returned. Try generating again.', 'newspack-popups' ) );
			}
		} );
	};

	// A fresh prompt generates its own copy — inserting one should never leave the
	// editor with an empty placeholder to fill by hand. Attempts are keyed by
	// framing: a move while the request is in flight drops the stale response, and
	// the effect then retries once for the new position.
	useEffect( () => {
		if ( shouldAutoGenerate( { canGenerate, overrideIsEmpty, patternResolved, boundName, attempted: autoAttempted.current.has( framing ) } ) ) {
			autoAttempted.current.add( framing );
			const requestedFraming = framing;
			fetchCandidates().then( ( { list, isCurrent } ) => {
				if ( ! list || ! isCurrent() || ( framingRef.current || undefined ) !== ( requestedFraming || undefined ) ) {
					return;
				}
				if ( ! list.length ) {
					setError( __( 'No suggestions were returned. Try generating again.', 'newspack-popups' ) );
					return;
				}
				// Copy typed while the request was in flight is the editor's, not
				// ours to replace: offer the response as candidates instead.
				if ( ! isOverrideEmpty( coreSelect( blockEditorStore ).getBlockAttributes( clientId )?.content ) ) {
					setCandidates( list );
					return;
				}
				apply( list[ 0 ] );
			} );
		}
	}, [ canGenerate, overrideIsEmpty, patternResolved, boundName, framing ] );

	// The pattern is gone from under this editor: the site opted out, or it was
	// lost. Nothing here can generate or apply copy, and the reason is not this
	// screen's to explain — the wizard owns the feature's state.
	if ( ! canGenerate || ( patternResolved && ! patternExists ) ) {
		return null;
	}

	return (
		// A selected pattern instance is a section block, and the inspector mounts
		// only the content and list slot groups for one — a default-group fill
		// would never render.
		<InspectorControls group="content">
			<PanelBody title={ __( 'Prompt Copy', 'newspack-popups' ) } initialOpen>
				<VStack spacing={ 4 }>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<p style={ { margin: 0 } }>
						{ framing
							? sprintf(
									/* translators: %1$s: the edited content's post type label. %2$s: the prompt's position. */
									__(
										'Copy is generated from this %1$s, framed for its position (%2$s). Review a suggestion and apply it to replace the current copy.',
										'newspack-popups'
									),
									POST_TYPE_LABEL,
									FRAMING_LABELS[ framing ].toLowerCase()
							  )
							: sprintf(
									/* translators: %s: the edited content's post type label. */
									__(
										'Copy is generated from this %s. Review a suggestion and apply it to replace the current copy.',
										'newspack-popups'
									),
									POST_TYPE_LABEL
							  ) }
					</p>
					{ patternResolved && ! boundName ? (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'The Contextual Prompt pattern has no editable copy field, so generated copy cannot be applied.',
								'newspack-popups'
							) }
						</Notice>
					) : (
						<CandidateList
							candidates={ candidates }
							onApply={ apply }
							onApplyingChange={ setApplying }
							action={
								<GenerateButton
									variant={ candidates.length ? 'tertiary' : 'secondary' }
									busy={ generating }
									disabled={ applying }
									onClick={ regenerate }
								>
									{ __( 'Regenerate Suggestions', 'newspack-popups' ) }
								</GenerateButton>
							}
						/>
					) }
				</VStack>
			</PanelBody>
		</InspectorControls>
	);
};

export const withPromptInstanceInspector = createHigherOrderComponent(
	BlockEdit => props =>
		isPromptInstance( props.name, props.attributes ) ? (
			<>
				<BlockEdit { ...props } />
				<PromptInstanceInspector clientId={ props.clientId } attributes={ props.attributes } />
			</>
		) : (
			<BlockEdit { ...props } />
		),
	'withPromptInstanceInspector'
);

export const registerContextualPromptInstance = () => {
	// No prompts inside prompts.
	if ( window.newspack_popups_blocks_data?.is_prompt ) {
		return;
	}

	addFilter( 'editor.BlockEdit', 'newspack-popups/contextual-prompt-instance', withPromptInstanceInspector );
};
