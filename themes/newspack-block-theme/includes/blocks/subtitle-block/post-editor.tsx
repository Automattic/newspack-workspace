/* globals newspack_block_theme_subtitle_block */
'use strict';

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useCallback, useRef } from '@wordpress/element';

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

const META_FIELD_NAME = newspack_block_theme_subtitle_block.post_meta_name;

const SUBTITLE_ID = 'newspack-post-subtitle-element';
const SUBTITLE_STYLE_ID = 'newspack-post-subtitle-element-style';

/**
 * Get the correct document for the editor canvas.
 * In iframe mode, the editor content is inside an iframe with name="editor-canvas".
 * In non-iframe mode, falls back to the admin document.
 */
const getEditorCanvas = (): Document => {
	const iframe = document.querySelector< HTMLIFrameElement >( 'iframe[name="editor-canvas"]' );
	if ( iframe?.contentDocument ) {
		return iframe.contentDocument;
	}
	return document;
};

const appendSubtitleToTitleDOMElement = (
	subtitle: string | undefined,
	editorDoc: Document,
	callback: ( updatedSubtitle: string | null ) => void
): void => {
	const titleWrapperEl = editorDoc.querySelector( '.edit-post-visual-editor__post-title-wrapper' );

	if ( titleWrapperEl ) {
		let subtitleEl = editorDoc.getElementById( SUBTITLE_ID );
		const titleParent = titleWrapperEl.parentNode;

		if ( ! editorDoc.getElementById( SUBTITLE_STYLE_ID ) ) {
			const style = editorDoc.createElement( 'style' );
			style.id = SUBTITLE_STYLE_ID;
			style.innerHTML = `
                #${ SUBTITLE_ID } {
                    max-width: calc(var(--wp--style--global--content-size, 632px) + var(--wp--preset--spacing--30)* 2);
                    margin-left: auto;
                    margin-right: auto;
                    margin-bottom: 2em;
                    padding-left: var(--wp--preset--spacing--30);
                    padding-right: var(--wp--preset--spacing--30);
                }
            `;
			editorDoc.head.appendChild( style );
		}

		if ( ! subtitleEl ) {
			subtitleEl = editorDoc.createElement( 'div' );
			subtitleEl.setAttribute( 'contenteditable', 'plaintext-only' );
			subtitleEl.addEventListener( 'input', () => {
				// Non-null: this closure only runs after the element above is created and attached.
				callback( subtitleEl!.textContent );
			} );
			subtitleEl.id = SUBTITLE_ID;
			// Non-null: `titleWrapperEl` (checked above) is a rendered element, so it always has a parent.
			titleParent!.insertBefore( subtitleEl, titleWrapperEl.nextSibling );
		}
		// Only update textContent if it differs, to avoid frustrating fast typists.
		const subtitleText = subtitle ?? '';
		if ( subtitleEl.textContent !== subtitleText ) {
			subtitleEl.textContent = subtitleText;
		}
	}
};

/**
 * This functionality is handled via DOM interaction, which is risky, but in the name of WYSIWYG.
 * The post subtitle is edited directly beneath the post title, and no block is
 * registered in the post editor – this block will only be registered in the site editor.
 */
const NewspackSubtitlePanel = (): ReactNode => {
	const subtitle = useSelect(
		// `select( 'core/editor' )` is typed against a registered `StoreDescriptor`, which this
		// (legacy, string-keyed) call site doesn't provide -- @wordpress/data has no exported
		// descriptor for `core/editor` selectors, so the return value is cast at this boundary.
		select =>
			(
				select( 'core/editor' ) as {
					getEditedPostAttribute: ( attribute: 'meta' ) => Record< string, string | undefined >;
				}
			 ).getEditedPostAttribute( 'meta' )[ META_FIELD_NAME ]
	);
	// Same boundary cast as above: `useDispatch( 'core/editor' )` has no exported descriptor either.
	const { editPost } = useDispatch( 'core/editor' ) as {
		editPost: ( edits: { meta: Record< string, string | null > } ) => void;
	};
	const saveSubtitle = useCallback(
		( updatedSubtitle: string | null ) => {
			editPost( {
				meta: {
					[ META_FIELD_NAME ]: updatedSubtitle,
				},
			} );
		},
		[ editPost ]
	);
	// Keep current subtitle state visible within effect.
	const subtitleRef = useRef( subtitle );
	useEffect( () => {
		subtitleRef.current = subtitle;
	}, [ subtitle ] );
	// Mount effect: poll for canvas, then create element.
	const timeoutRef = useRef< ReturnType< typeof setTimeout > >();
	useEffect( () => {
		let retryCount = 0;
		const maxRetries = 50; // 5 seconds at 100ms intervals.
		const tryAppend = () => {
			const editorDoc = getEditorCanvas();
			const titleWrapperEl = editorDoc.querySelector( '.edit-post-visual-editor__post-title-wrapper' );
			if ( titleWrapperEl ) {
				appendSubtitleToTitleDOMElement( subtitleRef.current, editorDoc, saveSubtitle );
			} else if ( retryCount < maxRetries ) {
				retryCount++;
				timeoutRef.current = setTimeout( tryAppend, 100 );
			}
		};
		tryAppend();
		return () => {
			clearTimeout( timeoutRef.current );
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Sync effect: update element when subtitle changes.
	// Extracted from appendSubtitleToTitleDOMElement() above.
	useEffect( () => {
		const editorDoc = getEditorCanvas();
		const subtitleEl = editorDoc.getElementById( SUBTITLE_ID );
		const subtitleText = typeof subtitle === 'string' ? subtitle : '';
		if ( subtitleEl && subtitleEl.textContent !== subtitleText ) {
			subtitleEl.textContent = subtitleText;
		}
	}, [ subtitle ] );

	// This component renders nothing of its own (it only drives the DOM interaction above), so it
	// implicitly returned `undefined` before this migration. Possible pre-existing bug: React logs
	// "Nothing was returned from render" for function components that don't return a valid node --
	// flagging rather than fixing, per migration scope. `return undefined;` makes that existing
	// return value explicit, for TS's benefit only; it changes nothing at runtime.
	return undefined;
};

registerPlugin( 'plugin-document-setting-panel-newspack-subtitle', {
	render: NewspackSubtitlePanel,
	// `icon` is typed `IconType | undefined` (no `null` variant); omitted rather than set to
	// `null`, which is equivalent (both mean "no icon") -- same call made in
	// newspack-ads/src/suppress-ads/index.tsx for the same `registerPlugin()` boundary.
} );
