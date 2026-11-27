/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockVariation } from '@wordpress/blocks';
import domReady from '@wordpress/dom-ready';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { Icon, gift } from '@wordpress/icons';

const SERVICE = 'newspack-gift-article';

type SharingButtonAttributes = {
	service?: string;
	label?: string;
};

type SharingButtonContext = {
	styleType?: string;
	iconColorValue?: string;
	iconBackgroundColorValue?: string;
};

type SharingButtonEditProps = {
	name: string;
	attributes: SharingButtonAttributes;
	context: SharingButtonContext;
	[ key: string ]: unknown;
};

const GiftSharingButton = ( { attributes, context }: { attributes: SharingButtonAttributes; context: SharingButtonContext } ) => {
	const styleType = context.styleType || 'icon-text';
	const blockProps = useBlockProps( { className: 'jetpack-sharing-button__list-item' } );
	return (
		<li { ...blockProps }>
			<Button
				className={ `jetpack-sharing-button__button style-${ styleType } share-${ SERVICE }` }
				style={ { color: context.iconColorValue, backgroundColor: context.iconBackgroundColorValue } }
			>
				{ styleType !== 'text' && <Icon icon={ gift } size={ 24 } /> }
				<span className="jetpack-sharing-button__service-label">{ attributes.label || __( 'Gift this article', 'newspack-plugin' ) }</span>
			</Button>
		</li>
	);
};

const withGiftIcon = ( BlockEdit: React.ComponentType< SharingButtonEditProps > ) => ( props: SharingButtonEditProps ) => {
	if ( props.name === 'jetpack/sharing-button' && props.attributes.service === SERVICE ) {
		return <GiftSharingButton attributes={ props.attributes } context={ props.context } />;
	}
	return <BlockEdit { ...props } />;
};

addFilter( 'editor.BlockEdit', 'newspack/gifting-sharing-button-icon', withGiftIcon );

domReady( () => {
	registerBlockVariation( 'jetpack/sharing-button', {
		name: SERVICE,
		title: __( 'Gift this article', 'newspack-plugin' ),
		icon: gift,
		attributes: { service: SERVICE, label: __( 'Gift this article', 'newspack-plugin' ) },
		isActive: [ 'service' ],
	} );
} );
