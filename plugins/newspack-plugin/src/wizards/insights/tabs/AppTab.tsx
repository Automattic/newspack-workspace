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
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Notice, Button, Grid, SelectControl } from '../../../../packages/components/src';
import WizardsActionCard from '../../wizards-action-card';
import TabSpinner from './components/TabSpinner';
import ReachSection from './app/ReachSection';
import EngagementSection from './app/EngagementSection';
import RetentionSection from './app/RetentionSection';
import NotificationsSection from './app/NotificationsSection';
import EditionsSection from './app/EditionsSection';
import ContentSection from './app/ContentSection';
import CompositionSection from './app/CompositionSection';
import type { TabSectionProps } from '../components/InsightsWizard';
import './app/app.scss';
import { fetchAppConfig, saveAppProperty, fetchAppMetrics, type AppConfig, type AppProperty, type AppMetrics } from '../api/app';

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

/** Ready state — fetch the windowed metrics for the selected property and render the sections. */
const AppMetricsView = ( { range }: { range: TabSectionProps[ 'range' ] } ) => {
	const [ metrics, setMetrics ] = useState< AppMetrics | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let active = true;
		setLoading( true );
		setError( null );
		fetchAppMetrics( range.start, range.end )
			.then( response => active && setMetrics( response.current ) )
			.catch( e => active && setError( e instanceof Error ? e.message : String( e ) ) )
			.finally( () => active && setLoading( false ) );
		return () => {
			active = false;
		};
	}, [ range.start, range.end ] );

	if ( loading ) {
		return <TabSpinner className="newspack-insights__tab-fallback" />;
	}
	if ( error ) {
		return <Notice isError noticeText={ error } />;
	}
	if ( ! metrics || metrics.tab_error ) {
		return <Notice noticeText={ __( 'App analytics aren’t available for this property yet.', 'newspack-plugin' ) } />;
	}
	return (
		<div className="newspack-insights__app-tab">
			<ReachSection metrics={ metrics } />
			<EngagementSection metrics={ metrics } />
			<RetentionSection metrics={ metrics } />
			<NotificationsSection metrics={ metrics } />
			<EditionsSection metrics={ metrics } />
			<ContentSection metrics={ metrics } />
			<CompositionSection metrics={ metrics } />
		</div>
	);
};

const AppTab = ( { range }: TabSectionProps ) => {
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

	return (
		<>
			<div className="newspack-insights__app-toolbar">
				<Button variant="link" onClick={ () => setChanging( true ) }>
					{ __( 'Change app property', 'newspack-plugin' ) }
				</Button>
			</div>
			<AppMetricsView range={ range } />
		</>
	);
};

export default AppTab;
