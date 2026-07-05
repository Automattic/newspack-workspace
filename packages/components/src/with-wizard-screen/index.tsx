/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { category } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Handoff, NewspackIcon, Notice, HandoffMessage, TabbedNavigation } from '../';
import { buttonProps } from '../button-props';
import type { ButtonAction } from '../button-props/button-props';
import type { TabbedNavigationItem } from '../tabbed-navigation';
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Renders the screen's primary button, with optional prop overrides.
 */
export type RenderPrimaryButton = ( overridingProps?: Record< string, unknown > ) => JSX.Element;

export interface WithWizardScreenProps {
	/** Additional CSS class name for the content wrapper. */
	className?: string;
	/** Label of the primary button. */
	buttonText?: React.ReactNode;
	/** Action of the primary button: a URL, a callback, or a handoff descriptor. */
	buttonAction?: ButtonAction;
	/** Whether the primary button is disabled. */
	buttonDisabled?: boolean;
	/** The wizard header text. */
	headerText?: string;
	/** The wizard sub-header text. */
	subHeaderText?: string;
	/** Tabs of the wizard's navigation. */
	tabbedNavigation?: ( TabbedNavigationItem & { isHiddenInNav?: boolean } )[];
	/** Label of the secondary button. */
	secondaryButtonText?: React.ReactNode;
	/** Action of the secondary button: a URL, a callback, or a handoff descriptor. */
	secondaryButtonAction?: ButtonAction;
	/** Renders content between the header and the wrapped component. */
	renderAboveContent?(): React.ReactNode;
	/** Whether to disable the tabs after the active one. */
	disableUpcomingInTabbedNavigation?: boolean;
}

/**
 * Higher-Order Component to provide plugin management and error handling to Newspack Wizards.
 */
export default function withWizardScreen< P extends object >(
	WrappedComponent: React.ComponentType< P & { renderPrimaryButton: RenderPrimaryButton } >,
	{ hidePrimaryButton }: { hidePrimaryButton?: boolean } = {}
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
		return (
			<>
				{ newspack_aux_data.is_debug_mode && <Notice debugMode /> }
				<div className="newspack-wizard__header">
					<div className="newspack-wizard__header__inner">
						<div className="newspack-wizard__title">
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
							<div>
								{ headerText && <h2>{ headerText }</h2> }
								{ subHeaderText && <span>{ subHeaderText }</span> }
							</div>
						</div>
					</div>
				</div>

				{ tabbedNavigation && (
					<TabbedNavigation
						disableUpcoming={ disableUpcomingInTabbedNavigation }
						items={ tabbedNavigation.filter( item => ! item.isHiddenInNav ) }
					/>
				) }

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
	};
	return WrappedWithWizardScreen;
}
