/**
 * WordPress dependencies
 */
import { RichText, useBlockProps } from '@wordpress/block-editor';

export default ( { attributes } ) => {
	let { content, href } = attributes;

	// HACK: The block content contains the anchor element after saving,
	// but not when first inserted in the editor. So here the wrapping anchor
	// is stripped. This will cause a warning in JS console, but no disruption
	// to the editing experience.
	if ( content.indexOf( '<a' ) === 0 ) {
		const el = document.createElement( 'div' );
		el.innerHTML = content;
		content = el.querySelector( 'a' ).innerHTML;
	}

	return (
		<p { ...useBlockProps.save() }>
			<a href={ href }>
				<RichText.Content value={ content } />
			</a>
		</p>
	);
};
