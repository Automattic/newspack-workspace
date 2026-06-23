/**
 * Field definitions for the Ads list DataView.
 *
 * Status renders the consolidated kind from
 * `newspack_newsletters_ad_status` so the column matches the filter.
 */

import { Icon } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { drafts, notAllowed, published, scheduled, trash } from '@wordpress/icons';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';

import { getAdminUrl } from '../../admin-globals';
import { formatPostDate } from '../../utils/format-date';
import { termsForTaxonomy } from '../../utils/terms';
import { statusKindLabel, STATUS_KIND_LABELS } from './status-label';

const STATUS_KIND_ICONS = {
	active: published,
	scheduled,
	expired: notAllowed,
	draft: drafts,
	trash,
};

const formatTimestampAsDate = timestamp => {
	if ( ! timestamp ) {
		return '';
	}
	const settings = getDateSettings();
	const format = settings.formats?.date || 'M j, Y';
	return dateI18n( format, timestamp * 1000 );
};

const formatDate = ymd => {
	if ( ! ymd ) {
		return '';
	}
	const settings = getDateSettings();
	const format = settings.formats?.date || 'M j, Y';
	// Append a noon UTC time so the parsed Date object lands on the
	// intended calendar day in any reasonable site timezone.
	return dateI18n( format, `${ ymd }T12:00:00Z` );
};

const editUrl = item => `${ getAdminUrl() }post.php?post=${ item.id }&action=edit`;

const getTitle = item => item?.title?.raw ?? item?.title?.rendered ?? '';

const renderTitle = ( { item } ) => {
	const raw = getTitle( item );
	// Auto-drafts carry WordPress's "Auto Draft" placeholder; show a friendly title instead.
	const title = ! raw || 'auto-draft' === item?.status ? __( '(no title)', 'newspack-newsletters' ) : raw;
	return (
		<a className="newspack-newsletters-list__title" href={ editUrl( item ) } onClickCapture={ event => event.stopPropagation() }>
			<strong>{ title }</strong>
		</a>
	);
};

const renderStatus = ( { item } ) => {
	const status = item?.newspack_newsletters_ad_status || {};
	const kind = status.kind || 'draft';
	const icon = STATUS_KIND_ICONS[ kind ] || STATUS_KIND_ICONS.draft;

	let label;
	if ( 'expired' === kind && status.expires_at ) {
		label = sprintf(
			/* translators: %s: formatted expiry date */
			__( 'Expired %s', 'newspack-newsletters' ),
			formatTimestampAsDate( status.expires_at )
		);
	} else if ( 'scheduled' === kind && status.starts_at ) {
		label = sprintf(
			/* translators: %s: formatted start date */
			__( 'Starts %s', 'newspack-newsletters' ),
			formatTimestampAsDate( status.starts_at )
		);
	} else {
		label = statusKindLabel( kind );
	}

	return (
		<span className="newspack-newsletters-list__status">
			<Icon className="newspack-newsletters-list__status-icon" icon={ icon } size={ 24 } />
			<span>{ label }</span>
		</span>
	);
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

const renderStartDate = ( { item } ) => formatDate( item?.meta?.start_date );
const renderExpiryDate = ( { item } ) => formatDate( item?.meta?.expiry_date );

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
			getValue: ( { item } ) => item?.meta?.start_date || '',
			render: renderStartDate,
		},
		{
			id: 'expiry_date',
			label: __( 'Expiration date', 'newspack-newsletters' ),
			enableSorting: true,
			getValue: ( { item } ) => item?.meta?.expiry_date || '',
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
