/**
 * External dependencies
 */
import { assign } from 'lodash';
import classnames from 'classnames';
import type { ComponentProps, ComponentType, ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { Fragment } from '@wordpress/element';
import { compose, createHigherOrderComponent } from '@wordpress/compose';
import { withSelect } from '@wordpress/data';
import { BlockControls, InspectorControls } from '@wordpress/block-editor';
import { MenuGroup, MenuItem, PanelBody, RadioControl, Toolbar, ToolbarDropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check, seen } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './style.scss';

declare const wp: { hooks: { addFilter: typeof import('@wordpress/hooks').addFilter } };

// `<Toolbar>` without a `label` renders its deprecated variant; the current
// types require `label`, so widen to the props this deprecated usage passes.
const LegacyToolbar = Toolbar as object as ComponentType< { children?: ReactNode } >;

// `MenuItem`'s type doesn't declare an `onClose` prop; it's passed through here anyway
// (pre-existing behavior, preserved as-is -- see the `onClick` handler below for the actual
// attribute-setting logic).
const MenuItemWithOnClose = MenuItem as ComponentType< ComponentProps< typeof MenuItem > & { onClose?: () => void } >;

const ATTRIBUTE_NAME = 'newsletterVisibility';

const EMAIL_ONLY_BLOCKS = [ 'newspack-newsletters/ad', 'newspack-newsletters/share' ];

interface VisibilityAttributes {
	newsletterVisibility?: string;
	align?: string;
	[ key: string ]: unknown;
}

interface BlockSettingsWithAttributes {
	attributes: Record< string, unknown >;
	[ key: string ]: unknown;
}

interface VisibilityControlProps {
	name: string;
	attributes: VisibilityAttributes;
	setAttributes: ( attributes: Record< string, unknown > ) => void;
	is_public?: boolean;
}

interface VisibilityNoticeProps {
	attributes: VisibilityAttributes;
	isSelected?: boolean;
	is_public?: boolean;
	setAttributes: ( attributes: Record< string, unknown > ) => void;
}

const visibilityOptions = [
	{
		label: __( 'Email and Web', 'newspack-newsletters' ),
		value: '',
	},
	{
		label: __( 'Email only', 'newspack-newsletters' ),
		value: 'email',
	},
	{
		label: __( 'Web only', 'newspack-newsletters' ),
		value: 'web',
	},
];

const addVisibilityAttribute = ( settings: BlockSettingsWithAttributes ) => {
	settings.attributes = assign( settings.attributes, {
		[ ATTRIBUTE_NAME ]: {
			type: 'string',
			default: visibilityOptions[ 0 ].value,
		},
	} );
	return settings;
};

const withVisibilityControl = createHigherOrderComponent(
	BlockEdit =>
		compose(
			withSelect( ( select: ( store: string ) => { getEditedPostAttribute?: ( key: string ) => { is_public?: boolean } } ) => {
				const meta: { is_public?: boolean } = select( 'core/editor' )?.getEditedPostAttribute?.( 'meta' ) || {};
				return { is_public: meta.is_public };
			} )
		)( ( props: VisibilityControlProps ) => {
			const { attributes, setAttributes } = props;
			const isEmailOnlyBlock = EMAIL_ONLY_BLOCKS.includes( props.name );
			const value = isEmailOnlyBlock ? 'email' : attributes[ ATTRIBUTE_NAME ];
			if ( ! props.is_public ) {
				return <BlockEdit { ...props } />;
			}
			return (
				<Fragment>
					<BlockControls>
						<LegacyToolbar>
							<ToolbarDropdownMenu label={ __( 'Visibility', 'newspack-newsletters' ) } icon={ seen }>
								{ ( { onClose } ) => (
									<MenuGroup>
										{ visibilityOptions.map( entry => {
											return (
												<MenuItemWithOnClose
													icon={ value === entry.value || ( ! value && entry.value === '' ) ? check : null }
													isSelected={ value === entry.value }
													key={ entry.value }
													onClick={ () => {
														setAttributes( {
															[ ATTRIBUTE_NAME ]: entry.value,
														} );
													} }
													onClose={ onClose }
													role="menuitemradio"
													disabled={ isEmailOnlyBlock }
												>
													{ entry.label }
												</MenuItemWithOnClose>
											);
										} ) }
									</MenuGroup>
								) }
							</ToolbarDropdownMenu>
						</LegacyToolbar>
					</BlockControls>
					<BlockEdit { ...props } />
					<InspectorControls>
						<PanelBody title={ __( 'Visibility', 'newspack-newsletters' ) }>
							{ isEmailOnlyBlock ? (
								<p>{ __( 'This block is only available in the email version of the newsletter.', 'newspack-newsletters' ) }</p>
							) : (
								<RadioControl
									label={ __( 'Where should this block be visible?', 'newspack-newsletters' ) }
									hideLabelFromVision
									selected={ value }
									options={ visibilityOptions }
									onChange={ selected => {
										setAttributes( { [ ATTRIBUTE_NAME ]: selected } );
									} }
									help={ __( 'Choose where this block appears.', 'newspack-newsletters' ) }
								/>
							) }
						</PanelBody>
					</InspectorControls>
				</Fragment>
			);
		} ) as ComponentType,
	'withVisibilityControl'
);

const withVisibilityNotice = createHigherOrderComponent(
	BlockListBlock =>
		compose(
			withSelect( ( select: ( store: string ) => { getEditedPostAttribute?: ( key: string ) => { is_public?: boolean } } ) => {
				const meta: { is_public?: boolean } = select( 'core/editor' )?.getEditedPostAttribute?.( 'meta' ) || {};
				return { is_public: meta.is_public };
			} )
		)( ( props: VisibilityNoticeProps ) => {
			const value = props.attributes[ ATTRIBUTE_NAME ];
			const shouldBePublic = ! props.is_public && value === 'web';
			if ( value && ( ( props.is_public && value === 'email' ) || value === 'web' ) ) {
				return (
					<div
						className={ classnames( {
							'wp-block': true,
							'newspack-newsletters__editor-block': true,
							[ `newsletters-block-visibility__${ value }` ]: !! value,
							'newsletters-block-selected': props.isSelected,
							'newsletters-block-error': shouldBePublic,
						} ) }
						data-align={ props.attributes?.align || null }
					>
						<span className="newsletters-block-visibility-label">
							{ shouldBePublic ? (
								<>
									{ __( 'Newsletter is not public, this block will not be visible.', 'newspack-newsletters' ) }
									<button
										type="button"
										onClick={ () => {
											props.setAttributes( { [ ATTRIBUTE_NAME ]: '' } );
										} }
									>
										{ __( 'Clear visibility attribute', 'newspack-newsletters' ) }
									</button>
								</>
							) : (
								<Fragment>
									{ value === 'web' && __( 'Only visible on public newsletter page.', 'newspack-newsletters' ) }
									{ value === 'email' && __( 'Only visible in the sent email.', 'newspack-newsletters' ) }
								</Fragment>
							) }
						</span>
						<BlockListBlock { ...props } />
					</div>
				);
			}
			return <BlockListBlock { ...props } />;
		} ) as ComponentType,
	'withVisibilityNotice'
);

export default () => {
	wp.hooks.addFilter( 'blocks.registerBlockType', 'newspack-newsletters/visibility-attribute', addVisibilityAttribute );
	wp.hooks.addFilter( 'editor.BlockEdit', 'newspack-newsletters/visibility-control', withVisibilityControl );
	wp.hooks.addFilter( 'editor.BlockListBlock', 'newspack-newsletters/visibility-notice', withVisibilityNotice );
};
