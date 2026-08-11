/**
 * Stands in for the impact table when there is nothing to price. Three causes
 * reach it and each says what would fill it.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { box, currencyDollar, info } from '@wordpress/icons';
import { Card } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Grid, SectionHeader } from '../../../../../packages/components/src';

export type ImpactEmptyReason = 'no-price' | 'no-products' | 'unsupported';

const getReasons = () => ( {
	'no-price': {
		icon: currencyDollar,
		title: __( 'No price set yet', 'newspack-plugin' ),
		body: __( 'Enter a price above and the resulting prices will appear here.', 'newspack-plugin' ),
	},
	'no-products': {
		icon: box,
		title: __( 'No products match this rule', 'newspack-plugin' ),
		body: __( 'Widen “Applies to”, or relax the eligibility conditions.', 'newspack-plugin' ),
	},
	unsupported: {
		icon: info,
		title: __( 'Preview unavailable', 'newspack-plugin' ),
		body: __( 'The pricing engine did not return a preview.', 'newspack-plugin' ),
	},
} );

export default function ImpactEmpty( { reason }: { reason: ImpactEmptyReason } ) {
	const { icon, title, body } = getReasons()[ reason ];
	return (
		<Card.Root className="newspack-pricing-rules__empty">
			<Card.Content>
				<Grid columns={ 4 } noMargin>
					<VStack start={ 2 } end={ 4 } spacing={ 8 }>
						<SectionHeader icon={ icon } title={ title } description={ body } pageHeader size="small" noMargin heading={ 3 } />
					</VStack>
				</Grid>
			</Card.Content>
		</Card.Root>
	);
}
