/**
 * WordPress dependencies
 */
import { BlockPreview, store as blockEditorStore } from '@wordpress/block-editor';
import { Spinner } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.scss';
import { getSamplePosts } from '../../editor/blocks/posts-inserter/sample-posts';
import { getTemplateBlocks } from '../../editor/blocks/posts-inserter/utils';

const POSTS_INSERTER = 'newspack-newsletters/posts-inserter';

const CORE_STYLESHEET_IDS = [ 'wp-block-library-css', 'wp-block-library-theme-css', 'wp-edit-blocks-css', 'wp-components-css' ];

const DEFAULT_FONTS_CSS =
	'body *:not(code) { font-family: georgia, serif; } body h1, body h2, body h3, body h4, body h5, body h6 { font-family: arial, sans-serif; }';

const buildResolvedStyles = () => {
	const sources = CORE_STYLESHEET_IDS.map( id => [ id, document.getElementById( id ) ] );
	if ( process.env.NODE_ENV !== 'production' ) {
		const missing = sources.filter( ( [ , node ] ) => ! node ).map( ( [ id ] ) => id );
		if ( missing.length ) {
			// eslint-disable-next-line no-console
			console.warn(
				`[newspack-newsletters] NewsletterPreview: core stylesheet(s) not enqueued, preview may render unstyled: ${ missing.join( ', ' ) }`
			);
		}
	}
	return sources
		.map( ( [ , node ] ) => node )
		.filter( Boolean )
		.map( source => source.outerHTML )
		.join( '\n' );
};

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
	const [ isReady, setIsReady ] = useState( false );

	// Admin-shell previews lack the editor-provided assets, so seed them; skip
	// the live editor (populated string) so its canvas isn't reloaded. Environment
	// is inferred from the private `__unstableResolvedAssets.styles` field: the live
	// editor populates it before any preview mounts, the admin shell leaves it
	// undefined. If a future WP populates it everywhere, admin-shell previews would
	// stop seeding and an explicit mount-site flag would be needed instead.
	const { updateSettings } = useDispatch( blockEditorStore );
	const resolvedAssets = useSelect( select => select( blockEditorStore ).getSettings().__unstableResolvedAssets, [] );
	useEffect( () => {
		if ( typeof resolvedAssets?.styles === 'string' ) {
			return;
		}
		updateSettings( {
			__unstableResolvedAssets: { styles: buildResolvedStyles(), scripts: '' },
			styles: [
				...( window.newspackNewslettersGlobalStyles ? [ { css: window.newspackNewslettersGlobalStyles } ] : [] ),
				{ css: DEFAULT_FONTS_CSS },
			],
		} );
	}, [ resolvedAssets, updateSettings ] );

	const additionalStyles = useMemo( () => {
		const rules = [];
		// `!important` so layout fonts/colors beat the seeded defaults and the
		// editor's own var-based font rules (higher specificity) in editor previews.
		if ( meta.font_body ) {
			rules.push( `body *:not( code ) { font-family: ${ meta.font_body } !important; }` );
		}
		if ( meta.font_header ) {
			rules.push( `body h1, body h2, body h3, body h4, body h5, body h6 { font-family: ${ meta.font_header } !important; }` );
		}
		if ( meta.background_color ) {
			rules.push( `body { background-color: ${ meta.background_color } !important; }` );
		}
		if ( meta.text_color ) {
			rules.push( `body { color: ${ meta.text_color } !important; }` );
		}
		if ( meta.custom_css ) {
			rules.push( meta.custom_css );
		}
		return rules.length ? [ { css: rules.join( '\n' ) } ] : [];
	}, [ meta.font_body, meta.font_header, meta.background_color, meta.text_color, meta.custom_css ] );

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
					// Scopes `editor.scss` overrides to layout thumbnails.
					iframe.contentDocument.body.classList.add( 'newspack-newsletters-layout-preview' );
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
		}, [ layoutId, previewBlocks ] );
		return ref;
	};

	return (
		<div
			ref={ useInlineStyles() }
			className={ `newspack-newsletters__layout-preview${ isReady ? ' is-ready' : '' }` }
			style={ { backgroundColor: meta.background_color } }
		>
			{ ! isReady && (
				<div className="newspack-newsletters__layout-preview-spinner">
					<Spinner />
				</div>
			) }
			<BlockPreview { ...props } blocks={ previewBlocks } additionalStyles={ additionalStyles } />
		</div>
	);
};

export default NewsletterPreview;
