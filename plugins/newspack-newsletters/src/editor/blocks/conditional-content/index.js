/* globals newspack_email_editor_data */
/**
 * External dependencies
 */
import { assign } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Fragment } from '@wordpress/element';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import {
	ExternalLink,
	PanelBody,
	TextControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { sprintf, __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getServiceProvider } from '../../../service-providers';
import './style.scss';

const config = newspack_email_editor_data.conditional_tag_support;

const addConditionalContentAttributes = settings => {
	settings.attributes = assign( settings.attributes, {
		conditionalBefore: {
			type: 'string',
			default: '',
		},
		conditionalAfter: {
			type: 'string',
			default: '',
		},
	} );
	return settings;
};

const withConditionalContentControl = createHigherOrderComponent(
	BlockEdit => props => {
		const { attributes, setAttributes } = props;
		const espName = getServiceProvider().displayName;
		const intro = espName
			? sprintf(
					/* translators: %s: email service provider name (e.g. Mailchimp). */
					__( 'Use conditional tags provided by %s to control who sees what in your email.', 'newspack-newsletters' ),
					espName
			  )
			: __( 'Use conditional tags provided by your email service provider to control who sees what in your email.', 'newspack-newsletters' );
		return (
			<Fragment>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PanelBody title={ __( 'Conditional Content', 'newspack-newsletters' ) }>
						<p>{ intro }</p>
						<VStack spacing={ 4 }>
							<TextControl
								className="newspack-newsletters__no-margin-bottom"
								label={ __( 'Opening tag', 'newspack-newsletters' ) }
								value={ attributes.conditionalBefore }
								onChange={ value => setAttributes( { conditionalBefore: value } ) }
								help={ sprintf(
									/* translators: %s: example opening tag */
									__( 'Opening tag for conditional content. E.g.: %s', 'newspack-newsletters' ),
									config?.example?.before
								) }
								__next40pxDefaultSize
							/>
							<TextControl
								className="newspack-newsletters__no-margin-bottom"
								label={ __( 'Closing tag', 'newspack-newsletters' ) }
								value={ attributes.conditionalAfter }
								onChange={ value => setAttributes( { conditionalAfter: value } ) }
								help={ sprintf(
									/* translators: %s: example closing tag */
									__( 'Closing tag for conditional content. E.g.: %s', 'newspack-newsletters' ),
									config?.example?.after
								) }
								__next40pxDefaultSize
							/>
							<ExternalLink href={ config?.support_url }>{ __( 'Click here to learn more.', 'newspack-newsletters' ) }</ExternalLink>
						</VStack>
					</PanelBody>
				</InspectorControls>
			</Fragment>
		);
	},
	'withConditionalContentControl'
);

const withConditionalContentNotice = createHigherOrderComponent(
	BlockListBlock => props => {
		const { conditionalBefore, conditionalAfter } = props.attributes;
		if ( ! conditionalBefore && ! conditionalAfter ) {
			return <BlockListBlock { ...props } />;
		}
		return (
			<div
				className={ classnames( {
					'wp-block': true,
					'newspack-newsletters__editor-block': true,
					'newsletters-block__conditional-content': true,
					'newsletters-block__conditional-content--selected': props.isSelected,
					'newsletters-block__conditional-content--error': ! conditionalAfter,
				} ) }
				data-align={ props.attributes?.align || null }
			>
				<span className="newsletters-block__conditional-content__label newsletters-block__conditional-content__label__before">
					{ ! conditionalBefore ? (
						<>
							{ __( 'Missing opening tag for conditional content.', 'newspack-newsletters' ) }
							<button
								type="button"
								onClick={ () => {
									props.setAttributes( { conditionalAfter: '' } );
								} }
							>
								{ __( 'Clear conditional', 'newspack-newsletters' ) }
							</button>
						</>
					) : (
						conditionalBefore
					) }
				</span>
				<span className="newsletters-block__conditional-content__label newsletters-block__conditional-content__label__after">
					{ ! conditionalAfter ? (
						<>
							{ __( 'Missing closing tag for conditional content.', 'newspack-newsletters' ) }
							<button
								type="button"
								onClick={ () => {
									props.setAttributes( { conditionalBefore: '' } );
								} }
							>
								{ __( 'Clear conditional', 'newspack-newsletters' ) }
							</button>
						</>
					) : (
						conditionalAfter
					) }
				</span>
				<BlockListBlock { ...props } />
			</div>
		);
	},
	'withConditionalContentNotice'
);

export default () => {
	wp.hooks.addFilter( 'blocks.registerBlockType', 'newspack-newsletters/conditional-content-attributes', addConditionalContentAttributes );
	wp.hooks.addFilter( 'editor.BlockEdit', 'newspack-newsletters/conditional-content-control', withConditionalContentControl );
	wp.hooks.addFilter( 'editor.BlockListBlock', 'newspack-newsletters/conditional-content-notice', withConditionalContentNotice );
};
