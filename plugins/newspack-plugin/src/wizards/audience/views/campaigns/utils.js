/* globals newspackAudienceCampaigns */
/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { gmdateI18n } from '@wordpress/date';
import { addQueryArgs } from '@wordpress/url';
import { applyFilters, addFilter } from '@wordpress/hooks';
import { useEffect, useState, Fragment } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * External dependencies.
 */
import memoize from 'lodash/memoize';
import compact from 'lodash/compact';

const allCriteria = window.newspackAudienceCampaigns?.criteria || [];

/**
 * Check whether the given popup is an overlay.
 *
 * @param {Object} popup Popup object to check.
 * @return {boolean} True if the popup is an overlay, otherwise false.
 */
export const isOverlay = popup => {
	const overlayPlacements = window.newspackAudienceCampaigns?.overlay_placements || [];
	return -1 < overlayPlacements.indexOf( popup.options.placement );
};

/**
 * Check whether the given popup is above-header.
 *
 * @param {Object} popup Popup object to check.
 * @return {boolean} True if the popup is a above-header, otherwise false.
 */
export const isAboveHeader = popup => 'above_header' === popup.options.placement;

export const isCustomPlacement = popup => {
	const customPlacements = window.newspackAudienceCampaigns?.custom_placements || {};

	return -1 < Object.keys( customPlacements ).indexOf( popup.options.placement );
};

/**
 * Check whether the given prompt is inline.
 *
 * @param {Object} prompt Prompt object to check.
 * @return {boolean} True if the prompt is inline, otherwise false.
 */
export const isInline = prompt => ! isOverlay( prompt );

const placementMap = {
	top: __( 'Top Overlay', 'newspack-plugin' ),
	top_left: __( 'Top Left Overlay', 'newspack-plugin' ),
	top_right: __( 'Top Right Overlay', 'newspack-plugin' ),
	center: __( 'Center Overlay', 'newspack-plugin' ),
	center_left: __( 'Center Left Overlay', 'newspack-plugin' ),
	center_right: __( 'Center Right Overlay', 'newspack-plugin' ),
	bottom: __( 'Bottom Overlay', 'newspack-plugin' ),
	bottom_left: __( 'Bottom Left Overlay', 'newspack-plugin' ),
	bottom_right: __( 'Bottom Right Overlay', 'newspack-plugin' ),
	inline: __( 'Inline', 'newspack-plugin' ),
	archives: __( 'In archive pages', 'newspack-plugin' ),
	above_header: __( 'Above Header', 'newspack-plugin' ),
	manual: __( 'Manual Only', 'newspack-plugin' ),
};

export const placementForPopup = ( { options: { frequency, placement } } ) => {
	const customPlacements = window.newspackAudienceCampaigns?.custom_placements || {};
	if ( 'manual' === frequency || customPlacements.hasOwnProperty( placement ) ) {
		return __( 'Custom Placement', 'newspack-plugin' );
	}
	return placementMap[ placement ];
};

export const placementsForPopups = prompt => {
	const customPlacements = window.newspackAudienceCampaigns?.custom_placements;
	const overlayPlacements = window.newspackAudienceCampaigns?.overlay_placements;
	const options = Object.keys( placementMap )
		.filter( key => ( isOverlay( prompt ) ? -1 < overlayPlacements.indexOf( key ) : -1 === overlayPlacements.indexOf( key ) ) )
		.map( key => ( { label: placementMap[ key ], value: key } ) );

	if ( ! isOverlay( prompt ) && customPlacements ) {
		return options.concat(
			Object.keys( customPlacements ).map( key => ( {
				label: customPlacements[ key ],
				value: key,
			} ) )
		);
	}

	return options;
};

const frequencyMap = {
	once: __( 'Once a month', 'newspack-plugin' ),
	weekly: __( 'Once a week', 'newspack-plugin' ),
	daily: __( 'Once a day', 'newspack-plugin' ),
	always: __( 'Every pageview', 'newspack-plugin' ),
	custom: __( 'Custom frequency (edit prompt to manage)', 'newspack-plugin' ),
};

