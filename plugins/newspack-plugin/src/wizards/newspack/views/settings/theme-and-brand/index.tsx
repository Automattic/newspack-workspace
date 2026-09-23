/**
 * Newspack > Settings > Theme and Brand
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useRef, Fragment } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import ThemeSelection from './theme-select';
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import { HomepageSelect } from './homepage-select';
import { Button, Divider, Grid, Router, SectionHeader, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import Header from './header';
import Footer from './footer';
import Colors from './colors';
import Logos from './logos';
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
	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/theme-mods' );
	const { setHeaderData, addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ data, setDataState ] = useState< ThemeData >( DEFAULT_DATA );
	const [ savedData, setSavedData ] = useState< ThemeData >( DEFAULT_DATA );

	const history = useHistory();

	function setData( newData: ThemeData ) {
		setDataState( { ...data, ...newData } );
	}

	function setFetchedData( res: ThemeData ) {
		setData( res );
		setSavedData( { ...data, ...res } );
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
		resetError();
		const requestData = data;
		return new Promise( ( resolve, reject ) =>
			wizardApiFetch(
				{
					data: requestData,
					path: '/newspack/v1/wizard/newspack-setup-wizard/theme',
					method: 'POST',
					updateCacheMethods: [ 'GET' ],
				},
				{
					onSuccess: res => {
						const saved = { ...requestData, ...res };
						setSavedData( saved );
						// Controls stay editable mid-request; keep any edit made meanwhile, unsaved.
						setDataState( current => ( current === requestData ? saved : current ) );
						if ( ! isPartOfSetup ) {
							removeNotice( 'theme-and-brand-saved' );
							addNotice( {
								id: 'theme-and-brand-saved',
								type: 'success',
								message: __( 'Settings saved.', 'newspack-plugin' ),
							} );
						}
						resolve( res );
					},
				}
			).catch( reject )
		);
	}

	useEffect( () => {
		wizardApiFetch(
			{
				path: '/newspack/v1/wizard/newspack-setup-wizard/theme',
			},
			{
				onSuccess: setFetchedData,
			}
		);
	}, [] );

	// The header keeps whichever callback it was handed, so publishing `save`
	// directly would pin the state of the render that published it.
	const submit = useRef( save );
	submit.current = save;

	const isDirty = data.theme !== savedData.theme || JSON.stringify( data.theme_mods ) !== JSON.stringify( savedData.theme_mods );

	useEffect( () => {
		if ( isPartOfSetup ) {
			return;
		}
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: () => submit.current().catch( () => {} ),
					disabled: isFetching || ! isDirty,
				},
				{
					type: 'more',
					label: __( 'Open Customizer', 'newspack-plugin' ),
					href: `customize.php?return=${ encodeURIComponent( window.location.href ) }`,
				},
			],
		} );
	}, [ isPartOfSetup, isFetching, isDirty, setHeaderData ] );

	const { confirmDialog: navBlockDialog } = useUnsavedChangesDialog( {
		when: ! isPartOfSetup && isDirty && ! isFetching,
	} );

	const updateThemeMods = ( theme_mods: ThemeMods ) => setData( { ...data, theme_mods } );

	const sections = [
		{
			key: 'logos',
			title: __( 'Logos', 'newspack-plugin' ),
			description: __( 'Upload your site logo and an optional alternative for the footer.', 'newspack-plugin' ),
			content: <Logos themeMods={ data.theme_mods } onUpdate={ updateThemeMods } />,
		},
		{
			key: 'colors',
			title: __( 'Colors', 'newspack-plugin' ),
			description: __( 'Pick your primary and secondary colors.', 'newspack-plugin' ),
			content: <Colors themeMods={ data.theme_mods } updateColors={ updateThemeMods } />,
		},
		{
			key: 'typography',
			title: __( 'Typography', 'newspack-plugin' ),
			description: __( 'Define the font pairing to use throughout your site.', 'newspack-plugin' ),
			content: <Typography data={ data.theme_mods } update={ updateThemeMods } />,
		},
		{
			key: 'header',
			title: __( 'Header', 'newspack-plugin' ),
			description: __( 'Set the header layout and background.', 'newspack-plugin' ),
			content: <Header themeMods={ data.theme_mods } updateHeader={ updateThemeMods } />,
		},
		{
			key: 'footer',
			title: __( 'Footer', 'newspack-plugin' ),
			description: __( 'Personalize the footer of your site.', 'newspack-plugin' ),
			content: <Footer themeMods={ data.theme_mods } onUpdate={ updateThemeMods } />,
		},
	];

	return (
		<WizardsTab isFetching={ isFetching }>
			{ navBlockDialog }
			{ errorMessage && (
				<Notice status="error" isDismissible={ false } politeness="polite">
					{ errorMessage }
				</Notice>
			) }
			{ ! isPartOfSetup && (
				<WizardSection title={ __( 'Theme', 'newspack-plugin' ) } description={ __( 'Update your site’s theme.', 'newspack-plugin' ) }>
					<ThemeSelection
						theme={ isFetching ? '' : data.theme || 'newspack-theme' }
						updateTheme={ theme => setData( { ...data, theme } ) }
					/>
				</WizardSection>
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
			{ sections.map( ( { key, title, description, content } ) => (
				<Fragment key={ key }>
					<Divider alignment="full-width" variant="tertiary" />
					<Grid columns={ 2 } gutter={ 32 } noMargin>
						<SectionHeader noMargin heading={ 2 } title={ title } description={ description } />
						{ content }
					</Grid>
				</Fragment>
			) ) }
			{ isPartOfSetup && (
				<div className="newspack-buttons-card">
					<Button variant="primary" onClick={ () => save().then( finishSetup, () => {} ) }>
						{ __( 'Finish', 'newspack-plugin' ) }
					</Button>
				</div>
			) }
		</WizardsTab>
	);
};

export default ThemeBrand;
