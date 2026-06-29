/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useMergeRefs, useRefEffect } from '@wordpress/compose';
import { BlockPreview } from '@wordpress/block-editor';
import { Placeholder, Spinner } from '@wordpress/components';
import { forwardRef } from '@wordpress/element';
import { pages } from '@wordpress/icons';
import { useCustomFontsInIframe } from '../../../newsletter-editor/styling';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Post-item line-height/weight and spacing for the preview, matching the WC
 * email renderer: headings 1.2 / body 1.5, an 8px gap between the title,
 * subtitle, date, excerpt and continue-reading link, and a 16px gap between the
 * featured-image and content columns (the email applies a 16px padding-left on
 * the content column).
 *
 * Font SIZES and COLOURS are deliberately NOT forced here: the block's own
 * heading/text font-size and colour controls write inline styles that the email
 * renderer honours, so the preview must honour them too (forcing sizes with
 * `!important` made the preview ignore those controls). Only line-height, weight
 * and spacing — which the block doesn't expose and which the email normalises —
 * are pinned.
 *
 * The editor otherwise gives each column its own `12px 6px` padding, which
 * double-pads the gap, so the column padding is zeroed and the 16px is supplied
 * solely by the columns gap. The preview is an iframed `BlockPreview`, so this
 * can't live in the block's editor stylesheet (the outer document) — it's
 * injected straight into the iframe.
 *
 * The column-scoped rules (8px inter-block gap, column padding / columns gap)
 * only affect the side-by-side layouts (image left / right), which build a
 * `core/columns`. The "image on top" layout (`utils.js`) renders flat blocks
 * with no columns, so those rules don't match it and its spacing is left alone.
 */
const POST_ITEM_PREVIEW_STYLES = `
	h3.wp-block-heading,
	h4.wp-block-heading {
		line-height: 1.2 !important;
		font-weight: 700 !important;
	}
	p.wp-block-paragraph {
		line-height: 1.5 !important;
		font-weight: 400 !important;
	}
	.wp-block-column > * {
		margin-block: 0 !important;
	}
	.wp-block-column > * + * {
		margin-block-start: 8px !important;
	}
	.wp-block-column {
		padding: 0 !important;
	}
	.wp-block-columns {
		gap: 16px !important;
	}
`;

/**
 * Posts Preview component.
 */
const PostsPreview = ( { isReady, blocks, className, viewportWidth }, ref ) => {
	// Iframe styles are not properly applied when nesting iframed editors.
	// This fix ensures the iframe is properly styled.
	const useIframeBorderFix = useRefEffect( node => {
		const observerCallback = () => {
			const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( iframe ) {
				const updateIframeStyle = () => {
					iframe.style.border = 0;
					observer.disconnect();
				};
				updateIframeStyle();
				iframe.addEventListener( 'load', updateIframeStyle );
			}
		};
		const observer = new MutationObserver( observerCallback );
		observer.observe( node, { childList: true } );
		return () => {
			observer.disconnect();
		};
	}, [] );

	// Append layout style if viewing layout preview.
	const useLayoutStyle = useRefEffect( node => {
		const style = document.getElementById( 'newspack-newsletters__layout-css' );
		if ( ! style ) {
			return;
		}
		const clonedStyle = style.cloneNode( true );
		const observerCallback = () => {
			const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( iframe ) {
				const doc = iframe.contentDocument;
				const appendStyle = () => {
					doc.body.id = style.dataset.previewid;
					if ( ! doc.contains( clonedStyle ) ) {
						doc.head.appendChild( clonedStyle );
					}
					observer.disconnect();
				};
				appendStyle();
				iframe.addEventListener( 'load', appendStyle );
			}
		};
		const observer = new MutationObserver( observerCallback );
		observer.observe( node, { childList: true } );
		return () => {
			observer.disconnect();
		};
	}, [] );

	// Inject the email-matching post-item typography into the preview iframe.
	//
	// The preview is an iframed `BlockPreview` whose document is populated — and
	// re-populated on every render — asynchronously, after the iframe element
	// mounts. So mirror the editor canvas-styling walker's reach: (re)inject the
	// <style> on the iframe `load` event AND whenever its document mutates, with
	// an id guard so it's never duplicated. A single non-subtree observer (the
	// previous approach) missed those later document swaps, so the style never
	// landed — which is why the spacing reset to the flow-layout default.
	const usePostItemStyles = useRefEffect( node => {
		const cleanups = [];
		const inject = iframe => {
			const doc = iframe.contentDocument;
			if ( ! doc?.head || doc.getElementById( 'newspack-posts-inserter-preview-typography' ) ) {
				return;
			}
			const styleEl = doc.createElement( 'style' );
			styleEl.id = 'newspack-posts-inserter-preview-typography';
			styleEl.textContent = POST_ITEM_PREVIEW_STYLES;
			doc.head.appendChild( styleEl );
		};
		const seen = new WeakSet();
		const watchIframe = iframe => {
			if ( seen.has( iframe ) ) {
				return;
			}
			seen.add( iframe );
			let innerObserver;
			const onReady = () => {
				inject( iframe );
				const doc = iframe.contentDocument;
				if ( doc?.body ) {
					innerObserver?.disconnect();
					innerObserver = new MutationObserver( () => inject( iframe ) );
					innerObserver.observe( doc.body, { childList: true, subtree: true } );
				}
			};
			onReady();
			iframe.addEventListener( 'load', onReady );
			cleanups.push( () => {
				iframe.removeEventListener( 'load', onReady );
				innerObserver?.disconnect();
			} );
		};
		const scan = () => {
			const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( iframe ) {
				watchIframe( iframe );
			}
		};
		scan();
		const observer = new MutationObserver( scan );
		observer.observe( node, { childList: true, subtree: true } );
		return () => {
			observer.disconnect();
			cleanups.forEach( fn => fn() );
		};
	}, [] );

	return (
		<div
			className={ classnames( 'newspack-posts-inserter__preview', className ) }
			ref={ useMergeRefs( [ ref, useIframeBorderFix, useLayoutStyle, usePostItemStyles, useCustomFontsInIframe() ] ) }
		>
			{ ! isReady && <Spinner /> }
			{ isReady && blocks?.length > 0 && <BlockPreview blocks={ blocks } viewportWidth={ viewportWidth } /> }
			{ isReady && ! blocks?.length && (
				<Placeholder
					icon={ pages }
					label={ __( 'No posts found', 'newspack-newsletters' ) }
					instructions={ __( 'Verify filter settings.', 'newspack-newsletters' ) }
				/>
			) }
		</div>
	);
};

export default forwardRef( PostsPreview );
