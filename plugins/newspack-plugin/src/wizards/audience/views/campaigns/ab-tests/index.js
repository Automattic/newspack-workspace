/**
 * The A/B Tests tab: one card per test, read from newspack-popups.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { chartBar } from '@wordpress/icons';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { EmptyState, Waiting, withWizardScreen } from '../../../../../../packages/components/src';
import TestCard from './test-card';
import { REST_BASE, sortTests } from './utils';
import './style.scss';

// The wizard's header, breadcrumbs and tabs come from withWizardScreen.
const ABTestsScreen = withWizardScreen( ( { children } ) => <>{ children }</> );

const ABTests = props => {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: REST_BASE } )
			.then( setData )
			.catch( err => setError( err?.message || __( 'The A/B tests could not be loaded.', 'newspack-plugin' ) ) );
	}, [] );

	const renameTest = ( testId, name ) =>
		setData( current => ( { ...current, tests: current.tests.map( test => ( test.test_id === testId ? { ...test, name } : test ) ) } ) );

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
		// The Start A/B Test button joins this empty state once the modal can pick a prompt.
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
			</EmptyState.Root>
		);
	} else {
		content = sortTests( data.tests ).map( test => <TestCard key={ test.test_id } test={ test } data={ data } onRenamed={ renameTest } /> );
	}

	return (
		<ABTestsScreen { ...props } subHeaderText={ __( 'See which version of each prompt gets more readers to act.', 'newspack-plugin' ) }>
			<Stack direction="column" gap="xl" className="newspack-ab-tests">
				{ data && ! data.provider?.available && (
					<Notice status="warning" isDismissible={ false } spokenMessage="">
						{ data.provider?.reason }
					</Notice>
				) }
				{ content }
			</Stack>
		</ABTestsScreen>
	);
};

export default ABTests;
