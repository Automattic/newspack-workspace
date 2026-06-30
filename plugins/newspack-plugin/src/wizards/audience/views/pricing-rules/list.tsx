/**
 * Pricing Rules list view (DataViews). Reads the standalone plugin's rules REST.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View } from '@wordpress/dataviews';
import { Spinner, Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { DataViews, Badge, Router } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import CatalogImpact from './catalog-impact';
import { intentLabel } from './recipes';
import { pricingModelSentence } from './model-sentence';

const { useHistory } = Router;

const API_PATH = '/wc-dynamic-pricing/v1/rules';

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'title', direction: 'asc' },
	search: '',
	fields: [ 'strategy', 'scope', 'priority', 'status', 'goal', 'reader_segments' ],
	filters: [], // Show all statuses by default; the REST already excludes trash.
	layout: {},
	titleField: 'title',
};

const ACTIVE_STATE_LEVEL = { active: 'success', scheduled: 'info', ended: 'default' } as const;

/** Map a rule's reader_segment condition (segment ids) to names via the vocab id→label map. */
function readerSegmentNames( conditions: PricingRuleRow[ 'conditions' ], map: Record< number, string > ): string[] {
	const ids = conditions.reader_segment;
	if ( ! Array.isArray( ids ) ) {
		return [];
	}
	return ids.map( id => map[ Number( id ) ] ?? `#${ id }` );
}

