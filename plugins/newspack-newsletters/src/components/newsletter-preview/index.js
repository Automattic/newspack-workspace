/**
 * WordPress dependencies
 */
import { BlockPreview } from '@wordpress/block-editor';
import { Spinner } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.scss';
import { getScopedCss } from '../../newsletter-editor/styling';
import { getSamplePosts } from '../../editor/blocks/posts-inserter/sample-posts';
import { getTemplateBlocks } from '../../editor/blocks/posts-inserter/utils';

const POSTS_INSERTER = 'newspack-newsletters/posts-inserter';

const withSamplePostsInserter = blocks => {
	if ( ! Array.isArray( blocks ) ) {
		return blocks;
	}
	const samples = getSamplePosts();
	let cursor = 0;
	const take = count => {
		const slice = Array.from( { length: count }, ( _, i ) => samples[ ( cursor + i ) % samples.length ] );
		cursor += count;
		return slice;
	};
	const walk = nodes =>
		nodes.flatMap( block => {
			if ( block?.name === POSTS_INSERTER ) {
				const count = block.attributes?.postsToShow || samples.length;
				return getTemplateBlocks( take( count ), block.attributes || {} );
			}
			if ( block?.innerBlocks?.length ) {
				return [ { ...block, innerBlocks: walk( block.innerBlocks ) } ];
			}
			return [ block ];
		} );
	return walk( blocks );
};

const NewsletterPreview = ( { layoutId = null, meta = {}, blocks, ...props } ) => {
	const previewBlocks = useMemo( () => withSamplePostsInserter( blocks ), [ blocks ] );
	const instanceId = useInstanceId( NewsletterPreview );
	const elementId = `preview-${ instanceId }`;
	const [ css, setCss ] = useState( '' );
	const [ isReady, setIsReady ] = useState( false );

	useEffect( () => {
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
		setCss( cssRules.length ? getScopedCss( `#${ elementId }`, cssRules.join( '\n' ) ) : '' );
	}, [ elementId, layoutId, meta.font_body, meta.font_header, meta.custom_css ] );

	// Apply the styles to the iframe editor.
	const useInlineStyles = () => {
		const ref = useRef();
		useEffect( () => {
			const node = ref.current;
			if ( ! node ) {
				return;
			}
			// Reset on input change so a new layout doesn't reveal mid-load.
			setIsReady( false );
			let cleanup = () => {};
			let cancelled = false;
			const safetyId = setTimeout( () => ! cancelled && setIsReady( true ), 8000 );
			const markReady = iframe => {
				if ( cancelled ) {
					return;
				}
				const doc = iframe?.contentDocument;
				if ( ! doc ) {
					return;
				}
				const awaitLoad = el =>
					new Promise( resolve => {
						el.addEventListener( 'load', resolve, { once: true } );
						el.addEventListener( 'error', resolve, { once: true } );
					} );
				const linkPromises = Array.from( doc.querySelectorAll( 'link[rel="stylesheet"]' ) )
					.filter( link => ! link.sheet )
					.map( awaitLoad );
				const imgPromises = Array.from( doc.querySelectorAll( 'img' ) )
					.filter( img => ! img.complete )
					.map( awaitLoad );
				Promise.all( [ ...linkPromises, ...imgPromises ] ).then( () => {
					if ( ! cancelled ) {
						clearTimeout( safetyId );
						setIsReady( true );
					}
				} );
			};
			const attach = iframe => {
				const appendStyle = () => {
					if ( ! iframe.contentDocument?.body ) {
						return;
					}
					// `wp-edit-blocks-css` is the editor variant — Gutenberg skips it but BlockPreview renders edit-mode markup (e.g. social-link buttons) that needs it.
					[ 'wp-block-library-css', 'wp-block-library-theme-css', 'wp-edit-blocks-css', 'wp-components-css' ].forEach( id => {
						const source = document.getElementById( id );
						if ( ! source || iframe.contentDocument.getElementById( id ) ) {
							return;
						}
						const clone = iframe.contentDocument.createElement( source.tagName );
						clone.id = id;
						if ( 'LINK' === source.tagName ) {
							clone.rel = 'stylesheet';
							clone.href = source.href;
						} else {
							clone.textContent = source.textContent;
						}
						iframe.contentDocument.head.appendChild( clone );
					} );
					const globalStylesId = 'newspack-newsletters-global-styles';
					if ( window.newspackNewslettersGlobalStyles && ! iframe.contentDocument.getElementById( globalStylesId ) ) {
						const globalStyles = iframe.contentDocument.createElement( 'style' );
						globalStyles.id = globalStylesId;
						globalStyles.textContent = window.newspackNewslettersGlobalStyles;
						iframe.contentDocument.head.appendChild( globalStyles );
					}
					// Newsletter-editor font defaults for surfaces that don't
					// enqueue `editor.css` (e.g. admin-shell layouts list).
					const defaultFontsId = 'newspack-newsletters-default-fonts';
					if ( ! iframe.contentDocument.getElementById( defaultFontsId ) ) {
						const defaultFonts = iframe.contentDocument.createElement( 'style' );
						defaultFonts.id = defaultFontsId;
						defaultFonts.textContent =
							'body *:not(code) { font-family: georgia, serif; } body h1, body h2, body h3, body h4, body h5, body h6 { font-family: arial, sans-serif; }';
						iframe.contentDocument.head.appendChild( defaultFonts );
					}
					iframe.contentDocument.body.id = elementId;
					// Scopes `editor.scss` overrides to layout thumbnails.
					iframe.contentDocument.body.classList.add( 'newspack-newsletters-layout-preview' );
					iframe.contentDocument.body.style.backgroundColor = meta.background_color || '';
					iframe.contentDocument.body.style.color = meta.text_color || '';
					const styleId = `newspack-newsletters__layout-preview-${ layoutId }`;
					let style = iframe.contentDocument.getElementById( styleId );
					if ( ! style ) {
						style = iframe.contentDocument.createElement( 'style' );
						style.id = styleId;
						iframe.contentDocument.head.appendChild( style );
					}
					style.textContent = css;
					markReady( iframe );
				};
				if ( 'complete' === iframe.contentDocument?.readyState ) {
					appendStyle();
				}
				iframe.addEventListener( 'load', appendStyle );
				cleanup = () => iframe.removeEventListener( 'load', appendStyle );
			};
			const initial = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( initial ) {
				attach( initial );
				return () => {
					cancelled = true;
					clearTimeout( safetyId );
					cleanup();
				};
			}
			const observer = new MutationObserver( () => {
				const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
				if ( iframe ) {
					observer.disconnect();
					attach( iframe );
				}
			} );
			observer.observe( node, { childList: true, subtree: true } );
			return () => {
				cancelled = true;
				clearTimeout( safetyId );
				observer.disconnect();
				cleanup();
			};
		}, [ layoutId, css, meta.background_color, meta.text_color, previewBlocks ] );
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
				className={ `newspack-newsletters__layout-preview${ isReady ? ' is-ready' : '' }` }
				style={ { backgroundColor: meta.background_color } }
			>
				{ ! isReady && (
					<div className="newspack-newsletters__layout-preview-spinner">
						<Spinner />
					</div>
				) }
				<BlockPreview { ...props } blocks={ previewBlocks } />
			</div>
		</Fragment>
	);
};

export default NewsletterPreview;
