/**
 * The A/B Tests tab: one card per test, read from newspack-popups.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { Button, Notice } from '@wordpress/components';
import { DataViews as BaseDataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { chartBar } from '@wordpress/icons';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { DataViews, EmptyState, Waiting, withWizardScreen } from '../../../../../../packages/components/src';
import StartTestModal from './start-test-modal';
import TestCard from './test-card';
import { REST_BASE, STATUS_ORDER, getPickerPrompts, getStatusLabel, getTestStatus, openVersionB, sortTests } from './utils';
import './style.scss';

// The wizard's header, breadcrumbs and tabs come from withWizardScreen.
const ABTestsScreen = withWizardScreen( ( { children } ) => <>{ children }</> );

/**
 * Ended tests stay out of the way until someone asks for them. Clearing the
 * Status filter returns to this rather than to every test.
 */
const DEFAULT_FILTER = { field: 'status', operator: 'isNot', value: 'ended' };
const ENDED_FILTER = { field: 'status', operator: 'is', value: 'ended' };

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [ DEFAULT_FILTER ],
	page: 1,
	perPage: 20,
	fields: [],
};

const FIELDS = [
	{
		id: 'name',
		label: __( 'Name', 'newspack-plugin' ),
		enableGlobalSearch: true,
		enableHiding: false,
		enableSorting: false,
		filterBy: false,
		getValue: ( { item } ) => item.name || item.test_id,
	},
	{
		id: 'status',
		label: __( 'Status', 'newspack-plugin' ),
		enableHiding: false,
		enableSorting: false,
		getValue: ( { item } ) => getTestStatus( item ),
		elements: STATUS_ORDER.map( status => ( { value: status, label: getStatusLabel( status ) } ) ),
		filterBy: { operators: [ 'is', 'isNot' ], isPrimary: true },
	},
];

/**
 * @param {Object} view A DataViews view.
 * @return {Object} The view, with the default Status filter put back if it was cleared.
 */
const withStatusFilter = view => {
	const filters = ( view.filters || [] ).filter( filter => filter.field !== 'status' || ( filter.value !== undefined && filter.value !== '' ) );
	return filters.some( filter => filter.field === 'status' ) ? { ...view, filters } : { ...view, filters: [ ...filters, DEFAULT_FILTER ] };
};

/**
 * @param {Object} view A DataViews view.
 * @return {string} What the card list says when nothing matches.
 */
const getNoMatchText = view => {
	const status = ( view.filters || [] ).find( filter => filter.field === 'status' );
	if ( ! view.search && status?.operator === DEFAULT_FILTER.operator && status?.value === DEFAULT_FILTER.value ) {
		return __( 'No active tests.', 'newspack-plugin' );
	}
	if ( ! view.search && status?.operator === ENDED_FILTER.operator && status?.value === ENDED_FILTER.value ) {
		return __( 'No ended tests yet.', 'newspack-plugin' );
	}
	return __( 'No tests match your search or filter.', 'newspack-plugin' );
};

