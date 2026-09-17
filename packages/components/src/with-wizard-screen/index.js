/**
 * WordPress dependencies
 */
import { cloneElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Button, Handoff, Notice, HandoffMessage, TabbedNavigation, Page } from '../';
import { activeBreadcrumbs } from '../wizard/breadcrumbs-select';
import { buttonProps } from '../button-props';
import Router from '../proxied-imports/router';
import './style.scss';

const { useLocation } = Router;

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Derives the active-tab breadcrumb trail from the current route. Only rendered
 * for tabbed wizards, which always mount inside a Router, so calling useLocation
 * here keeps router-free consumers (e.g. standalone multibranded) from crashing.
 *
 * @param {Object}   props          Component props.
 * @param {Array}    props.sections Tabbed navigation sections.
 * @param {Function} props.render   Renders the page given the breadcrumb trail.
 * @return {JSX.Element} The rendered page.
 */
const RouteBreadcrumbs = ( { sections, render } ) => {
	const { pathname } = useLocation();
	return render( activeBreadcrumbs( sections, pathname ) );
};

/**
 * Higher-Order Component to provide plugin management and error handling to Newspack Wizards.
 */
export default function withWizardScreen( WrappedComponent, { hidePrimaryButton, hideHeader } = {} ) {
	const WrappedWithWizardScreen = props => {
		const {
			className,
			buttonText,
			buttonAction,
			buttonDisabled,
			headerText,
			subHeaderText,
			tabbedNavigation,
			breadcrumbItems,
			headerActions,
			secondaryButtonText,
			secondaryButtonAction,
			renderAboveContent,
			disableUpcomingInTabbedNavigation,
		} = props;

		const retrievedButtonProps = buttonProps( buttonAction );
		const retrievedSecondaryButtonProps = buttonProps( secondaryButtonAction );
		const SecondaryCTAComponent = retrievedSecondaryButtonProps.plugin ? Handoff : Button;
		const shouldRenderPrimaryButton = buttonText && buttonAction;
		const shouldRenderSecondaryButton = secondaryButtonText && secondaryButtonAction;
		const renderPrimaryButton = ( overridingProps = {} ) =>
			retrievedButtonProps.plugin ? (
				<Handoff isPrimary { ...retrievedButtonProps } { ...overridingProps }>
					{ buttonText }
				</Handoff>
			) : (
				<Button
					isPrimary={ ! buttonDisabled }
					isSecondary={ !! buttonDisabled }
					disabled={ buttonDisabled }
					// Allow overridingProps to set children.
					// eslint-disable-next-line react/no-children-prop
					children={ buttonText }
					{ ...retrievedButtonProps }
					{ ...overridingProps }
				/>
			);
		const tabbedNavigationRegion = tabbedNavigation && (
			<TabbedNavigation
				disableUpcoming={ disableUpcomingInTabbedNavigation }
				items={ tabbedNavigation.filter( item => ! item.isHiddenInNav ) }
			/>
		);

		const content = (
			<>
				<HandoffMessage />

				<div className={ classnames( 'newspack-wizard newspack-wizard__content', className ) }>
					{ typeof renderAboveContent === 'function' ? renderAboveContent() : null }
					{ <WrappedComponent { ...props } renderPrimaryButton={ renderPrimaryButton } /> }
					{ ( shouldRenderPrimaryButton || shouldRenderSecondaryButton ) && (
						<div className="newspack-buttons-card">
							{ shouldRenderPrimaryButton && ! hidePrimaryButton && renderPrimaryButton() }
							{ shouldRenderSecondaryButton && (
								<SecondaryCTAComponent isSecondary { ...retrievedSecondaryButtonProps }>
									{ secondaryButtonText }
								</SecondaryCTAComponent>
							) }
						</div>
					) }
				</div>
			</>
		);

		const renderPage = crumbs => {
			let pageBreadcrumbs = crumbs ?? [];
			if ( ! pageBreadcrumbs.length && headerText ) {
				pageBreadcrumbs = [ { label: headerText } ];
			}
			return (
				<>
					{ newspack_aux_data.is_debug_mode && <Notice debugMode /> }
					{ hideHeader ? (
						// Without the Page shell the tabs still own the content: it
						// renders inside the active tab's panel.
						<>{ tabbedNavigationRegion ? cloneElement( tabbedNavigationRegion, { content } ) : content }</>
					) : (
						<Page
							breadcrumbItems={ pageBreadcrumbs }
							subTitle={ subHeaderText }
							actions={ headerActions }
							tabbedNavigation={ tabbedNavigationRegion }
						>
							{ content }
						</Page>
					) }
				</>
			);
		};

		if ( hideHeader ) {
			return renderPage();
		}
		if ( breadcrumbItems ) {
			return renderPage( breadcrumbItems );
		}
		if ( tabbedNavigation ) {
			return <RouteBreadcrumbs sections={ tabbedNavigation } render={ renderPage } />;
		}
		return renderPage();
	};
	return WrappedWithWizardScreen;
}
