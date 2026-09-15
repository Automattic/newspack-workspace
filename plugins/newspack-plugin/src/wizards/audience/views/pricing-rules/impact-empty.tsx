/**
 * Stands in for the impact table when there is nothing to price. Each cause
 * says what would fill it.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { box, info } from '@wordpress/icons';
import { Card } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import EmptyState from '../../../../../packages/components/src/empty-state';

export type ImpactEmptyReason = 'no-products' | 'unsupported';

const getReasons = () => ( {
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

interface ImpactEmptyProps {
	reason: ImpactEmptyReason;
	// The editor sits under a section h2; the modal sits under its own title h1.
	headingLevel?: 2 | 3;
}

export default function ImpactEmpty( { reason, headingLevel = 3 }: ImpactEmptyProps ) {
	const { icon, title, body } = getReasons()[ reason ];
	return (
		<Card.Root className="newspack-pricing-rules__empty">
			<Card.Content>
				<EmptyState.Root size="small">
					<EmptyState.Header
						icon={ icon }
						title={ title }
						description={ body }
						heading={ headingLevel }
						className="newspack-pricing-rules__empty-header"
					/>
				</EmptyState.Root>
			</Card.Content>
		</Card.Root>
	);
}
