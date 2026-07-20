/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { Component, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { SectionHeader, Notice } from '../';
import SettingsSection from './SettingsSection';
import type { PluginSettingField, PluginSettingValue } from './SettingsSection';

/**
 * Plugin settings, keyed by section.
 */
type PluginSettingsData = Record< string, PluginSettingField[] >;

type PluginSettingsProps = {
	/** The plugin's slug, used to build the settings API path. */
	pluginSlug: string;
	/** Whether to use the Newspack wizard settings API instead of the plugin's own. */
	isWizard?: boolean;
	/** Called with the fetched settings. */
	afterFetch?: ( settings: PluginSettingsData ) => void;
	/** Called with the updated settings after a section is saved. */
	afterUpdate?: ( settings: PluginSettingsData ) => void;
	/** Title displayed above the settings. `null` suppresses the default title. */
	title?: string | null;
	/** Description displayed under the title. Forwarded to `SectionHeader`, which also accepts a render function. */
	description?: React.ReactNode | ( () => React.ReactNode );
	/** Whether section headers have a grey background. */
	hasGreyHeader?: boolean;
	/** HTML heading level of the title. */
	titleLevel?: 1 | 2 | 3 | 4 | 5 | 6;
	children?: React.ReactNode;
};

type PluginSettingsState = {
	inFlight: boolean;
	settings: PluginSettingsData;
	error: { message?: string } | null;
};

class PluginSettings extends Component< PluginSettingsProps, PluginSettingsState > {
	static defaultProps = {
		title: __( 'General Settings', 'newspack-plugin' ),
	};

	static Section = SettingsSection;

	constructor( props: PluginSettingsProps ) {
		super( props );
		this.state = {
			inFlight: false,
			settings: {},
			error: null,
		};
	}

	fetchSettings = () => {
		const { afterFetch, pluginSlug, isWizard } = this.props;
		this.setState( { inFlight: true } );
		apiFetch< PluginSettingsData >( {
			path: isWizard ? `/newspack/v1/wizard/${ pluginSlug }/settings` : `/${ pluginSlug }/v1/settings`,
		} )
			.then( settings => {
				this.setState( { settings, error: null } );
				if ( 'function' === typeof afterFetch ) {
					afterFetch( settings );
				}
			} )
			.catch( error => {
				this.setState( { error } );
			} )
			.finally( () => {
				this.setState( { inFlight: false } );
			} );
	};

	componentDidMount() {
		this.fetchSettings();
	}

	getSettingsValues = ( sectionKey: string ) => {
		return (
			this.state.settings[ sectionKey ]?.reduce( ( map: Record< string, PluginSettingValue | undefined >, setting ) => {
				map[ String( setting.key ) ] = setting.value;
				return map;
			}, {} ) || {}
		);
	};

	handleSettingChange = ( sectionKey: string ) => ( key: string | undefined, value: unknown ) => {
		const sectionSettings = [ ...this.state.settings[ sectionKey ] ];
		sectionSettings.forEach( setting => {
			if ( setting.key === key ) {
				setting.value = value as PluginSettingValue;
			}
		} );
		this.setState( {
			settings: {
				...this.state.settings,
				[ sectionKey ]: sectionSettings,
			},
		} );
	};

	handleSectionUpdate = ( sectionKey: string ) => ( data?: Record< string, unknown > ) => {
		const { afterUpdate, pluginSlug, isWizard } = this.props;
		this.setState( { inFlight: true } );
		apiFetch< PluginSettingsData >( {
			path: isWizard ? `/newspack/v1/wizard/${ pluginSlug }/settings` : `/${ pluginSlug }/v1/settings`,
			method: 'POST',
			data: {
				section: sectionKey,
				settings: data ? data : this.getSettingsValues( sectionKey ),
			},
		} )
			.then( settings => {
				this.setState( {
					settings: {
						...this.state.settings,
						[ sectionKey ]: settings[ sectionKey ],
					},
					error: null,
				} );
				if ( 'function' === typeof afterUpdate ) {
					afterUpdate( settings );
				}
			} )
			.catch( error => {
				this.setState( { error } );
			} )
			.finally( () => {
				this.setState( { inFlight: false } );
			} );
	};

	/**
	 * Get the section setting containing section information.
	 *
	 * @param sectionKey The section name.
	 * @return The section setting.
	 */
	getSectionInfo = ( sectionKey: string ) => {
		return this.state.settings[ sectionKey ]?.find( setting => ! setting.key || setting.key === 'active' );
	};

	/**
	 * Get the section title.
	 *
	 * @param sectionKey The section name.
	 * @return The section title.
	 */
	getSectionTitle = ( sectionKey: string ) => {
		return this.getSectionInfo( sectionKey )?.description;
	};

	/**
	 * Get the section description.
	 *
	 * @param sectionKey The section name.
	 * @return The section description.
	 */
	getSectionDescription = ( sectionKey: string ) => {
		return this.getSectionInfo( sectionKey )?.help;
	};

	/**
	 * Get whether a section is active.
	 *
	 * @param sectionKey The section name.
	 * @return Whether the section is active or not. Null if the section is not found or does not support activation.
	 */
	isSectionActive = ( sectionKey: string ): boolean | null => {
		const { settings } = this.state;
		const activation = settings[ sectionKey ]?.find( setting => setting.key === 'active' );
		if ( ! activation ) {
			return null;
		}
		return activation.value as boolean;
	};

	/**
	 * Get list of section field settings.
	 *
	 * @param sectionKey The section name.
	 * @return List of section fields.
	 */
	getSectionFields = ( sectionKey: string ) => {
		return this.state.settings[ sectionKey ]?.filter( setting => setting.key && setting.key !== 'active' );
	};

	/**
	 * Render.
	 */
	render() {
		const { title, description, hasGreyHeader, children, titleLevel = 1 } = this.props;
		const { settings, inFlight, error } = this.state;
		return (
			<Fragment>
				{ title && <SectionHeader title={ title } heading={ titleLevel } description={ description } /> }
				{ error && <Notice isError noticeText={ error.message } /> }
				<div
					className={ classnames( 'newspack-plugin-settings', {
						'newspack-wizard-section__is-loading': inFlight && ! Object.keys( settings ).length,
					} ) }
				>
					{ Object.keys( settings ).map( sectionKey => (
						<SettingsSection
							key={ sectionKey }
							disabled={ inFlight }
							id={ `plugin-settings-${ sectionKey }` }
							sectionKey={ sectionKey }
							title={ this.getSectionTitle( sectionKey ) }
							description={ this.getSectionDescription( sectionKey ) }
							active={ this.isSectionActive( sectionKey ) }
							fields={ this.getSectionFields( sectionKey ) }
							onChange={ this.handleSettingChange( sectionKey ) }
							onUpdate={ this.handleSectionUpdate( sectionKey ) }
							hasGreyHeader={ hasGreyHeader }
						/>
					) ) }
					{ children }
				</div>
			</Fragment>
		);
	}
}

export default PluginSettings;
