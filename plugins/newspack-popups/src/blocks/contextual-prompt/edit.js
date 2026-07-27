/**
 * Contextual Prompt block editor component.
 *
 * The block is the styled container: design (background, text color, padding,
 * border, gap) comes from its own supports, with site-wide defaults supplied
 * as theme.json data. Its two children are fixed — the copy paragraph (text
 * editable) and the CTA, which is disabled outright: it renders from site
 * settings and is not editable per-story.
 *
 * Inserting the block generates its copy automatically; the block toolbar and
 * sidebar offer regeneration with per-candidate Apply.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { InspectorControls, useBlockProps, useInnerBlocksProps, store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock, createBlocksFromInnerBlocksTemplate } from '@wordpress/blocks';
import { Disabled, Notice, PanelBody } from '@wordpress/components';

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

// The CTA follows the site's reader-revenue setup: the donate block when
// Newspack donations are native, a plain button otherwise. The button defaults
// to the donor landing page configured in Campaigns settings, so conversions
// through it count the reader as a donor; publishers can retarget it per-story.
const DONATIONS_NATIVE = window.newspack_popups_blocks_data?.donations_native ?? true;
const DONOR_LANDING_URL = window.newspack_popups_blocks_data?.donor_landing_url || undefined;

export const getTemplate = () => [
	[ 'core/paragraph', {} ],
	DONATIONS_NATIVE
		? [ 'newspack-blocks/donate', { className: 'is-style-modern' } ]
		: [ 'core/buttons', {}, [ [ 'core/button', { text: __( 'Donate', 'newspack-popups' ), url: DONOR_LANDING_URL } ] ] ],
];

/**
 * Build a ready-to-insert prompt block carrying the given copy.
 *
 * @param {string} copy The prompt copy.
 * @return {Object} The block.
 */
export const createPromptBlock = copy => {
	const template = getTemplate();
	// The copy paragraph is the first child.
	template[ 0 ][ 1 ].content = toRichTextContent( copy );
	return createBlock( 'newspack-popups/contextual-prompt', {}, createBlocksFromInnerBlocksTemplate( template ) );
};

export const ContextualPromptEditor = ( { clientId } ) => {
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: getTemplate(),
		templateLock: 'all',
	} );

	const { postId, copyClientId, copyIsEmpty, framing } = useSelect(
		select => {
			const blockEditor = select( blockEditorStore );
			const copyId = blockEditor.getClientIdsOfDescendants( clientId ).find( id => 'core/paragraph' === blockEditor.getBlockName( id ) );
			const content = copyId ? blockEditor.getBlockAttributes( copyId ).content : undefined;
			return {
				// The core/editor store only exists in the post editor; elsewhere
				// (widgets editor) there is no post to generate from.
				postId: select( 'core/editor' )?.getCurrentPostId?.(),
				copyClientId: copyId,
				copyIsEmpty: ! content || ! content.toString().trim(),
				// Framing buckets the prompt's position among the top-level blocks; a
				// nested prompt can't be bucketed, matching get_placement()'s 'unknown'.
				framing:
					blockEditor.getBlockHierarchyRootClientId( clientId ) === clientId
						? framingForPosition( blockEditor.getBlockIndex( clientId ), blockEditor.getBlockCount() )
						: null,
			};
		},
		[ clientId ]
	);
	// Generation needs a real post: in the Site Editor getCurrentPostId() is a
	// template id string, which the endpoint cannot use.
	const canGenerate = Number.isInteger( postId );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	// Both children stay selectable so publishers can tweak them per-story; the
	// template lock prevents moving or removing them.
	const ctaClientId = useSelect(
		select => {
			const blockEditor = select( blockEditorStore );
			return blockEditor.getClientIdsOfDescendants( clientId ).find( id => 'newspack-blocks/donate' === blockEditor.getBlockName( id ) );
		},
		[ clientId ]
	);

	// Editor/front-end parity: stamp the resolved theme accent onto the CTA so
	// the canvas preview matches the render-time color. The stored value is
	// cosmetic — the front end always re-resolves.
	const accentColor = window.newspack_popups_blocks_data?.accent_color;
	const ctaButtonColor = useSelect(
		select => ( ctaClientId ? select( blockEditorStore ).getBlockAttributes( ctaClientId )?.buttonColor : undefined ),
		[ ctaClientId ]
	);
	useEffect( () => {
		if ( ctaClientId && accentColor && ctaButtonColor !== accentColor ) {
			wp.data.dispatch( 'core/block-editor' ).__unstableMarkNextChangeAsNotPersistent();
			updateBlockAttributes( ctaClientId, { buttonColor: accentColor } );
		}
	}, [ ctaClientId, accentColor, ctaButtonColor ] );

	const [ generating, setGenerating ] = useState( false );
	const [ candidates, setCandidates ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const autoAttempted = useRef( new Set() );

	// The block can be moved after a request is in flight; a request framed for
	// the old position must not overwrite the current one's candidates.
	const framingRef = useRef( framing );
	useEffect( () => {
		framingRef.current = framing;
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
				content: wp.data.select( 'core/editor' )?.getEditedPostContent?.(),
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
		if ( copyClientId ) {
			updateBlockAttributes( copyClientId, { content: toRichTextContent( candidate.body ) } );
		}
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

	// A fresh prompt generates its own copy — inserting the block should never
	// leave the editor with an empty placeholder to fill by hand. Attempts are
	// keyed by framing: a move while the request is in flight drops the stale
	// response, and the effect then retries once for the new position.
	useEffect( () => {
		if ( canGenerate && copyClientId && copyIsEmpty && ! autoAttempted.current.has( framing ) ) {
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
				const current = wp.data.select( 'core/block-editor' ).getBlockAttributes( copyClientId )?.content;
				if ( current && current.toString().trim() ) {
					setCandidates( list );
					return;
				}
				apply( list[ 0 ] );
			} );
		}
	}, [ canGenerate, copyClientId, copyIsEmpty, framing ] );

	// While the auto request fills the empty copy, the block reads as busy:
	// content disabled behind a "Generating copy…" label. An explicit
	// regenerate over existing copy leaves the block editable.
	const isAutoGenerating = generating && copyIsEmpty;
	const { children: innerContent, ...wrapperProps } = innerBlocksProps;

	return (
		<>
			{ canGenerate && (
				<InspectorControls>
					<PanelBody title={ __( 'Copy', 'newspack-popups' ) } initialOpen>
						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						) }
						<p style={ { marginTop: 0 } }>
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
						<GenerateButton busy={ generating } onClick={ regenerate }>
							{ __( 'Regenerate Suggestions', 'newspack-popups' ) }
						</GenerateButton>
						<CandidateList candidates={ candidates } onApply={ apply } />
					</PanelBody>
				</InspectorControls>
			) }
			<div { ...wrapperProps } className={ isAutoGenerating ? `${ wrapperProps.className } is-generating-copy` : wrapperProps.className }>
				{ isAutoGenerating ? (
					<>
						<Disabled>{ innerContent }</Disabled>
						<span className="newspack-popups__contextual-prompt-generating" aria-hidden={ false }>
							{ __( 'Generating copy…', 'newspack-popups' ) }
						</span>
					</>
				) : (
					innerContent
				) }
			</div>
		</>
	);
};
