/**
 * WordPress dependencies
 */
import { useContext, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { BlockControls, useBlockProps, useInnerBlocksProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, Button, ToolbarButton, ToolbarGroup, Tooltip } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { getSharedAuthorContext } from '../../shared/author-context';
import { getAvailableServices, getIconSizeOptions, roundIconSize } from './utils';

const ALLOWED_BLOCKS = [ 'newspack/author-social-link' ];

// Module-level cache so multiple block instances share one fetch.
let allServiceKeysCache = null;
const fetchAllServiceKeys = () => {
	if ( ! allServiceKeysCache ) {
		allServiceKeysCache = apiFetch( { path: '/newspack/v1/social-icons' } )
			.then( svgs => Object.keys( svgs ) )
			.catch( () => [] );
	}
	return allServiceKeysCache;
};

const presetToVar = value => {
	if ( typeof value !== 'string' ) {
		return value;
	}
	return value.replace( /^var:preset\|([^|]+)\|(.+)$/, 'var(--wp--preset--$1--$2)' );
};

const resolveColor = ( presetSlug, customValue ) => {
	if ( presetSlug ) {
		return `var(--wp--preset--color--${ presetSlug })`;
	}
	if ( typeof customValue === 'string' ) {
		return presetToVar( customValue ) || customValue;
	}
	return undefined;
};

/**
 * Edit component for the Author Social Links inner block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @param {string}   props.clientId      Block client ID.
 * @return {JSX.Element} The edit component.
 */
export default function Edit( { attributes, setAttributes, clientId } ) {
	const AuthorContext = getSharedAuthorContext();
	const author = useContext( AuthorContext );
	const { iconSize, style: styleAttr, textColor, backgroundColor, className } = attributes;
	const hasPopulated = useRef( false );
	const [ allServiceKeys, setAllServiceKeys ] = useState( null ); // null = loading

	const isBrand = ( className || '' ).split( ' ' ).includes( 'is-style-brand' );
	const iconSizeValue = typeof iconSize === 'number' ? iconSize : parseInt( iconSize ?? 24, 10 ) || 24;
	const iconColor = ! isBrand ? resolveColor( textColor, styleAttr?.color?.text ) : undefined;
	const iconBackground = ! isBrand ? resolveColor( backgroundColor, styleAttr?.color?.background ) : undefined;

	// Hide color panel when "Brand" is active; rename labels when "Default".
	useEffect( () => {
		const sidebar = document.querySelector( '.interface-complementary-area' );
		if ( ! sidebar ) {
			return;
		}

		const COLOR_LABEL_MAP = {
			Text: __( 'Icon color', 'newspack-plugin' ),
			Background: __( 'Icon background', 'newspack-plugin' ),
		};

		const updateColorPanel = container => {
			container.querySelectorAll( '.color-block-support-panel' ).forEach( el => {
				el.style.display = isBrand ? 'none' : '';
			} );

			if ( isBrand ) {
				return;
			}

			container.querySelectorAll( '.block-editor-panel-color-gradient-settings__color-name' ).forEach( el => {
				if ( COLOR_LABEL_MAP[ el.textContent ] ) {
					el.textContent = COLOR_LABEL_MAP[ el.textContent ];
				}
			} );
			container.querySelectorAll( '.components-menu-item__item' ).forEach( el => {
				if ( COLOR_LABEL_MAP[ el.textContent ] ) {
					el.textContent = COLOR_LABEL_MAP[ el.textContent ];
				}
			} );
		};

		updateColorPanel( sidebar );

		const observer = new MutationObserver( () => updateColorPanel( sidebar ) );
		observer.observe( sidebar, { childList: true, subtree: true } );

		return () => observer.disconnect();
	}, [ isBrand ] );

	const blockProps = useBlockProps( {
		className: 'author-profile-social__list',
		style: {
			'--icon-size': `${ roundIconSize( iconSizeValue ) }px`,
			...( iconColor && { '--icon-color': iconColor } ),
			...( iconBackground && { '--icon-background': iconBackground } ),
		},
	} );

	// Get inner blocks (stable reference from the store).
	const innerBlocks = useSelect( select => select( 'core/block-editor' ).getBlocks( clientId ), [ clientId ] );
	const innerBlockCount = innerBlocks.length;
	const currentServices = useMemo( () => innerBlocks.map( b => b.attributes.service ).filter( Boolean ), [ innerBlocks ] );

	const { replaceInnerBlocks } = useDispatch( 'core/block-editor' );

	// Fetch the full list of supported services from the social icons endpoint.
	useEffect( () => {
		fetchAllServiceKeys().then( setAllServiceKeys );
	}, [] );

	// Auto-populate inner blocks from author data on first render (when no saved inner blocks).
	// Wait for the service keys fetch to complete so we can use the full list.
	useEffect( () => {
		if ( hasPopulated.current || innerBlockCount > 0 || allServiceKeys === null ) {
			return;
		}

		const services = allServiceKeys.length > 0 ? allServiceKeys : getAvailableServices( author );
		if ( services.length === 0 ) {
			return;
		}

		hasPopulated.current = true;

		const blocks = services.map( service => createBlock( 'newspack/author-social-link', { service } ) );
		replaceInnerBlocks( clientId, blocks, false );
	}, [ author, allServiceKeys, innerBlockCount, clientId, replaceInnerBlocks ] );

	const services = getAvailableServices( author );
	const missingServices = services.filter( s => ! currentServices.includes( s ) );

	const resetLinks = () => {
		const resetWith = allServiceKeys?.length > 0 ? allServiceKeys : services;
		const blocks = resetWith.map( service => createBlock( 'newspack/author-social-link', { service } ) );
		replaceInnerBlocks( clientId, blocks, false );
	};

	const addMissingLinks = () => {
		const newBlocks = missingServices.map( service => createBlock( 'newspack/author-social-link', { service } ) );
		replaceInnerBlocks( clientId, [ ...innerBlocks, ...newBlocks ], false );
	};

	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		allowedBlocks: ALLOWED_BLOCKS,
		orientation: 'horizontal',
		renderAppender: false,
	} );

	if ( services.length === 0 && innerBlockCount === 0 ) {
		return (
			<ul { ...blockProps }>
				<li className="social-links-placeholder">{ __( 'Social links will appear here.', 'newspack-plugin' ) }</li>
			</ul>
		);
	}

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<Tooltip text={ __( 'Reset links', 'newspack-plugin' ) }>
						<ToolbarButton label={ __( 'Reset links', 'newspack-plugin' ) } onClick={ resetLinks }>
							{ __( 'Reset', 'newspack-plugin' ) }
						</ToolbarButton>
					</Tooltip>
				</ToolbarGroup>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'newspack-plugin' ) }>
					<SelectControl
						label={ __( 'Icon size', 'newspack-plugin' ) }
						value={ iconSizeValue }
						options={ getIconSizeOptions() }
						onChange={ value => setAttributes( { iconSize: Number( value ) || 24 } ) }
						__next40pxDefaultSize
					/>
					{ missingServices.length > 0 && (
						<Button variant="secondary" onClick={ addMissingLinks }>
							{ __( 'Add missing links', 'newspack-plugin' ) }
						</Button>
					) }
				</PanelBody>
			</InspectorControls>
			<ul { ...innerBlocksProps } />
		</>
	);
}
