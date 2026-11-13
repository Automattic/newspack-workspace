/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { currencyDollar } from '@wordpress/icons';
import { EmptyState, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button } from '../../../../../packages/components/src';

export default function PricingRulesOnboarding() {
	return (
		<div className="newspack-wizard__column">
			<Stack direction="column" align="center">
				<EmptyState.Root>
					<EmptyState.Icon icon={ currencyDollar } />
					<EmptyState.Title>{ __( 'No pricing rules yet', 'newspack-plugin' ) }</EmptyState.Title>
					<EmptyState.Description>
						{ __(
							'Rules adjust product prices automatically, such as intro offers, loyalty pricing, and win-back discounts.',
							'newspack-plugin'
						) }
					</EmptyState.Description>
					<EmptyState.Actions>
						<Button variant="primary" href="#/new">
							{ __( 'Add Rule', 'newspack-plugin' ) }
						</Button>
					</EmptyState.Actions>
				</EmptyState.Root>
			</Stack>
		</div>
	);
}
