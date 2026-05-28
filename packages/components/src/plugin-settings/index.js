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

class PluginSettings extends Component {
	constructor() {
		super( ...arguments );
		this.state = {
			inFlight: false,
			settings: {},
			error: null,
		};
	}

	fetchSettings = () => {
		const { afterFetch, pluginSlug, isWizard } = this.props;
		this.setState( { inFlight: true } );
		apiFetch( {
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

	getSettingsValues = sectionKey => {
		return (
			this.state.settings[ sectionKey ]?.reduce( ( map, setting ) => {
				map[ setting.key ] = setting.value;
				return map;
			}, {} ) || {}
		);
	};

	handleSettingChange = sectionKey => ( key, value ) => {
		const sectionSettings = [ ...this.state.settings[ sectionKey ] ];
		sectionSettings.forEach( setting => {
			if ( setting.key === key ) {
				setting.value = value;
			}
		} );
		this.setState( {
			settings: {
				...this.state.settings,
				[ sectionKey ]: sectionSettings,
			},
		} );
	};

	handleSectionUpdate = sectionKey => data => {
		const { afterUpdate, pluginSlug, isWizard } = this.props;
		this.setState( { inFlight: true } );
		apiFetch( {
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
	 * @param {string} sectionKey The section name.
	 * @return {Object} The section setting.
	 */
	getSectionInfo = sectionKey => {
		return this.state.settings[ sectionKey ]?.find( setting => ! setting.key || setting.key === 'active' );
	};

	/**
	 * Get the section title.
	 *
	 * @param {string} sectionKey The section name.
	 * @return {string} The section title.
	 */
	getSectionTitle = sectionKey => {
		return this.getSectionInfo( sectionKey )?.description;
	};

	/**
	 * Get the section description.
	 *
	 * @param {string} sectionKey The section name.
	 * @return {string} The section description.
	 */
	getSectionDescription = sectionKey => {
		return this.getSectionInfo( sectionKey )?.help;
	};

	/**
	 * Get whether a section is active.
	 *
	 * @param {string} sectionKey The section name.
	 * @return {?boolean} Whether the section is active or not. Null if the section is not found or does not support activation.
	 */
	isSectionActive = sectionKey => {
		const { settings } = this.state;
		const activation = settings[ sectionKey ]?.find( setting => setting.key === 'active' );
		if ( ! activation ) {
			return null;
		}
		return activation.value;
	};

	/**
	 * Get list of section field settings.
	 *
	 * @param {string} sectionKey The section name.
	 * @return {?Array} List of section fields.
	 */
	getSectionFields = sectionKey => {
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

PluginSettings.defaultProps = {
	title: __( 'General Settings', 'newspack-plugin' ),
};

PluginSettings.Section = SettingsSection;

export default PluginSettings;
