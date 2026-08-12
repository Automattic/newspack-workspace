/**
 * One impact figure as a scorecard: its label, the number, and what it counts.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { Card } from '@wordpress/ui';

export interface StatTileProps {
	label: string;
	// Pre-formatted by the caller. Null renders the null glyph.
	value: string | null;
	description: string;
	secondary?: string;
	actionLabel?: string;
	onAction?: () => void;
}

const EM_DASH = '—';

export default function StatTile( { label, value, description, secondary, actionLabel, onAction }: StatTileProps ) {
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
				<div className="newspack-pricing-rules__tile-footer">
					<span className="newspack-pricing-rules__tile-description">{ description }</span>
					{ actionLabel && onAction && (
						// A modal trigger, so a button styled as a link rather than an anchor.
						<Button variant="link" onClick={ onAction }>
							{ actionLabel }
						</Button>
					) }
				</div>
			</Card.Content>
		</Card.Root>
	);
}