export const frequenciesForPopup = () => {
	return Object.keys( frequencyMap ).map( key => ( { label: frequencyMap[ key ], value: key } ) );
};

export const overlaySizesForPopups = () => {
	return window.newspackAudienceCampaigns?.overlay_sizes;
};

export const getCardClassName = ( status, forceDisabled = false ) => {
	if ( 'publish' !== status || forceDisabled ) {
		return 'newspack-card__is-disabled';
	}
	return 'newspack-card__is-supported';
};

export const promptDescription = prompt => {
	const { categories, tags, campaign_groups: campaigns, status } = prompt;
	const descriptionMessages = [];
	if ( campaigns.length > 0 ) {
		const campaignsList = campaigns.map( ( { name } ) => name ).join( ', ' );
		descriptionMessages.push(
			( campaigns.length === 1 ? __( 'Campaign: ', 'newspack-plugin' ) : __( 'Campaigns: ', 'newspack-plugin' ) ) + campaignsList
		);
	}
	if ( categories.length > 0 ) {
		descriptionMessages.push( __( 'Categories: ', 'newspack-plugin' ) + categories.map( category => category.name ).join( ', ' ) );
	}
	if ( tags.length > 0 ) {
		descriptionMessages.push( __( 'Tags: ', 'newspack-plugin' ) + tags.map( tag => tag.name ).join( ', ' ) );
	}
	if ( 'pending' === status ) {
		descriptionMessages.push( __( 'Pending review', 'newspack-plugin' ) );
	}
	if ( 'future' === status ) {
		descriptionMessages.push( __( 'Scheduled', 'newspack-plugin' ) );
	}
	descriptionMessages.push( __( 'Frequency: ', 'newspack-plugin' ) + frequencyForPopup( prompt ) );
	return descriptionMessages.length ? descriptionMessages.join( ' | ' ) : null;
};

export const segmentDescription = segment => {
	const descriptionMessages = [];

	// If the segment is disabled.
	if ( segment.configuration.is_disabled ) {
		descriptionMessages.push( __( 'Segment disabled', 'newspack-plugin' ) );
	}

	if ( segment.criteria ) {
		for ( const config of allCriteria ) {
			const item = segment.criteria.find( ( { criteria_id } ) => criteria_id === config.id );
			if ( item?.value ) {
				let value = item.value;
				if ( config.options ) {
					const option = config.options.find( ( { value: optionValue } ) => optionValue === item.value );
					if ( option ) {
						value = option.label;
					}
				}
				if ( Array.isArray( value ) ) {
					value = value.join( ', ' );
				} else if ( typeof value === 'object' ) {
					const values = [];
					for ( const key in value ) {
						if ( value[ key ] ) {
							values.push( `${ key }: ${ value[ key ] }` );
						}
					}
					value = values.join( ', ' );
				}
				const message = applyFilters(
					'newspack.wizards.campaigns.segmentDescription.criteriaMessage',
					sprintf( '%1$s: %2$s', config.name, value ),
					value,
					config,
					item
				);
				if ( message ) {
					descriptionMessages.push( message );
				}
			}
		}
	}

	if ( ! descriptionMessages.length ) {
		return null;
	}

	// Criteria messages can be React elements (some `criteriaMessage` filters resolve
	// their labels asynchronously). Interleave the separators as nodes instead of
	// `.join( ' | ' )`, which would coerce those elements to the string "[object Object]".
	return (
		<Fragment>
			{ descriptionMessages.map( ( message, index ) => (
				<Fragment key={ index }>
					{ index > 0 && ' | ' }
					{ message }
				</Fragment>
			) ) }
		</Fragment>
	);
};

