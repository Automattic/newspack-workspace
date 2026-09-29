/**
 * The shared subscription card (NPPD-1753 §3.2).
 *
 * ONE card renders every subscription the wizard shows, whatever the screen: an
 * individual plan on a person's profile, a group that person owns or belongs to,
 * and the same group's billing shape in the group-detail "View subscription"
 * drawer. Person profile and group detail were built in parallel, so the card
 * lives here — outside either screen — precisely so the two cannot drift into
 * two cards that look almost but not quite alike.
 *
 * It is deliberately self-contained: it imports only WordPress packages, the
 * shared component library and the wizard's own stylesheet, and every prop is
 * about *a subscription*, never about a person or a group. Nothing here needs to
 * know which screen mounted it.
 *
 * Layout, matching the signed-off design:
 *
 *   ┌───────────────────────────────────────────────────┐
 *   │ Title (optionally a link) [Badge] [Badge]     [⋮] │  ← header
 *   │ subline                                           │
 *   ├───────────────────────────────────────────────────┤
 *   │ LABEL          LABEL                              │  ← rows, two columns
 *   │ value          value                              │
 *   └───────────────────────────────────────────────────┘
 *
 * Title and badges are left-aligned; every per-status action collapses into the
 * single right-aligned "more" kebab, so a card with four possible actions is no
 * taller or busier than one with none.
 */

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	Dropdown,
	MenuGroup,
	MenuItem,
} from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, Card, Grid } from '../../../../packages/components/src';
// The card's classes live in the wizard's single stylesheet alongside the
// screens that host it, so importing it here keeps the card renderable from any
// screen without that screen having to remember to pull the styles in.
import '../screens/style.scss';

/**
 * A labeled label/value row on a card, matching the quick-edit drawer style.
 *
 * Each row is its own one-pair description list, so a screen reader announces
 * the value *as* the value of its label rather than as two adjacent unrelated
 * strings. A single `dl` around all the rows would be the other option, but the
 * rows are laid out by a Grid whose own wrapper element would then sit between
 * the `dl` and its `dt`/`dd` — invalid, and no better read aloud.
 *
 * Exported because the "View subscription" drawer lays the same label/value
 * pairs out vertically rather than in the card's two-column grid.
 *
 * @param {Object} props          Component props.
 * @param {string} props.label    The row label.
 * @param {*}      props.children The row value.
 */
export const CardRow = ( { label, children } ) => (
	<dl className="newspack-subscribers__card-row">
		<dt className="newspack-subscribers__card-label">{ label }</dt>
		<dd className="newspack-subscribers__card-value">{ children }</dd>
	</dl>
);

/**
 * The right-aligned "more" kebab: a moreVertical toggle opening a MenuGroup of
 * actions. Rendered only when there is at least one action, so a read-only card
 * shows no dead affordance.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.toggleLabel Accessible name for the toggle.
 * @param {Array}    props.actions     Action descriptors.
 * @param {Function} props.onAction    Called with the action once chosen.
 */
const CardActionsMenu = ( { toggleLabel, actions, onAction } ) => (
	<Dropdown
		className="newspack-subscribers__card-menu"
		placement="bottom-end"
		renderToggle={ ( { isOpen, onToggle } ) => (
			<Button
				className="newspack-subscribers__card-menu-toggle"
				icon={ moreVertical }
				size="compact"
				onClick={ onToggle }
				aria-expanded={ isOpen }
				label={ toggleLabel }
				showTooltip={ false }
			/>
		) }
		renderContent={ ( { onClose } ) => (
			<MenuGroup>
				{ actions.map( action => (
					<MenuItem
						key={ action.key || action.label }
						href={ action.href }
						target={ action.target }
						isDestructive={ !! action.isDestructive }
						disabled={ !! action.disabled }
						aria-label={ action.ariaLabel }
						onClick={ () => {
							onClose();
							onAction( action );
						} }
					>
						{ action.label }
					</MenuItem>
				) ) }
			</MenuGroup>
		) }
	/>
);

