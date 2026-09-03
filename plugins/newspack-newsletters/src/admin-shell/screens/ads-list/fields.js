/**
 * Field definitions for the Ads list DataView.
 *
 * Status renders the consolidated kind from
 * `newspack_newsletters_ad_status` so the column matches the filter.
 */

import { Tooltip } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n, getSettings as getDateSettings, gmdateI18n } from '@wordpress/date';

import { StatusIndicator } from 'newspack-components';

import { getAdminUrl } from '../../admin-globals';
import { formatPostDate } from '../../utils/format-date';
import { termsForTaxonomy } from '../../utils/terms';
import { statusKindLabel, STATUS_KIND_LABELS } from './status-label';

export const STATUS_KIND_STATUSES = {
	active: 'active',
	scheduled: 'scheduled',
	expired: 'ended',
	draft: 'draft',
	trash: 'trash',
};

// Ad windows are whole calendar days: the meta is `Y-m-d`, and `is_ad_active()`
// compares it as a string against the newsletter's own date, so none of these
// values carries a time.
//
// Keep the publisher's configured pattern, which is what supplies locale
// ordering (`j. F Y` on a German site). Fall back only when that pattern also
// carries a time — `date_format` is allowed to, and then the anchor below
// would surface as a meaningless clock reading, e.g. "8:00 am" on an EDT site.
const TIME_FORMAT_TOKENS = /[aABgGhHisuvcrTeIOPZ]/;
const adDateFormat = () => {
	const configured = getDateSettings().formats?.date;
	if ( configured && ! TIME_FORMAT_TOKENS.test( configured.replace( /\\./g, '' ) ) ) {
		return configured;
	}
	/* translators: PHP date format for an ad's start/expiration day. Date tokens only — these values have no time. */
	return __( 'F j, Y', 'newspack-newsletters' );
};

// One hint per column: each says the thing relevant to its own date, so the
// inclusivity falls out of the wording rather than needing its own sentence.
const startDateHint = () => __( 'Runs from this day, in the site timezone.', 'newspack-newsletters' );
const expiryDateHint = () => __( 'Runs through the end of this day, in the site timezone.', 'newspack-newsletters' );

// The REST status payload sends `starts_at`/`expires_at` in two shapes that
// need opposite handling, so keep them as separate named formatters rather
// than one function that guesses.
//
// A day anchor stands for a calendar day off the `Y-m-d` meta, pinned at noon
// UTC by the REST layer: format it in UTC, because site time moves it to the
// next day once the offset reaches +12.
const formatDayAnchor = timestamp => ( timestamp ? gmdateI18n( adDateFormat(), timestamp * 1000 ) : '' );

// An instant is a real moment — `post_date_gmt`, the auto-publish time of a
// `future` post: format it in site time, the day the scheduler shows. In UTC an
// ad publishing at 21:00 in New York would be dated the following day.
const formatInstant = timestamp => ( timestamp ? dateI18n( adDateFormat(), timestamp * 1000 ) : '' );

const formatDate = value => {
	if ( ! value ) {
		return '';
	}
	// Tolerate a legacy ISO datetime meta value by keeping only the date.
	const ymd = String( value ).slice( 0, 10 );
	return gmdateI18n( adDateFormat(), `${ ymd }T00:00:00Z` );
};

const editUrl = item => `${ getAdminUrl() }post.php?post=${ item.id }&action=edit`;

const getTitle = item => item?.title?.raw ?? item?.title?.rendered ?? '';

const renderTitle = ( { item } ) => {
	const raw = getTitle( item );
	const title = raw || __( '(no title)', 'newspack-newsletters' );
	return (
		<a className="newspack-newsletters-list__title" href={ editUrl( item ) } onClickCapture={ event => event.stopPropagation() }>
			<strong>{ title }</strong>
		</a>
	);
};

const renderStatus = ( { item } ) => {
	const status = item?.newspack_newsletters_ad_status || {};
	const kind = status.kind || 'draft';
	const statusName = STATUS_KIND_STATUSES[ kind ] || STATUS_KIND_STATUSES.draft;

	let label;
	if ( 'expired' === kind && status.expires_at ) {
		// `expires_at` is only ever set from the date meta, so always an anchor.
		label = sprintf(
			/* translators: %s: formatted expiry date */
			__( 'Expired %s', 'newspack-newsletters' ),
			formatDayAnchor( status.expires_at )
		);
	} else if ( 'scheduled' === kind && status.starts_at ) {
		// Scheduled has two sources: a `future` post reports its publish instant,
		// while a published ad with a future start date reports a day anchor.
		const formatStart = 'future' === item?.status ? formatInstant : formatDayAnchor;
		label = sprintf(
			/* translators: %s: formatted start date */
			__( 'Starts %s', 'newspack-newsletters' ),
			formatStart( status.starts_at )
		);
	} else {
		label = statusKindLabel( kind );
	}

	return <StatusIndicator status={ statusName }>{ label }</StatusIndicator>;
};

