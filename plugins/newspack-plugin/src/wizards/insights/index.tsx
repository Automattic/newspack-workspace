/**
 * Newspack Insights wizard React entry (NPPD-1602).
 *
 * Loaded lazily by src/wizards/index.tsx when ?page=newspack-insights.
 * Reads boot config from window.newspackInsights (set by the PHP
 * wizard via wp_localize_script) and mounts InsightsWizard.
 */

/**
 * Internal dependencies
 */
import InsightsWizard, { type InsightsBootConfig } from './components/InsightsWizard';
import './style.scss';

declare global {
	interface Window {
		newspackInsights?: InsightsBootConfig;
	}
}

const FALLBACK_CONFIG: InsightsBootConfig = {
	tabs: {
		audience: true,
		engagement: true,
		conversion: true,
		gates: true,
		prompts: true,
		subscribers: true,
		donors: true,
		advertising: true,
	},
	defaultDateRange: ( () => {
		const pad = ( n: number ) => String( n ).padStart( 2, '0' );
		const toISO = ( d: Date ) => `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
		const today = new Date();
		const thirtyAgo = new Date( today );
		// Inclusive 30-day window ending today: today + 29 prior days = 30 days.
		thirtyAgo.setDate( thirtyAgo.getDate() - 29 );
		return {
			preset: 'last-30' as const,
			start: toISO( thirtyAgo ),
			end: toISO( today ),
		};
	} )(),
	defaultComparison: false,
	timezone: 'UTC',
	settingsUrl: '',
	lastUpdated: null,
};

const Index = () => {
	const config = window.newspackInsights ?? FALLBACK_CONFIG;
	return <InsightsWizard config={ config } />;
};

export default Index;