export default function PricingRulesList() {
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const history = useHistory();
	const [ data, setData ] = useState< PricingRuleRow[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ segmentMap, setSegmentMap ] = useState< Record< number, string > >( {} );
	const [ currency, setCurrency ] = useState< PricingRulesCurrency >( { code: '', symbol: '', decimals: 2 } );

	useEffect( () => {
		setHeaderData( {
			actions: [ { type: 'primary', label: __( 'Add rule', 'newspack-plugin' ), href: '#/new' } ],
		} );
	}, [ setHeaderData ] );

	const fetchData = useCallback( () => {
		setIsLoading( true );
		apiFetch< PricingRulesResponse >( { path: API_PATH } )
			.then( response => {
				setData( response.rules || [] );
				setCurrency( response.currency );
				// Capture the reader_segment id→label options so the list can name segments.
				const seg = ( response.conditions || [] ).find( c => c.id === 'reader_segment' );
				const map: Record< number, string > = {};
				( seg?.options || [] ).forEach( o => {
					map[ o.value ] = o.label;
				} );
				setSegmentMap( map );
			} )
			.catch( () =>
				addNotice( {
					message: __( 'Failed to load pricing rules. Please refresh the page.', 'newspack-plugin' ),
					type: 'error',
					id: 'pricing-rules-fetch-error',
				} )
			)
			.finally( () => setIsLoading( false ) );
	}, [ addNotice ] );

	useEffect( () => {
		fetchData();
	}, [ fetchData ] );

	const trashRule = useCallback(
		( id: number ) => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Move this pricing rule to the trash?', 'newspack-plugin' ) ) ) {
				return;
			}
			apiFetch( { path: `${ API_PATH }/${ id }`, method: 'DELETE' } )
				.then( () => fetchData() )
				.catch( () =>
					addNotice( { message: __( 'Failed to trash the rule.', 'newspack-plugin' ), type: 'error', id: 'pricing-rules-trash-error' } )
				);
		},
		[ addNotice, fetchData ]
	);

	const statusElements = useMemo( () => {
		const seen = new Map< string, string >();
		data.forEach( item => seen.set( item.status, item.status_label ) );
		return Array.from( seen, ( [ value, label ] ) => ( { value, label } ) );
	}, [ data ] );

	const fields: Field< PricingRuleRow >[] = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Name', 'newspack-plugin' ),
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.title,
				render: ( { item } ) => (
					<Button variant="link" onClick={ () => history.push( `/edit/${ item.id }` ) }>
						<strong>{ item.title || `#${ item.id }` }</strong>
					</Button>
				),
			},
			{
				id: 'deal_id',
				label: __( 'Deal ID', 'newspack-plugin' ),
				getValue: ( { item } ) => item.deal_key,
				render: ( { item } ) => <code>{ item.deal_key }</code>,
				enableSorting: false,
			},
			{
				id: 'strategy',
				label: __( 'Pricing model', 'newspack-plugin' ),
				getValue: ( { item } ) => pricingModelSentence( item, currency ),
				render: ( { item } ) => <span>{ pricingModelSentence( item, currency ) }</span>,
				enableSorting: false,
			},
			{
				id: 'scope',
				label: __( 'Applies to', 'newspack-plugin' ),
				getValue: ( { item } ) => item.scope_label,
				render: ( { item } ) => (
					<span>
						{ item.scope_label }
						{ item.scope_ids.length ? ` (${ item.scope_ids.length })` : '' }
					</span>
				),
				enableSorting: false,
			},
			{ id: 'priority', label: __( 'Priority', 'newspack-plugin' ), getValue: ( { item } ) => item.priority },
			{
				id: 'status',
				label: __( 'Status', 'newspack-plugin' ),
				getValue: ( { item } ) => item.status,
				render: ( { item } ) => <Badge level={ item.status === 'publish' ? 'success' : 'default' } text={ item.status_label } />,
				elements: statusElements,
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'active_window',
				label: __( 'Active window', 'newspack-plugin' ),
				getValue: ( { item } ) => item.active_state,
				render: ( { item } ) => <Badge level={ ACTIVE_STATE_LEVEL[ item.active_state ] } text={ item.active_state } />,
				enableSorting: false,
			},
			{
				id: 'goal',
				label: __( 'Goal', 'newspack-plugin' ),
				getValue: ( { item } ) => intentLabel( item.intent ),
				render: ( { item } ) => <span>{ intentLabel( item.intent ) }</span>,
				enableSorting: false,
			},
			{
				id: 'reader_segments',
				label: __( 'Reader segments', 'newspack-plugin' ),
				getValue: ( { item } ) => readerSegmentNames( item.conditions, segmentMap ).join( ', ' ),
				render: ( { item } ) => {
					const names = readerSegmentNames( item.conditions, segmentMap );
					return names.length ? (
						<span>{ names.join( ', ' ) }</span>
					) : (
						<span className="newspack-pricing-rules__muted">{ __( 'Any reader', 'newspack-plugin' ) }</span>
					);
				},
				enableSorting: false,
			},
			{
				id: 'publicize',
				label: __( 'Publicize', 'newspack-plugin' ),
				getValue: ( { item } ) => ( item.publicize ? 'yes' : 'no' ),
				render: ( { item } ) => <span>{ item.publicize ? __( 'Shown', 'newspack-plugin' ) : __( 'Silent', 'newspack-plugin' ) }</span>,
				enableSorting: false,
			},
		],
		[ statusElements, history, segmentMap, currency ]
	);

	const actions: Action< PricingRuleRow >[] = useMemo(
		() => [
			{ id: 'edit', label: __( 'Edit', 'newspack-plugin' ), isPrimary: true, callback: items => history.push( `/edit/${ items[ 0 ].id }` ) },
			{ id: 'trash', label: __( 'Trash', 'newspack-plugin' ), isDestructive: true, callback: items => trashRule( items[ 0 ].id ) },
		],
		[ history, trashRule ]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( data, view, fields ), [ data, view, fields ] );

	return (
		<div className="newspack-pricing-rules">
			<CatalogImpact />
			{ isLoading ? (
				<div style={ { display: 'flex', justifyContent: 'center', padding: '48px' } }>
					<Spinner />
				</div>
			) : (
				<DataViews
					data={ processedData }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					isLoading={ isLoading }
					getItemId={ ( item: PricingRuleRow ) => String( item.id ) }
					search
				/>
			) }
		</div>
	);
}