/**
 * A count as a share of the segmented audience.
 *
 * Rounds to whole percent, which is as much precision as a weekly sample
 * supports — with one exception: a segment that reached somebody must not
 * round down to a flat `0%`, which reads as "nobody" and is the one thing
 * these numbers exist to distinguish.
 *
 * @param {number} value Sessions for this segment.
 * @param {number} total Sessions where segmentation ran.
 * @return {string} Formatted percentage.
 */
const reachShare = ( value, total ) => {
	if ( 0 === value ) {
		return '0%';
	}
	const percent = ( value / total ) * 100;
	return percent < 1 ? '<1%' : `${ Math.round( percent ) }%`;
};

/**
 * One-line reach summary for a segment row, from the GA4 numbers the segments
 * endpoint attaches (GA4_Segment_Reach::decorate_segments).
 *
 * `matched` counts sessions where the reader satisfied the segment's criteria;
 * `won` counts the subset where the segment won the priority match, which is
 * the audience its prompts can actually reach. Both read as a share of the
 * sessions segmentation evaluated — a raw session count says little without
 * knowing how big the week was, and the gap between the two shares is what
 * shows higher-priority segments absorbing readers.
 *
 * The denominator stays on the line rather than being folded away: 34% of 40
 * sessions and 34% of 40,000 support very different decisions, and a segment
 * list is exactly where someone decides what to target.
 *
 * @param {Object} segment Segment, possibly carrying a `reach` object.
 * @return {string|null} The line, or null when reach reporting is inactive.
 */
export const segmentReachDescription = segment => {
	const reach = segment?.reach;
	if ( ! reach ) {
		return null;
	}
	const total = Number( reach.total_sessions );
	// Null numbers mean the cache exists but this segment has no rows yet — a
	// young segment, no traffic, or GA thresholding. All honestly "no data
	// yet"; rendering 0 would overclaim. A missing or zero denominator is the
	// same story from the other side: a week GA reported nothing for, where
	// every share would divide by zero.
	if ( null === reach.matched || undefined === reach.matched || ! total ) {
		return __( 'No reach data yet', 'newspack-plugin' );
	}
	return sprintf(
		/* Translators: %1$d: number of days the report covers. %2$s: share of the audience the segment reached, e.g. 34%. %3$s: total session count. %4$s: share the segment's prompts can reach. %5$s: date of the data. */
		__( 'Reach (%1$dd): %2$s of %3$s sessions · prompt audience: %4$s · as of %5$s', 'newspack-plugin' ),
		// The window is a server-side constant, passed through so the label
		// can't drift from the report it describes.
		Number( reach.range_days ) || 7,
		reachShare( Number( reach.matched ), total ),
		total.toLocaleString(),
		reachShare( Number( reach.won ), total ),
		// `as_of` is the last calendar day the report covers, computed in UTC.
		// It labels a day rather than marking a moment, so it must read the
		// same everywhere: anchor it to UTC midnight and format in UTC. Site
		// time would shift it a day for negative offsets (`dateI18n` renders
		// 2026-08-06 as "Aug 5" at UTC-5).
		gmdateI18n( 'M j', `${ reach.as_of }T00:00:00+00:00` )
	);
};

const getFavoriteCategoryNamesFn = async favoriteCategories => {
	const favoriteCategoryNames = await Promise.all(
		favoriteCategories.map( async categoryId => {
			try {
				const category = await apiFetch( {
					path: addQueryArgs( '/wp/v2/categories/' + categoryId, {
						_fields: 'name',
					} ),
				} );
				return category.name;
			} catch ( e ) {
				return '';
			}
		} )
	);
	return compact( favoriteCategoryNames );
};
const getFavoriteCategoryNames = memoize( getFavoriteCategoryNamesFn );

