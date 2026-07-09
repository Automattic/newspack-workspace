/**
 * InsightsWizard
 *
 * Top-level chrome for the Newspack Insights wizard. Adopts the
 * design-system `Wizard` so the page shell (header, tab nav, footer)
 * matches every other Newspack admin wizard. Insights still owns its
 * own date range / comparison-mode state and per-tab data fetching;
 * the Wizard's @wordpress/data auto-fetch is opted out via
 * `isInitialFetchTriggered={ false }`.
 *
 * Routing is hash-based (`#/audience`) via the Wizard's internal
 * HashRouter. A one-shot effect on mount rewrites any legacy
 * `?tab=<key>` query into the new hash form so existing bookmarks
 * and shared links still land on the right tab.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Component, lazy, Suspense, useEffect } from '@wordpress/element';
import type { ErrorInfo, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { Wizard } from '../../../../packages/components/src';
import CooldownNotice from './CooldownNotice';
import HeaderControls from './HeaderControls';
import PrintDocumentMeta from './PrintDocumentMeta';
import TabFeedback from './TabFeedback';
import NextSteps from './NextSteps';
import FeedbackShipCallback from './FeedbackShipCallback';
import TabSpinner from '../tabs/components/TabSpinner';
import usePendingControls from '../state/usePendingControls';
import type { DateRange } from '../state/useDateRange';
import { RefreshRegistryProvider } from '../state/refreshRegistry';

export type TabKey =
	| 'audience'
	| 'engagement'
	| 'conversion'
	| 'gates'
	| 'prompts'
	| 'subscribers'
	| 'donors'
	| 'advertising'
	| 'newsletter_ads'
	| 'app';

export interface TabDef {
	key: TabKey;
	label: string;
}

/** A single outcome-worded "next steps" link (NPPD-1842). */
export interface NextStepLink {
	/** Outcome-worded label, e.g. "Grow reader revenue" — never a generic "Help". */
	label: string;
	/** Absolute help-site URL. */
	url: string;
}

export const ALL_TABS: TabDef[] = [
	{ key: 'audience', label: __( 'Audience', 'newspack-plugin' ) },
	{ key: 'engagement', label: __( 'Engagement', 'newspack-plugin' ) },
	{ key: 'conversion', label: __( 'Conversion Journey', 'newspack-plugin' ) },
	{ key: 'gates', label: __( 'Gates', 'newspack-plugin' ) },
	{ key: 'prompts', label: __( 'Campaigns', 'newspack-plugin' ) },
	{ key: 'subscribers', label: __( 'Subscribers', 'newspack-plugin' ) },
	{ key: 'donors', label: __( 'Donors', 'newspack-plugin' ) },
	{ key: 'advertising', label: __( 'Advertising', 'newspack-plugin' ) },
	{ key: 'newsletter_ads', label: __( 'Newsletter Ads', 'newspack-plugin' ) },
	{ key: 'app', label: __( 'App', 'newspack-plugin' ) },
];

export type TabVisibility = Record< TabKey, boolean >;

export interface InsightsBootConfig {
	tabs: TabVisibility;
	defaultDateRange: DateRange;
	defaultComparison: boolean;
	timezone: string;
	adminUrl?: string;
	settingsUrl: string;
	siteKitUrl: string;
	lastUpdated: null | string;
	/** Site title, rendered in the PDF export document header (NPPD-1661). */
	publisherName: string;
	/** Absolute REST URL for the feedback endpoint (NPPD-1728 abandon beacon). */
	feedbackBeaconUrl: string;
	/** `wp_rest` nonce for the abandon beacon (rides as a `_wpnonce` query param). */
	feedbackBeaconNonce: string;
	/**
	 * Per-tab outcome-worded "next steps" links, rendered as a strip in the tab
	 * footer below the metrics (NPPD-1842). Product-owned mapping supplied by
	 * `get_next_steps_links()` in class-insights-wizard.php. Tabs with no strong
	 * outcome match (Gates, Campaigns, Advertising in v1) are simply absent from
	 * the map and render no strip. Optional so config fixtures need not set it.
	 */
	nextStepsLinks?: Partial< Record< TabKey, NextStepLink[] > >;
	/**
	 * Site WooCommerce currency code (e.g. "USD", "CAD") — Woo revenue is
	 * formatted in this currency (DSGNEWS-188). Optional so config fixtures need
	 * not set it; the formatter falls back to USD.
	 */
	currency?: string;
}

export interface InsightsWizardProps {
	config: InsightsBootConfig;
}

const TAB_KEYS = ALL_TABS.map( t => t.key );

const isTabKey = ( v: unknown ): v is TabKey => typeof v === 'string' && ( TAB_KEYS as readonly string[] ).includes( v );

/**
 * Per-tab lazy chunks. The wizard now routes per tab so each chunk
 * only loads on demand when the user navigates to that hash.
 */
const AudienceTab = lazy( () => import( '../tabs/AudienceTab' ) );
const EngagementTab = lazy( () => import( '../tabs/EngagementTab' ) );
const ConversionTab = lazy( () => import( '../tabs/ConversionTab' ) );
const GatesTab = lazy( () => import( '../tabs/GatesTab' ) );
const PromptsTab = lazy( () => import( '../tabs/PromptsTab' ) );
const SubscribersTab = lazy( () => import( '../tabs/SubscribersTab' ) );
const DonorsTab = lazy( () => import( '../tabs/DonorsTab' ) );
const AdvertisingTab = lazy( () => import( '../tabs/AdvertisingTab' ) );
const NewsletterAdsTab = lazy( () => import( '../tabs/NewsletterAdsTab' ) );
const AppTab = lazy( () => import( '../tabs/AppTab' ) );