const renderTerms =
	taxonomy =>
	( { item } ) => {
		const terms = termsForTaxonomy( item, taxonomy );
		return terms
			.map( term => term?.name )
			.filter( Boolean )
			.join( ', ' );
	};

const renderAdDate = ( value, hint ) => {
	const formatted = formatDate( value );
	if ( ! formatted ) {
		return '';
	}
	return (
		<Tooltip text={ hint }>
			<span>{ formatted }</span>
		</Tooltip>
	);
};

const renderStartDate = ( { item } ) => renderAdDate( item?.meta?.start_date, startDateHint() );
const renderExpiryDate = ( { item } ) => renderAdDate( item?.meta?.expiry_date, expiryDateHint() );

const renderImpressions = ( { item } ) => String( item?.meta?.tracking_impressions ?? 0 );
const renderClicks = ( { item } ) => String( item?.meta?.tracking_clicks ?? 0 );
const renderPrice = ( { item } ) => {
	const price = item?.meta?.price;
	if ( ! price ) {
		return '';
	}
	return String( price );
};

const renderDate = ( { item } ) => formatPostDate( item );

export function getFields( { advertisers = [], placements = [] } = {} ) {
	const statusLabels = STATUS_KIND_LABELS();

	return [
		{
			id: 'title',
			label: __( 'Title', 'newspack-newsletters' ),
			enableGlobalSearch: true,
			getValue: ( { item } ) => getTitle( item ),
			render: renderTitle,
		},
		{
			id: 'status',
			label: __( 'Status', 'newspack-newsletters' ),
			elements: [
				{ value: 'active', label: statusLabels.active },
				{ value: 'scheduled', label: statusLabels.scheduled },
				{ value: 'expired', label: statusLabels.expired },
				{ value: 'draft', label: statusLabels.draft },
				{ value: 'trash', label: statusLabels.trash },
			],
			filterBy: { operators: [ 'isAny' ] },
			getValue: ( { item } ) => item?.newspack_newsletters_ad_status?.kind || 'draft',
			render: renderStatus,
		},
		{
			id: 'advertiser',
			label: __( 'Advertiser', 'newspack-newsletters' ),
			elements: advertisers.map( term => ( {
				value: String( term.id ),
				label: term.name,
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
			getValue: ( { item } ) =>
				termsForTaxonomy( item, 'newspack_nl_advertiser' )
					.map( term => term?.name )
					.filter( Boolean )
					.join( ', ' ),
			render: renderTerms( 'newspack_nl_advertiser' ),
		},
		{
			id: 'ad_placement',
			label: __( 'Ad placement', 'newspack-newsletters' ),
			elements: placements.map( term => ( {
				value: String( term.id ),
				label: term.name,
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			enableSorting: false,
			getValue: ( { item } ) =>
				termsForTaxonomy( item, 'newspack_nl_ad_placement' )
					.map( term => term?.name )
					.filter( Boolean )
					.join( ', ' ),
			render: renderTerms( 'newspack_nl_ad_placement' ),
		},
		{
			id: 'start_date',
			label: __( 'Start date', 'newspack-newsletters' ),
			enableSorting: true,
			// Slice legacy ISO datetime to Y-m-d for consistent sort/export.
			getValue: ( { item } ) => String( item?.meta?.start_date || '' ).slice( 0, 10 ),
			render: renderStartDate,
		},
		{
			id: 'expiry_date',
			label: __( 'Expiration date', 'newspack-newsletters' ),
			enableSorting: true,
			// Slice legacy ISO datetime to Y-m-d for consistent sort/export.
			getValue: ( { item } ) => String( item?.meta?.expiry_date || '' ).slice( 0, 10 ),
			render: renderExpiryDate,
		},
		{
			id: 'impressions',
			label: __( 'Impressions', 'newspack-newsletters' ),
			enableSorting: true,
			getValue: ( { item } ) => Number( item?.meta?.tracking_impressions ?? 0 ),
			render: renderImpressions,
		},
		{
			id: 'clicks',
			label: __( 'Clicks', 'newspack-newsletters' ),
			enableSorting: true,
			getValue: ( { item } ) => Number( item?.meta?.tracking_clicks ?? 0 ),
			render: renderClicks,
		},
		{
			id: 'price',
			label: __( 'Price', 'newspack-newsletters' ),
			enableSorting: true,
			getValue: ( { item } ) => Number( item?.meta?.price ?? 0 ),
			render: renderPrice,
		},
		{
			id: 'categories',
			label: __( 'Categories', 'newspack-newsletters' ),
			enableSorting: false,
			getValue: ( { item } ) =>
				termsForTaxonomy( item, 'category' )
					.map( term => term?.name )
					.filter( Boolean )
					.join( ', ' ),
			render: renderTerms( 'category' ),
		},
		{
			id: 'date',
			label: __( 'Date', 'newspack-newsletters' ),
			enableSorting: true,
			getValue: ( { item } ) => item?.date || '',
			render: renderDate,
		},
	];
}