const FavoriteCategoriesNames = ( { ids } ) => {
	const [ favoriteCategoryNames, setFavoriteCategoryNames ] = useState( [] );
	useEffect( () => {
		getFavoriteCategoryNames( ids ).then( setFavoriteCategoryNames );
	}, [ ids ] );
	if ( ! favoriteCategoryNames.length ) {
		return null;
	}
	return (
		<span>
			{ __( 'Favorite Categories:', 'newspack-plugin' ) } { favoriteCategoryNames.length ? favoriteCategoryNames.join( ', ' ) : '' }
		</span>
	);
};

addFilter( 'newspack.wizards.campaigns.segmentDescription.criteriaMessage', 'newspack.favoriteCategories', ( message, value, config, item ) => {
	if ( 'favorite_categories' === config.id ) {
		if ( ! item.value?.length ) {
			return null;
		}
		return <FavoriteCategoriesNames ids={ item.value } />;
	}
	return message;
} );

const getItems = memoize( async path => {
	try {
		const items = await apiFetch( {
			path,
		} );
		const values = Array.isArray( items ) ? items : Object.values( items );
		return values.map( item => ( {
			id: isNaN( parseInt( item.id ) ) ? item.id.toString() : parseInt( item.id ),
			label: item.title || item.name,
		} ) );
	} catch ( e ) {
		return [];
	}
} );

const ItemNames = ( { label, ids, path, deletedItemLabel } ) => {
	const [ items, setItems ] = useState( [] );
	useEffect( () => {
		getItems( path ).then( setItems );
	}, [ ids ] );
	if ( ! items.length ) {
		return null;
	}
	const labels = ids.map( id => {
		const item = items.find( it => it.id === id );
		return item ? item.label : deletedItemLabel;
	} );
	return (
		<span>
			{ label } { labels.length ? labels.join( ', ' ) : '' }
		</span>
	);
};

addFilter(
	'newspack.wizards.campaigns.segmentDescription.criteriaMessage',
	'newspack.newsletterSubscribedLists',
	( message, value, config, item ) => {
		if ( [ 'subscribed_lists', 'not_subscribed_lists' ].includes( config.id ) ) {
			if ( ! item.value?.length ) {
				return null;
			}
			return (
				<ItemNames
					label={
						config.id === 'subscribed_lists' ? __( 'Subscribed to:', 'newspack-plugin' ) : __( 'Not subscribed to:', 'newspack-plugin' )
					}
					ids={ item.value }
					path="/newspack-newsletters/v1/lists_config"
					deletedItemLabel={ __( 'Deleted list', 'newspack-plugin' ) }
				/>
			);
		}
		return message;
	}
);

addFilter( 'newspack.wizards.campaigns.segmentDescription.criteriaMessage', 'newspack.activeSubscriptions', ( message, value, config, item ) => {
	if ( [ 'active_subscriptions', 'not_active_subscriptions' ].includes( config.id ) ) {
		if ( ! item.value?.length ) {
			return null;
		}
		return (
			<ItemNames
				label={
					config.id === 'active_subscriptions'
						? __( 'Has active subscription(s):', 'newspack-plugin' )
						: __( 'Does not have active subscription(s):', 'newspack-plugin' )
				}
				ids={ item.value }
				path={ `${ newspackAudienceCampaigns.api }/subscription-products` }
				deletedItemLabel={ __( 'Deleted subscription', 'newspack-plugin' ) }
			/>
		);
	}
	return message;
} );

addFilter( 'newspack.wizards.campaigns.segmentDescription.criteriaMessage', 'newspack.activeMemberships', ( message, value, config, item ) => {
	if ( [ 'active_memberships', 'not_active_memberships' ].includes( config.id ) ) {
		if ( ! item.value?.length ) {
			return null;
		}
		return (
			<ItemNames
				label={
					config.id === 'active_memberships'
						? __( 'Has active membership(s):', 'newspack-plugin' )
						: __( 'Does not have active membership(s):', 'newspack-plugin' )
				}
				ids={ item.value }
				path="/wc/v3/memberships/plans?per_page=100"
				deletedItemLabel={ __( 'Deleted membership', 'newspack-plugin' ) }
			/>
		);
	}
	return message;
} );

