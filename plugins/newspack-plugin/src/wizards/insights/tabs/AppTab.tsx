/**
 * AppTab (Tab 10, NPPD-1882).
 *
 * Mobile-app analytics for Pugpig app publishers, live from the GA4 Data API
 * against a publisher-selected app property. Drives the connect → select → render
 * state machine and, once ready, fetches the windowed metrics and renders the
 * sections (Reach so far; Engagement, Notifications, Editions follow).
 *
 * States:
 *   1. loading           — fetching config
 *   2. not connected      — CTA to Newspack → Connections → Google (NOT Site Kit)
 *   3. connected, no prop — property picker (spans all accounts the identity sees)
 *   4. ready              — fetch windowed metrics → render sections
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Notice, Button, Grid, SelectControl } from '../../../../packages/components/src';
import WizardsActionCard from '../../wizards-action-card';
import TabSpinner from './components/TabSpinner';
import LastUpdated from '../components/LastUpdated';
import useAppMetricsData from '../hooks/useAppMetricsData';
import ReachSection from './app/ReachSection';
import EngagementSection from './app/EngagementSection';
import RetentionSection from './app/RetentionSection';
import NotificationsSection from './app/NotificationsSection';
import EditionsSection from './app/EditionsSection';
import ContentSection from './app/ContentSection';
import CompositionSection from './app/CompositionSection';
import type { TabSectionProps } from '../components/InsightsWizard';
import './app/app.scss';
import { fetchAppConfig, saveAppProperty, type AppConfig, type AppProperty } from '../api/app';

/** Label a property option so a separate (e.g. Firebase) account is distinguishable. */
const propertyLabel = ( p: AppProperty ): string =>
	p.account_name && p.account_name !== p.property_name
		? `${ p.account_name } → ${ p.property_name } (${ p.property_id })`
		: `${ p.property_name } (${ p.property_id })`;

/** Connect state — points at Newspack → Connections → Google, not Site Kit. */
const ConnectState = ( { settingsUrl }: { settingsUrl: string } ) => (
	<Notice
		isWarning
		className="newspack-insights__connect-banner"
		noticeText={
			<>
				{ __( 'Connect Google in Newspack → Connections to see your app analytics.', 'newspack-plugin' ) }{ ' ' }
				<Button variant="link" href={ settingsUrl || 'admin.php?page=newspack-settings' }>
					{ __( 'Go to Connections →', 'newspack-plugin' ) }
				</Button>
			</>
		}
	/>
);

/** Property picker — choose which GA4 property holds the app's analytics. */
const PropertyPicker = ( { config, onSaved }: { config: AppConfig; onSaved: ( next: AppConfig ) => void } ) => {
	const [ value, setValue ] = useState< string >( config.selected_property ?? '' );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const options = [
		{ label: __( 'Select an app property…', 'newspack-plugin' ), value: '' },
		...config.properties.map( p => ( { label: propertyLabel( p ), value: p.property_id } ) ),
	];

	const save = async () => {
		setSaving( true );
		setError( null );
		try {
			onSaved( await saveAppProperty( value ) );
		} catch ( e ) {
			setError( e instanceof Error ? e.message : __( 'Could not save the selected property.', 'newspack-plugin' ) );
		} finally {
			setSaving( false );
		}
	};

	return (
		<WizardsActionCard
			isMedium
			title={ __( 'App analytics property', 'newspack-plugin' ) }
			description={ __(
				'Choose the Google Analytics property that receives your app’s data. It may be in a different account than your website.',
				'newspack-plugin'
			) }
			actionContent={
				<Button variant="primary" onClick={ save } disabled={ saving || '' === value }>
					{ saving ? __( 'Saving…', 'newspack-plugin' ) : __( 'Save property', 'newspack-plugin' ) }
				</Button>
			}
			error={ error || config.properties_error || null }
		>
			<Grid noMargin rowGap={ 16 }>
				<SelectControl
					label={ __( 'App analytics property', 'newspack-plugin' ) }
					hideLabelFromVision
					value={ value }
					options={ options }
					onChange={ setValue }
					disabled={ saving }
				/>
			</Grid>
		</WizardsActionCard>
	);
};

