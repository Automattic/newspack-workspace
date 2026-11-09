/**
 * CellList
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Children, useId, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

type CellListProps = {
	/** The items, one per line. Each needs a key. */
	children: React.ReactNode;
	/** How many items show before "Show N more". */
	limit?: number;
	/** Whether the list starts open. */
	defaultExpanded?: boolean;
	/** Called with the new state when the list opens or closes. */
	onToggle?: ( isExpanded: boolean ) => void;
};

/**
 * A list of items inside a table cell, one per line: the first few, then a
 * "Show N more" toggle for the rest.
 *
 * The toggle stops its click, so a clickable row doesn't open as well. DataViews
 * remounts cells when its fields are rebuilt, which resets this component; a list
 * that has to stay open across that keeps its state outside, through
 * `defaultExpanded` and `onToggle`.
 */
const CellList = ( { children, limit = 2, defaultExpanded = false, onToggle }: CellListProps ) => {
	const items = Children.toArray( children );
	const [ isExpanded, setIsExpanded ] = useState( defaultExpanded );
	const listId = useId();
	const canCollapse = items.length > limit;
	const shown = canCollapse && ! isExpanded ? items.slice( 0, limit ) : items;
	return (
		<Stack direction="column" gap="sm" align="flex-start" className="newspack-cell-list">
			<Stack direction="column" gap="sm" align="flex-start" id={ listId }>
				{ shown }
			</Stack>
			{ canCollapse && (
				<Button
					variant="link"
					aria-expanded={ isExpanded }
					aria-controls={ listId }
					onClick={ ( event: React.MouseEvent ) => {
						event.stopPropagation();
						setIsExpanded( ! isExpanded );
						onToggle?.( ! isExpanded );
					} }
				>
					{ isExpanded
						? __( 'Show less', 'newspack-plugin' )
						: sprintf(
								// translators: %d: the number of items the toggle reveals.
								_n( 'Show %d more', 'Show %d more', items.length - limit, 'newspack-plugin' ),
								items.length - limit
						  ) }
				</Button>
			) }
		</Stack>
	);
};

export default CellList;
