/**
 * Empty state for the Pricing Rules list.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { currencyDollar } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Grid, SectionHeader } from '../../../../../packages/components/src';

export default function PricingRulesOnboarding() {
	return (
		<div
			style={ {
				margin: '0 auto',
				maxWidth: 'calc(var(--newspack-wizard-section-space) * 2 + var(--newspack-wizard-section-width))',
				padding: '0 var(--newspack-wizard-section-space) 0',
			} }
		>
			<Grid columns={ 4 } noMargin>
				<VStack start={ 2 } end={ 4 } spacing={ 8 }>
					<SectionHeader
						icon={ currencyDollar }
						title={ __( 'Get started with pricing rules', 'newspack-plugin' ) }
						description={ __(
							'Set up rules that adjust product prices automatically, from intro offers to loyalty pricing and win-back discounts.',
							'newspack-plugin'
						) }
						pageHeader
						noMargin
					/>
					<HStack alignment="center">
						<Button variant="primary" href="#/new">
							{ __( 'Add Rule', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Grid>
		</div>
	);
}
