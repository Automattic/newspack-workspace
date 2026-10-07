/**
 * A plain link field for buttons inside a Contextual Prompt card.
 *
 * The toolbar's link control rejects an on-page link with a `?` after the `#`
 * (`#donate?amount=10`), the shape donation modals open from with an amount
 * preselected. Core's hash-link check allows no `?` or `/` in a fragment,
 * though the URL standard does, and it has no filter. This field writes the
 * button's `url` directly, so a prompt's button can open an on-page modal on
 * any site.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { InspectorControls, store as blockEditorStore } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { isDetachedPromptCard } from './instance';

/**
 * Whether any of a block's parents is a prompt card. The pattern's own card
 * Group and a detached card in a story both carry the marker class, so one
 * check covers the pattern editor and detached cards.
 *
 * @param {Array<{name: string, attributes: Object}>} parents The block's parents.
 * @return {boolean} Whether the block sits inside a prompt card.
 */
export const isInsidePromptCard = parents => parents.some( ( { name, attributes } ) => isDetachedPromptCard( name, attributes ) );

/**
 * The sidebar field, shown only once the button is known to sit in a card.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.clientId      The button's client id.
 * @param {string}   props.url           The button's current link.
 * @param {Function} props.setAttributes The button's attribute setter.
 * @return {Element|null} The field, or nothing outside a card.
 */
const PromptButtonLink = ( { clientId, url, setAttributes } ) => {
	const insideCard = useSelect(
		select => {
			const { getBlockParents, getBlockName, getBlockAttributes } = select( blockEditorStore );
			return isInsidePromptCard(
				getBlockParents( clientId ).map( id => ( { name: getBlockName( id ), attributes: getBlockAttributes( id ) } ) )
			);
		},
		[ clientId ]
	);

	if ( ! insideCard ) {
		return null;
	}

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Link', 'newspack-popups' ) }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Button link', 'newspack-popups' ) }
					help={ __( 'Also accepts on-page links that the toolbar rejects, such as #donate?amount=10.', 'newspack-popups' ) }
					value={ url || '' }
					onChange={ value => setAttributes( { url: value || undefined } ) }
				/>
			</PanelBody>
		</InspectorControls>
	);
};

/**
 * Add the field to a selected link button. The button's own editor always
 * renders first and in the same place, so selecting it never remounts it.
 */
export const withPromptButtonLink = createHigherOrderComponent(
	BlockEdit => props => (
		<>
			<BlockEdit { ...props } />
			{ 'core/button' === props.name && props.isSelected && 'button' !== props.attributes?.tagName && (
				<PromptButtonLink clientId={ props.clientId } url={ props.attributes?.url } setAttributes={ props.setAttributes } />
			) }
		</>
	),
	'withPromptButtonLink'
);

/**
 * Register the field.
 */
export const registerPromptButtonLink = () => {
	addFilter( 'editor.BlockEdit', 'newspack-popups/contextual-prompt-button-link', withPromptButtonLink );
};
