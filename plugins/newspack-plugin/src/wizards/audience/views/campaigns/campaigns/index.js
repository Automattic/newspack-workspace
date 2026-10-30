/**
 * Campaign management screen.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { Notice } from '@wordpress/components';
import { DataViews as BaseDataViews } from '@wordpress/dataviews';
import { decodeEntities } from '@wordpress/html-entities';
import { useContext, useEffect, useMemo, useState, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { DataViews, Router, withWizardScreen } from '../../../../../../packages/components/src';
import SegmentGroup from '../../../components/segment-group';
import { dataForCampaignId, isAboveHeader, placementForPopup } from '../utils';
import { CampaignsContext } from '../../../contexts';
import './style.scss';

const { useHistory } = Router;

const DEFAULT_CAMPAIGNS_FILTER = 'active';
const STATUS_FILTERS = [ DEFAULT_CAMPAIGNS_FILTER, 'all', 'inactive', 'trash' ];

const filterByCampaign = ( prompts, campaignId ) => {
	if ( ! campaignId ) {
		return prompts;
	}
	if ( 'unassigned' === campaignId ) {
		return prompts.filter( ( { campaign_groups: campaigns } ) => ! campaigns || ! campaigns.length );
	}
	return prompts.filter( ( { campaign_groups: campaigns } ) => campaigns && campaigns.find( term => +term.term_id === +campaignId ) );
};

const filterByStatus = ( prompts, status ) => {
	if ( 'trash' === status ) {
		return prompts.filter( prompt => 'trash' === prompt.status );
	}
	const notTrashedPrompts = prompts.filter( prompt => 'trash' !== prompt.status );
	if ( DEFAULT_CAMPAIGNS_FILTER === status ) {
		return notTrashedPrompts.filter( prompt => 'publish' === prompt.status );
	}
	if ( 'inactive' === status ) {
		return notTrashedPrompts.filter( prompt => 'publish' !== prompt.status );
	}
	return notTrashedPrompts;
};

const filterBySearch = ( prompts, search ) => {
	const term = search.trim().toLowerCase();
	return term ? prompts.filter( ( { title } ) => decodeEntities( title ).toLowerCase().includes( term ) ) : prompts;
};

const filterByPlacement = ( prompts, placement ) => ( placement ? prompts.filter( prompt => placement === placementForPopup( prompt ) ) : prompts );

const groupBySegment = ( segments, prompts ) => {
	const grouped = [];
	grouped.push(
		...segments.map( ( { name: label, id, configuration, criteria } ) => ( {
			label,
			id,
			configuration,
			criteria,
			prompts: prompts.filter( ( { segments: _segments } ) => {
				if ( ! _segments ) {
					return false;
				}
				const found = _segments.find( _segment => _segment.term_id === parseInt( id ) );
				return !! found;
			} ),
		} ) )
	);
	grouped.push( {
		label: __( 'Everyone', 'newspack-plugin' ),
		id: '',
		prompts: prompts.filter( ( { segments: _segments } ) => _segments.length === 0 ),
		configuration: {},
	} );
	return grouped;
};

/**
 * Campaign management screen.
 */