const ABTests = props => {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isStarting, setIsStarting ] = useState( false );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ justEnded, setJustEnded ] = useState( null );
	// "View A/B Test" in a prompt's menu links here with the test to show.
	const targetTestId = new URLSearchParams( props.location?.search || '' ).get( 'test' );

	useEffect( () => {
		apiFetch( { path: REST_BASE } )
			.then( response => {
				// A link to an ended test would land on a list that hides it.
				if ( ( response?.tests || [] ).some( test => test.test_id === targetTestId && getTestStatus( test ) === 'ended' ) ) {
					setView( current => ( { ...current, filters: [ ENDED_FILTER ] } ) );
				}
				setData( response );
			} )
			.catch( err => setError( err?.message || __( 'The A/B tests could not be loaded.', 'newspack-plugin' ) ) );
	}, [] );

	// The End Test button goes away with the test's old card, so the ended card
	// takes focus instead, first in a list switched to show it.
	const endTest = ended => {
		setData( current => ( { ...current, tests: current.tests.map( test => ( test.test_id === ended.test_id ? ended : test ) ) } ) );
		setView( current => ( { ...current, search: '', page: 1, filters: [ ENDED_FILTER ] } ) );
		setJustEnded( ended.test_id );
		speak(
			sprintf(
				// Translators: %s is the letter of the version kept, such as "B".
				__( 'Test ended. Kept %s.', 'newspack-plugin' ),
				String( ended.kept ).toUpperCase()
			)
		);
		// The prompt list in Campaigns: the drafted version and the test badges
		// change. Quietly, so the heading the card focuses stays on screen.
		props.refetch?.( { quiet: true } );
	};

	const shownTests = useMemo( () => {
		const tests = data?.tests || [];
		const { data: matched } = filterSortAndPaginate( tests, { ...view, page: 1, perPage: Math.max( tests.length, 1 ) }, FIELDS );
		const sorted = sortTests( matched );
		const first = sorted.findIndex( test => test.test_id === justEnded );
		return first > 0 ? [ sorted[ first ], ...sorted.slice( 0, first ), ...sorted.slice( first + 1 ) ] : sorted;
	}, [ data, view, justEnded ] );

	const renameTest = ( testId, name ) =>
		setData( current => ( { ...current, tests: current.tests.map( test => ( test.test_id === testId ? { ...test, name } : test ) ) } ) );

	const startButton = (
		<Button variant="primary" onClick={ () => setIsStarting( true ) } __next40pxDefaultSize>
			{ __( 'Start A/B Test', 'newspack-plugin' ) }
		</Button>
	);
	const hasTests = ( data?.tests || [] ).length > 0;
	const picker = getPickerPrompts( data?.prompts );

	let content;
	if ( error ) {
		content = (
			<Notice status="error" isDismissible={ false } politeness="polite">
				{ error }
			</Notice>
		);
	} else if ( ! data ) {
		content = <Waiting isCenter />;
	} else if ( ( data.tests || [] ).length === 0 ) {
		content = (
			<EmptyState.Root>
				<EmptyState.Header
					icon={ chartBar }
					title={ __( 'Find out which prompt works best', 'newspack-plugin' ) }
					description={ __(
						'Run two versions of a prompt side by side. Newspack splits readers between them and shows you which one gets more of them to act.',
						'newspack-plugin'
					) }
				/>
				<EmptyState.Actions>{ startButton }</EmptyState.Actions>
			</EmptyState.Root>
		);
	} else {
		content = (
			<>
				<DataViews
					className="newspack-campaigns__toolbar"
					data={ data.tests }
					fields={ FIELDS }
					view={ view }
					onChangeView={ next => setView( withStatusFilter( next ) ) }
					paginationInfo={ { totalItems: shownTests.length, totalPages: 1 } }
					defaultLayouts={ { table: {} } }
					getItemId={ item => item.test_id }
				>
					{ /* Search and filters only: this tab lists cards, not rows. */ }
					<Stack direction="row" justify="space-between" align="flex-start" gap="xs" className="dataviews__view-actions">
						<Stack direction="row" align="center" gap="sm" wrap="wrap" className="dataviews__search">
							<BaseDataViews.Search label={ __( 'Search tests', 'newspack-plugin' ) } />
							<BaseDataViews.Filters />
						</Stack>
					</Stack>
				</DataViews>
				{ shownTests.length === 0 ? (
					<p className="newspack-ab-tests__no-match">{ getNoMatchText( view ) }</p>
				) : (
					shownTests.map( test => (
						<TestCard
							key={ test.test_id }
							test={ test }
							data={ data }
							onRenamed={ renameTest }
							onEnded={ endTest }
							isTarget={ test.test_id === ( justEnded || targetTestId ) }
						/>
					) )
				) }
			</>
		);
	}

	return (
		<ABTestsScreen
			{ ...props }
			subHeaderText={ __( 'See which version of each prompt gets more readers to act.', 'newspack-plugin' ) }
			headerActions={ hasTests ? startButton : null }
		>
			<Stack direction="column" gap="xl" className="newspack-ab-tests">
				{ data && ! data.provider?.available && (
					<Notice status="warning" isDismissible={ false } spokenMessage="">
						{ data.provider?.reason }
					</Notice>
				) }
				{ content }
			</Stack>
			{ isStarting && (
				<StartTestModal
					prompts={ picker.startable }
					hasIneligible={ picker.hasIneligible }
					goals={ data?.goals || {} }
					intro={ __(
						'Pick the prompt to test. It becomes version A, and version B starts as a draft copy with the same placement.',
						'newspack-plugin'
					) }
					onCreated={ openVersionB }
					onClose={ () => setIsStarting( false ) }
				/>
			) }
		</ABTestsScreen>
	);
};

export default ABTests;
