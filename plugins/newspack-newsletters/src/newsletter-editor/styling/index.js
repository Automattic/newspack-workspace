/* eslint-disable jsdoc/no-undefined-types, jsdoc/valid-types */

/**
 * WordPress dependencies
 */
import { PlainText, __experimentalPanelColorGradientSettings as PanelColorGradientSettings } from '@wordpress/block-editor'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { compose, useInstanceId } from '@wordpress/compose';
import {
	BaseControl,
	Panel,
	PanelBody,
	PanelRow,
	SelectControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect, withDispatch, withSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.scss';

const fontOptgroups = [
	{
		label: __( 'Sans Serif', 'newspack-newsletters' ),
		options: [
			{
				value: 'Arial, Helvetica, sans-serif',
				label: __( 'Arial', 'newspack-newsletters' ),
			},
			{
				value: 'Tahoma, sans-serif',
				label: __( 'Tahoma', 'newspack-newsletters' ),
			},
			{
				value: 'Trebuchet MS, sans-serif',
				label: __( 'Trebuchet', 'newspack-newsletters' ),
			},
			{
				value: 'Verdana, sans-serif',
				label: __( 'Verdana', 'newspack-newsletters' ),
			},
		],
	},

	{
		label: __( 'Serif', 'newspack-newsletters' ),
		options: [
			{
				value: 'Georgia, serif',
				label: __( 'Georgia', 'newspack-newsletters' ),
			},
			{
				value: 'Palatino, serif',
				label: __( 'Palatino', 'newspack-newsletters' ),
			},
			{
				value: 'Times New Roman, serif',
				label: __( 'Times New Roman', 'newspack-newsletters' ),
			},
		],
	},

	{
		label: __( 'Monospace', 'newspack-newsletters' ),
		options: [
			{
				value: 'Courier, monospace',
				label: __( 'Courier', 'newspack-newsletters' ),
			},
		],
	},
];

const customStylesSelector = select => {
	const { getEditedPostAttribute } = select( 'core/editor' );
	const meta = getEditedPostAttribute( 'meta' );
	return {
		fontBody: meta.font_body || fontOptgroups[ 1 ].options[ 0 ].value,
		fontHeader: meta.font_header || fontOptgroups[ 0 ].options[ 0 ].value,
		backgroundColor: meta.background_color || '#ffffff',
		textColor: meta.text_color || '#000000',
		customCss: meta.custom_css || '',
	};
};

// Create a temporary DOM document (not displayed) for parsing CSS rules.
const doc = document.implementation.createHTMLDocument( 'Temp' );

/**
 * Takes a given CSS string, parses it, and scopes all its rules to the given `scope`.
 *
 * @param {string} scope The scope to apply to each rule in the CSS.
 * @param {string} css   The CSS to scope.
 * @return {string} Scoped CSS string.
 */
export const getScopedCss = ( scope, css ) => {
	const style = doc.querySelector( 'style' ) || document.createElement( 'style' );

	style.textContent = css;
	doc.head.appendChild( style );

	const rules = [ ...style.sheet.cssRules ];
	return rules
		.map( rule => {
			rule.selectorText = rule.selectorText
				.split( ',' )
				.map( selector => `${ scope } ${ selector }` )
				.join( ', ' );
			return rule.cssText;
		} )
		.join( '\n' );
};

/**
 * Hook to apply body and header fonts variables in store to an iframe as root
 * element style property.
 *
 * @return {import('react').RefObject} The component to be rendered.
 */
export const useCustomFontsInIframe = () => {
	const ref = useRef();
	const { fontBody, fontHeader } = useSelect( customStylesSelector );
	useEffect( () => {
		const node = ref.current;
		const updateIframe = () => {
			const iframe = node.querySelector( 'iframe[title="Editor canvas"]' );
			if ( iframe ) {
				const updateStyleProperties = () => {
					const element = iframe.contentDocument?.documentElement;
					if ( element ) {
						element.style.setProperty( '--newspack-newsletters-body-font', fontBody );
						element.style.setProperty( '--newspack-newsletters-header-font', fontHeader );
						element.querySelector( 'body' ).style.setProperty( 'background', 'none' );
					}
				};
				updateStyleProperties();
				// Handle Firefox iframe.
				iframe.addEventListener( 'load', updateStyleProperties );
				return () => {
					iframe.removeEventListener( 'load', updateStyleProperties );
				};
			}
		};
		updateIframe();
		const observer = new MutationObserver( updateIframe );
		observer.observe( node, { childList: true } );
		return () => {
			observer.disconnect();
		};
	}, [ fontBody, fontHeader ] );
	return ref;
};

const EDITOR_CANVAS_SELECTOR = 'iframe[name="editor-canvas"]';

// TODO: Remove the parent-document fallback once WP 7.0 is officially released
// and becomes the minimum supported version.
const getEditorCanvasDocument = () => {
	const iframe = document.querySelector( EDITOR_CANVAS_SELECTOR );
	return iframe?.contentDocument ?? document;
};

export const ApplyStyling = withSelect( customStylesSelector )( ( { fontBody, fontHeader, backgroundColor, textColor, customCss } ) => {
	// Bumped on canvas iframe mount/remount/load so the per-style effects
	// below re-apply styles inside the new canvas document.
	const [ iframeKey, setIframeKey ] = useState( 0 );

	useEffect( () => {
		const bump = () => setIframeKey( key => key + 1 );
		let currentIframe = document.querySelector( EDITOR_CANVAS_SELECTOR );
		if ( currentIframe ) {
			currentIframe.addEventListener( 'load', bump );
		}
		const observer = new MutationObserver( () => {
			const nextIframe = document.querySelector( EDITOR_CANVAS_SELECTOR );
			if ( nextIframe === currentIframe ) {
				return;
			}
			if ( currentIframe ) {
				currentIframe.removeEventListener( 'load', bump );
			}
			currentIframe = nextIframe;
			if ( currentIframe ) {
				currentIframe.addEventListener( 'load', bump );
			}
			bump();
		} );
		// Scope the observer to the editor content region rather than the whole
		// body so unrelated editor mutations don't trigger the callback.
		const observerRoot = document.querySelector( '.interface-interface-skeleton__content' ) || document.body;
		observer.observe( observerRoot, { childList: true, subtree: true } );
		return () => {
			if ( currentIframe ) {
				currentIframe.removeEventListener( 'load', bump );
			}
			observer.disconnect();
		};
	}, [] );

	useEffect( () => {
		getEditorCanvasDocument().documentElement.style.setProperty( '--newspack-newsletters-body-font', fontBody );
	}, [ fontBody, iframeKey ] );

	useEffect( () => {
		getEditorCanvasDocument().documentElement.style.setProperty( '--newspack-newsletters-header-font', fontHeader );
	}, [ fontHeader, iframeKey ] );

	useEffect( () => {
		const canvasDoc = getEditorCanvasDocument();
		// Inside the iframe, clear the body background so the
		// editor-styles-wrapper background color is visible.
		if ( canvasDoc !== document && canvasDoc.body ) {
			canvasDoc.body.style.setProperty( 'background', 'none' );
		}
		const editorElement = canvasDoc.querySelector( '.editor-styles-wrapper' );
		if ( editorElement ) {
			editorElement.style.backgroundColor = backgroundColor;
			editorElement.style.color = textColor;
		}
	}, [ backgroundColor, textColor, iframeKey ] );

	useEffect( () => {
		const canvasDoc = getEditorCanvasDocument();
		const editorElement = canvasDoc.querySelector( '.editor-styles-wrapper' );
		if ( ! editorElement ) {
			return;
		}
		let styleEl = canvasDoc.getElementById( 'newspack-newsletters__custom-styles' );
		if ( ! styleEl ) {
			styleEl = canvasDoc.createElement( 'style' );
			styleEl.setAttribute( 'type', 'text/css' );
			styleEl.setAttribute( 'id', 'newspack-newsletters__custom-styles' );
			canvasDoc.head.appendChild( styleEl );
		}
		styleEl.textContent = getScopedCss( '.editor-styles-wrapper', customCss );
	}, [ customCss, iframeKey ] );

	return null;
} );

export const Styling = compose( [
	withDispatch( dispatch => {
		const { editPost } = dispatch( 'core/editor' );
		return { editPost };
	} ),
	withSelect( customStylesSelector ),
] )( ( { editPost, fontBody, fontHeader, customCss, backgroundColor, textColor } ) => {
	const updateStyleValue = ( key, value ) => {
		editPost( { meta: { [ key ]: value } } );
	};

	const instanceId = useInstanceId( SelectControl );

	const renderFontOptions = () =>
		fontOptgroups.map( group => (
			<optgroup key={ group.label } label={ group.label }>
				{ group.options.map( option => (
					<option key={ option.value } value={ option.value }>
						{ option.label }
					</option>
				) ) }
			</optgroup>
		) );

	return (
		<Panel>
			<PanelColorGradientSettings
				title={ __( 'Color', 'newspack-newsletters' ) }
				gradients={ [] } // Pass empty array to disable gradients.
				settings={ [
					{
						colorValue: textColor,
						onColorChange: value => updateStyleValue( 'text_color', value ),
						label: __( 'Text', 'newspack-newsletters' ),
					},
					{
						colorValue: backgroundColor,
						onColorChange: value => updateStyleValue( 'background_color', value ),
						label: __( 'Background', 'newspack-newsletters' ),
					},
				] }
			/>
			<PanelBody name="newsletters-typography-panel" title={ __( 'Typography', 'newspack-newsletters' ) }>
				<VStack spacing={ 4 }>
					<SelectControl
						label={ __( 'Headings font', 'newspack-newsletters' ) }
						value={ fontHeader }
						onChange={ value => updateStyleValue( 'font_header', value ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						{ renderFontOptions() }
					</SelectControl>
					<SelectControl
						label={ __( 'Body font', 'newspack-newsletters' ) }
						value={ fontBody }
						onChange={ value => updateStyleValue( 'font_body', value ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						{ renderFontOptions() }
					</SelectControl>
				</VStack>
			</PanelBody>
			<PanelBody name="newsletters-css-panel" title={ __( 'Custom CSS', 'newspack-newsletters' ) } initialOpen={ false }>
				<PanelRow className="newspack-newsletters__css-panel">
					<BaseControl
						id={ `inspector-custom-css-control-${ instanceId }` }
						label={ __( 'Custom CSS', 'newspack-newsletters' ) }
						help={ __(
							'This is an advanced feature and may result in unpredictable behavior. Custom CSS will be appended to default styles in sent emails only.',
							'newspack-newsletters'
						) }
						hideLabelFromVision
					>
						<PlainText
							className="components-textarea-control__input"
							value={ customCss }
							onChange={ content => editPost( { meta: { custom_css: content } } ) }
							aria-label={ __( 'Custom CSS', 'newspack-newsletters' ) }
							placeholder={ __( 'Write custom CSS…', 'newspack-newsletters' ) }
						/>
					</BaseControl>
				</PanelRow>
			</PanelBody>
		</Panel>
	);
} );