const Campaigns = props => {
	const { campaignId: routeFilter = DEFAULT_CAMPAIGNS_FILTER, campaigns = [], segments } = props;

	const isStatusRoute = STATUS_FILTERS.includes( routeFilter );
	const campaignId = isStatusRoute ? undefined : routeFilter;

	const routeStatus = isStatusRoute && DEFAULT_CAMPAIGNS_FILTER !== routeFilter ? routeFilter : null;
	const [ chosenStatus, setChosenStatus ] = useState( routeStatus );
	const [ placement, setPlacement ] = useState();
	const [ search, setSearch ] = useState( '' );

	// A campaign opens on everything in it, so its drafts can be activated together.
	const status = chosenStatus ?? ( campaignId ? 'all' : DEFAULT_CAMPAIGNS_FILTER );

	const allPrompts = useContext( CampaignsContext );
	const statusPrompts = filterByStatus( filterByCampaign( allPrompts, campaignId ), status );
	const prompts = filterBySearch( filterByPlacement( statusPrompts, placement ), search );
	const placements = [ ...new Set( filterByStatus( allPrompts, 'all' ).map( placementForPopup ) ) ].filter( Boolean ).sort();
	const hasUnassigned = filterByCampaign( filterByStatus( allPrompts, 'all' ), 'unassigned' ).length;
	const hasPublishedAboveHeader = allPrompts.some( prompt => 'publish' === prompt.status && isAboveHeader( prompt ) );

	// A new route opens on that route's own status: a campaign created while Trash
	// is selected must not open on an empty Trash view.
	useEffect( () => {
		setChosenStatus( routeStatus );
	}, [ routeFilter ] );

	const history = useHistory();

	const campaignData = dataForCampaignId( campaignId, campaigns );

	const fields = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'newspack-plugin' ),
				enableGlobalSearch: true,
				enableSorting: false,
				getValue: ( { item } ) => item.title,
			},
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				elements: [
					{ value: 'active', label: __( 'Active', 'newspack-plugin' ) },
					{ value: 'inactive', label: __( 'Inactive', 'newspack-plugin' ) },
					{ value: 'all', label: __( 'All', 'newspack-plugin' ) },
					{ value: 'trash', label: __( 'Trash', 'newspack-plugin' ) },
				],
				filterBy: { operators: [ 'is' ], isPrimary: true },
				enableSorting: false,
			},
			{
				id: 'placement',
				label: __( 'Placement', 'newspack-plugin' ),
				elements: placements.map( value => ( { value, label: value } ) ),
				filterBy: { operators: [ 'is' ], isPrimary: true },
				enableSorting: false,
			},
			{
				id: 'campaign',
				label: __( 'Campaign', 'newspack-plugin' ),
				elements: [
					...( hasUnassigned ? [ { value: 'unassigned', label: __( 'Unassigned', 'newspack-plugin' ) } ] : [] ),
					...campaigns.map( ( { term_id: id, name, status: groupStatus } ) => ( {
						value: String( id ),
						label:
							'archive' === groupStatus
								? sprintf(
										// Translators: %s: campaign name.
										__( '%s (archived)', 'newspack-plugin' ),
										name
								  )
								: name,
					} ) ),
				],
				filterBy: { operators: [ 'is' ], isPrimary: true },
				enableSorting: false,
			},
		],
		[ campaigns, hasUnassigned, placements.join() ]
	);

	const view = {
		type: 'table',
		search,
		page: 1,
		perPage: 100,
		fields: [],
		filters: [
			{ field: 'status', operator: 'is', value: status },
			...( campaignId ? [ { field: 'campaign', operator: 'is', value: String( campaignId ) } ] : [] ),
			...( placement ? [ { field: 'placement', operator: 'is', value: placement } ] : [] ),
		],
	};

	const onChangeView = next => {
		setSearch( next.search || '' );
		const nextStatus = next.filters?.find( ( { field } ) => 'status' === field )?.value;
		const nextCampaign = next.filters?.find( ( { field } ) => 'campaign' === field )?.value;
		setPlacement( next.filters?.find( ( { field } ) => 'placement' === field )?.value );
		if ( nextCampaign !== ( campaignId ? String( campaignId ) : undefined ) ) {
			history.push( nextCampaign ? `/campaigns/${ nextCampaign }` : '/campaigns' );
		} else if ( nextStatus !== status ) {
			if ( campaignId ) {
				setChosenStatus( nextStatus ?? null );
			} else {
				history.push( nextStatus && DEFAULT_CAMPAIGNS_FILTER !== nextStatus ? `/campaigns/${ nextStatus }` : '/campaigns' );
			}
		}
	};

	const isDefaultView = DEFAULT_CAMPAIGNS_FILTER === status && ! campaignId && ! placement && ! search;
	const campaignIsEmpty = !! campaignId && ! filterByStatus( filterByCampaign( allPrompts, campaignId ), 'all' ).length;
	const hideEmptySegments = ! campaignIsEmpty && ( 'trash' === status || 'inactive' === status || !! placement || !! search.trim() );
	const statusGroups = groupBySegment( segments, statusPrompts );
	const groups = groupBySegment( segments, prompts )
		.map( ( segment, index ) => ( { ...segment, statusPrompts: statusGroups[ index ].prompts } ) )
		.filter( segment => {
			if ( DEFAULT_CAMPAIGNS_FILTER === status && ! campaignId && segment.configuration.is_disabled ) {
				return false;
			}
			return ! hideEmptySegments || segment.prompts.length > 0;
		} );

	return (
		<Fragment>
			{ hasPublishedAboveHeader && (
				<Notice className="newspack-campaigns__above-header-notice" status="warning" isDismissible={ false } spokenMessage="">
					{ __(
						'This site has published above-header prompts. These appear at the very top of the page and push the rest of the content down as they load, which can lower your Cumulative Layout Shift (CLS) score. To reduce the impact, keep above-header prompts short, or use an overlay placement instead.',
						'newspack-plugin'
					) }
				</Notice>
			) }
			<DataViews
				className={ classnames( 'newspack-campaigns__toolbar', { 'is-default-view': isDefaultView } ) }
				view={ view }
				onChangeView={ onChangeView }
				fields={ fields }
				data={ prompts }
				getItemId={ item => String( item.id ) }
				paginationInfo={ { totalItems: prompts.length, totalPages: 1 } }
				defaultLayouts={ { table: {} } }
			>
				<Stack direction="row" justify="space-between" align="flex-start" gap="xs" className="dataviews__view-actions">
					<Stack direction="row" align="center" gap="sm" wrap="wrap" className="dataviews__search">
						<BaseDataViews.Search label={ __( 'Search prompts', 'newspack-plugin' ) } />
						<BaseDataViews.Filters />
					</Stack>
				</Stack>
			</DataViews>
			<Stack direction="column" gap="xl">
				{ groups.map( segment => (
					<SegmentGroup
						key={ segment.id || 'everyone' }
						{ ...props }
						segment={ segment }
						campaignId={ campaignId || status }
						isTrashView={ 'trash' === status }
						campaignData={ campaignData }
					/>
				) ) }
				{ ! groups.length && (
					<p className="newspack-campaigns__no-results">
						{ ( search.trim() || placement ) && __( 'No prompts match your search.', 'newspack-plugin' ) }
						{ ! search.trim() && ! placement && 'trash' === status && __( 'Trash is empty.', 'newspack-plugin' ) }
						{ ! search.trim() && ! placement && 'inactive' === status && __( 'No inactive prompts.', 'newspack-plugin' ) }
					</p>
				) }
			</Stack>
		</Fragment>
	);
};
export default withWizardScreen( Campaigns );
