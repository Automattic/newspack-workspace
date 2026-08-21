/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { createElement, isValidElement } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { DropdownMenu } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { Badge, Card, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import Button from '../button';
import type { BadgeIntent, CardBadge, HeadingLevel } from '../types';
import './style.scss';

type CardFeatureIcon = {
	/** The icon node to render (e.g. a WordPress <Icon> component). */
	node: React.ReactNode;
	/** SVG fill colour, applied via currentColor. */
	fill?: string;
	/** Background colour for the icon container. */
	backgroundColor?: string;
	/**
	 * Border-radius of the icon container.
	 * 'small' uses $radius-small (2px), 'full' uses $radius-round (50%).
	 * Only relevant when backgroundColor is set, where it defaults to 'small'.
	 */
	radius?: 'small' | 'full';
};

type MoreControl = {
	title: string;
	onClick: () => void;
	icon?: JSX.Element;
};

type CardFeatureProps = {
	title: string;
	/** Heading level for the title. Defaults to 3, which sits under a `SectionHeader` or a `WizardsTab` heading. */
	headingLevel?: HeadingLevel;
	description?: string;
	/** Icon shown beside the title: a descriptor (coloured badge) or a ready element rendered as-is. */
	icon?: CardFeatureIcon | React.ReactElement;
	/** Whether the feature is currently enabled. */
	enabled?: boolean;
	/**
	 * When set, the card enters the "unmet requirements" state: an error badge
	 * displays this string and the title drops to the muted text colour. By
	 * default the primary button is blocked — set `requirementsActionable`
	 * if the primary button is the remediation for the unmet requirement.
	 * Also the button's accessible description, so it must read after the label.
	 */
	requirements?: string;
	/**
	 * When `requirements` is set, keep the primary button clickable so the
	 * user can remediate the unmet requirement from this card, and keep the
	 * "More" dropdown available — the feature is degraded but still operable
	 * (e.g. can be disabled), unlike a hard-locked requirement.
	 */
	requirementsActionable?: boolean;
	/** Label for the primary button in its "Enable" states: not enabled, or enabled with an unmet requirement. Default: "Enable". */
	enableLabel?: string;
	/** Show the primary button as busy (spinner) and disabled while an action is in flight. */
	busy?: boolean;
	/** Label for the primary button in its "Configure" state: enabled, with no unmet requirement. Default: "Configure". */
	configureLabel?: string;
	/**
	 * Called when the primary button is clicked while it reads "Enable". That is
	 * the not-enabled state, and also the enabled state with unmet requirements,
	 * where the requirement rather than the feature is what the button acts on.
	 */
	onEnable?: () => void;
	/** Called when the primary button is clicked while it reads "Configure": enabled, with no unmet requirements. */
	onConfigure?: () => void;
	/** Controls rendered inside the "More" dropdown, shown when enabled — including the unmet-requirements state when `requirementsActionable`. */
	moreControls?: MoreControl[];
	/** Badge shown when enabled. Ignored while `requirements` is set, which takes the badge. Defaults to "Enabled" at the "stable" intent. */
	badge?: CardBadge;
	className?: string;
};

/**
 * CardFeature component.
 *
 * A card for presenting a named feature or setting with a predictable
 * action model: a primary button, an optional "More" dropdown when enabled,
 * and an automatic badge reflecting the current state.
 */
const CardFeature = ( {
	title,
	headingLevel = 3,
	description,
	icon,
	enabled = false,
	requirements,
	requirementsActionable = false,
	enableLabel,
	busy = false,
	configureLabel,
	onEnable,
	onConfigure,
	moreControls,
	badge: badgeProp,
	className,
}: CardFeatureProps ) => {
	const instanceId = useInstanceId( CardFeature, 'newspack-card-feature' );
	const badgeId = `${ instanceId }__badge`;
	const describedById = requirements ? badgeId : undefined;
	const isMuted = !! requirements;
	const classes = classnames( 'newspack-card-feature', className, {
		'newspack-card-feature--muted': isMuted,
	} );

	let badge: { label: string; intent: BadgeIntent } | undefined;
	if ( requirements ) {
		badge = { label: requirements, intent: 'high' };
	} else if ( enabled ) {
		badge = { label: badgeProp?.label ?? __( 'Enabled', 'newspack-plugin' ), intent: badgeProp?.intent ?? 'stable' };
	}

	const isConfigureState = enabled && ! requirements;
	const buttonLabel = isConfigureState ? configureLabel ?? __( 'Configure', 'newspack-plugin' ) : enableLabel ?? __( 'Enable', 'newspack-plugin' );
	const buttonAccessibleLabel = sprintf(
		// translators: %1$s: the button's visible action label, e.g. "Enable". %2$s: the feature's name. The visible label must stay first (WCAG 2.5.3).
		_x( '%1$s %2$s', 'accessible button name: visible action label, then feature name', 'newspack-plugin' ),
		buttonLabel,
		title
	);
	const showMoreControls = enabled && !! moreControls?.length && ( ! requirements || requirementsActionable );

	const handleButtonClick = () => {
		if ( isConfigureState ) {
			onConfigure?.();
		} else {
			onEnable?.();
		}
	};

	const iconDescriptor = icon && ! isValidElement( icon ) ? ( icon as CardFeatureIcon ) : null;
	const iconClasses = iconDescriptor
		? classnames( 'newspack-card-feature__icon', {
				'newspack-card-feature__icon--radius-small': !! iconDescriptor.backgroundColor && iconDescriptor.radius !== 'full',
				'newspack-card-feature__icon--radius-full': iconDescriptor.radius === 'full',
		  } )
		: undefined;

	let renderedIcon = null;
	if ( isValidElement( icon ) ) {
		renderedIcon = icon;
	} else if ( iconDescriptor ) {
		renderedIcon = (
			// Decorative: a vendor mark passed as `node` carries no aria-hidden of its own.
			<div
				aria-hidden="true"
				className={ iconClasses }
				style={ {
					backgroundColor: iconDescriptor.backgroundColor,
					color: iconDescriptor.fill,
				} }
			>
				{ iconDescriptor.node }
			</div>
		);
	}

	return (
		<Card.Root className={ classes }>
			<Card.Header>
				<Stack direction="row" align="start" gap="lg">
					<Stack className="newspack-card-feature__content" direction="column" gap="sm">
						{ createElement( `h${ headingLevel }`, { className: 'newspack-card-feature__title' }, title ) }
						{ description && <p className="newspack-card-feature__description">{ description }</p> }
					</Stack>
					{ renderedIcon }
				</Stack>
			</Card.Header>
			<Card.Content className="newspack-card-feature__actions">
				<Stack direction="row" align="center" justify="space-between" gap="sm" wrap="wrap">
					<Stack direction="row" align="center" gap="sm">
						<Button
							variant={ isConfigureState ? 'tertiary' : 'secondary' }
							accessibleWhenDisabled
							aria-describedby={ describedById }
							aria-label={ buttonAccessibleLabel }
							disabled={ ( isMuted && ! requirementsActionable ) || busy }
							isBusy={ busy }
							onClick={ handleButtonClick }
							size="compact"
						>
							{ buttonLabel }
						</Button>
						{ showMoreControls && (
							<DropdownMenu
								icon={ moreVertical }
								label={ sprintf(
									// translators: %s: the feature's name.
									__( 'More options for %s', 'newspack-plugin' ),
									title
								) }
								controls={ moreControls }
								toggleProps={ { size: 'compact' } }
							/>
						) }
					</Stack>
					{ badge && (
						<Badge id={ describedById } className="newspack-card-feature__badge" intent={ badge.intent }>
							{ badge.label }
						</Badge>
					) }
				</Stack>
			</Card.Content>
		</Card.Root>
	);
};

export default CardFeature;
