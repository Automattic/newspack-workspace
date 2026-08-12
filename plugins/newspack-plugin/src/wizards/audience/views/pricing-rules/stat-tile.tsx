/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Card } from '@wordpress/ui';

export interface StatTileProps {
	label: string;
	// Pre-formatted by the caller. Null renders the null glyph.
	value: string | null;
	description: string;
	secondary?: string;
}

const EM_DASH = '—';

export default function StatTile( { label, value, description, secondary }: StatTileProps ) {
	return (
		<Card.Root className="newspack-pricing-rules__tile">
			<Card.Content className="newspack-pricing-rules__tile-content">
				<span className="newspack-pricing-rules__tile-label">{ label }</span>
				<div className="newspack-pricing-rules__tile-body">
					{ null === value ? (
						// ARIA prohibits naming a generic element; without a role the label is dropped.
						<span className="newspack-pricing-rules__tile-value" role="img" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
							{ EM_DASH }
						</span>
					) : (
						<span className="newspack-pricing-rules__tile-value">{ value }</span>
					) }
					{ secondary && <span className="newspack-pricing-rules__tile-secondary">{ secondary }</span> }
				</div>
				<span className="newspack-pricing-rules__tile-description">{ description }</span>
			</Card.Content>
		</Card.Root>
	);
}
