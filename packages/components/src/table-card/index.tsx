/**
 * TableCard
 *
 * The DataViews-in-a-card composition from @wordpress/ui. Which card edges the
 * table touches follows upstream first/last-child rules, so it changes with the
 * slots present — the README spells the combinations out.
 */

/**
 * WordPress dependencies
 */
import { Card, Stack } from '@wordpress/ui';

interface TableCardProps {
	title?: React.ReactNode;
	/** Id for the heading, so a caller's region can name itself via `aria-labelledby`. */
	titleId?: string;
	heading?: 1 | 2 | 3 | 4 | 5 | 6;
	actions?: React.ReactNode;
	before?: React.ReactNode;
	after?: React.ReactNode;
	className?: string;
	/** Rendered edge-to-edge via `Card.FullBleed`. */
	children: React.ReactNode;
}

/** `0` is a renderable slot value; the falsy leftovers of `cond && <X />` are not. */
const isRenderable = ( node: React.ReactNode ) => node !== undefined && node !== null && node !== '' && typeof node !== 'boolean';

export default function TableCard( { title, titleId, heading = 3, actions, before, after, className, children }: TableCardProps ) {
	const TitleTag = `h${ heading }` as const;
	const hasTitle = isRenderable( title );
	const hasActions = isRenderable( actions );
	return (
		<Card.Root className={ className }>
			{ ( hasTitle || hasActions ) && (
				<Card.Header
					render={ hasActions ? <Stack direction="row" justify={ hasTitle ? 'space-between' : 'end' } align="center" /> : undefined }
				>
					{ hasTitle && <Card.Title render={ <TitleTag id={ titleId } /> }>{ title }</Card.Title> }
					{ /* Its own row, so several actions don't scatter across the header's space-between. */ }
					{ hasActions && (
						<Stack direction="row" gap="sm" align="center">
							{ actions }
						</Stack>
					) }
				</Card.Header>
			) }
			<Card.Content render={ <Stack direction="column" gap="lg" /> }>
				{ before }
				<Card.FullBleed>{ children }</Card.FullBleed>
				{ after }
			</Card.Content>
		</Card.Root>
	);
}
