/**
 * InsightsWizard
 *
 * Top-level chrome for the Newspack Insights wizard. Owns active tab,
 * date range, and comparison-mode state; renders header (title, date
 * picker, comparison toggle), tab navigation, and the lazy-loaded tab
 * content. Per-tab cache freshness ("Last updated …") lives inside
 * each tab's first section heading, not the global header.
 *
 * Tab routing happens entirely client-side via URL query persistence so
 * tabs are linkable and refresh restores state.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ComparisonToggle from './ComparisonToggle';
import DateRangePicker from './DateRangePicker';
import TabContent from './TabContent';
import TabNavigation, { ALL_TABS, type TabKey, type TabVisibility } from './TabNavigation';
import useComparisonMode from '../state/useComparisonMode';
import useDateRange, { type DateRange } from '../state/useDateRange';
import { RefreshRegistryProvider } from '../state/refreshRegistry';

export interface InsightsBootConfig {
	tabs: TabVisibility;
	defaultDateRange: DateRange;
	defaultComparison: boolean;
	timezone: string;
	settingsUrl: string;
}

export interface InsightsWizardProps {
	config: InsightsBootConfig;
}

const TAB_KEYS = ALL_TABS.map( t => t.key );

const isTabKey = ( v: unknown ): v is TabKey => typeof v === 'string' && ( TAB_KEYS as readonly string[] ).includes( v );

/**
 * The list of visible tabs derived from the boot config visibility map.
 */
const getVisibleTabs = ( visibility: TabVisibility ): TabKey[] => TAB_KEYS.filter( k => visibility[ k as TabKey ] ) as TabKey[];

/**
 * Read initial active tab from URL ?tab=, falling back to the first
 * visible tab. Returns null if no tabs are visible — caller renders an
 * empty state in that case rather than forcing an arbitrary tab key.
 */
const readInitialTab = ( visibility: TabVisibility, visibleTabs: TabKey[] ): TabKey | null => {
	if ( visibleTabs.length === 0 ) {
		return null;
	}
	if ( typeof window === 'undefined' ) {
		return visibleTabs[ 0 ];
	}
	const fromUrl = new URLSearchParams( window.location.search ).get( 'tab' );
	if ( isTabKey( fromUrl ) && visibility[ fromUrl ] ) {
		return fromUrl;
	}
	return visibleTabs[ 0 ];
};

const writeTabToUrl = ( tab: TabKey ) => {
	if ( typeof window === 'undefined' ) {
		return;
	}
	const params = new URLSearchParams( window.location.search );
	params.set( 'tab', tab );
	const next = `${ window.location.pathname }?${ params.toString() }${ window.location.hash }`;
	window.history.replaceState( window.history.state, '', next );
};

const InsightsWizard = ( { config }: InsightsWizardProps ) => {
	const visibleTabs = getVisibleTabs( config.tabs );
	const initialTab = readInitialTab( config.tabs, visibleTabs );

	const [ activeTab, setActiveTabState ] = useState< TabKey | null >( () => initialTab );

	const setActiveTab = useCallback( ( tab: TabKey ) => {
		setActiveTabState( tab );
	}, [] );

	useEffect( () => {
		if ( activeTab ) {
			writeTabToUrl( activeTab );
		}
	}, [ activeTab ] );

	const { range, setPreset, setCustom } = useDateRange( {
		defaultRange: config.defaultDateRange,
	} );

	const {
		enabled: comparisonEnabled,
		setEnabled: setComparisonEnabled,
		previousRange,
	} = useComparisonMode( {
		defaultEnabled: config.defaultComparison,
		currentRange: range,
	} );

	const hasVisibleTabs = visibleTabs.length > 0;

	return (
		<RefreshRegistryProvider>
			<div className="newspack-insights">
				<header className="newspack-insights__header">
					<div className="newspack-insights__header-left">
						<h1 className="newspack-insights__title">{ __( 'Insights', 'newspack-plugin' ) }</h1>
					</div>
					<div className="newspack-insights__header-right">
						{ hasVisibleTabs && (
							<>
								<DateRangePicker range={ range } onPresetChange={ setPreset } onCustomChange={ setCustom } />
								<ComparisonToggle enabled={ comparisonEnabled } onChange={ setComparisonEnabled } />
							</>
						) }
					</div>
				</header>

				{ hasVisibleTabs && activeTab ? (
					<>
						<TabNavigation activeTab={ activeTab } visibility={ config.tabs } onTabChange={ setActiveTab } />
						<TabContent activeTab={ activeTab } range={ range } previousRange={ previousRange } />
					</>
				) : (
					<div className="newspack-insights__empty" role="status">
						<h2 className="newspack-insights__empty-title">{ __( 'No insights sections available', 'newspack-plugin' ) }</h2>
						<p className="newspack-insights__empty-message">
							{ __(
								'Insights sections light up as data sources become available for this site. Check back after you have receivers configured, or visit Settings to configure data sources.',
								'newspack-plugin'
							) }
						</p>
					</div>
				) }
			</div>
		</RefreshRegistryProvider>
	);
};

export default InsightsWizard;