/**
 * Props every tab component receives. Exported so each tab module can
 * type its own props from the same source.
 */
export interface TabSectionProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const Fallback = () => <TabSpinner className="newspack-insights__tab-fallback" />;

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

const renderTabComponent = ( tabKey: TabKey, sectionProps: TabSectionProps ): ReactNode => {
	switch ( tabKey ) {
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
		case 'newsletter_ads':
			return <NewsletterAdsTab { ...sectionProps } />;
		case 'app':
			return <AppTab { ...sectionProps } />;
		default:
			return null;
	}
};

interface TabSectionRenderProps extends TabSectionProps {
	tabKey: TabKey;
	tabLabel: string;
	publisherName: string;
	nextStepsLinks: NextStepLink[];
	feedbackBeaconUrl: string;
	feedbackBeaconNonce: string;
}

/**
 * Per-tab wrapper rendered by the Wizard. Carries the print-only document
 * header/footer (NPPD-1661 — used by the "Print / Save as PDF" export), the
 * CooldownNotice, the feedback ship-callback (NPPD-1728, top of tab), the
 * error boundary (keyed by tab so a chunk-load error clears when the user
 * navigates away), the Suspense boundary for the lazy tab chunk, and the
 * per-tab feedback footer (NPPD-1728). The feedback footer sits outside the
 * error boundary so it still renders — carrying the same `tabKey` as
 * `context` — even when a tab chunk fails to load (feedback about a broken tab
 * is still signal).
 */
const TabSection = ( {
	tabKey,
	tabLabel,
	publisherName,
	range,
	previousRange,
	nextStepsLinks,
	feedbackBeaconUrl,
	feedbackBeaconNonce,
}: TabSectionRenderProps ) => (
	<>
		<PrintDocumentMeta tabLabel={ tabLabel } publisherName={ publisherName } range={ range } previousRange={ previousRange } />
		<CooldownNotice tab={ tabKey } range={ range } previousRange={ previousRange } />
		<FeedbackShipCallback context={ tabKey } />
		<NextSteps links={ nextStepsLinks } />
		<TabErrorBoundary key={ tabKey }>
			<Suspense fallback={ <Fallback /> }>{ renderTabComponent( tabKey, { range, previousRange } ) }</Suspense>
		</TabErrorBoundary>
		<footer className="newspack-insights__tab-footer">
			<TabFeedback context={ tabKey } beaconUrl={ feedbackBeaconUrl } beaconNonce={ feedbackBeaconNonce } />
		</footer>
	</>
);

const InsightsWizard = ( { config }: InsightsWizardProps ) => {
	const controls = usePendingControls( {
		defaultRange: config.defaultDateRange,
		defaultComparison: config.defaultComparison,
	} );

	// Backwards-compat: rewrite legacy ?tab=X URLs to #/X so existing
	// bookmarks and shared links land on the right tab. One-shot on mount.
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return;
		}
		const params = new URLSearchParams( window.location.search );
		const fromQuery = params.get( 'tab' );
		if ( ! fromQuery || ! isTabKey( fromQuery ) || ! config.tabs[ fromQuery ] ) {
			return;
		}
		params.delete( 'tab' );
		const search = params.toString();
		const next = `${ window.location.pathname }${ search ? '?' + search : '' }#/${ fromQuery }`;
		window.history.replaceState( window.history.state, '', next );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const visibleTabs = ALL_TABS.filter( t => config.tabs[ t.key ] );

	if ( visibleTabs.length === 0 ) {
		return (
			<div className="newspack-insights">
				<div className="newspack-insights__empty" role="status">
					<h2 className="newspack-insights__empty-title">{ __( 'No insights sections available', 'newspack-plugin' ) }</h2>
					<p className="newspack-insights__empty-message">
						{ __(
							'Insights sections light up as data sources become available for this site. Check back after you have receivers configured, or visit Settings to configure data sources.',
							'newspack-plugin'
						) }
					</p>
				</div>
			</div>
		);
	}

	// Pass range / previousRange through each section's `props` map rather
	// than via Wizard's `sharedProps`. Wizard supports both, but its
	// JSDoc typedef omits `sharedProps`, so TS strict mode rejects the
	// call site. Per-section props are functionally equivalent and read
	// more locally.
	const sections = visibleTabs.map( t => ( {
		path: `/${ t.key }`,
		label: t.label,
		exact: true,
		render: TabSection,
		props: {
			tabKey: t.key,
			tabLabel: t.label,
			range: controls.appliedRange,
			previousRange: controls.appliedPreviousRange,
			publisherName: config.publisherName,
			nextStepsLinks: config.nextStepsLinks?.[ t.key ] ?? [],
			feedbackBeaconUrl: config.feedbackBeaconUrl,
			feedbackBeaconNonce: config.feedbackBeaconNonce,
		},
	} ) );

	const renderAboveSections = () => <HeaderControls controls={ controls } />;

	return (
		<RefreshRegistryProvider>
			<div className="newspack-insights">
				<Wizard
					headerText={ __( 'Insights', 'newspack-plugin' ) }
					sections={ sections }
					renderAboveSections={ renderAboveSections }
					requiredPlugins={ [] }
					isInitialFetchTriggered={ false }
					hasSimpleFooter
				/>
			</div>
		</RefreshRegistryProvider>
	);
};

export default InsightsWizard;
