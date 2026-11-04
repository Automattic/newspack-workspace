/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockVariation } from '@wordpress/blocks';
import domReady from '@wordpress/dom-ready';
import { addFilter } from '@wordpress/hooks';
import { __, _x } from '@wordpress/i18n';
import { Icon, copy } from '@wordpress/icons';

const SERVICE = 'republish';

const RepublishSharingButton = ( { attributes, context } ) => {
	const styleType = context.styleType || 'icon-text';
	const blockProps = useBlockProps( { className: 'jetpack-sharing-button__list-item' } );
	return (
		<li { ...blockProps }>
			<Button
				className={ `jetpack-sharing-button__button style-${ styleType } share-${ SERVICE }` }
				style={ { color: context.iconColorValue, backgroundColor: context.iconBackgroundColorValue } }
			>
				{ styleType !== 'text' && <Icon icon={ copy } size={ 24 } /> }
				<span className="jetpack-sharing-button__service-label">{ attributes.label || __( 'Republish', 'republication-tracker-tool' ) }</span>
			</Button>
		</li>
	);
};

const withRepublishIcon = BlockEdit => props => {
	if ( props.name === 'jetpack/sharing-button' && props.attributes.service === SERVICE ) {
		return <RepublishSharingButton attributes={ props.attributes } context={ props.context } />;
	}
	return <BlockEdit { ...props } />;
};

addFilter( 'editor.BlockEdit', 'republication-tracker-tool/sharing-button-icon', withRepublishIcon );
domReady( () => {
	registerBlockVariation( 'jetpack/sharing-button', {
		name: SERVICE,
		title: _x( 'Republish', 'Jetpack sharing button', 'republication-tracker-tool' ),
		icon: copy,
		attributes: { service: SERVICE, label: _x( 'Republish', 'Jetpack sharing button', 'republication-tracker-tool' ) },
		isActive: [ 'service' ],
	} );
} );
