/**
 * L0 — Discounts empty state (onboarding).
 *
 * Shown when there are no discount rules: a genuinely empty store, or the
 * `?empty=1` preview param on the discounts tab. Mirrors the audience
 * institutions onboarding so it reads as part of the design system.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { percent } from '@wordpress/icons';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Grid, SectionHeader } from '../../../../packages/components/src';

export default function EmptyDiscounts( { onAdd } ) {
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
						icon={ percent }
						title={ __( 'Get started with subscriber discounts', 'newspack-plugin' ) }
						description={ __(
							'Offer subscribers a discount on store products. Create your first rule to choose which plan gets what off which products.',
							'newspack-plugin'
						) }
						pageHeader
						noMargin
					/>
					<HStack alignment="center">
						<Button variant="primary" onClick={ onAdd }>
							{ __( 'Add discount', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Grid>
		</div>
	);
}
