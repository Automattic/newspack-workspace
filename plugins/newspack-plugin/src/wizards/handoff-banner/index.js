import '../../shared/js/public-path';

/**
 * Handoff Banner
 */

/**
 * WordPress dependencies.
 */
import { createElement, render, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { Button } from '../../../packages/components/src';
import './style.scss';

const HandoffBanner = ( {
	bodyText = __( 'Return to Newspack after completing configuration', 'newspack-plugin' ),
	primaryButtonText = __( 'Back to Newspack', 'newspack-plugin' ),
	dismissButtonText = __( 'Dismiss', 'newspack-plugin' ),
	primaryButtonURL = '/wp-admin/admin.php?page=newspack-dashboard',
} ) => {
	const [ visibility, setVisibility ] = useState( true );
	return (
		visibility && (
			<div className="newspack-handoff-banner">
				<div className="newspack-handoff-banner__text">{ bodyText }</div>
				<div className="newspack-handoff-banner__buttons">
					<Button variant="tertiary" isSmall onClick={ () => setVisibility( false ) }>
						{ dismissButtonText }
					</Button>
					<Button variant="primary" isSmall href={ primaryButtonURL }>
						{ primaryButtonText }
					</Button>
				</div>
			</div>
		)
	);
};

const el = document.getElementById( 'newspack-handoff-banner' );
if ( el ) {
	const wpcontent = document.getElementById( 'wpcontent' );
	if ( wpcontent ) {
		const paddingLeft = parseInt( window.getComputedStyle( wpcontent ).paddingLeft, 10 );
		if ( paddingLeft ) {
			el.style.marginLeft = `-${ paddingLeft }px`;
			el.style.width = `calc(100% + ${ paddingLeft }px)`;
		}
	}

	const wpbody = document.getElementById( 'wpbody' );
	if ( wpbody ) {
		const applyWooCommerceOffset = () => {
			const wooHeader = document.querySelector( '.woocommerce-layout__header' );
			if ( wooHeader && wpbody.style.marginTop ) {
				el.style.marginTop = wpbody.style.marginTop;
				return true;
			}
			return false;
		};
		if ( ! applyWooCommerceOffset() ) {
			const timeoutId = setTimeout( () => observer.disconnect(), 5000 );
			const observer = new MutationObserver( () => {
				if ( applyWooCommerceOffset() ) {
					clearTimeout( timeoutId );
					observer.disconnect();
				}
			} );
			observer.observe( wpbody, { attributes: true, attributeFilter: [ 'style' ] } );
		}
	}

	const { primary_button_url: primaryButtonURL, banner_text: bodyText, banner_button_text: primaryButtonText } = el.dataset;
	render(
		createElement( HandoffBanner, {
			primaryButtonURL,
			...( bodyText && { bodyText } ),
			...( primaryButtonText && { primaryButtonText } ),
		} ),
		el
	);
}
