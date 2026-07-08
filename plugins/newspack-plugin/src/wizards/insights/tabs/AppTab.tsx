/**
 * AppTab (Tab 10, NPPD-1882).
 *
 * Mobile-app analytics for Pugpig app publishers, live from the GA4 Data API
 * against a publisher-selected app property. PR0 implements the connect → select
 * → render state machine; the metric sections land in later PRs.
 *
 * States:
 *   1. loading           — fetching config
 *   2. not connected      — CTA to Newspack → Connections → Google (NOT Site Kit)
 *   3. connected, no prop — property picker (spans all accounts the identity sees)
 *   4. ready              — placeholder for the metric sections (Phase 1)
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
import type { TabSectionProps } from '../components/InsightsWizard';
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

/** Ready state — metric sections land here in Phase 1. */
const ReadyState = ( { config, range }: { config: AppConfig; range: TabSectionProps[ 'range' ] } ) => (
	<div className="newspack-insights__app-ready">
		<Notice
			noticeText={ sprintf(
				// translators: %1$s is a GA4 property id, %2$s and %3$s are dates.
				__( 'App analytics for property %1$s (%2$s – %3$s) will appear here.', 'newspack-plugin' ),
				config.selected_property ?? '',
				range.start,
				range.end
			) }
		/>
	</div>
);

const AppTab = ( { range }: TabSectionProps ) => {
	const [ config, setConfig ] = useState< AppConfig | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

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

	// No property chosen, or the saved one is no longer visible → pick one.
	if ( ! config.selected_property || ! config.selected_is_visible ) {
		return <PropertyPicker config={ config } onSaved={ setConfig } />;
	}

	return <ReadyState config={ config } range={ range } />;
};

export default AppTab;
