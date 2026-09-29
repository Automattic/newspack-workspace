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
const clearBannerOffset = () => {
	document.documentElement.classList.remove( VISIBLE_CLASS );
	document.documentElement.style.removeProperty( HEIGHT_PROPERTY );
};

// The mounted banner's measurement, held at module scope so code outside the
// component can ask for a fresh reading. Null while no banner is mounted.
let measureBannerOffset = null;

/**
 * Publish a fresh offset. Anything that moves the banner without resizing it —
 * the ResizeObserver below never sees that — has to call this.
 */
export const remeasureBannerOffset = () => measureBannerOffset?.();

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
	// The measurement is the banner's distance from the top of the document, not
	// its own height: anything stacked above it in the flow — the admin bar
	// padding `html.wp-toolbar` keeps even where the bar itself is hidden, the
	// WooCommerce header offset below — has to be cleared too. Adding `scrollY`
	// back keeps the value at rest: the admin document behind a fixed editor can
	// scroll, and a viewport-relative reading taken mid-scroll would shrink (or
	// go negative) and drag the editor up under the banner.
	useEffect( () => {
		const banner = bannerRef.current;
		if ( ! visibility || ! banner ) {
			clearBannerOffset();
			return;
		}
		const updateOffset = () => {
			document.documentElement.classList.add( VISIBLE_CLASS );
			document.documentElement.style.setProperty(
				HEIGHT_PROPERTY,
				`${ Math.ceil( banner.getBoundingClientRect().bottom + window.scrollY ) }px`
			);
		};
		measureBannerOffset = updateOffset;
		updateOffset();
		const cleanUp = () => {
			measureBannerOffset = null;
			clearBannerOffset();
		};
		if ( typeof window.ResizeObserver !== 'function' ) {
			return cleanUp;
		}
		const observer = new window.ResizeObserver( updateOffset );
		observer.observe( banner );
		return () => {
			observer.disconnect();
			cleanUp();
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
				// The margin pushes the banner down without changing its size, so
				// the published offset has to be taken again. A no-op before mount.
				remeasureBannerOffset();
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
