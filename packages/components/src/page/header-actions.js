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
 * @typedef {Object} PageAction
 * @property {string}                                  label         Visible label, and the accessible name of an icon-only button.
 * @property {'primary'|'secondary'|'tertiary'|'more'} [type]        Button variant. Any other value, `more` included, puts the
 *                                                                   action only in the overflow menu.
 * @property {string}                                  [id]          Stable key.
 * @property {string}                                  [ariaLabel]   Accessible name when it must say more than the label; it has
 *                                                                   to contain the label.
 * @property {Function}                                [onClick]     Click handler.
 * @property {string}                                  [href]        Link target.
 * @property {*}                                       [icon]        Shown beside the label, and on the action's menu item.
 * @property {*}                                       [mobileIcon]  Below 782px, the one action left out of the menu shows as
 *                                                                   an icon-only button with this icon, named by its `label`.
 * @property {boolean}                                 [disabled]    Disables the button or menu item.
 * @property {boolean}                                 [destructive] Shows the action as destructive.
 * @property {boolean}                                 [isBusy]      Shows the button as busy, and disables its menu item.
 * @property {boolean}                                 [separator]   Starts a new group in the overflow menu.
 */

/**
 * Whether `actions` is a list of action objects rather than rendered nodes.
 *
 * @param {*} actions Page `actions` prop.
 * @return {boolean} True for a non-empty list of plain action objects.
 */
export const isActionList = actions =>
	Array.isArray( actions ) && actions.length > 0 && actions.every( action => action && 'object' === typeof action && ! isValidElement( action ) );

const BUTTON_TYPES = [ 'primary', 'secondary', 'tertiary' ];

const isButton = action => BUTTON_TYPES.includes( action.type );

const keyFor = ( action, index ) => action.id || `${ action.label }-${ index }`;

const groupBySeparator = actions =>
	actions.reduce( ( groups, action ) => {
		if ( action.separator || ! groups.length ) {
			groups.push( [] );
		}
		groups[ groups.length - 1 ].push( action );
		return groups;
	}, [] );

const ActionButton = ( { action, iconOnly = false } ) => {
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

const runMenuAction = async ( action, event ) => {
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

const ActionsMenu = ( { groups } ) => (
	<DropdownMenu icon={ moreVertical } label={ __( 'More actions', 'newspack-plugin' ) } className="newspack-page__header-menu">
		{ ( { onClose } ) =>
			groups.map( ( group, groupIndex ) => (
				<MenuGroup key={ groupIndex }>
					{ group.map( ( action, index ) => (
						<MenuItem
							key={ keyFor( action, index ) }
							aria-label={ action.ariaLabel }
							icon={ action.icon }
							href={ isJavascriptHref( action.href ) ? undefined : action.href }
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
 * @param {Object}       props
 * @param {PageAction[]} props.actions
 * @return {JSX.Element} Header actions.
 */
const HeaderActions = ( { actions } ) => {
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
