/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { currencyDollar } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button } from '../../../../../packages/components/src';
import EmptyState from '../../../../../packages/components/src/empty-state';

export default function PricingRulesOnboarding() {
	return (
		<div className="newspack-wizard__column">
			<EmptyState.Root>
				<EmptyState.Header
					icon={ currencyDollar }
					title={ __( 'Get started with pricing rules', 'newspack-plugin' ) }
					description={ __(
						'Set up rules that adjust product prices automatically, from intro offers to loyalty pricing and win-back discounts.',
						'newspack-plugin'
					) }
				/>
				<EmptyState.Actions>
					<Button variant="primary" href="#/new">
						{ __( 'Add Rule', 'newspack-plugin' ) }
					</Button>
				</EmptyState.Actions>
			</EmptyState.Root>
		</div>
	);
}
