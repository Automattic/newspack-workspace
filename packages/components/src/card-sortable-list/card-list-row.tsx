/**
 * Card list row: the card each CardSortableList row is built from.
 */

/**
 * External dependencies
 */
import classNames from 'classnames';

/**
 * WordPress dependencies.
 */
import { DropdownMenu, MenuItem, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { moreVertical } from '@wordpress/icons';
import { Badge, Card, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import TooltipBadge from '../tooltip-badge';
import type { CardBadge } from '../types';
import './style.scss';

type CardListItemAction = {
	label: string;
	icon?: unknown;
	action: () => void;
	disabled?: boolean;
	destructive?: boolean;
};

export type CardListItem = {
	id: string | number;
	title: string;
	description?: React.ReactNode;
	badge?: CardBadge;
	/** A second badge, e.g. a warning, whose tooltip explains it. */
	secondaryBadge?: CardBadge & { tooltip?: string };
	toggleChecked?: boolean;
	onToggleChange?: () => void;
	actions?: CardListItemAction[];
	/** Accessible name of the actions menu button, e.g. one that names the item. */
	actionsLabel?: string;
};

const TitleTag = 'h3';

const CardListRow = ( {
	item,
	controls,
	size = 'default',
	...cardProps
}: {
	item: CardListItem;
	/** Rendered ahead of the title, e.g. a drag handle. */
	controls?: React.ReactNode;
	/** `large` keeps the card's own padding and uses a heavier title, for a row that stands alone on a page. */
	size?: 'default' | 'large';
} & Omit< React.ComponentProps< typeof Card.Root >, 'className' > ) => {
	const hasToggle = item.onToggleChange !== undefined;
	const hasActions = Boolean( item.actions?.length );

	return (
		<Card.Root className={ classNames( 'newspack-card--core--sortable-list__card', { 'is-large': 'large' === size } ) } { ...cardProps }>
			<Card.Header className="newspack-card--core--sortable-list__header" render={ <Stack direction="row" align="center" gap="lg" /> }>
				{ controls }
				<div className="newspack-card--core--sortable-list__summary">
					<Card.Title render={ <TitleTag /> } className="newspack-card--core--sortable-list__title">
						{ item.title }
						{ item.badge?.label && <Badge intent={ item.badge.intent ?? 'none' }>{ item.badge.label }</Badge> }
						{ item.secondaryBadge?.label &&
							( item.secondaryBadge.tooltip ? (
								<TooltipBadge
									label={ item.secondaryBadge.label }
									tooltip={ item.secondaryBadge.tooltip }
									intent={ item.secondaryBadge.intent }
								/>
							) : (
								<Badge intent={ item.secondaryBadge.intent ?? 'none' }>{ item.secondaryBadge.label }</Badge>
							) ) }
					</Card.Title>
					{ item.description && <p className="newspack-card--core--sortable-list__description">{ item.description }</p> }
				</div>
				{ ( hasToggle || hasActions ) && (
					<Stack direction="row" align="center" gap="sm" className="newspack-card--core--sortable-list__end">
						{ hasToggle && (
							<ToggleControl
								className="newspack-card--core--sortable-list__toggle"
								label={ item.title }
								checked={ item.toggleChecked }
								onChange={ item.onToggleChange as () => void }
								__nextHasNoMarginBottom
							/>
						) }
						{ hasActions && (
							<DropdownMenu icon={ moreVertical } label={ item.actionsLabel || __( 'More actions', 'newspack-plugin' ) }>
								{ ( { onClose } ) =>
									item.actions?.map( ( action, actionIndex ) => (
										<MenuItem
											key={ actionIndex }
											icon={ action.icon as never }
											onClick={ () => {
												onClose();
												action.action();
											} }
											disabled={ action.disabled || false }
											isDestructive={ action.destructive || false }
										>
											{ action.label }
										</MenuItem>
									) )
								}
							</DropdownMenu>
						) }
					</Stack>
				) }
			</Card.Header>
		</Card.Root>
	);
};

export default CardListRow;
