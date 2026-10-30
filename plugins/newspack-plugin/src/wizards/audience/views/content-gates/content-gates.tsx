/**
 * Content Gate component.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { DataViews as BaseDataViews } from '@wordpress/dataviews';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { DataViews } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import ContentGatesOnboarding from './content-gates-onboarding';
import ContentGatesPriority from './content-gates-priority';
import ContentGateSettings from './content-gate-settings';
import AdvancedSettings from './advanced-settings';
import { filterGates, getGatesSubTitle, getUsefulGateFilterValues } from './utils';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';
import './style.scss';

const ContentGates = ( { updateGatesData }: { updateGatesData: ( gates: Gate[] ) => void } ) => {
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as WizardData;
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ showPriorityModal, setShowPriorityModal ] = useState( false );
	const [ showAdvancedSettings, setShowAdvancedSettings ] = useState( false );
	const gates = ( wizardData?.gates || [] ) as Gate[];
	const gateCount = gates.length;
	const [ chosenSearch, setChosenSearch ] = useState( '' );
	const [ chosenStatus, setChosenStatus ] = useState< string | undefined >();
	const [ chosenAccess, setChosenAccess ] = useState< string | undefined >();

	const canSearch = gateCount > 1;
	const statusValues = getUsefulGateFilterValues( gates, 'status' );
	const accessValues = getUsefulGateFilterValues( gates, 'access' );
	const search = canSearch ? chosenSearch : '';
	const status = chosenStatus && statusValues.includes( chosenStatus ) ? chosenStatus : undefined;
	const access = chosenAccess && accessValues.includes( chosenAccess ) ? chosenAccess : undefined;
	const filteredGates = filterGates( gates, { search, status, access } );

	// Forget a choice whose filter has gone, or it would come back when a later gate revives the filter.
	useEffect( () => {
		setChosenSearch( search );
		setChosenStatus( status );
		setChosenAccess( access );
	}, [ search, status, access ] );

	const fields = useMemo( () => {
		const labels: Record< string, string > = {
			active: __( 'Active', 'newspack-plugin' ),
			inactive: __( 'Inactive', 'newspack-plugin' ),
			registered: __( 'Registered Access', 'newspack-plugin' ),
			paid: __( 'Paid Access', 'newspack-plugin' ),
		};
		const filterField = ( id: string, label: string, values: string[] ) => ( {
			id,
			label,
			elements: values.map( value => ( { value, label: labels[ value ] } ) ),
			filterBy: { operators: [ 'is' as const ], isPrimary: true },
			enableSorting: false,
		} );
		return [
			{
				id: 'title',
				label: __( 'Title', 'newspack-plugin' ),
				enableGlobalSearch: true,
				enableSorting: false,
				getValue: ( { item }: { item: Gate } ) => item.title,
			},
			...( statusValues.length ? [ filterField( 'status', __( 'Status', 'newspack-plugin' ), statusValues ) ] : [] ),
			...( accessValues.length ? [ filterField( 'access', __( 'Access', 'newspack-plugin' ), accessValues ) ] : [] ),
		];
	}, [ statusValues.join(), accessValues.join() ] ); // eslint-disable-line react-hooks/exhaustive-deps -- both arrays are rebuilt each render.

	const view = {
		type: 'table' as const,
		search,
		page: 1,
		perPage: 100,
		fields: [],
		filters: [
			...( status ? [ { field: 'status', operator: 'is' as const, value: status } ] : [] ),
			...( access ? [ { field: 'access', operator: 'is' as const, value: access } ] : [] ),
		],
	};

	const onChangeView = ( next: { search?: string; filters?: { field: string; value?: string }[] } ) => {
		setChosenSearch( next.search || '' );
		setChosenStatus( next.filters?.find( ( { field } ) => 'status' === field )?.value );
		setChosenAccess( next.filters?.find( ( { field } ) => 'access' === field )?.value );
	};

	useEffect( () => {
		const subTitle = getGatesSubTitle();
		if ( ! gateCount ) {
			setHeaderData( { actions: [], subTitle } );
			return;
		}
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Add Content Gate', 'newspack-plugin' ),
					mobileIcon: 'plus',
					href: '#/edit/new/all',
				},
				...( gateCount > 1
					? [
							{
								type: 'more',
								label: __( 'Gate Priority', 'newspack-plugin' ),
								action: () => setShowPriorityModal( true ),
							},
					  ]
					: [] ),
				{
					type: 'more',
					label: __( 'Advanced Settings', 'newspack-plugin' ),
					action: () => setShowAdvancedSettings( true ),
				},
			],
			subTitle,
		} );
	}, [ gateCount ] );

	if ( ! gates?.length ) {
		return <ContentGatesOnboarding />;
	}

	return (
		<>
			<ContentGatesPriority
				showModal={ showPriorityModal }
				closeModal={ () => setShowPriorityModal( false ) }
				updateGatesData={ updateGatesData }
			/>
			<AdvancedSettings showModal={ showAdvancedSettings } closeModal={ () => setShowAdvancedSettings( false ) } />
			{ canSearch && (
				<DataViews
					className={ classnames( 'newspack-content-gates__toolbar', { 'is-default-view': ! search && ! status && ! access } ) }
					view={ view }
					onChangeView={ onChangeView }
					fields={ fields }
					data={ filteredGates }
					getItemId={ ( item: Gate ) => String( item.id ) }
					paginationInfo={ { totalItems: filteredGates.length, totalPages: 1 } }
					defaultLayouts={ { table: {} } }
				>
					<Stack direction="row" justify="space-between" align="flex-start" gap="xs" className="dataviews__view-actions">
						<Stack direction="row" align="center" gap="sm" wrap="wrap" className="dataviews__search">
							<BaseDataViews.Search label={ __( 'Search content gates', 'newspack-plugin' ) } />
							<BaseDataViews.Filters />
						</Stack>
					</Stack>
				</DataViews>
			) }
			<Stack direction="column" gap="lg" className="newspack-content-gates__gates">
				{ filteredGates.map( gate => {
					return <ContentGateSettings key={ gate.id } gate={ gate } updateGatesData={ updateGatesData } />;
				} ) }
				{ ! filteredGates.length && (
					<p className="newspack-content-gates__no-results">{ __( 'No content gates match your search.', 'newspack-plugin' ) }</p>
				) }
			</Stack>
		</>
	);
};
export default ContentGates;
