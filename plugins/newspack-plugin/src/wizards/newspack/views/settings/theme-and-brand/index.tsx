/**
 * Newspack > Settings > Theme and Brand
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, Fragment } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import ThemeSelection from './theme-select';
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import { HomepageSelect } from './homepage-select';
import { Button, Router } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import Header from './header';
import Footer from './footer';
import Colors from './colors';
import Typography from './typography';
import { DEFAULT_THEME_MODS } from '../constants';

const { useHistory } = Router;

const DEFAULT_DATA: ThemeData = {
	etc: { post_count: '0' },
	theme: 'newspack-theme',
	homepage_patterns: [],
	theme_mods: { ...DEFAULT_THEME_MODS },
};

const ThemeBrand = ( { isPartOfSetup = false } ) => {
	const { wizardApiFetch, isFetching } = useWizardApiFetch( 'newspack-settings/theme-mods' );
	const [ data, setDataState ] = useState< ThemeData >( DEFAULT_DATA );

	const history = useHistory();

	function setData( newData: ThemeData ) {
		setDataState( { ...data, ...newData } );
	}

	const finishSetup = () => {
		wizardApiFetch(
			{
				data,
				path: '/newspack/v1/wizard/newspack-setup-wizard/complete',
				method: 'POST',
				updateCacheMethods: [ 'GET' ],
			},
			{
				onSuccess: res => {
					setData( res );
					history.push( '/completed' );
				},
			}
		);
	};

	async function save() {
		return new Promise( resolve =>
			wizardApiFetch(
				{
					data,
					path: '/newspack/v1/wizard/newspack-setup-wizard/theme',
					method: 'POST',
					updateCacheMethods: [ 'GET' ],
				},
				{
					onSuccess: res => {
						setData( res );
						resolve( res );
					},
				}
			)
		);
	}

	useEffect( () => {
		wizardApiFetch(
			{
				path: '/newspack/v1/wizard/newspack-setup-wizard/theme',
			},
			{
				onSuccess: setData,
			}
		);
	}, [] );

	return (
		<WizardsTab title={ __( 'Theme and Brand', 'newspack-plugin' ) } isFetching={ isFetching }>
			{ ! isPartOfSetup && (
				<Fragment>
					<WizardSection title={ __( 'Theme', 'newspack-plugin' ) } description={ __( 'Update your sites theme.', 'newspack-plugin' ) }>
						<ThemeSelection
							theme={ isFetching ? '' : data.theme || 'newspack-theme' }
							updateTheme={ theme => setData( { ...data, theme } ) }
						/>
					</WizardSection>
				</Fragment>
			) }
			{ isPartOfSetup && (
				<WizardSection title={ __( 'Homepage', 'newspack-plugin' ) } description={ __( 'Select a homepage layout.', 'newspack-plugin' ) }>
					<HomepageSelect
						isFetching={ isFetching }
						homepagePatternIndex={ data.theme_mods.homepage_pattern_index }
						homepagePatterns={ data.homepage_patterns }
						updateHomepagePattern={ homepage_pattern_index => {
							setData( {
								...data,
								theme_mods: {
									...data.theme_mods,
									homepage_pattern_index,
								},
							} );
						} }
					/>
				</WizardSection>
			) }
			<WizardSection
				title={ __( 'Colors', 'newspack-plugin' ) }
				description={ __( 'Pick your primary and secondary colors.', 'newspack-plugin' ) }
			>
				<Colors
					themeMods={ data.theme_mods }
					updateColors={ theme_mods => {
						setData( {
							...data,
							theme_mods,
						} );
					} }
				/>
			</WizardSection>
			<WizardSection
				title={ __( 'Typography', 'newspack-plugin' ) }
				description={ __( 'Define the font pairing to use throughout your site', 'newspack-plugin' ) }
			>
				<Typography
					data={ data.theme_mods }
					isFetching={ isFetching }
					update={ theme_mods => {
						setData( {
							...data,
							theme_mods,
						} );
					} }
				/>
			</WizardSection>
			<WizardSection
				title={ __( 'Header', 'newspack-plugin' ) }
				description={ __( 'Update the header and add your logo.', 'newspack-plugin' ) }
			>
				<Header
					themeMods={ data.theme_mods }
					updateHeader={ theme_mods => {
						setData( {
							...data,
							theme_mods,
						} );
					} }
				/>
			</WizardSection>
			<WizardSection
				title={ __( 'Footer', 'newspack-plugin' ) }
				description={ __( 'Personalize the footer of your site.', 'newspack-plugin' ) }
			>
				<Footer
					themeMods={ data.theme_mods }
					onUpdate={ theme_mods => {
						setData( {
							...data,
							theme_mods,
						} );
					} }
				/>
			</WizardSection>
			<div className="newspack-buttons-card">
				{ isPartOfSetup ? (
					<Button variant="primary" onClick={ () => save().then( finishSetup ) }>
						{ __( 'Finish', 'newspack-plugin' ) }
					</Button>
				) : (
					<Button variant="primary" onClick={ save }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				) }
			</div>
		</WizardsTab>
	);
};

export default ThemeBrand;
