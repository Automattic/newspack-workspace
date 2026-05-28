import { RawHTML, useMemo } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import usePreventNav from '../hooks/usePreventNav';

/* eslint-disable jsx-a11y/anchor-is-valid */

/**
 * Individual collection item component.
 *
 * @param {Object} props            Component props.
 * @param {Object} props.collection Collection post object.
 * @param {Object} props.attributes Block attributes.
 * @return {JSX.Element} Collection item component.
 */
const CollectionItem = ( { collection, attributes } ) => {
	const {
		showFeaturedImage,
		showCategory,
		showTitle,
		showExcerpt,
		showPeriod,
		showVolume,
		showNumber,
		showCTAs,
		numberOfCTAs,
		showSubscriptionUrl,
		showOrderUrl,
		specificCTAs,
		layout,
	} = attributes;

	// Prevent default navigation for non-navigable anchors in the editor.
	const preventNav = usePreventNav();

	// Decode once per render instead of on every usage.
	const titleText = useMemo( () => decodeEntities( collection?.title?.rendered || '' ), [ collection?.title?.rendered ] );

	// Get featured image from embedded data.
	const featuredImage = useMemo( () => collection?._embedded?.[ 'wp:featuredmedia' ]?.[ 0 ], [ collection?._embedded ] );

	// Get categories from embedded data.
	const categories = useMemo( () => {
		return collection?._embedded?.[ 'wp:term' ]?.find( terms => terms?.[ 0 ]?.taxonomy === 'newspack_collection_category' ) || [];
	}, [ collection?._embedded ] );

	// Collection metadata.
	const meta = collection.meta || {};
	const volume = meta.newspack_collection_volume;
	const number = meta.newspack_collection_number;
	const period = meta.newspack_collection_period;

	// Build filtered CTAs with memoization.
	const filteredCtas = useMemo( () => {
		let next = ( collection.ctas || [] ).filter( cta => {
			// Filter by subscription toggle.
			if ( cta.label === __( 'Subscribe', 'newspack-plugin' ) && ! showSubscriptionUrl ) {
				return false;
			}
			// Filter by order toggle.
			if ( cta.label === __( 'Order', 'newspack-plugin' ) && ! showOrderUrl ) {
				return false;
			}
			return true;
		} );

		// Filter by specific labels if provided (case-insensitive, trims whitespace).
		if ( specificCTAs && specificCTAs.trim() ) {
			const specificLabels = new Set( specificCTAs.split( ',' ).map( label => label.trim().toLowerCase() ) );
			next = next.filter( cta => specificLabels.has( String( cta.label ).toLowerCase() ) );
		}

		// Limit number of CTAs.
		return next.slice( 0, numberOfCTAs || 1 );
	}, [ collection.ctas, showSubscriptionUrl, showOrderUrl, specificCTAs, numberOfCTAs ] );

	// Build meta elements, memoized.
	const metaElements = useMemo( () => {
		const elements = [];

		// Add period first.
		if ( showPeriod && period ) {
			elements.push(
				<span key="period" className="wp-block-newspack-collections__period">
					{ period }
				</span>
			);
		}

		// Add separator if we have period and will have volume/number.
		if ( showPeriod && period && ( ( showVolume && volume ) || ( showNumber && number ) ) ) {
			const separator =
				layout === 'list' ? (
					<span key="period-sep" className="wp-block-newspack-collections__divider">
						{ ' / ' }
					</span>
				) : (
					<br key="period-sep" />
				);
			elements.push( separator );
		}

		// Volume.
		if ( showVolume && volume ) {
			elements.push(
				<span key="volume" className="wp-block-newspack-collections__volume">
					{ `Vol. ${ volume }` }
				</span>
			);
		}

		// Separator between volume and number.
		if ( showVolume && volume && showNumber && number ) {
			elements.push(
				<span key="vol-num-sep" className="wp-block-newspack-collections__divider">
					{ ' / ' }
				</span>
			);
		}

		// Number.
		if ( showNumber && number ) {
			elements.push(
				<span key="number" className="wp-block-newspack-collections__number">
					{ `No. ${ number }` }
				</span>
			);
		}

		return elements;
	}, [ showPeriod, period, showVolume, volume, showNumber, number, layout ] );

	return (
		<article className="wp-block-newspack-collections__item">
			{ showFeaturedImage && (
				<div className="wp-block-newspack-collections__image">
					{ featuredImage ? (
						<a href="#" onClick={ preventNav }>
							<img
								src={ featuredImage?.media_details?.sizes?.full?.source_url }
								alt={ featuredImage.alt_text || titleText }
								loading="lazy"
							/>
						</a>
					) : (
						<div className="wp-block-newspack-collections__placeholder" aria-hidden="true" />
					) }
				</div>
			) }

			<div className="wp-block-newspack-collections__content">
				{ showCategory && categories.length > 0 && (
					<div className="wp-block-newspack-collections__categories">
						{ categories.map( category => (
							<a key={ category.id } href="#" onClick={ preventNav } className="wp-block-newspack-collections__category">
								{ decodeEntities( category.name ) }
							</a>
						) ) }
					</div>
				) }

				{ showTitle && (
					<h3 className="wp-block-newspack-collections__title has-normal-font-size">
						<a href="#" onClick={ preventNav }>
							{ titleText }
						</a>
					</h3>
				) }

				{ metaElements.length > 0 && (
					<div className="wp-block-newspack-collections__meta has-medium-gray-color has-text-color has-small-font-size">
						{ metaElements }
					</div>
				) }

				{ showExcerpt && ( collection.excerpt?.rendered || collection.content?.rendered ) && (
					<div className="wp-block-newspack-collections__excerpt">
						<RawHTML>{ collection.excerpt?.rendered || collection.content?.rendered }</RawHTML>
					</div>
				) }

				{ showCTAs && filteredCtas.length > 0 && (
					<div className="wp-block-newspack-collections__ctas">
						{ filteredCtas.map( ( cta, index ) => (
							<a
								key={ `${ cta.label }-${ index }` }
								href="#"
								onClick={ preventNav }
								className="wp-block-button__link has-dark-gray-color has-light-gray-background-color has-text-color has-background has-link-color wp-element-button"
							>
								{ cta.label }
							</a>
						) ) }
					</div>
				) }
			</div>
		</article>
	);
};

export default CollectionItem;
