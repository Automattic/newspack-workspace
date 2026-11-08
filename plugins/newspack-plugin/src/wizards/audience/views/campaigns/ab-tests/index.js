/**
 * The A/B Tests tab: one card per test, read from newspack-popups.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import { chartBar } from '@wordpress/icons';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { EmptyState, Waiting, withWizardScreen } from '../../../../../../packages/components/src';
import StartTestModal from './start-test-modal';
import TestCard from './test-card';
import { REST_BASE, getPickerPrompts, openVersionB, sortTests } from './utils';
import './style.scss';

// The wizard's header, breadcrumbs and tabs come from withWizardScreen.
const ABTestsScreen = withWizardScreen( ( { children } ) => <>{ children }</> );

const ABTests = props => {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isStarting, setIsStarting ] = useState( false );
	// "View A/B Test" in a prompt's menu links here with the test to show.
	const targetTestId = new URLSearchParams( props.location?.search || '' ).get( 'test' );

	useEffect( () => {
		apiFetch( { path: REST_BASE } )
			.then( setData )
			.catch( err => setError( err?.message || __( 'The A/B tests could not be loaded.', 'newspack-plugin' ) ) );
	}, [] );

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
		content = sortTests( data.tests ).map( test => (
			<TestCard key={ test.test_id } test={ test } data={ data } onRenamed={ renameTest } isTarget={ test.test_id === targetTestId } />
		) );
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
