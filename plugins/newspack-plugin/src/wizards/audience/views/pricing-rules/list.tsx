/**
 * Pricing Rules list view (DataViews). Reads the standalone plugin's rules REST.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Action, Field, View, RenderModalProps } from '@wordpress/dataviews';
import {
	Spinner,
	Button,
	Notice,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { DataViews, Router, StatusIndicator, WizardBanner } from '../../../../../packages/components/src';
import { formatCount } from '../../../../../packages/components/src/breadcrumbs/format-count';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import CatalogImpact from './catalog-impact';
import PricingRulesOnboarding from './onboarding';
import { postStatus } from '../../post-status';
import { intentLabel } from './recipes';
import { pricingModelSentence } from './model-sentence';
import { RULES_API_PATH as API_PATH, IMPACT_PREVIEW_API_PATH } from './constants';

const { useHistory } = Router;

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

// The catalogue walk is not bounded by the limit, so a hung route would otherwise
// hold the whole screen behind the spinner indefinitely.
const STATS_GATE_TIMEOUT_MS = 8000;

export const ACTIVE_STATE_STATUS = { active: 'active', scheduled: 'scheduled', ended: 'ended' } as const;

const ACTIVE_STATE_LABEL: Record< PricingRuleRow[ 'active_state' ], string > = {
	active: __( 'Active', 'newspack-plugin' ),
	scheduled: __( 'Scheduled', 'newspack-plugin' ),
	ended: __( 'Ended', 'newspack-plugin' ),
};

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
	const [ hasError, setHasError ] = useState( false );
	// Distinct from isLoading: only the first settle blanks the screen, so a
	// refetch after trashing a rule leaves the table up.
	const [ hasResolved, setHasResolved ] = useState( false );
	const [ stats, setStats ] = useState< CatalogImpactResponse | null >( null );
	const [ statsResolved, setStatsResolved ] = useState( false );
	// Remounts the card so its cached product sample cannot outlive a trash.
	const [ statsVersion, setStatsVersion ] = useState( 0 );
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ segmentMap, setSegmentMap ] = useState< Record< number, string > >( {} );
	const [ currency, setCurrency ] = useState< PricingRulesCurrency >( { code: '', symbol: '', decimals: 2 } );

	const isReady = hasResolved && statsResolved;
	// Raw payload, not the filtered view: a search that matches nothing keeps the
	// DataViews treatment rather than claiming the site has no rules. `isLoading`
	// as well as `isReady`, so a refetch cannot answer "does this site have rules"
	// from a payload that is already being replaced.
	const isEmpty = isReady && ! isLoading && ! hasError && data.length === 0;

	useEffect( () => {
		setHeaderData( {
			actions: ! isReady || isEmpty ? [] : [ { type: 'primary', label: __( 'Add Rule', 'newspack-plugin' ), href: '#/new' } ],
		} );
	}, [ setHeaderData, isReady, isEmpty ] );

	const fetchData = useCallback( () => {
		setIsLoading( true );
		setHasError( false );
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
			.catch( () => setHasError( true ) )
			.finally( () => {
				setIsLoading( false );
				setHasResolved( true );
			} );
	}, [] );

	useEffect( () => {
		fetchData();
	}, [ fetchData ] );

	// One row is enough: total_matching, count_limited and audience are all computed
	// before the limit applies, and pricing the whole sample costs several times as much.
	const statsRequest = useRef( 0 );
	const gateTimer = useRef< ReturnType< typeof setTimeout > | undefined >( undefined );

	const fetchStats = useCallback( () => {
		const generation = ++statsRequest.current;
		gateTimer.current = setTimeout( () => {
			if ( generation === statsRequest.current ) {
				setStatsResolved( true );
			}
		}, STATS_GATE_TIMEOUT_MS );
		apiFetch< CatalogImpactResponse >( { path: addQueryArgs( IMPACT_PREVIEW_API_PATH, { limit: 1 } ) } )
			.then( res => {
				if ( generation === statsRequest.current ) {
					setStats( res );
					setStatsVersion( v => v + 1 );
				}
			} )
			.catch( () => {
				// The list is the screen. A missing headline is not worth a notice.
			} )
			.finally( () => {
				clearTimeout( gateTimer.current );
				if ( generation === statsRequest.current ) {
					setStatsResolved( true );
				}
			} );
	}, [] );

	useEffect( () => {
		fetchStats();
		return () => {
			// Invalidates any in-flight response so it cannot write after unmount.
			statsRequest.current++;
			clearTimeout( gateTimer.current );
		};
	}, [ fetchStats ] );

	const trashRule = useCallback(
		( id: number ) => {
			apiFetch( { path: `${ API_PATH }/${ id }`, method: 'DELETE' } )
				.then( () => {
					fetchData();
					// The trashed rule leaves the engine's active union.
					fetchStats();
				} )
				.catch( () =>
					addNotice( { message: __( 'Failed to trash the rule.', 'newspack-plugin' ), type: 'error', id: 'pricing-rules-trash-error' } )
				);
		},
		[ addNotice, fetchData, fetchStats ]
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
				render: ( { item } ) => <StatusIndicator status={ postStatus( item.status ) }>{ item.status_label }</StatusIndicator>,
				elements: statusElements,
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'active_window',
				label: __( 'Active window', 'newspack-plugin' ),
				getValue: ( { item } ) => item.active_state,
				render: ( { item } ) => (
					<StatusIndicator status={ ACTIVE_STATE_STATUS[ item.active_state ] ?? 'ended' }>
						{ ACTIVE_STATE_LABEL[ item.active_state ] ?? item.active_state }
					</StatusIndicator>
				),
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
			{
				id: 'trash',
				label: __( 'Trash', 'newspack-plugin' ),
				isDestructive: true,
				// Confirm via the WP modal pattern (DataViews RenderModal) rather than window.confirm.
				RenderModal: ( { items, closeModal }: RenderModalProps< PricingRuleRow > ) => {
					const rule = items[ 0 ];
					return (
						<VStack spacing={ 4 }>
							<p>
								{ sprintf(
									/* translators: %s: the pricing rule's name. */
									__( 'Move “%s” to the trash?', 'newspack-plugin' ),
									rule.title || `#${ rule.id }`
								) }
							</p>
							<HStack justify="flex-end">
								<Button variant="tertiary" onClick={ closeModal }>
									{ __( 'Cancel', 'newspack-plugin' ) }
								</Button>
								<Button
									variant="primary"
									isDestructive
									onClick={ () => {
										trashRule( rule.id );
										closeModal?.();
									} }
								>
									{ __( 'Move to Trash', 'newspack-plugin' ) }
								</Button>
							</HStack>
						</VStack>
					);
				},
			},
		],
		[ history, trashRule ]
	);

	const { data: processedData, paginationInfo } = useMemo( () => filterSortAndPaginate( data, view, fields ), [ data, view, fields ] );

	// No count while the fetch is in flight or after it failed: a "(0)" would read as an empty list.
	const totalItems = paginationInfo.totalItems;
	useEffect( () => {
		setHeaderData( {
			sectionName: [
				{
					label: __( 'Pricing Rules', 'newspack-plugin' ),
					count: isLoading || hasError || isEmpty ? undefined : totalItems,
					countLabel: sprintf(
						/* translators: %s: number of pricing rules matching the current view. */
						_n( '%s rule', '%s rules', totalItems, 'newspack-plugin' ),
						formatCount( totalItems )
					),
				},
			],
		} );
	}, [ setHeaderData, totalItems, isLoading, hasError, isEmpty ] );

	if ( ! isReady ) {
		return (
			<HStack className="newspack-pricing-rules__loading" justify="center" role="status">
				<Spinner />
				<span className="screen-reader-text">{ __( 'Loading pricing rules…', 'newspack-plugin' ) }</span>
			</HStack>
		);
	}

	if ( isEmpty ) {
		return <PricingRulesOnboarding />;
	}

	return (
		<div className="newspack-pricing-rules">
			{ hasError && (
				<WizardBanner>
					<Notice
						className="newspack-wizard__load-error"
						status="error"
						isDismissible={ false }
						actions={ [ { label: __( 'Retry', 'newspack-plugin' ), onClick: fetchData } ] }
					>
						{ __( 'Could not load pricing rules.', 'newspack-plugin' ) }
					</Notice>
				</WizardBanner>
			) }
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
			{ stats?.supported && <CatalogImpact key={ statsVersion } stats={ stats } /> }
		</div>
	);
}
