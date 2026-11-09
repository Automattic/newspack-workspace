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

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import { TableCardContext } from './context';
import './style.scss';

interface TableCardProps {
	title?: React.ReactNode;
	/** Id for the heading, so a caller's region can name itself via `aria-labelledby`. */
	titleId?: string;
	heading?: 1 | 2 | 3 | 4 | 5 | 6;
	actions?: React.ReactNode;
	/** A line under the title, inside the header; the actions sit centered beside both. */
	description?: React.ReactNode;
	before?: React.ReactNode;
	after?: React.ReactNode;
	/**
	 * Draw a line between the header and the table, which then starts right
	 * under it. On whenever the card has a header; pass `false` to leave it out.
	 */
	separator?: boolean;
	className?: string;
	/** Rendered edge-to-edge via `Card.FullBleed`. */
	children: React.ReactNode;
}

/** `0` is a renderable slot value; the falsy leftovers of `cond && <X />` are not. */
const isRenderable = ( node: React.ReactNode ) => node !== undefined && node !== null && node !== '' && typeof node !== 'boolean';

export default function TableCard( { title, titleId, heading = 3, actions, description, before, after, separator = true, className, children }: TableCardProps ) {
	const TitleTag = `h${ heading }` as const;
	const hasTitle = isRenderable( title );
	const hasActions = isRenderable( actions );
	const hasDescription = isRenderable( description );
	const hasHeader = hasTitle || hasActions || hasDescription;
	const rowProps = { direction: 'row', justify: hasTitle || hasDescription ? 'space-between' : 'end', align: 'center' } as const;
	const titleNode = hasTitle && <Card.Title render={ <TitleTag id={ titleId } /> }>{ title }</Card.Title>;
	/* Its own row, so several actions don't scatter across the header's space-between. */
	const actionsNode = hasActions && (
		<Stack direction="row" gap="sm" align="center">
			{ actions }
		</Stack>
	);
	return (
		<TableCardContext.Provider value={ true }>
			<Card.Root className={ classnames( 'newspack-table-card', { 'newspack-table-card--separated': hasHeader && separator }, className ) }>
				{ hasHeader && (
					<Card.Header className="newspack-table-card__header" render={ hasActions || hasDescription ? <Stack { ...rowProps } /> : undefined }>
						{ hasDescription ? (
							<Stack direction="column" gap="xs">
								{ titleNode }
								<div className="newspack-table-card__description">{ description }</div>
							</Stack>
						) : (
							titleNode
						) }
						{ actionsNode }
					</Card.Header>
				) }
				<Card.Content render={ <Stack direction="column" gap="lg" /> }>
					{ before }
					<Card.FullBleed>{ children }</Card.FullBleed>
					{ after }
				</Card.Content>
			</Card.Root>
		</TableCardContext.Provider>
	);
}
