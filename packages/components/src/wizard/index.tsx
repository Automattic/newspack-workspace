/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import { DropdownMenu, MenuItem } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState, forwardRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { category, chevronLeft, moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Footer, Notice, Button, NewspackIcon, TabbedNavigation, PluginInstaller, SectionHeader, HandoffMessage } from '../';
import Router from '../proxied-imports/router';
import registerStore, { WIZARD_STORE_NAMESPACE } from './store';
import type { WizardHeaderAction, WizardHeaderData, WizardNotice, WizardsStoreSelectors } from './store';
import type { SectionHeaderProps } from '../section-header';
import WizardSnackbar from './components/WizardSnackbar';
import WizardError from './components/WizardError';

registerStore();

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
	/** The header text. */
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
	/** Whether the wizard header is fixed. */
	fixedHeader?: boolean;
}

/**
 * Wizard Component
 *
 * Provides a tabbed UI with history.
 * @param root0
 * @param root0.sections
 * @param root0.headerText
 * @param root0.apiSlug
 * @param root0.sharedProps
 * @param root0.subHeaderText
 * @param root0.hasSimpleFooter
 * @param root0.className
 * @param root0.renderAboveSections
 * @param root0.requiredPlugins
 * @param root0.isInitialFetchTriggered
 * @param root0.fixedHeader
 * @param ref
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
		fixedHeader = false,
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
					<PluginInstaller plugins={ requiredPlugins } onStatus={ ( { complete } ) => setPluginRequirementsSatisfied( complete ) } />
				),
			},
		];
	}

	// When plugins are required but not yet satisfied, `displayedSections` is replaced with
	// the PluginInstaller. Use it for routing so the installer actually mounts and runs.
	const routedSections = pluginRequirementsSatisfied ? sections : displayedSections;

	const urlWithoutHash = window.location.href.split( '#' )[ 0 ];

	return (
		<div ref={ ref }>
			<div
				className={ classnames( isLoading ? 'newspack-wizard__is-loading' : 'newspack-wizard__is-loaded', {
					'newspack-wizard__is-loading-quiet': isQuietLoading,
					'newspack-wizard__fixed-header': fixedHeader,
				} ) }
			>
				<HashRouter hashType="slash">
					{ newspack_aux_data.is_debug_mode && <Notice debugMode /> }
					<div className="newspack-wizard__header">
						<div className="newspack-wizard__header__inner">
							<div className="newspack-wizard__title">
								{ newspack_urls.dashboard !== urlWithoutHash ? (
									<Button
										isLink
										href={ newspack_urls.dashboard }
										label={ __( 'Return to Dashboard', 'newspack-plugin' ) }
										showTooltip={ true }
										icon={ category }
										iconSize={ 36 }
									>
										<NewspackIcon size={ 36 } />
									</Button>
								) : (
									<NewspackIcon size={ 36 } />
								) }
								<div>
									{ headerText && (
										<h2 className="newspack-wizard__header__title">
											{ headerText }
											{ sectionName && (
												<span className="newspack-wizard__header__section">
													<span className="newspack-wizard__header__section__separator"> / </span> { sectionName }
												</span>
											) }
										</h2>
									) }
									{ subHeaderText && <span>{ subHeaderText }</span> }
								</div>
							</div>
						</div>
						{ !! actions?.length && (
							<div className="newspack-wizard__header__actions">
								{ mainActions?.map( ( action, index ) => (
									<Button
										key={ index }
										className="newspack-wizard__header__actions__main"
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
									className={ moreActions?.length === 0 ? 'newspack-wizard__header__actions__more--primary-only' : '' }
									icon={ moreVertical }
									label={ __( 'More', 'newspack-plugin' ) }
									popoverProps={ {
										className: 'newspack-wizard__header__actions__more',
									} }
								>
									{ () => (
										<>
											{ actions.map( ( action, index ) => {
												// MenuItem's type omits `href`, though its underlying Button supports it.
												const menuItemProps = {
													className:
														action.type === 'primary' || action.type === 'secondary'
															? 'newspack-wizard__header__actions__more__main'
															: 'newspack-wizard__header__actions__more__more',
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
										</>
									) }
								</DropdownMenu>
							</div>
						) }
					</div>

					{ displayedSections.length > 1 && (
						<TabbedNavigation items={ displayedSections }>
							<WizardError />
						</TabbedNavigation>
					) }
					<HandoffMessage />

					{ sections.length > 1 && <ResetHeaderData /> }

					<div className="newspack-wizard__main">
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
														heading={ 1 }
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
				</HashRouter>
				{ !! notices?.length &&
					notices.map( ( notice, index ) => (
						<WizardSnackbar key={ notice.id || index } type={ notice.type } id={ notice.id } actions={ notice.actions }>
							{ notice.message }
						</WizardSnackbar>
					) ) }
			</div>
			{ ! isLoading && <Footer simple={ hasSimpleFooter } /> }
		</div>
	);
};

export default forwardRef( Wizard );
