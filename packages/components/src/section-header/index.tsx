/**
 * Section Header
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef } from '@wordpress/element';
import {
	DropdownMenu,
	MenuItem,
	Tooltip,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { Icon, chevronLeft, moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Badge, Button, Grid } from '..';
import type { BadgeLevel } from '../badge';
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

export interface SectionHeaderBadge {
	/** The badge's text. */
	label: string;
	/** Badge level, e.g., 'success', 'info', 'warning', 'error'. */
	level?: BadgeLevel;
}

export interface SectionHeaderMenuItem {
	/** The menu item's label. */
	label: React.ReactNode;
	/** Icon displayed next to the label. */
	icon?: React.ComponentProps< typeof MenuItem >[ 'icon' ];
	/** URL the menu item links to. */
	href?: string;
	/** Called when the menu item is clicked. */
	action?: () => void;
	/** Whether the menu item is disabled. */
	disabled?: boolean;
	/** Whether the menu item is destructive. */
	destructive?: boolean;
}

export interface SectionHeaderAction {
	/** The action's label. */
	label: React.ReactNode;
	/** URL the action links to. */
	href?: string;
	/** Called when the action is clicked. */
	action?: () => void;
}

export interface SectionHeaderProps {
	/** URL to navigate back to. */
	backNav?: string;
	/** Badges to display next to the title. */
	badges?: SectionHeaderBadge[];
	/** Indicates if the header is centered. */
	centered?: boolean;
	/** Additional CSS class name. */
	className?: string | null;
	/** Description of the section. */
	description?: React.ReactNode | ( () => React.ReactNode );
	/** HTML heading level, e.g., 1 for h1, 2 for h2, etc. */
	heading?: 1 | 2 | 3 | 4 | 5 | 6;
	/** Icon to display in the header. */
	icon?: React.ComponentProps< typeof Icon >[ 'icon' ] | null;
	/** Indicates if the header should use a white theme. */
	isWhite?: boolean;
	/** Indicates if the header should have no margin. */
	noMargin?: boolean;
	/** Indicates if the header is used as a page header. */
	pageHeader?: boolean;
	/** The title of the section. */
	title: string | ( () => React.ReactNode );
	/** Optional ID for the header element. */
	id?: string | null;
	/** Items for the more-options dropdown menu. */
	menu?: SectionHeaderMenuItem[];
	/** Primary action button. */
	primaryAction?: SectionHeaderAction;
	/** Secondary action link. */
	secondaryAction?: SectionHeaderAction;
	/** Optional children to display in the header. */
	children?: React.ReactNode;
}

/**
 * Creates a section header.
 *
 * @param props                 - The properties for the section header.
 * @param props.backNav
 * @param props.badges
 * @param props.centered
 * @param props.className
 * @param props.description
 * @param props.heading
 * @param props.icon
 * @param props.isWhite
 * @param props.noMargin
 * @param props.pageHeader
 * @param props.title
 * @param props.id
 * @param props.menu
 * @param props.primaryAction
 * @param props.secondaryAction
 * @param props.children
 */
const SectionHeader = ( {
	backNav = '',
	badges,
	centered = false,
	className = null,
	description = '',
	heading = 2,
	icon = null,
	isWhite = false,
	noMargin = false,
	pageHeader = false,
	title,
	id = null,
	menu,
	primaryAction,
	secondaryAction,
	children = null,
}: SectionHeaderProps ) => {
	// If id is in the URL as a scrollTo param, scroll to it on render.
	const ref = useRef< HTMLDivElement | null >( null );
	useEffect( () => {
		const scrollToId = new URLSearchParams( window.location.search ).get( 'scrollTo' );
		if ( scrollToId && scrollToId === id ) {
			// Let parent scroll action run before running this.
			window.setTimeout( () => ref.current?.scrollIntoView( { behavior: 'smooth' } ), 250 );
		}
	}, [] );

	const classes = classnames(
		'newspack-section-header',
		centered && 'newspack-section-header--is-centered',
		isWhite && 'newspack-section-header--is-white',
		noMargin && 'newspack-section-header--no-margin',
		pageHeader && 'newspack-section-header--page-header'
	);

	const HeadingTag = pageHeader ? 'h1' : ( `h${ heading }` as const );

	let titleContent = null;

	if ( typeof title === 'string' ) {
		titleContent = (
			<div className="newspack-section-header__title-container">
				<HeadingTag>
					{ title }
					{ badges?.length
						? badges.map( ( badge, i ) => <Badge key={ i } text={ badge.label } level={ badge.level || 'default' } /> )
						: null }
				</HeadingTag>
				{ !! menu?.length && (
					<DropdownMenu className="newspack-section-header__menu" icon={ moreVertical } label={ __( 'More options', 'newspack-plugin' ) }>
						{ () => (
							<>
								{ menu.map( ( item, index ) => {
									// MenuItem's type omits `href`, though its underlying Button supports it.
									const menuItemProps = {
										icon: item.icon,
										href: item.href,
										onClick: item.action,
										disabled: item.disabled || false,
										isDestructive: item.destructive || false,
									};
									return (
										<MenuItem key={ index } { ...menuItemProps }>
											{ item.label }
										</MenuItem>
									);
								} ) }
							</>
						) }
					</DropdownMenu>
				) }
				{ secondaryAction && (
					<div className="newspack-section-header__secondary-action">
						<Button variant="link" href={ secondaryAction.href } onClick={ secondaryAction.action }>
							{ secondaryAction.label }
						</Button>
					</div>
				) }
			</div>
		);
	} else if ( typeof title === 'function' ) {
		titleContent = <HeadingTag>{ title() }</HeadingTag>;
	}

	return (
		<div
			id={ id ?? undefined }
			className={ classnames(
				'newspack-section-header__container',
				backNav && 'newspack-section-header--has-back-nav',
				primaryAction && 'newspack-section-header--has-primary-action',
				className
			) }
			ref={ ref }
		>
			<Grid columns={ 1 } gutter={ 8 } className={ classes }>
				{ icon && (
					<div className="newspack-section-header__icon">
						<Icon icon={ icon } size={ 48 } />
					</div>
				) }
				{ backNav ? (
					<HStack alignment="left" style={ { position: 'relative' } }>
						<div className="newspack-section-header__back-nav">
							<Tooltip text={ __( 'Go back', 'newspack-plugin' ) }>
								<Button href={ backNav } icon={ chevronLeft } variant="tertiary" />
							</Tooltip>
						</div>
						{ titleContent }
					</HStack>
				) : (
					titleContent
				) }
				{ description && typeof description === 'string' && <p>{ description }</p> }
				{ typeof description === 'function' && <p>{ description() }</p> }
				{ description && typeof description !== 'string' && typeof description !== 'function' && <p>{ description }</p> }
				{ children && <div className="newspack-section-header__children">{ children }</div> }
			</Grid>
			{ primaryAction && (
				<div className="newspack-section-header__primary-action">
					<Button href={ primaryAction.href } variant="primary" onClick={ primaryAction.action }>
						{ primaryAction.label }
					</Button>
				</div>
			) }
		</div>
	);
};

export default SectionHeader;
