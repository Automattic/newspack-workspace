/**
 * WordPress dependencies
 */
import { cloneElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Button, Handoff, Notice, HandoffMessage, TabbedNavigation, Page } from '../';
import { activeBreadcrumbs } from '../wizard/breadcrumbs-select';
import type { BreadcrumbSection } from '../wizard/breadcrumbs-select';
import type { BreadcrumbItem } from '../breadcrumbs';
import { buttonProps } from '../button-props';
import type { ButtonAction } from '../button-props/button-props';
import type { TabbedNavigationItem } from '../tabbed-navigation';
import Router from '../proxied-imports/router';
import './style.scss';

const { useLocation } = Router;

/**
 * External dependencies
 */
import classnames from 'classnames';
import type { ReactElement, ReactNode } from 'react';

/**
 * Renders the screen's primary button, with optional prop overrides.
 */
export type RenderPrimaryButton = ( overridingProps?: Record< string, unknown > ) => JSX.Element;

export interface WithWizardScreenProps {
	/** Additional CSS class name for the content wrapper. */
	className?: string;
	/** Label of the primary button. */
	buttonText?: ReactNode;
	/** Action of the primary button: a URL, a callback, or a handoff descriptor. */
	buttonAction?: ButtonAction;
	/** Whether the primary button is disabled. */
	buttonDisabled?: boolean;
	/** The wizard header text. */
	headerText?: string;
	/** The wizard sub-header text. */
	subHeaderText?: ReactNode;
	/** Tabs of the wizard's navigation. */
	tabbedNavigation?: ( TabbedNavigationItem & { isHiddenInNav?: boolean } )[];
	/** Explicit breadcrumb trail, overriding the route-derived one. */
	breadcrumbItems?: BreadcrumbItem[];
	/** Actions rendered in the page header. */
	headerActions?: ReactNode;
	/** Label of the secondary button. */
	secondaryButtonText?: ReactNode;
	/** Action of the secondary button: a URL, a callback, or a handoff descriptor. */
	secondaryButtonAction?: ButtonAction;
	/** Renders content between the header and the wrapped component. */
	renderAboveContent?: () => ReactNode;
	/** Whether to disable the tabs after the active one. */
	disableUpcomingInTabbedNavigation?: boolean;
}

/**
 * Derives the active-tab breadcrumb trail from the current route. Only rendered
 * for tabbed wizards, which always mount inside a Router, so calling useLocation
 * here keeps router-free consumers (e.g. standalone multibranded) from crashing.
 */
const RouteBreadcrumbs = ( { sections, render }: { sections: BreadcrumbSection[]; render: ( crumbs: BreadcrumbItem[] ) => JSX.Element } ) => {
	const { pathname } = useLocation();
	return render( activeBreadcrumbs( sections, pathname ) );
};

/**
 * Higher-Order Component to provide plugin management and error handling to Newspack Wizards.
 * @param WrappedComponent
 * @param root0
 * @param root0.hidePrimaryButton
 * @param root0.hideHeader
 */
export default function withWizardScreen< P extends object >(
	WrappedComponent: React.ComponentType< P & { renderPrimaryButton: RenderPrimaryButton } >,
	{ hidePrimaryButton, hideHeader }: { hidePrimaryButton?: boolean; hideHeader?: boolean } = {}
) {
	const WrappedWithWizardScreen = ( props: P & WithWizardScreenProps ) => {
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

		const retrievedButtonProps = buttonProps( buttonAction || {} );
		const retrievedSecondaryButtonProps = buttonProps( secondaryButtonAction || {} );
		const SecondaryCTAComponent = retrievedSecondaryButtonProps.plugin ? Handoff : Button;
		const shouldRenderPrimaryButton = buttonText && buttonAction;
		const shouldRenderSecondaryButton = secondaryButtonText && secondaryButtonAction;
		const renderPrimaryButton: RenderPrimaryButton = ( overridingProps = {} ) =>
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

		const renderPage = ( crumbs?: BreadcrumbItem[] ) => {
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
						<>{ tabbedNavigationRegion ? cloneElement( tabbedNavigationRegion as ReactElement, { content } ) : content }</>
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