export const isSameType = ( campaignA, campaignB ) => {
	return campaignA.options.placement === campaignB.options.placement;
};

const sharesSegments = ( segmentsA, segmentsB ) => {
	const segmentsArrayA = segmentsA.map( segment => segment.term_id );
	const segmentsArrayB = segmentsB.map( segment => segment.term_id );
	return ( ! segmentsArrayA.length && ! segmentsArrayB.length ) || segmentsArrayA.some( segment => -1 < segmentsArrayB.indexOf( segment ) );
};

export const buildWarning = prompt => {
	if ( isOverlay( prompt ) || isAboveHeader( prompt ) ) {
		return sprintf(
			// Translators: %s is prompt type (above-header or overlay).
			__( 'If multiple %s are rendered on the same pageview, only the most recent one will be displayed.', 'newspack-plugin' ),
			isAboveHeader( prompt ) ? __( 'above-header prompts', 'newspack-plugin' ) : __( 'overlays', 'newspack-plugin' )
		);
	}

	if ( isCustomPlacement( prompt ) ) {
		return __( 'If multiple prompts are rendered in the same custom placement, only the most recent one will be displayed.', 'newspack-plugin' );
	}

	return '';
};

export const warningForPopup = ( prompts, prompt ) => {
	const warningMessages = [];

	if ( 'publish' === prompt.status && ( isAboveHeader( prompt ) || isOverlay( prompt ) || isCustomPlacement( prompt ) ) ) {
		const promptCategories = prompt.categories;
		const conflictingPrompts = prompts.filter( conflict => {
			const conflictCategories = conflict.categories;

			// There's a conflict if both campaigns have zero categories, or if they share at least one category.
			const hasConflictingCategory =
				( 0 === promptCategories.length && 0 === conflictCategories.length ) ||
				promptCategories.some( category => conflictCategories.some( conflictCategory => category.term_id === conflictCategory.term_id ) );

			return (
				'publish' === conflict.status &&
				conflict.id !== prompt.id &&
				isSameType( prompt, conflict ) &&
				sharesSegments( prompt.segments, conflict.segments ) &&
				hasConflictingCategory
			);
		} );

		const filteredConflictingPrompts = applyFilters( 'newspack.wizards.campaigns.conflictingPrompts', conflictingPrompts, prompt, prompts );

		if ( 0 < filteredConflictingPrompts.length ) {
			return (
				<>
					<strong>
						{ sprintf(
							// Translators: %s: 'Conflicts' or 'Conflict' depending on number of conflicts.
							__( '%s detected:', 'newspack-plugin' ),
							1 < filteredConflictingPrompts.length ? __( 'Conflicts', 'newspack-plugin' ) : __( 'Conflict', 'newspack-plugin' )
						) }
					</strong>
					<ul>
						{ filteredConflictingPrompts.map( conflictingPrompt => (
							<li key={ conflictingPrompt.id }>
								<p data-testid={ `conflict-warning-${ prompt.id }` }>
									<strong>
										{ sprintf(
											// Translators: %s: title of the conflicting prompt.
											__( '%s:', 'newspack-plugin' ),
											decodeEntities( conflictingPrompt.title )
										) }{ ' ' }
									</strong>
									<span>{ buildWarning( prompt, promptCategories ) }</span>
								</p>
							</li>
						) ) }
					</ul>
				</>
			);
		}

		return null;
	}

	return warningMessages.length ? warningMessages.join( ' ' ) : null;
};

export const frequencyForPopup = ( { options: { frequency } } ) => frequencyMap[ frequency ];

export const dataForCampaignId = ( id, campaigns ) => campaigns.reduce( ( acc, group ) => ( +id > 0 && +id === +group.term_id ? group : acc ), null );
