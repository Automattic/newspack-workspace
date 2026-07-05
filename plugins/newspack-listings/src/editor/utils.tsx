/**
 * Util functions for Newspack Listings.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { Icon, calendar, mapMarker, postList, store } from '@wordpress/icons';

/**
 * Check if the current post in the editor is a listing CPT.
 *
 * @param {string|null} listingType (Optional) If given, check if the current post is this exact listing type
 * @return {boolean} Whether or not the current post is a listing CPT.
 */
export const isListing = ( listingType: string | null = null ): boolean => {
	if ( ! window.newspack_listings_data ) {
		return false;
	}

	const { post_type, post_types } = window.newspack_listings_data;

	// If passed a listingType arg, just check whether it matches the current post type.
	if ( null !== listingType ) {
		return listingType === post_type;
	}

	// Otherwise, check whether the current post type is any listing type.
	// `post_types` only ever has the four keys below (from PHP), so iterating via
	// `Object.keys` (cast to those literal keys) keeps direct, uncast indexing below.
	for ( const slug of Object.keys( post_types ) as ( keyof NewspackListingsPostTypes )[] ) {
		if ( post_types.hasOwnProperty( slug ) && post_type === post_types[ slug ].name ) {
			return true;
		}
	}

	return false;
};

/**
 * Convert hex color to RGB.
 * From https://stackoverflow.com/questions/5623838/rgb-to-hex-and-hex-to-rgb
 *
 * @param {string} hex Color in HEX format
 * @return {Array} RGB values, e.g. [red, green, blue]
 */
const hexToRGB = ( hex: string ): number[] =>
	hex
		.replace( /^#?([a-f\d])([a-f\d])([a-f\d])$/i, ( m, r, g, b ) => '#' + r + r + g + g + b + b )
		.substring( 1 )
		// Always matches for a valid 3- or 6-digit hex color (the expected input shape); not guarded further here.
		.match( /.{2}/g )!
		.map( x => parseInt( x, 16 ) );

/**
 * Get contrast ratio of the given backgroundColor compared to black.
 *
 * @param {string} backgroundColor Color HEX value to compare with black.
 * @return {number} Contrast ratio vs. black.
 */
export const getContrastRatio = ( backgroundColor: string ): number => {
	const blackColor = '#000';
	const backgroundColorRGB = hexToRGB( backgroundColor );
	const blackRGB = hexToRGB( blackColor );

	const l1 =
		0.2126 * Math.pow( backgroundColorRGB[ 0 ] / 255, 2.2 ) +
		0.7152 * Math.pow( backgroundColorRGB[ 1 ] / 255, 2.2 ) +
		0.0722 * Math.pow( backgroundColorRGB[ 2 ] / 255, 2.2 );
	const l2 =
		0.2126 * Math.pow( blackRGB[ 0 ] / 255, 2.2 ) + 0.7152 * Math.pow( blackRGB[ 1 ] / 255, 2.2 ) + 0.0722 * Math.pow( blackRGB[ 2 ] / 255, 2.2 );

	return l1 > l2 ? parseInt( String( ( l1 + 0.05 ) / ( l2 + 0.05 ) ) ) : parseInt( String( ( l2 + 0.05 ) / ( l1 + 0.05 ) ) );
};

/**
 * Attributes read by getCuratedListClasses, matching the Curated List block's `block.json`.
 */
type CuratedListClassAttributes = {
	backgroundColor?: string;
	hasDarkBackground?: boolean;
	queryMode?: boolean;
	showNumbers?: boolean;
	showMap?: boolean;
	showSortUi?: boolean;
	showImage?: boolean;
	mediaPosition?: string;
	typeScale?: number;
	imageScale?: number;
};

/**
 * Get array of class names for Curated List, based on attributes.
 *
 * @param {string} className  The base class name for the block.
 * @param {Object} attributes Block attributes.
 * @return {Array} Array of class names for the block.
 */
export const getCuratedListClasses = ( className: string, attributes: CuratedListClassAttributes ): string[] => {
	const { backgroundColor, hasDarkBackground, queryMode, showNumbers, showMap, showSortUi, showImage, mediaPosition, typeScale, imageScale } =
		attributes;

	const classes = [ className, 'newspack-listings__curated-list' ];

	if ( showNumbers ) {
		classes.push( 'show-numbers' );
	}
	if ( showMap ) {
		classes.push( 'show-map' );
	}
	if ( showSortUi ) {
		classes.push( 'has-sort-ui' );
	}
	if ( showImage ) {
		classes.push( 'show-image' );
		classes.push( `media-position-${ mediaPosition }` );
		classes.push( `media-size-${ imageScale }` );
	}
	if ( backgroundColor ) {
		if ( hasDarkBackground ) {
			classes.push( 'has-dark-background' );
		}
		classes.push( 'has-background-color' );
	}
	if ( queryMode ) {
		classes.push( 'query-mode' );
	}

	classes.push( `type-scale-${ typeScale }` );

	return classes;
};

/**
 * Hook to tell us whether the current render is the initial render
 * (immediately after mount, with default props) or a subsequent render.
 * Useful so we don't fire side effects before block attributes are ready.
 *
 * return {boolean} True if this is the initial render, false if subsequent.
 */
export const useDidMount = () => {
	const didMount = useRef( true );

	useEffect( () => {
		didMount.current = false;
	}, [] );

	return didMount.current;
};

/**
 * Generic utility to capitalize a given string.
 *
 * @param {string} str String to capitalize.
 * @return {string} Same string, with first letter capitalized.
 */
export const capitalize = ( str: string ): string => str[ 0 ].toUpperCase() + str.slice( 1 );

/**
 * Map listing type icons to listing type slugs.
 *
 * @param {string} listingTypeSlug Slug of the listing type to get an icon for.
 *                                 One of: event, generic, marketplace, place
 * @return {Function} SVG component for the matching icon.
 */
export const getIcon = ( listingTypeSlug: string ) => {
	switch ( listingTypeSlug ) {
		case 'event':
			return <Icon icon={ calendar } />;
		case 'marketplace':
			return <Icon icon={ store } />;
		case 'place':
			return <Icon icon={ mapMarker } />;
		default:
			return <Icon icon={ postList } />;
	}
};

/**
 * A term (category or tag) as referenced on a listing post for class name purposes.
 */
type ListingPostTerm = { slug: string };

/**
 * The subset of a listing post's shape read by getTermClasses.
 */
type ListingPostForClasses = {
	id?: number;
	type?: string;
	category?: ListingPostTerm[];
	tags?: ListingPostTerm[];
	classes?: string[];
};

/**
 * Get an array of term-based class names for the given or current listing.
 *
 * @param {Object} post Post object for the post.
 * @return {Array} Array of term-based class names.
 */
export const getTermClasses = ( post: ListingPostForClasses ): string[] => {
	const classes: string[] = [];

	if ( ! post.id || ! post.type ) {
		return classes;
	}

	// Post type class.
	classes.push( `type-${ post.type }` );

	// Category and tag classes.
	( post.category || [] ).forEach( category => classes.push( `category-${ category.slug }` ) );
	( post.tags || [] ).forEach( tag => classes.push( `tag-${ tag.slug }` ) );

	// Add any extra classes.
	const extraClasses = post.classes && Array.isArray( post.classes ) ? post.classes : [];

	return classes.concat( extraClasses );
};
