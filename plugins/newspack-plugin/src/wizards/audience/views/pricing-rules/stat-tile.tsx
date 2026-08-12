/**
 * One impact figure as a scorecard: its label, the number, and what it counts.
 */

/**
 * WordPress dependencies
 */
import { _x } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { Card } from '@wordpress/ui';

export interface StatTileProps {
	label: string;
	// Pre-formatted by the caller. Null renders the null glyph.
	value: string | null;
	// Spoken in place of the visible value, for figures whose meaning rests on
	// punctuation a screen reader may not announce.
	valueLabel?: string;
	description: string;
	secondary?: string;
	actionLabel?: string;
	onAction?: () => void;
}

const EM_DASH = '—';

export default function StatTile( { label, value, valueLabel, description, secondary, actionLabel, onAction }: StatTileProps ) {
	const shown = null === value ? EM_DASH : value;
	const spoken = valueLabel ?? ( null === value ? _x( 'Not applicable', 'a statistic with no number to show', 'newspack-plugin' ) : undefined );

	return (
		<Card.Root className="newspack-pricing-rules__tile">
			<Card.Content className="newspack-pricing-rules__tile-content">
				{ /* Both screens place the grid under a section heading, so the tiles are its children. */ }
				<h3 className="newspack-pricing-rules__tile-label">{ label }</h3>
				<div className="newspack-pricing-rules__tile-body">
					<span className="newspack-pricing-rules__tile-value">
						{ spoken ? (
							<>
								<span aria-hidden="true">{ shown }</span>
								<span className="screen-reader-text">{ spoken }</span>
							</>
						) : (
							shown
						) }
					</span>
					{ secondary && <span className="newspack-pricing-rules__tile-secondary">{ secondary }</span> }
				</div>
				<div className="newspack-pricing-rules__tile-footer">
					<span className="newspack-pricing-rules__tile-description">{ description }</span>
					{ actionLabel && onAction && (
						// A modal trigger, so a button styled as a link rather than an anchor.
						<Button variant="link" className="newspack-pricing-rules__tile-action" onClick={ onAction }>
							{ actionLabel }
						</Button>
					) }
				</div>
			</Card.Content>
		</Card.Root>
	);
}
