/**
 * Campaign management screen.
 */

/**
 * WordPress dependencies.
 */
import { Notice, SearchControl } from '@wordpress/components';
import { decodeEntities } from '@wordpress/html-entities';
import { useContext, useState, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Router, withWizardScreen } from '../../../../../../packages/components/src';
import SegmentGroup from '../../../components/segment-group';
import { dataForCampaignId, isAboveHeader } from '../utils';
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
	const { campaignActions, campaignId: routeFilter = DEFAULT_CAMPAIGNS_FILTER, campaigns = [], segments } = props;

	const isStatusRoute = STATUS_FILTERS.includes( routeFilter );
	const campaignId = isStatusRoute ? undefined : routeFilter;
	const status = isStatusRoute ? routeFilter : 'all';

	const [ search, setSearch ] = useState( '' );

	const allPrompts = useContext( CampaignsContext );
	const statusPrompts = filterByStatus( filterByCampaign( allPrompts, campaignId ), status );
	const prompts = filterBySearch( statusPrompts, search );
	const hasUnassigned = filterByCampaign( filterByStatus( allPrompts, 'all' ), 'unassigned' ).length;
	const hasPublishedAboveHeader = allPrompts.some( prompt => 'publish' === prompt.status && isAboveHeader( prompt ) );

	const history = useHistory();

	const campaignData = dataForCampaignId( campaignId, campaigns );

	const viewItems = [
		{ value: DEFAULT_CAMPAIGNS_FILTER, label: __( 'Active Prompts', 'newspack-plugin' ) },
		{ value: 'all', label: __( 'All Prompts', 'newspack-plugin' ) },
		{ value: 'inactive', label: __( 'Inactive Prompts', 'newspack-plugin' ) },
		{ value: 'trash', label: __( 'Trash', 'newspack-plugin' ) },
		...( hasUnassigned || 'unassigned' === routeFilter ? [ { value: 'unassigned', label: __( 'Unassigned Prompts', 'newspack-plugin' ) } ] : [] ),
	];
	const campaignGroups = [
		{
			label: __( 'Campaigns', 'newspack-plugin' ),
			items: campaigns
				.filter( ( { status: groupStatus } ) => 'archive' !== groupStatus )
				.map( ( { term_id: id, name } ) => ( { value: String( id ), label: name } ) ),
		},
		{
			label: __( 'Archived Campaigns', 'newspack-plugin' ),
			items: campaigns
				.filter( ( { status: groupStatus } ) => 'archive' === groupStatus )
				.map( ( { term_id: id, name } ) => ( {
					value: String( id ),
					label: sprintf(
						// Translators: %s: campaign name.
						__( '%s (archived)', 'newspack-plugin' ),
						name
					),
				} ) ),
		},
	].filter( group => group.items.length );
	const allViewItems = [ ...viewItems, ...campaignGroups.flatMap( group => group.items ) ];
	const selectedView = allViewItems.find( item => item.value === String( routeFilter ) ) ?? allViewItems[ 0 ];

	const renderViewItem = item => (
		<SelectControl.Item key={ item.value } value={ item } label={ item.label }>
			<SelectControl.ItemLabel>{ item.label }</SelectControl.ItemLabel>
		</SelectControl.Item>
	);

	const campaignIsEmpty = !! campaignId && ! filterByStatus( filterByCampaign( allPrompts, campaignId ), 'all' ).length;
	const hideEmptySegments = ! campaignIsEmpty && ( 'trash' === status || 'inactive' === status || !! search.trim() );
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
			<div className="newspack-campaigns__view-toolbar">
				<SearchControl
					className="newspack-campaigns__search"
					label={ __( 'Search prompts', 'newspack-plugin' ) }
					placeholder={ __( 'Search prompts', 'newspack-plugin' ) }
					value={ search }
					onChange={ value => setSearch( value ) }
					__nextHasNoMarginBottom
					size="compact"
				/>
				<SelectControl
					className="newspack-campaigns__view-select"
					label={ __( 'Campaigns', 'newspack-plugin' ) }
					hideLabelFromVision
					size="compact"
					items={ allViewItems }
					value={ selectedView }
					onValueChange={ item => {
						if ( item ) {
							history.push( DEFAULT_CAMPAIGNS_FILTER === item.value ? '/campaigns' : `/campaigns/${ item.value }` );
						}
					} }
				>
					{ viewItems.map( renderViewItem ) }
					{ campaignGroups.map( group => (
						<SelectControl.Group key={ group.label }>
							<SelectControl.GroupLabel>{ group.label }</SelectControl.GroupLabel>
							{ group.items.map( renderViewItem ) }
						</SelectControl.Group>
					) ) }
				</SelectControl>
				{ campaignActions }
			</div>
			<Stack direction="column" gap="lg">
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
						{ search.trim() && __( 'No prompts match your search.', 'newspack-plugin' ) }
						{ ! search.trim() && 'trash' === status && __( 'Trash is empty.', 'newspack-plugin' ) }
						{ ! search.trim() && 'inactive' === status && __( 'No inactive prompts.', 'newspack-plugin' ) }
					</p>
				) }
			</Stack>
		</Fragment>
	);
};
export default withWizardScreen( Campaigns );
