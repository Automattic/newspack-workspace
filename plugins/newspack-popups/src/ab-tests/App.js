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

const STATUS_ORDER = [ 'running', 'needs-attention', 'draft', 'incomplete' ];

/**
 * The A/B Tests page.
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Boot data.
 */
const App = ( { data } ) => {
	const { tests = [], provider = {}, campaigns_url: campaignsUrl, in_audience_menu: inAudienceMenu } = data;
	return (
		<Page
			className="newspack-popups-ab-tests"
			breadcrumbItems={
				inAudienceMenu
					? [
							{ label: __( 'Audience Management', 'newspack-popups' ) },
							{ label: __( 'Campaigns', 'newspack-popups' ), url: campaignsUrl },
							{ label: __( 'A/B Tests', 'newspack-popups' ) },
					  ]
					: [ { label: __( 'Campaigns', 'newspack-popups' ) }, { label: __( 'A/B Tests', 'newspack-popups' ) } ]
			}
			subTitle={ __( 'See which version of each prompt gets more readers to act.', 'newspack-popups' ) }
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
							description={ __( 'Give two prompts the same test name to compare them.', 'newspack-popups' ) }
						/>
						<EmptyState.Actions>
							<Button variant="primary" href={ campaignsUrl }>
								{ __( 'Go to prompts', 'newspack-popups' ) }
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
