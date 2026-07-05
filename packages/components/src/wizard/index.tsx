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
import WizardSnackbar from './components/WizardSnackbar';
import WizardError from './components/WizardError';

registerStore();

/**
 * Icon registry for resolving icon name strings passed through the data store.
 * React elements from @wordpress/icons can't cross webpack entry point boundaries
 * because each bundle has its own copy of the icon primitives.
 */
const ICON_REGISTRY = { chevronLeft, category, moreVertical };
const resolveIcon = icon => {
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
 * @typedef  {Object}     WizardProps
 * @property {string}     headerText                The header text.
 * @property {string}     [subHeaderText]           The sub-header text, optional.
 * @property {string}     [apiSlug]                 The API slug, optional.
 * @property {string}     [className]               CSS classes, optional.
 * @property {any[]}      sections                  Array of sections.
 * @property {boolean}    [hasSimpleFooter]         Indicates if a simple footer is used, optional.
 * @property {() => void} [renderAboveSections]     Function to render content above sections, optional.
 * @property {string[]}   [requiredPlugins]         Array of required plugin strings, optional.
 * @property {boolean}    [isInitialFetchTriggered] Indicates if the initial fetch should be triggered, optional.
 */

/**
 * Wizard Component
 *
 * Provides a tabbed UI with history.
 *
 * @param {WizardProps} props
 * @return {JSX.Element} Wizard component
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
	},
	ref
) => {
	const isLoading = useSelect( select => select( WIZARD_STORE_NAMESPACE ).isLoading() );
	const isQuietLoading = useSelect( select => select( WIZARD_STORE_NAMESPACE ).isQuietLoading() );
	const headerData = useSelect( select => select( WIZARD_STORE_NAMESPACE ).getHeaderData() );
	const notices = useSelect( select => select( WIZARD_STORE_NAMESPACE ).getNotices() );
	const { actions, backNav, badges, sectionDescription, sectionMenu, sectionName, sectionTitle, sectionPrimaryAction, sectionSecondaryAction } =
		headerData;

	const mainActions = actions?.filter( action => action.type === 'primary' || action.type === 'secondary' );
	const moreActions = actions?.filter( action => action.type === 'more' );

	// Trigger initial data fetch. Some sections might not use the wizard data,
	// but for consistency, fetching is triggered regardless of the section.
	useSelect( select => isInitialFetchTriggered && select( WIZARD_STORE_NAMESPACE ).getWizardAPIData( apiSlug ) );

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
						{ actions?.length > 0 && (
							<div className="newspack-wizard__header__actions">
								{ mainActions.map( ( action, index ) => (
									<Button
										key={ index }
										className="newspack-wizard__header__actions__main"
										href={ action.href }
										icon={ resolveIcon( action.icon ) }
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
									popoverProps={ { className: 'newspack-wizard__header__actions__more' } }
								>
									{ () =>
										actions.map( ( action, index ) => (
											<MenuItem
												key={ index }
												className={
													action.type === 'primary' || action.type === 'secondary'
														? 'newspack-wizard__header__actions__more__main'
														: 'newspack-wizard__header__actions__more__more'
												}
												icon={ action.icon }
												href={ action.href }
												onClick={ action.action }
												disabled={ action.disabled || false }
												isDestructive={ action.destructive || false }
											>
												{ action.label }
											</MenuItem>
										) )
									}
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
														title={ sectionTitle || section.title }
														description={ sectionDescription || section.description }
														badges={ badges || section.badges }
														menu={ sectionMenu || section.menu }
														primaryAction={ sectionPrimaryAction || section.primaryAction }
														secondaryAction={ sectionSecondaryAction || section.secondaryAction }
														heading={ 1 }
														noMargin
													/>
												) }
												<SectionComponent { ...routerProps } { ...sectionProps } { ...sharedProps } />
											</div>
										) }
									/>
								);
							} ) }
							<Redirect to={ displayedSections[ 0 ].path } />
						</Switch>
					</div>
				</HashRouter>
				{ notices?.length > 0 &&
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
