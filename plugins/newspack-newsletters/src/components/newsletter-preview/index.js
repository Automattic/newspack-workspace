/**
 * WordPress dependencies
 */
import { BlockPreview } from '@wordpress/block-editor';
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.scss';
import { getScopedCss } from '../../newsletter-editor/styling';

const NewsletterPreview = ( { layoutId = null, meta = {}, ...props } ) => {
	const [ elementId, setElementId ] = useState( '' );
	const [ css, setCss ] = useState( '' );

	// Generate inline layout styles for the preview.
	useEffect(
		() => {
			const _elementId = `preview-${ Math.round( Math.random() * 1000 ) }`;
			setElementId( _elementId );
			const cssRules = [];
			if ( meta.font_body ) {
				cssRules.push( `*:not( code ) { font-family: ${ meta.font_body }; }` );
			}
			if ( meta.font_header ) {
				cssRules.push( `h1, h2, h3, h4, h5, h6 { font-family: ${ meta.font_header }; }` );
			}
			if ( meta.custom_css ) {
				cssRules.push( meta.custom_css );
			}
			setCss( cssRules.length ? getScopedCss( `#${ _elementId }`, cssRules.join( '\n' ) ) : '' );
		},
		[ layoutId ].concat( Object.values( meta ) )
	);

	// Apply the styles to the iframe editor.
	const useInlineStyles = () => {
		const ref = useRef();
		useEffect( () => {
			const node = ref.current;
			const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( iframe ) {
				const appendStyle = () => {
					const style = document.createElement( 'style' );
					style.id = `newspack-newsletters__layout-preview-${ layoutId }`;
					style.textContent = css;
					if ( iframe.contentDocument?.body ) {
						iframe.contentDocument.body.id = elementId;
						iframe.contentDocument.head.appendChild( style );
					}
				};
				appendStyle();
				// Handle Firefox iframe.
				iframe.addEventListener( 'load', appendStyle );
				return () => {
					iframe.removeEventListener( 'load', appendStyle );
				};
			}
		}, [ layoutId, css ] );
		return ref;
	};

	return (
		<Fragment>
			<style id="newspack-newsletters__layout-css" data-previewid={ elementId }>
				{ css }
			</style>
			<div
				ref={ useInlineStyles() }
				id={ elementId }
				className="newspack-newsletters__layout-preview"
				style={ {
					backgroundColor: meta.background_color,
					textColor: meta.text_color,
				} }
			>
				<BlockPreview { ...props } />
			</div>
		</Fragment>
	);
};

export default NewsletterPreview;
