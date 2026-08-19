/**
 * External dependencies.
 */
import classnames from 'classnames';
import type { ReactElement, ReactNode } from 'react';

/**
 * WordPress dependencies.
 */
// Notice is aliased: `Notice` below is Newspack's own, which this file also uses.
import { DropdownMenu, MenuGroup, MenuItem, Notice as CoreNotice, SlotFillProvider, createSlotFill } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { cloneElement, createInterpolateElement, isValidElement, useEffect, useState, forwardRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { category, chevronLeft, moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Footer, Notice, Button, TabbedNavigation, PluginInstaller, SectionHeader, HandoffMessage, Page } from '../';
import { activeBreadcrumbs, appendSectionName } from './breadcrumbs-select';
import Router from '../proxied-imports/router';
import registerStore, { WIZARD_STORE_NAMESPACE } from './store';
import type { WizardHeaderAction, WizardHeaderData, WizardNotice, WizardsStoreSelectors } from './store';
import type { SectionHeaderProps } from '../section-header';
import type { BreadcrumbItem } from '../breadcrumbs';
import WizardSnackbar from './components/WizardSnackbar';
import WizardError from './components/WizardError';

registerStore();

/**
 * Renders a view's page-level banner outside the padded content column, so it sits
 * flush beneath the header rather than indented within the section it describes.
 */
const { Slot: WizardBannerSlot, Fill: WizardBanner } = createSlotFill( 'NewspackWizardBanner' );

export { WizardBanner };

/**
 * Icon registry for resolving icon name strings passed through the data store.
 * React elements from @wordpress/icons can't cross webpack entry point boundaries
 * because each bundle has its own copy of the icon primitives.
 */
const ICON_REGISTRY: Record< string, JSX.Element > = {
	chevronLeft,
	category,
	moreVertical,
};
const resolveIcon = ( icon?: string | JSX.Element | null ) => {
	if ( typeof icon === 'string' ) {
		return ICON_REGISTRY[ icon ] || null;
	}
	return icon;
};

const { HashRouter, Redirect, Route, Switch, useLocation } = Router;

/**
 * Interpolate a translated message's named tags, falling back to plain text.
 *
 * The message is translated, so its tags are site-controlled: any .mo file under
 * wp-content/languages/plugins, any GlotPress export, or any `gettext`-filter
 * plugin can supply one. `createInterpolateElement` throws on an unbalanced
 * closing tag whose name is in the conversion map - verified against core's own
 * element.js, which is what runs here since the bundle externalizes wp-element.
 * Nothing above this renders an error boundary, so an uncaught throw would blank
 * the whole wizard, including the screen the inert-gating notice links to as the
 * fix. Degraded copy is recoverable; a blank screen is not.
 *
 * The other malformed cases already degrade on their own and are left alone: an
 * unclosed opener drops that tag and renders the rest as text, and a tag not in
 * the map renders literally.
 */
const interpolateOrPlainText = ( message: string, conversion: Record< string, ReactElement > ): ReactNode => {
	try {
		return createInterpolateElement( message, conversion );
	} catch {
		return message.replace( /<\/?[a-zA-Z][a-zA-Z0-9]*\s*\/?>/g, '' );
	}
};

/**
 * Reset the header data when a new section is rendered.
 */
const ResetHeaderData = () => {
	const location = useLocation();
	const { resetHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	useEffect( () => {
		resetHeaderData();
		window.scrollTo( 0, 0 );
	}, [ location.pathname, resetHeaderData ] );

	return null;
};

/**
 * A wizard section, rendered as a route.
 */
export interface WizardSection {
	/** The section's route path. */
	path: string;
	/** The section's component. Receives the router props, `props` and `sharedProps`. */
	render?: React.ElementType;
	/** The section's tab label. */
	label?: React.ReactNode;
	/** Whether the route has to match exactly. */
	exact?: boolean;
	/** Whether the section is hidden entirely. */
	isHidden?: boolean;
	/** Whether the section is excluded from the tabbed navigation. */
	isHiddenInTabbedNavigation?: boolean;
	/** Additional paths marking the section's tab as active. */
	activeTabPaths?: string[];
	/** The section's explicit breadcrumb trail. */
	breadcrumbs?: BreadcrumbItem[];
	/** The section header's title. */
	title?: SectionHeaderProps[ 'title' ];
	/** The section header's description. */
	description?: SectionHeaderProps[ 'description' ];
	/** Badges displayed next to the section title. */
	badges?: SectionHeaderProps[ 'badges' ];
	/** Items of the section's more-options menu. */
	menu?: SectionHeaderProps[ 'menu' ];
	/** URL to navigate back to. */
	backNav?: string;
	/** The section's primary action. */
	primaryAction?: SectionHeaderProps[ 'primaryAction' ];
	/** The section's secondary action. */
	secondaryAction?: SectionHeaderProps[ 'secondaryAction' ];
	/** Whether the section content spans the full width. */
	fullWidth?: boolean;
	/** Additional props passed to the section component. */
	props?: Record< string, unknown >;
}

export interface WizardProps {
	/** Array of sections. */
	sections?: WizardSection[];
	/** Fallback heading, used only when no section declares breadcrumbs. */
	headerText?: string;
	/** The API slug of the wizard data fetched on mount. */
	apiSlug?: string;
	/** Props passed to every section component. */
	sharedProps?: Record< string, unknown >;
	/** The sub-header text. */
	subHeaderText?: string;
	/** Indicates if a simple footer is used. */
	hasSimpleFooter?: boolean;
	/** CSS classes of the section content wrapper. */
	className?: string;
	/** Function to render content above sections. */
	renderAboveSections?: () => React.ReactNode;
	/** Slugs of plugins required by the wizard. */
	requiredPlugins?: string[];
	/** Indicates if the initial fetch should be triggered. */
	isInitialFetchTriggered?: boolean;
	/** Render the sections without the page header shell (tabs still own the content). */
	hideHeader?: boolean;
}

interface WizardHeaderRegionProps {
	hideHeader?: boolean;
	headerText?: string;
	sections: WizardSection[];
	sectionName?: string | BreadcrumbItem[];
	subTitle?: ReactNode;
	actions?: ReactNode;
	tabbedNavigation?: ReactNode;
	children?: ReactNode;
}

/**
 * Wizard header + content region. Rendered inside the wizard's HashRouter so it
 * can read the current route and derive the active-tab breadcrumb.
 */
const WizardHeaderRegion = ( {
	hideHeader,
	headerText,
	sections,
	sectionName,
	subTitle,
	actions,
	tabbedNavigation,
	children,
}: WizardHeaderRegionProps ) => {
	const { pathname } = useLocation();

	if ( hideHeader ) {
		// Without the Page shell the tabs still own the content: it renders
		// inside the active tab's panel.
		if ( isValidElement( tabbedNavigation ) ) {
			return cloneElement( tabbedNavigation as ReactElement, { content: children } );
		}
		return <>{ children }</>;
	}

	let breadcrumbItems = activeBreadcrumbs( sections, pathname );
	if ( ! breadcrumbItems.length && headerText ) {
		breadcrumbItems = [ { label: headerText } ];
	}
	// Append any render-time leaf crumb(s) the section supplied via
	// headerData.sectionName (deduped against the current trailing label).
	breadcrumbItems = appendSectionName( breadcrumbItems, sectionName );

	return (
		<Page breadcrumbItems={ breadcrumbItems } subTitle={ subTitle } actions={ actions } tabbedNavigation={ tabbedNavigation }>
			{ children }
		</Page>
	);
};

/**
 * Wizard Component
 *
 * Provides a tabbed UI with history.
 */
const Wizard = (
	{
		sections = [],
		headerText,
		apiSlug,
		sharedProps = {},
		subHeaderText,
		hasSimpleFooter,
		className,
		renderAboveSections,
		requiredPlugins = [],
		isInitialFetchTriggered = true,
		hideHeader = false,
	}: WizardProps,
	ref: React.ForwardedRef< HTMLDivElement >
) => {
	const isLoading = useSelect( select => ( select( WIZARD_STORE_NAMESPACE ) as WizardsStoreSelectors ).isLoading() );
	const isQuietLoading = useSelect( select => ( select( WIZARD_STORE_NAMESPACE ) as WizardsStoreSelectors ).isQuietLoading() );
	const headerData: WizardHeaderData = useSelect( select => ( select( WIZARD_STORE_NAMESPACE ) as WizardsStoreSelectors ).getHeaderData() );
	const notices: WizardNotice[] = useSelect( select => ( select( WIZARD_STORE_NAMESPACE ) as WizardsStoreSelectors ).getNotices() );
	const { actions, backNav, badges, sectionDescription, sectionMenu, sectionName, sectionTitle, sectionPrimaryAction, sectionSecondaryAction } =
		headerData;

	const mainActions = actions?.filter(
		( action ): action is WizardHeaderAction & { type: 'primary' | 'secondary' } => action.type === 'primary' || action.type === 'secondary'
	);
	const moreActions = actions?.filter( action => action.type === 'more' );

	// Trigger initial data fetch. Some sections might not use the wizard data,
	// but for consistency, fetching is triggered regardless of the section.
	useSelect( select => isInitialFetchTriggered && ( select( WIZARD_STORE_NAMESPACE ) as WizardsStoreSelectors ).getWizardAPIData( apiSlug ) );

	let displayedSections = sections.filter( section => ! section.isHidden );

	const [ pluginRequirementsSatisfied, setPluginRequirementsSatisfied ] = useState( requiredPlugins.length === 0 );
	if ( ! pluginRequirementsSatisfied ) {
		headerText = requiredPlugins.length > 1 ? __( 'Required plugins', 'newspack-plugin' ) : __( 'Required plugin', 'newspack-plugin' );
		displayedSections = [
			{
				path: '/',
				render: () => (
					<PluginInstaller
						plugins={ requiredPlugins }
						onStatus={ ( { complete }: { complete: boolean } ) => setPluginRequirementsSatisfied( complete ) }
					/>
				),
			},
		];
	}

	// When plugins are required but not yet satisfied, `displayedSections` is replaced with
	// the PluginInstaller. Use it for routing so the installer actually mounts and runs.
	const routedSections = pluginRequirementsSatisfied ? sections : displayedSections;

	const tabbedNavigation = displayedSections.length > 1 && (
		<TabbedNavigation items={ displayedSections }>
			<WizardError />
		</TabbedNavigation>
	);

	// Rendered here rather than as a core admin notice, which wizards strip at
	// priority -9999. Sits as the first child of .newspack-wizard__main so it lands
	// flush beneath the header region and spans the full width in every view: this
	// describes the state of the whole site, not of the section below it, so it reads
	// as page chrome rather than as content.
	const inertGating = newspack_aux_data?.inert_gating;
	const inertGatingNotice = inertGating?.show && (
		<CoreNotice status="warning" isDismissible={ false } className="newspack-wizard__inert-gating-notice">
			{ /* The conversion map takes childless elements and fills them from the
			     translated string, so jsx-a11y can't see the content they end up with. */ }
			{ interpolateOrPlainText( inertGating.message, {
				/* eslint-disable jsx-a11y/anchor-has-content */
				accessControl: <a href={ inertGating.urls.accessControl } />,
				audience: <a href={ inertGating.urls.audience } />,
				/* eslint-enable jsx-a11y/anchor-has-content */
				strong: <strong />,
			} ) }
		</CoreNotice>
	);

	const content = (
		<>
			<HandoffMessage />

			{ sections.length > 1 && <ResetHeaderData /> }

			<div className="newspack-wizard__main">
				{ inertGatingNotice }
				<WizardBannerSlot bubblesVirtually />
				<Switch>
					{ routedSections.map( ( section, index ) => {
						const SectionComponent = section.render;
						const sectionProps = section.props || {};
						return (
							<Route
								key={ index }
								exact={ section.exact ?? false }
								path={ section.path }
								render={ routerProps => (
									<div
										className={ classnames( 'newspack-wizard__content', className, {
											'newspack-wizard__content--full-width': section.fullWidth,
										} ) }
									>
										{ 'function' === typeof renderAboveSections ? renderAboveSections() : null }
										{ ( sectionTitle || section.title ) && (
											<SectionHeader
												className="newspack-wizard__section-header"
												backNav={ backNav || section.backNav }
												title={ sectionTitle || section.title || '' }
												description={ sectionDescription || section.description }
												badges={ badges || section.badges }
												menu={ sectionMenu || section.menu }
												primaryAction={ sectionPrimaryAction || section.primaryAction }
												secondaryAction={ sectionSecondaryAction || section.secondaryAction }
												heading={ 2 }
												noMargin
											/>
										) }
										{ SectionComponent && <SectionComponent { ...routerProps } { ...sectionProps } { ...sharedProps } /> }
									</div>
								) }
							/>
						);
					} ) }
					<Redirect to={ displayedSections[ 0 ].path } />
				</Switch>
			</div>
		</>
	);

	const headerActions =
		actions && actions.length > 0 ? (
			<>
				{ mainActions?.map( ( action, index ) => (
					<Button
						key={ index }
						className="newspack-wizard__actions__main"
						href={ action.href }
						icon={ resolveIcon( action.icon ) ?? undefined }
						variant={ action.type }
						onClick={ action.action }
						disabled={ action.disabled || false }
						isDestructive={ action.destructive || false }
					>
						{ action.label }
					</Button>
				) ) }
				<DropdownMenu
					className={ moreActions?.length === 0 ? 'newspack-wizard__actions__more--primary-only' : '' }
					icon={ moreVertical }
					label={ __( 'More', 'newspack-plugin' ) }
					popoverProps={ { className: 'newspack-wizard__actions__more' } }
				>
					{ () =>
						// Split actions into groups whenever an action opts in via `separator: true`.
						// Consecutive MenuGroups render the WordPress-standard divider between them.
						actions
							.reduce< WizardHeaderAction[][] >( ( groups, action ) => {
								if ( action.separator || groups.length === 0 ) {
									groups.push( [] );
								}
								groups[ groups.length - 1 ].push( action );
								return groups;
							}, [] )
							.map( ( group, groupIndex ) => (
								<MenuGroup key={ groupIndex }>
									{ group.map( ( action, index ) => {
										// MenuItem's type omits `href`, though its underlying Button supports it.
										const menuItemProps = {
											className:
												action.type === 'primary' || action.type === 'secondary'
													? 'newspack-wizard__actions__more__main'
													: 'newspack-wizard__actions__more__more',
											icon: ( action.icon ?? undefined ) as JSX.Element | undefined,
											href: action.href,
											onClick: action.action,
											disabled: action.disabled || false,
											isDestructive: action.destructive || false,
										};
										return (
											<MenuItem key={ index } { ...menuItemProps }>
												{ action.label }
											</MenuItem>
										);
									} ) }
								</MenuGroup>
							) )
					}
				</DropdownMenu>
			</>
		) : undefined;

	return (
		<SlotFillProvider>
			<div ref={ ref }>
				<div
					className={ classnames( isLoading ? 'newspack-wizard__is-loading' : 'newspack-wizard__is-loaded', {
						'newspack-wizard__is-loading-quiet': isQuietLoading,
					} ) }
				>
					<HashRouter hashType="slash">
						{ newspack_aux_data.is_debug_mode && <Notice debugMode /> }
						<WizardHeaderRegion
							hideHeader={ hideHeader }
							headerText={ headerText }
							sections={ routedSections }
							sectionName={ sectionName }
							subTitle={ subHeaderText }
							actions={ headerActions }
							tabbedNavigation={ tabbedNavigation }
						>
							{ content }
						</WizardHeaderRegion>
					</HashRouter>
					{ !! notices?.length && (
						<div className="newspack-wizard__snackbar-list">
							{ notices.map( ( notice, index ) => (
								<WizardSnackbar key={ notice.id || index } id={ notice.id } type={ notice.type } actions={ notice.actions }>
									{ notice.message }
								</WizardSnackbar>
							) ) }
						</div>
					) }
				</div>
				{ ! isLoading && <Footer simple={ hasSimpleFooter } /> }
			</div>
		</SlotFillProvider>
	);
};

export default forwardRef( Wizard );
