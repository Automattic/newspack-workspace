/**
 * L0 — Restrictions empty state (onboarding).
 *
 * Shown when there are no restrictions: a genuinely empty store, or the
 * `?empty=1` preview param on the subscriber-only tab. Mirrors EmptyDiscounts.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { store } from '@wordpress/icons';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Grid, SectionHeader } from '../../../../packages/components/src';

export default function EmptyRestrictions( { onAdd } ) {
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
						icon={ store }
						title={ __( 'Get started with subscriber-only products', 'newspack-plugin' ) }
						description={ __(
							'Make products available exclusively to subscribers. Create your first restriction to choose which products are subscriber-only and which subscriptions unlock them.',
							'newspack-plugin'
						) }
						pageHeader
						noMargin
					/>
					<HStack alignment="center">
						<Button variant="primary" onClick={ onAdd }>
							{ __( 'Add restriction', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Grid>
		</div>
	);
}
