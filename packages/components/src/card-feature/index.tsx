/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createElement, isValidElement } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { DropdownMenu } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { Card, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import Badge, { type BadgeLevel } from '../badge';
import Button from '../button';
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

type HeadingLevel = 1 | 2 | 3 | 4 | 5 | 6;

type MoreControl = {
	title: string;
	onClick: () => void;
	icon?: JSX.Element;
};

type CardFeatureProps = {
	title: string;
	/**
	 * Heading level for the title. Pick the level that fits the surrounding
	 * document outline: 3 under a `SectionHeader`, 2 when the cards sit directly
	 * under a page's h1. Levels 2-6 are the practical range, since wp-admin's own
	 * `.wrap h1` rule outranks the card's title class.
	 */
	titleLevel?: HeadingLevel;
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
	 *
	 * This string is also the primary button's accessible description, so write
	 * it to read sensibly after the button's own label.
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
	/** Badge text shown when enabled. Ignored while `requirements` is set, which takes the badge. Default: "Enabled". */
	badgeText?: string;
	/** Badge level shown when enabled. Ignored while `requirements` is set, which forces an error badge. Default: "success". */
	badgeLevel?: BadgeLevel;
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
	titleLevel = 2,
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
	badgeText,
	badgeLevel = 'success',
	className,
}: CardFeatureProps ) => {
	const instanceId = useInstanceId( CardFeature, 'newspack-card-feature' );
	const badgeId = `${ instanceId }__badge`;
	// The button's description and the badge's id must appear and disappear together.
	const describedById = requirements ? badgeId : undefined;
	const isMuted = !! requirements;
	const classes = classnames( 'newspack-card-feature', className, {
		'newspack-card-feature--muted': isMuted,
	} );

	let badge: { text: string; level: BadgeLevel } | undefined;
	if ( requirements ) {
		badge = { text: requirements, level: 'error' };
	} else if ( enabled ) {
		badge = { text: badgeText ?? __( 'Enabled', 'newspack-plugin' ), level: badgeLevel };
	}

	const isConfigureState = enabled && ! requirements;
	const buttonLabel = isConfigureState ? configureLabel ?? __( 'Configure', 'newspack-plugin' ) : enableLabel ?? __( 'Enable', 'newspack-plugin' );
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
			// Decorative: the feature is always named in the adjacent title, and a
			// vendor mark passed as `node` carries no aria-hidden of its own.
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
						{ createElement( `h${ titleLevel }`, { className: 'newspack-card-feature__title' }, title ) }
						{ description && <p className="newspack-card-feature__description">{ description }</p> }
					</Stack>
					{ renderedIcon }
				</Stack>
			</Card.Header>
			<Card.Content className="newspack-card-feature__actions">
				<Stack direction="row" align="center" justify="space-between" gap="sm">
					<Stack direction="row" align="center" gap="sm">
						<Button
							variant={ isConfigureState ? 'tertiary' : 'secondary' }
							accessibleWhenDisabled
							aria-describedby={ describedById }
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
								label={ __( 'More', 'newspack-plugin' ) }
								controls={ moreControls }
								toggleProps={ { size: 'compact' } }
							/>
						) }
					</Stack>
					{ badge && <Badge id={ describedById } text={ badge.text } level={ badge.level } /> }
				</Stack>
			</Card.Content>
		</Card.Root>
	);
};

export default CardFeature;