/** Ready state — read the windowed metrics (via the shared cache) and render the sections. */
const AppMetricsView = ( { range, previousRange }: Pick< TabSectionProps, 'range' | 'previousRange' > ) => {
	const { status, data, error } = useAppMetricsData( range, previousRange );
	const current = data?.current ?? null;
	// Only surface period-over-period deltas when the comparison toggle is on.
	// (Fixture mode returns a `previous` window unconditionally, so gate here.)
	const previous = previousRange ? data?.previous ?? null : null;

	if ( status === 'error' ) {
		return <Notice isError noticeText={ error || __( 'Could not load app analytics.', 'newspack-plugin' ) } />;
	}
	// `idle`/`loading` before the first payload lands: show the spinner rather
	// than briefly flashing the "not available" notice (the cache slot starts
	// idle with null data, before the fetch effect runs).
	if ( ! current ) {
		return <TabSpinner className="newspack-insights__tab-fallback" />;
	}
	if ( current.tab_error ) {
		return <Notice noticeText={ __( 'App analytics aren’t available for this property yet.', 'newspack-plugin' ) } />;
	}

	// The shared "Last updated: … + kebab (Refresh / Print / Export JSON)" chrome,
	// hosted in the first section's heading like the other data tabs.
	const lastUpdated = <LastUpdated tab="app" range={ range } previousRange={ previousRange } />;

	return (
		<div className="newspack-insights__app-tab">
			{ /* Ordered as a narrative: scale (Reach) → depth (Engagement) → what
			     they read (Content) → who they are (Audience) → loyalty (Retention)
			     → the app-ops channels (Notifications, Editions) last. */ }
			<ReachSection metrics={ current } previous={ previous } lastUpdated={ lastUpdated } />
			<EngagementSection metrics={ current } previous={ previous } />
			<ContentSection metrics={ current } />
			<CompositionSection metrics={ current } />
			<RetentionSection metrics={ current } />
			<NotificationsSection metrics={ current } previous={ previous } />
			<EditionsSection metrics={ current } previous={ previous } />
		</div>
	);
};

const AppTab = ( { range, previousRange }: TabSectionProps ) => {
	const [ config, setConfig ] = useState< AppConfig | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ changing, setChanging ] = useState( false );

	useEffect( () => {
		let active = true;
		fetchAppConfig()
			.then( c => active && setConfig( c ) )
			.catch( e => active && setError( e instanceof Error ? e.message : String( e ) ) )
			.finally( () => active && setLoading( false ) );
		return () => {
			active = false;
		};
	}, [] );

	if ( loading ) {
		return <TabSpinner className="newspack-insights__tab-fallback" />;
	}

	if ( error || ! config ) {
		return <Notice isError noticeText={ error || __( 'Could not load the App tab.', 'newspack-plugin' ) } />;
	}

	if ( ! config.connected ) {
		return <ConnectState settingsUrl={ config.settings_url } />;
	}

	// No property chosen, the saved one is no longer visible, or the user asked
	// to change it → show the picker.
	if ( ! config.selected_property || ! config.selected_is_visible || changing ) {
		return (
			<PropertyPicker
				config={ config }
				onSaved={ next => {
					setConfig( next );
					setChanging( false );
				} }
			/>
		);
	}

	// Name the property the tab is reading — it often lives in a different Google
	// account than the website, so surfacing which one confirms you're looking at
	// the right data. `selected_is_visible` guarantees it's in the list here.
	const selected = config.properties.find( property => property.property_id === config.selected_property );

	return (
		<>
			<div className="newspack-insights__app-toolbar">
				<span className="newspack-insights__app-property">
					{ sprintf(
						/* translators: %s: the selected GA4 app property (name and id). */
						__( 'App property: %s', 'newspack-plugin' ),
						selected ? propertyLabel( selected ) : config.selected_property
					) }
				</span>
				<span className="newspack-insights__app-property-sep" aria-hidden="true">
					·
				</span>
				<Button variant="link" onClick={ () => setChanging( true ) }>
					{ __( 'Change', 'newspack-plugin' ) }
				</Button>
			</div>
			<AppMetricsView range={ range } previousRange={ previousRange } />
		</>
	);
};

export default AppTab;
