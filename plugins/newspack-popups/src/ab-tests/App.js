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
import { getTestStatus } from './utils';

const STATUS_ORDER = [ 'running', 'draft', 'incomplete' ];

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
			breadcrumbItems={ [ { label: __( 'Campaigns', 'newspack-popups' ) }, { label: __( 'A/B Tests', 'newspack-popups' ) } ] }
			subTitle={ __( 'Compare a control prompt with the challengers that share its Test ID.', 'newspack-popups' ) }
		>
			<div className="newspack-popups-ab-tests__content">
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
					[ ...tests ]
						.sort( ( a, b ) => STATUS_ORDER.indexOf( getTestStatus( a ) ) - STATUS_ORDER.indexOf( getTestStatus( b ) ) )
						.map( test => <TestCard key={ test.test_id } test={ test } data={ data } /> )
				) }
			</div>
		</Page>
	);
};

export default App;
