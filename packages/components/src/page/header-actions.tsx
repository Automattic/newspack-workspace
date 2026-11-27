/**
 * WordPress dependencies.
 */
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { useViewportMatch } from '@wordpress/compose';
import { isValidElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import Button, { isJavascriptHref } from '../button';

/**
 * External dependencies.
 */
import type { MouseEvent, ReactElement } from 'react';

type ButtonActionType = 'primary' | 'secondary' | 'tertiary';

export interface PageAction {
	/** Visible label, and the accessible name of an icon-only button. */
	label: string;
	/**
	 * Button variant. Any other value, `more` included, puts the action only in
	 * the overflow menu.
	 */
	type?: ButtonActionType | 'more';
	/** Stable key. */
	id?: string;
	/** Accessible name when it must say more than the label; it has to contain the label. */
	ariaLabel?: string;
	/** Click handler. */
	onClick?: ( event?: MouseEvent< HTMLElement > ) => void | Promise< void >;
	/** Link target. */
	href?: string;
	/** Shown beside the label, and on the action's menu item. */
	icon?: ReactElement;
	/**
	 * Below 782px, the one action left out of the menu shows as an icon-only button
	 * with this icon, named by its `label`.
	 */
	mobileIcon?: ReactElement;
	/** Disables the button or menu item. */
	disabled?: boolean;
	/** Shows the action as destructive. */
	destructive?: boolean;
	/** Shows the button as busy, and disables its menu item. */
	isBusy?: boolean;
	/** Starts a new group in the overflow menu. */
	separator?: boolean;
}

type ButtonAction = PageAction & { type: ButtonActionType };

/**
 * Whether `actions` is a list of action objects rather than rendered nodes.
 *
 * @param actions Page `actions` prop.
 * @return True for a non-empty list of plain action objects.
 */
export const isActionList = ( actions: unknown ): actions is PageAction[] =>
	Array.isArray( actions ) && actions.length > 0 && actions.every( action => action && 'object' === typeof action && ! isValidElement( action ) );

const BUTTON_TYPES: ReadonlyArray< PageAction[ 'type' ] > = [ 'primary', 'secondary', 'tertiary' ];

const isButton = ( action: PageAction ): action is ButtonAction => BUTTON_TYPES.includes( action.type );

const keyFor = ( action: PageAction, index: number ): string => action.id || `${ action.label }-${ index }`;

const groupBySeparator = ( actions: PageAction[] ): PageAction[][] =>
	actions.reduce< PageAction[][] >( ( groups, action ) => {
		if ( action.separator || ! groups.length ) {
			groups.push( [] );
		}
		groups[ groups.length - 1 ].push( action );
		return groups;
	}, [] );

const ActionButton = ( { action, iconOnly = false }: { action: ButtonAction; iconOnly?: boolean } ) => {
	const shared = {
		variant: action.type,
		href: action.href,
		onClick: action.onClick,
		disabled: action.disabled || false,
		isDestructive: action.destructive || false,
		isBusy: action.isBusy || undefined,
	};
	if ( iconOnly ) {
		return <Button { ...shared } icon={ action.mobileIcon } label={ action.ariaLabel || action.label } showTooltip />;
	}
	return (
		<Button { ...shared } icon={ action.icon } aria-label={ action.ariaLabel }>
			{ action.label }
		</Button>
	);
};

const runMenuAction = async ( action: PageAction, event: MouseEvent< HTMLElement > ): Promise< void > => {
	if ( ! action.onClick ) {
		return;
	}
	if ( ! action.href ) {
		action.onClick( event );
		return;
	}
	// Matches Button: a link with a handler follows once the handler settles.
	event.preventDefault();
	await action.onClick( event );
	window.location.href = action.href;
};

const ActionsMenu = ( { groups }: { groups: PageAction[][] } ) => (
	<DropdownMenu
		icon={ moreVertical }
		label={ __( 'More actions', 'newspack-plugin' ) }
		className="newspack-page__header-menu"
		popoverProps={ { className: 'newspack-page__header-menu-popover' } }
	>
		{ ( { onClose } ) =>
			groups.map( ( group, groupIndex ) => (
				<MenuGroup key={ groupIndex }>
					{ group.map( ( action, index ) => (
						<MenuItem
							key={ keyFor( action, index ) }
							aria-label={ action.ariaLabel }
							icon={ action.icon }
							// MenuItem forwards href to its underlying Button (rendered as a link); the WP
							// type models MenuItem as button-only, so href is passed via a spread.
							{ ...{ href: isJavascriptHref( action.href ) ? undefined : action.href } }
							onClick={ event => {
								onClose();
								runMenuAction( action, event );
							} }
							disabled={ action.disabled || action.isBusy || false }
							isDestructive={ action.destructive || false }
						>
							{ action.label }
						</MenuItem>
					) ) }
				</MenuGroup>
			) )
		}
	</DropdownMenu>
);

/**
 * Page header actions given as data.
 *
 * From 782px every button shows, and menu-only items sit in a kebab menu.
 * Below that, one action stays out: the primary, or the first button when there
 * is none, as an icon when it has a `mobileIcon`. The other buttons move into one
 * menu, ahead of the menu-only items, so the header keeps to a single row.
 *
 * @param props
 * @param props.actions
 * @return Header actions.
 */
const HeaderActions = ( { actions }: { actions: PageAction[] } ) => {
	const isWide = useViewportMatch( 'medium' );
	const buttons = actions.filter( isButton );
	const menuOnly = actions.filter( action => ! isButton( action ) );

	if ( isWide ) {
		return (
			<>
				{ buttons.map( ( action, index ) => (
					<ActionButton key={ keyFor( action, index ) } action={ action } />
				) ) }
				{ menuOnly.length > 0 && <ActionsMenu groups={ groupBySeparator( menuOnly ) } /> }
			</>
		);
	}

	const visible = buttons.find( action => 'primary' === action.type ) || buttons[ 0 ];
	const collapsed = buttons.filter( action => action !== visible );
	const groups = [
		...( collapsed.length ? [ collapsed.map( action => ( { ...action, separator: false } ) ) ] : [] ),
		...groupBySeparator( menuOnly ),
	];

	return (
		<>
			{ visible && <ActionButton action={ visible } iconOnly={ Boolean( visible.mobileIcon ) } /> }
			{ groups.length > 0 && <ActionsMenu groups={ groups } /> }
		</>
	);
};

export default HeaderActions;
