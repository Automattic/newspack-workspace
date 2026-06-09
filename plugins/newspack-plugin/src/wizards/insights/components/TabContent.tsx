/**
 * TabContent
 *
 * Lazy-loads the appropriate tab component based on activeTab and renders
 * it inside a Suspense boundary with a skeleton fallback. Each tab is
 * code-split via React.lazy. An ErrorBoundary wraps Suspense so a chunk
 * load failure (deploy mid-session, ad blocker, transient network) shows
 * a recoverable message instead of crashing the wizard.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Component, lazy, Suspense } from '@wordpress/element';
import type { ErrorInfo, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type { TabKey } from './TabNavigation';
import type { DateRange } from '../state/useDateRange';

const AudienceTab = lazy( () => import( '../tabs/AudienceTab' ) );
const EngagementTab = lazy( () => import( '../tabs/EngagementTab' ) );
const ConversionTab = lazy( () => import( '../tabs/ConversionTab' ) );
const GatesTab = lazy( () => import( '../tabs/GatesTab' ) );
const PromptsTab = lazy( () => import( '../tabs/PromptsTab' ) );
const SubscribersTab = lazy( () => import( '../tabs/SubscribersTab' ) );
const DonorsTab = lazy( () => import( '../tabs/DonorsTab' ) );
const AdvertisingTab = lazy( () => import( '../tabs/AdvertisingTab' ) );

/**
 * Props every tab component receives. Exported so each tab module can
 * type its own props from the same source, satisfying strict-mode TS
 * when TabContent passes range / previousRange via the spread below.
 */
export interface TabSectionProps {
	range: DateRange;
	previousRange: DateRange | null;
}

export interface TabContentProps extends TabSectionProps {
	activeTab: TabKey;
}

const Fallback = () => (
	<div className="newspack-insights__tab-fallback" role="status" aria-live="polite">
		{ __( 'Loading…', 'newspack-plugin' ) }
	</div>
);

interface TabErrorBoundaryProps {
	children: ReactNode;
}

interface TabErrorBoundaryState {
	error: Error | null;
}

class TabErrorBoundary extends Component< TabErrorBoundaryProps, TabErrorBoundaryState > {
	state: TabErrorBoundaryState = { error: null };

	static getDerivedStateFromError( error: Error ): TabErrorBoundaryState {
		return { error };
	}

	componentDidCatch( error: Error, info: ErrorInfo ): void {
		// eslint-disable-next-line no-console
		console.error( 'Insights tab failed to load', error, info );
	}

	handleReload = (): void => {
		if ( typeof window !== 'undefined' ) {
			window.location.reload();
		}
	};

	render() {
		if ( this.state.error ) {
			return (
				<div className="newspack-insights__tab-error" role="alert">
					<p>{ __( 'This section could not be loaded.', 'newspack-plugin' ) }</p>
					<button type="button" className="newspack-insights__tab-error-action" onClick={ this.handleReload }>
						{ __( 'Reload the page', 'newspack-plugin' ) }
					</button>
				</div>
			);
		}
		return this.props.children;
	}
}

const TabContent = ( { activeTab, range, previousRange }: TabContentProps ) => {
	const sectionProps: TabSectionProps = { range, previousRange };
	const renderTab = () => {
		switch ( activeTab ) {
			case 'audience':
				return <AudienceTab { ...sectionProps } />;
			case 'engagement':
				return <EngagementTab { ...sectionProps } />;
			case 'conversion':
				return <ConversionTab { ...sectionProps } />;
			case 'gates':
				return <GatesTab { ...sectionProps } />;
			case 'prompts':
				return <PromptsTab { ...sectionProps } />;
			case 'subscribers':
				return <SubscribersTab { ...sectionProps } />;
			case 'donors':
				return <DonorsTab { ...sectionProps } />;
			case 'advertising':
				return <AdvertisingTab { ...sectionProps } />;
			default:
				return null;
		}
	};
	return (
		<div
			className="newspack-insights__tab-content"
			role="tabpanel"
			id={ `newspack-insights-panel-${ activeTab }` }
			aria-labelledby={ `newspack-insights-tab-${ activeTab }` }
		>
			{ /* Keyed by activeTab so switching tabs remounts the boundary and
			     clears any error from a failed chunk load — otherwise the error
			     UI would persist across tabs and lock the content area. */ }
			<TabErrorBoundary key={ activeTab }>
				<Suspense fallback={ <Fallback /> }>{ renderTab() }</Suspense>
			</TabErrorBoundary>
		</div>
	);
};

export default TabContent;
