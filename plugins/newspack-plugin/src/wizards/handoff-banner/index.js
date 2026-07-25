import '../../shared/js/public-path';

/**
 * Handoff Banner
 */

/**
 * WordPress dependencies.
 */
import { createElement, render, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { Button } from '../../../packages/components/src';
import './style.scss';

const VISIBLE_CLASS = 'newspack-handoff-banner-visible';
const HEIGHT_PROPERTY = '--newspack-handoff-banner-height';

/**
 * Stop advertising the space the banner takes. Pages that reserve room for the
 * banner fall back to a zero offset.
 */
const clearBannerHeight = () => {
	document.documentElement.classList.remove( VISIBLE_CLASS );
	document.documentElement.style.removeProperty( HEIGHT_PROPERTY );
};

export const HandoffBanner = ( {
	bodyText = __( 'Return to Newspack after completing configuration', 'newspack-plugin' ),
	primaryButtonText = __( 'Back to Newspack', 'newspack-plugin' ),
	dismissButtonText = __( 'Dismiss', 'newspack-plugin' ),
	primaryButtonURL = '/wp-admin/admin.php?page=newspack-dashboard',
} ) => {
	const [ visibility, setVisibility ] = useState( true );
	const bannerRef = useRef( null );

	// Full-screen editors lay themselves out against the viewport and ignore the
	// banner's place in the document flow. Publish the measured space their scoped
	// CSS has to reserve, and take it back when the banner goes.
	//
	// The measurement is the banner's distance from the top of the viewport, not
	// its own height: anything stacked above it in the flow — the admin bar
	// padding `html.wp-toolbar` keeps even where the bar itself is hidden, the
	// WooCommerce header offset below — has to be cleared too.
	useEffect( () => {
		const banner = bannerRef.current;
		if ( ! visibility || ! banner ) {
			clearBannerHeight();
			return;
		}
		const updateHeight = () => {
			document.documentElement.classList.add( VISIBLE_CLASS );
			document.documentElement.style.setProperty( HEIGHT_PROPERTY, `${ Math.ceil( banner.getBoundingClientRect().bottom ) }px` );
		};
		updateHeight();
		if ( typeof window.ResizeObserver !== 'function' ) {
			return clearBannerHeight;
		}
		const observer = new window.ResizeObserver( updateHeight );
		observer.observe( banner );
		return () => {
			observer.disconnect();
			clearBannerHeight();
		};
	}, [ visibility ] );

	return (
		visibility && (
			<div className="newspack-handoff-banner" ref={ bannerRef }>
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
