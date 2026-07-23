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
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { InspectorControls, useBlockProps, useInnerBlocksProps, store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock, createBlocksFromInnerBlocksTemplate } from '@wordpress/blocks';
import { Button, PanelBody } from '@wordpress/components';

const POST_TYPE_LABEL = window.newspack_popups_blocks_data?.post_type_label || __( 'post', 'newspack-popups' );

const FRAMING_LABELS = {
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	top: sprintf( __( 'Top of %s', 'newspack-popups' ), POST_TYPE_LABEL ),
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	mid: sprintf( __( 'Mid-%s', 'newspack-popups' ), POST_TYPE_LABEL ),
	/* translators: %s: the edited content's post type label, e.g. "post", "page". */
	end: sprintf( __( 'End of %s', 'newspack-popups' ), POST_TYPE_LABEL ),
};

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
	template[ 0 ][ 1 ].content = copy;
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
			// The framing follows the block's actual position in the story, with a
			// small buffer: a prompt sitting two paragraphs in still reads as a
			// top-of-story ask, and likewise near the end.
			const FRAMING_BUFFER = 3;
			const index = blockEditor.getBlockIndex( clientId );
			const total = blockEditor.getBlockCount();
			let position = 'mid';
			if ( index < FRAMING_BUFFER ) {
				position = 'top';
			} else if ( index >= total - FRAMING_BUFFER ) {
				position = 'end';
			}
			return {
				postId: select( 'core/editor' ).getCurrentPostId(),
				copyClientId: copyId,
				copyIsEmpty: ! content || ! content.toString().trim(),
				framing: position,
			};
		},
		[ clientId ]
	);
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
	const autoRan = useRef( false );

	const fetchCandidates = async () => {
		setGenerating( true );
		try {
			const response = await apiFetch( {
				path: '/wp/v2/newspack-editorial-assistant/generate/donation',
				method: 'POST',
				data: { post_id: postId, content: wp.data.select( 'core/editor' ).getEditedPostContent(), framing },
			} );
			const payload = response && response.data ? response.data : response;
			return payload?.candidates || [];
		} finally {
			setGenerating( false );
		}
	};

	const apply = candidate => {
		if ( copyClientId ) {
			updateBlockAttributes( copyClientId, { content: candidate.body } );
		}
		setCandidates( [] );
	};

	const regenerate = () => fetchCandidates().then( setCandidates );

	// A fresh prompt generates its own copy — inserting the block should never
	// leave the editor with an empty placeholder to fill by hand.
	useEffect( () => {
		if ( copyClientId && copyIsEmpty && ! autoRan.current ) {
			autoRan.current = true;
			fetchCandidates().then( list => {
				if ( list[ 0 ] ) {
					apply( list[ 0 ] );
				}
			} );
		}
	}, [ copyClientId, copyIsEmpty ] );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Copy', 'newspack-popups' ) } initialOpen>
					<p style={ { marginTop: 0 } }>
						{ sprintf(
							/* translators: %1$s: the edited content's post type label. %2$s: the prompt's position. */
							__(
								'Copy is generated from this %1$s, framed for its position (%2$s). Review a suggestion and apply it to replace the current copy.',
								'newspack-popups'
							),
							POST_TYPE_LABEL,
							FRAMING_LABELS[ framing ].toLowerCase()
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ regenerate }
						disabled={ generating }
						isBusy={ generating }
						__next40pxDefaultSize
						style={ { width: '100%', justifyContent: 'center' } }
					>
						{ generating ? __( 'Generating…', 'newspack-popups' ) : __( 'Regenerate suggestions', 'newspack-popups' ) }
					</Button>
					{ candidates.map( ( candidate, index ) => (
						<div key={ index } style={ { marginTop: '16px' } }>
							<strong>{ FRAMING_LABELS[ candidate.framing ] || candidate.framing }</strong>
							<p style={ { margin: '4px 0 8px' } }>{ candidate.body }</p>
							<Button variant="primary" size="small" onClick={ () => apply( candidate ) }>
								{ __( 'Apply', 'newspack-popups' ) }
							</Button>
						</div>
					) ) }
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps } />
		</>
	);
};