/**
 * @typedef  {Object}   SubscriptionCardAction
 * @property {string}   [key]           React key; falls back to the label.
 * @property {string}   label           Menu item label.
 * @property {string}   [ariaLabel]     Accessible name, qualified with the plan
 *                                      name when several cards are listed.
 * @property {string}   [href]          Renders the item as a link.
 * @property {string}   [target]        Link target, for `href` items.
 * @property {Function} [onClick]       Handler, for non-link items.
 * @property {boolean}  [isDestructive] Render in the destructive style.
 * @property {boolean}  [disabled]      Render disabled.
 */

/**
 * @typedef  {Object} SubscriptionCardRow
 * @property {string} label The row label, e.g. "Next billing".
 * @property {*}      value The row value.
 */

/**
 * Render one subscription as a card.
 *
 * @param {Object}                   props                Component props.
 * @param {string}                   props.title          The plan/subscription name.
 * @param {string}                   [props.titleHref]    Renders the title as a link to this URL.
 * @param {Function}                 [props.onTitleClick] Renders the title as a link-styled button.
 * @param {string}                   [props.titleLabel]   Accessible name for the title link.
 * @param {*}                        [props.titleSuffix]  Muted text after the title, e.g. "(Group)".
 * @param {Array}                    [props.badges]       Status badges, `{ label, intent }`.
 * @param {*}                        [props.subline]      Secondary line under the title.
 * @param {SubscriptionCardRow[]}    [props.rows]         Label/value rows, laid out in two columns.
 * @param {SubscriptionCardAction[]} [props.actions]      Actions for the "more" menu.
 * @param {string}                   [props.actionsLabel] Accessible name for the "more" toggle.
 * @param {string}                   [props.className]    Extra class name.
 * @param {*}                        [props.children]     Content rendered below the rows.
 * @return {JSX.Element} The card.
 */
export default function SubscriptionCard( {
	title,
	titleHref,
	onTitleClick,
	titleLabel,
	titleSuffix,
	badges = [],
	subline,
	rows = [],
	actions = [],
	actionsLabel,
	className,
	children,
} ) {
	const isLinkedTitle = !! titleHref || !! onTitleClick;
	const titleContent = (
		<>
			{ title }
			{ titleSuffix && (
				<>
					&nbsp;
					<span className="newspack-subscribers__muted">{ titleSuffix }</span>
				</>
			) }
		</>
	);

	return (
		<Card
			__experimentalCoreCard
			// The card's own padding, kebab alignment and title-link styling hang
			// off this class rather than off a host screen's wrapper, so the card
			// looks identical inside the profile column and inside a drawer.
			className={ classnames( 'newspack-subscribers__card', className ) }
			__experimentalCoreProps={ {
				header: (
					<HStack justify="space-between" alignment="center">
						<VStack spacing={ 1 } expanded={ false }>
							<HStack spacing={ 2 } justify="flex-start" expanded={ false } wrap>
								{ /* h3: the section hosting these cards is an h2, so the card
								     title is the next level down — no heading-level skip. */ }
								<h3 className="newspack-subscribers__card-title">
									{ isLinkedTitle ? (
										<Button
											variant="link"
											className="newspack-subscribers__card-title-link"
											href={ titleHref }
											onClick={ onTitleClick }
											aria-label={ titleLabel }
										>
											{ titleContent }
										</Button>
									) : (
										titleContent
									) }
								</h3>
								{ badges.map( badge => (
									<Badge key={ badge.label } intent={ badge.intent || 'none' }>
										{ badge.label }
									</Badge>
								) ) }
							</HStack>
							{ subline && <span className="newspack-subscribers__muted">{ subline }</span> }
						</VStack>
						{ actions.length > 0 && (
							<CardActionsMenu
								// translators: %s is a subscription/plan name.
								toggleLabel={ actionsLabel || sprintf( __( 'Subscription actions: %s', 'newspack-plugin' ), title ) }
								actions={ actions }
								onAction={ action => action.onClick?.() }
							/>
						) }
					</HStack>
				),
			} }
		>
			{ rows.length > 0 && (
				<Grid columns={ 2 } gutter={ 16 } noMargin>
					{ rows.map( row => (
						<CardRow key={ row.label } label={ row.label }>
							{ row.value }
						</CardRow>
					) ) }
				</Grid>
			) }
			{ children }
		</Card>
	);
}
