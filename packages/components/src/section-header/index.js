/**
 * Section Header
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef } from '@wordpress/element';
import { DropdownMenu, MenuItem, Tooltip, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Icon, chevronLeft, moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Badge, Button, Grid } from '..';
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Represents a section header component.
 *
 * @typedef {Object} SectionHeaderProps
 * @property {string}             [backNav='']       - URL to navigate back to.
 * @property {string|string[]}    [badge]            - Badge to display in the header.
 * @property {string}             [badgeLevel]       - Badge level, e.g., 'success', 'info', 'warning', 'error'.
 * @property {boolean}            [centered=false]   - Indicates if the header is centered.
 * @property {?string}            [className=null]   - Additional CSS class name.
 * @property {string|Function|*}  [description]      - Description of the section.
 * @property {number}             [heading=2]        - HTML heading level, e.g., 1 for h1, 2 for h2, etc.
 * @property {string|Function|*}  [icon]             - Icon to display in the header.
 * @property {boolean}            [isWhite=false]    - Indicates if the header should use a white theme.
 * @property {boolean}            [noMargin=false]   - Indicates if the header should have no margin.
 * @property {boolean}            [pageHeader=false] - Indicates if the header is used as a page header.
 * @property {string}             title              - The title of the section.
 * @property {?string}            [id=null]          - Optional ID for the header element.
 * @property {?string|Function|*} [children=null]    - Optional children to display in the header.
 */

/**
 * Creates a section header.
 *
 * @param {SectionHeaderProps} props - The properties for the section header.
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
} ) => {
	// If id is in the URL as a scrollTo param, scroll to it on render.
	const ref = useRef();
	useEffect( () => {
		const params = new Proxy( new URLSearchParams( window.location.search ), {
			get: ( searchParams, prop ) => searchParams.get( prop ),
		} );
		const scrollToId = params.scrollTo;
		if ( scrollToId && scrollToId === id ) {
			// Let parent scroll action run before running this.
			window.setTimeout( () => ref.current.scrollIntoView( { behavior: 'smooth' } ), 250 );
		}
	}, [] );

	const classes = classnames(
		'newspack-section-header',
		centered && 'newspack-section-header--is-centered',
		isWhite && 'newspack-section-header--is-white',
		noMargin && 'newspack-section-header--no-margin',
		pageHeader && 'newspack-section-header--page-header'
	);

	const HeadingTag = pageHeader ? 'h1' : `h${ heading }`;

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
				{ menu?.length > 0 && (
					<DropdownMenu className="newspack-section-header__menu" icon={ moreVertical } label={ __( 'More options', 'newspack-plugin' ) }>
						{ () =>
							menu.map( ( item, index ) => (
								<MenuItem
									key={ index }
									icon={ item.icon }
									href={ item.href }
									onClick={ item.action }
									disabled={ item.disabled || false }
									isDestructive={ item.destructive || false }
								>
									{ item.label }
								</MenuItem>
							) )
						}
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
			id={ id }
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
