/**
 * External dependencies
 */
import { assign } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Fragment } from '@wordpress/element';
import { compose, createHigherOrderComponent } from '@wordpress/compose';
import { withSelect } from '@wordpress/data';
import { BlockControls, InspectorControls } from '@wordpress/block-editor';
import { MenuGroup, MenuItem, PanelBody, SelectControl, Toolbar, ToolbarDropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './style.scss';

const ATTRIBUTE_NAME = 'newsletterVisibility';

const EMAIL_ONLY_BLOCKS = [ 'newspack-newsletters/ad', 'newspack-newsletters/share' ];

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

const addVisibilityAttribute = settings => {
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
			withSelect( select => {
				const { is_public } = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
				return { is_public };
			} )
		)( props => {
			const { attributes, setAttributes } = props;
			const isEmailOnlyBlock = EMAIL_ONLY_BLOCKS.includes( props.name );
			const value = isEmailOnlyBlock ? 'email' : attributes[ ATTRIBUTE_NAME ];
			if ( ! props.is_public ) {
				return <BlockEdit { ...props } />;
			}
			return (
				<Fragment>
					<BlockControls>
						<Toolbar>
							<ToolbarDropdownMenu label={ __( 'Visibility', 'newspack-newsletters' ) } icon={ 'visibility' }>
								{ ( { onClose } ) => (
									<MenuGroup>
										{ visibilityOptions.map( entry => {
											return (
												<MenuItem
													icon={ ( value === entry.value || ( ! value && entry.value === '' ) ) && check }
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
												</MenuItem>
											);
										} ) }
									</MenuGroup>
								) }
							</ToolbarDropdownMenu>
						</Toolbar>
					</BlockControls>
					<BlockEdit { ...props } />
					<InspectorControls>
						<PanelBody title={ __( 'Visibility', 'newspack-newsletters' ) }>
							<SelectControl
								label={ __( 'Where should this block be visible?', 'newspack-newsletters' ) }
								value={ value }
								options={ visibilityOptions }
								onChange={ selected => {
									setAttributes( { [ ATTRIBUTE_NAME ]: selected } );
								} }
								help={
									isEmailOnlyBlock
										? __( 'This block is only available in the email version of the newsletter.', 'newspack-newsletters' )
										: __(
												"If the newsletter is going to be viewable publicly on this site, select here if you'd like this block to be visible in a particular version.",
												'newspack-newsletters'
										  )
								}
								disabled={ isEmailOnlyBlock }
							/>
						</PanelBody>
					</InspectorControls>
				</Fragment>
			);
		} ),
	'withVisibilityControl'
);

const withVisibilityNotice = createHigherOrderComponent(
	BlockListBlock =>
		compose(
			withSelect( select => {
				const { is_public } = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
				return { is_public };
			} )
		)( props => {
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
		} ),
	'withVisibilityNotice'
);

export default () => {
	wp.hooks.addFilter( 'blocks.registerBlockType', 'newspack-newsletters/visibility-attribute', addVisibilityAttribute );
	wp.hooks.addFilter( 'editor.BlockEdit', 'newspack-newsletters/visibility-control', withVisibilityControl );
	wp.hooks.addFilter( 'editor.BlockListBlock', 'newspack-newsletters/visibility-notice', withVisibilityNotice );
};
