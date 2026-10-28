/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

/**
 * External dependencies
 */
import { EmptyState, Page } from 'newspack-components';

/**
 * Internal dependencies
 */
import TestCard from './TestCard';

/**
 * The A/B Tests page.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Boot data.
 */
const App = ( { data } ) => {
	const { tests = [], provider = {}, editor_new_prompt_url: newPromptUrl } = data;
	return (
		<Page
			className="newspack-popups-ab-tests"
			breadcrumbItems={ [ { label: __( 'Prompts', 'newspack-popups' ) }, { label: __( 'A/B Tests', 'newspack-popups' ) } ] }
			subTitle={ __(
				'Each test compares a control prompt with the challengers that share its Test ID. Stats come from the connected stats source and are kept for an hour between refreshes.',
				'newspack-popups'
			) }
		>
			{ ! provider.available && (
				<Notice status="warning" isDismissible={ false } spokenMessage="">
					{ provider.reason }
				</Notice>
			) }
			{ tests.length === 0 ? (
				<EmptyState.Root>
					<EmptyState.Header
						title={ __( 'No A/B tests yet', 'newspack-popups' ) }
						description={ __( 'Give two prompts the same Test ID in the prompt editor to start one.', 'newspack-popups' ) }
					/>
					<EmptyState.Actions>
						<Button variant="primary" href={ newPromptUrl }>
							{ __( 'Create a prompt', 'newspack-popups' ) }
						</Button>
					</EmptyState.Actions>
				</EmptyState.Root>
			) : (
				tests.map( test => <TestCard key={ test.test_id } test={ test } data={ data } /> )
			) }
		</Page>
	);
};

export default App;
