/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { isValidElement } from '@wordpress/element';
import { DropdownMenu, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Badge from '../badge';
import Button from '../button';
import Card from '../card';
import './style.scss';

type BadgeLevel = 'default' | 'info' | 'success' | 'warning' | 'error';

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
	 * Only relevant when backgroundColor is set.
	 */
	radius?: 'small' | 'full';
};

type MoreControl = {
	title: string;
	onClick: () => void;
	icon?: React.ReactNode;
};

type CardFeatureProps = {
	title: string;
	description?: string;
	/** Icon shown beside the title: a descriptor (coloured badge) or a ready element rendered as-is. */
	icon?: CardFeatureIcon | React.ReactElement;
	/** Whether the feature is currently enabled. */
	enabled?: boolean;
	/**
	 * When set, the card enters the "unmet requirements" state: an error
	 * badge displays this string and the title/description are muted. By
	 * default the primary button is disabled — set `requirementsActionable`
	 * if the primary button is the remediation for the unmet requirement.
	 */
	requirements?: string;
	/**
	 * When `requirements` is set, keep the primary button clickable so the
	 * user can remediate the unmet requirement from this card.
	 */
	requirementsActionable?: boolean;
	/** Primary button label when not enabled. Default: "Enable". */
	enableLabel?: string;
	/** Show the primary button as busy (spinner) and disabled while an action is in flight. */
	busy?: boolean;
	/** Primary button label when enabled. Default: "Configure". */
	configureLabel?: string;
	/** Called when the primary button is clicked and the feature is not enabled. */
	onEnable?: () => void;
	/** Called when the primary button is clicked and the feature is enabled. */
	onConfigure?: () => void;
	/** Controls rendered inside the "More" dropdown, shown only when enabled. */
	moreControls?: MoreControl[];
	/** Badge text shown when enabled. Default: "Enabled". */
	badgeText?: string;
	/** Badge level shown when enabled. Default: "success". */
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

	return (
		<Card
			className={ classes }
			__experimentalCoreCard
			__experimentalCoreProps={ {
				headerStyle: { padding: 32 },
				header: (
					<>
						<HStack alignment="top" spacing={ 4 }>
							<div className="newspack-card-feature__content">
								<h2 className="newspack-card-feature__title">{ title }</h2>
								{ description && <p className="newspack-card-feature__description">{ description }</p> }
							</div>
							{ icon &&
								( iconDescriptor ? (
									<div
										className={ iconClasses }
										style={ {
											backgroundColor: iconDescriptor.backgroundColor,
											color: iconDescriptor.fill,
										} }
									>
										{ iconDescriptor.node }
									</div>
								) : (
									icon
								) ) }
						</HStack>
						<HStack alignment="edge">
							<HStack expanded={ false } spacing="8px">
								<Button
									variant={ isConfigureState ? 'tertiary' : 'secondary' }
									disabled={ ( isMuted && ! requirementsActionable ) || busy }
									isBusy={ busy }
									onClick={ handleButtonClick }
									size="compact"
								>
									{ buttonLabel }
								</Button>
								{ isConfigureState && !! moreControls?.length && (
									<DropdownMenu
										icon={ moreVertical }
										label={ __( 'More', 'newspack-plugin' ) }
										controls={ moreControls }
										toggleProps={ { size: 'compact' } }
									/>
								) }
							</HStack>
							{ badge && <Badge text={ badge.text } level={ badge.level } /> }
						</HStack>
					</>
				),
			} }
		/>
	);
};

export default CardFeature;
