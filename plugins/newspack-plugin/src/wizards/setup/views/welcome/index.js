/**
 * External dependencies.
 */
import omit from 'lodash/omit';
import times from 'lodash/times';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useRef, useEffect, useState } from '@wordpress/element';
import { Icon, addCard, check, info, layout } from '@wordpress/icons';
import { isURL } from '@wordpress/url';
import { CheckboxControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import {
	ActionCard,
	Button,
	Card,
	GlobalNotices,
	Grid,
	NewspackIcon,
	Notice,
	ProgressBar,
	TextControl,
	withWizardScreen,
} from '../../../../../packages/components/src';
import Router from '../../../../../packages/components/src/proxied-imports/router';
import '../../style.scss';

const { useHistory } = Router;
const POST_COUNT = newspack_aux_data.is_e2e ? 12 : 40;
const STARTER_CONTENT_REQUEST_COUNT = POST_COUNT + 3;

const ERROR_TYPES = {
	plugin_configuration: { message: __( 'Installation', 'newspack' ) },
	starter_content: { message: __( 'Starter content', 'newspack' ) },
};

const starterContentInit = ( approach, site = '' ) =>
	apiFetch( {
		path: `/newspack/v1/wizard/newspack-setup-wizard/starter-content/init`,
		method: 'post',
		data: { type: approach, site },
	} );

const starterContentFetch = endpoint =>
	apiFetch( {
		path: `/newspack/v1/wizard/newspack-setup-wizard/starter-content/${ endpoint }`,
		method: 'post',
	} );

const Welcome = ( { buttonAction } ) => {
	const [ installationProgress, setInstallationProgress ] = useState( 0 );
	const [ softwareInfo, setSoftwareInfo ] = useState( [] );
	const [ isSSL, setIsSSL ] = useState( null );
	const [ shouldInstallStarterContent, setShouldInstallStarterContent ] = useState( true );
	const [ errors, setErrors ] = useState( [] );
	const [ existingSiteURL, setExistingSiteURL ] = useState( '' );
	const [ setupApproach, setSetupApproach ] = useState();

	const isSetupApproachNew = setupApproach === 'generated';
	const isSetupApproachMigrate = setupApproach === 'import';

	const addError = errorInfo => error => setErrors( _errors => [ ..._errors, { ...errorInfo, error } ] );

	const total = ( shouldInstallStarterContent ? STARTER_CONTENT_REQUEST_COUNT : 0 ) + softwareInfo.length;

	useEffect( () => {
		document.body.classList.add( 'newspack-wizard__welcome' );

		apiFetch( { path: '/newspack/v1/wizard/newspack-setup-wizard/initial-check/' } ).then( res => {
			setSoftwareInfo( res.plugins );
			setIsSSL( res.is_ssl );
		} );

		return () => document.body.classList.remove( 'newspack-wizard__welcome' );
	}, [] );

	const increment = () => setInstallationProgress( progress => progress + 1 );

	const install = async () => {
		// Reset state.
		setErrors( [] );
		setInstallationProgress( 0 );
		// Wait 1ms to avoid an immediate "done" state if there's no need to install anything.
		await new Promise( resolve => setTimeout( resolve, 1 ) );
		// Plugins and theme.
		const softwarePromises = softwareInfo.map( item => {
			if ( item.Status === 'active' ) {
				increment();
				return () => Promise.resolve();
			}
			return () =>
				apiFetch( {
					path: `/newspack/v1/plugins/${ item.Slug }/configure/`,
					method: 'POST',
				} )
					.then( increment )
					.catch(
						addError( {
							info: ERROR_TYPES.plugin_configuration,
							item: `${ __( 'Failed to install', 'newspack' ) } ${ item.Name }`,
						} )
					);
		} );
		for ( let i = 0; i < softwarePromises.length; i++ ) {
			await softwarePromises[ i ]();
		}

		if ( ( isSetupApproachNew && shouldInstallStarterContent ) || isSetupApproachMigrate ) {
			await starterContentInit( setupApproach, existingSiteURL )
				.then( increment )
				.catch( err => {
					window.location = '/wp-admin/admin.php?page=newspack-setup-wizard&newspack-notice=_error_' + err.message;
				} );

			// Generate posts.
			await Promise.allSettled(
				times( POST_COUNT, n =>
					starterContentFetch( `post/${ n }` )
						.then( increment )
						.catch(
							addError( {
								info: ERROR_TYPES.starter_content,
								item: __( 'Failed to create a post.', 'newspack' ),
							} )
						)
				)
			);

			// Generate homepage.
			await starterContentFetch( `homepage` )
				.then( increment )
				.catch(
					addError( {
						info: ERROR_TYPES.starter_content,
						item: __( 'Failed to create the homepage.', 'newspack' ),
					} )
				);

			// Generate theme.
			await starterContentFetch( `theme` )
				.then( increment )
				.catch(
					addError( {
						info: ERROR_TYPES.starter_content,
						item: __( 'Failed to activate the theme.', 'newspack' ),
					} )
				);
		}
	};

	const history = useHistory();
	const nextRouteAddress = buttonAction.href;
	const hasErrors = errors.length > 0;
	const isInit = installationProgress === 0;
	const isDone = installationProgress === total && ! hasErrors;
	const redirectCounterRef = useRef();

	const REDIRECT_COUNTER_DURATION = 5;
	const [ redirectCounter, setRedirectCounter ] = useState( REDIRECT_COUNTER_DURATION );
	useEffect( () => {
		if ( redirectCounter === 0 ) {
			clearInterval( redirectCounterRef.current );
			history.push( nextRouteAddress.replace( '#', '' ) );
		}
	}, [ redirectCounter ] );

	useEffect( () => {
		if ( isDone && redirectCounter === REDIRECT_COUNTER_DURATION ) {
			// Trigger redirect countdown.
			redirectCounterRef.current = setInterval( () => {
				setRedirectCounter( counter => counter - 1 );
			}, 1000 );
		}
	}, [ isDone, redirectCounter ] );

	const getHeadingText = () => {
		if ( hasErrors ) {
			return __( 'Installation error', 'newspack' );
		}
		if ( isDone ) {
			return __( 'Installation complete', 'newspack' );
		}
		return __( 'Installing…', 'newspack' );
	};

	const getInfoText = () => {
		if ( hasErrors ) {
			return __(
				'There has been an error during the installation. Please retry or manually install required plugins to continue with the configuration of your site.',
				'newspack'
			);
		}
		if ( isInit ) {
			return __(
				'We will help you get set up by installing the most relevant plugins first before requiring a few details from you in order to build your site.',
				'newspack'
			);
		}
		if ( isDone ) {
			return __( 'Click the button below to start configuring your site.', 'newspack' );
		}
		if ( shouldInstallStarterContent ) {
			return __(
				'We are now installing core plugins and pre-populating your site with categories and placeholder stories to help you pre-configure it. All placeholder content can be deleted later.',
				'newspack'
			);
		}
		return __( 'We are now installing core plugins.', 'newspack' );
	};

	const getHeadingIcon = () => {
		if ( hasErrors ) {
			return <Icon className="newspack--error" icon={ info } />;
		}
		if ( isDone ) {
			return (
				<span className="newspack-checkbox-icon newspack-checkbox-icon--checked">
					<Icon icon={ check } />
				</span>
			);
		}
	};

	const renderErrorBox = ( error, i ) => (
		<ActionCard
			isSmall
			key={ i }
			title={ error.info.message + ': ' + error.item }
			actionText={ __( 'Retry', 'newspack' ) }
			onClick={ install }
			secondaryActionText={ __( 'Skip', 'newspack' ) }
			secondaryDestructive
			onSecondaryActionClick={ () => skipError( i ) }
			className="newspack--error-actioncard"
		/>
	);

	const skipError = i => {
		const updatedErrors = [];
		for ( let j = 0; j < errors.length; ++j ) {
			if ( i !== j ) {
				updatedErrors.push( errors[ j ] );
			}
		}
		setErrors( updatedErrors );
		increment();
	};

	return (
		<>
			{ isInit && (
				<HStack>
					<h1 className="screen-reader-text">{ __( 'Welcome to Newspack', 'newspack' ) }</h1>
					<NewspackIcon simple size={ 64 } />
				</HStack>
			) }

			<Card isNarrow noBorder className={ errors.length === 0 && installationProgress > 0 && ! isDone ? 'loading' : null }>
				<Grid columns={ 1 }>
					{ ! isInit && (
						<h1>
							{ getHeadingIcon() }
							{ getHeadingText() }
						</h1>
					) }

					{ errors.length === 0 && installationProgress > 0 ? <ProgressBar completed={ installationProgress } total={ total } /> : null }

					<p>
						{ getInfoText() }
						{ isDone && (
							<>
								<br />
								<i>
									{ __( 'Automatic redirection in', 'newspack' ) } { redirectCounter } { __( 'seconds…', 'newspack' ) }
								</i>
							</>
						) }
					</p>

					{ isSSL === false && (
						<Notice isError noticeText={ __( "This site does not use HTTPS. Newspack can't be installed.", 'newspack' ) } />
					) }

					{ errors.length ? errors.map( renderErrorBox ) : null }

					{ ( isInit || isDone ) && (
						<>
							<GlobalNotices />
							{ isInit && (
								<>
									<VStack spacing={ 4 }>
										<Card
											__experimentalCoreCard
											isSmall
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Start a new site', 'newspack' ) }</h3>
														<p style={ { margin: 0 } }>{ __( "You don't have content to import", 'newspack' ) }</p>
													</>
												),
												icon: addCard,
												iconBackgroundColor: true,
												actionType: 'chevron',
												onHeaderClick: () => setSetupApproach( 'generated' ),
												isActive: isSetupApproachNew,
											} }
										/>
										<Card
											__experimentalCoreCard
											isSmall
											__experimentalCoreProps={ {
												header: (
													<>
														<h3>{ __( 'Migrate an existing WordPress site', 'newspack' ) }</h3>
														<p style={ { margin: 0 } }>{ __( 'You have content to import', 'newspack' ) }</p>
													</>
												),
												icon: layout,
												iconBackgroundColor: true,
												actionType: 'chevron',
												onHeaderClick: () => setSetupApproach( 'import' ),
												isActive: isSetupApproachMigrate,
											} }
										/>
									</VStack>
									{ isSetupApproachMigrate && (
										<TextControl
											label={ __( 'URL', 'newspack' ) }
											placeholder="https://yourgroovydomain.com/"
											type="url"
											help={ __(
												'We will import the last 50 articles from your existing site to help you with the set up and customization.',
												'newspack'
											) }
											onChange={ val => setExistingSiteURL( val ) }
										/>
									) }
								</>
							) }
							<Card noBorder className="newspack-card__footer">
								{ isInit && isSetupApproachNew && (
									<CheckboxControl
										checked={ shouldInstallStarterContent }
										label={ __( 'Install starter content', 'newspack' ) }
										onChange={ setShouldInstallStarterContent }
									/>
								) }
								{ isInit && ( isSetupApproachNew || isSetupApproachMigrate ) && (
									<Button
										disabled={ ! isSSL || ( isSetupApproachMigrate && ! isURL( existingSiteURL ) ) }
										isPrimary
										onClick={ install }
									>
										{ __( 'Get Started', 'newspack' ) }
									</Button>
								) }
								{ ! isInit && (
									<Button disabled={ ! isSSL } isPrimary href={ isDone ? nextRouteAddress : null }>
										{ __( 'Continue', 'newspack' ) }
									</Button>
								) }
							</Card>
						</>
					) }
				</Grid>
			</Card>
		</>
	);
};

const WelcomeWizardScreen = withWizardScreen( Welcome );
// eslint-disable-next-line react/display-name
export default props => <WelcomeWizardScreen { ...omit( props, [ 'routes', 'headerText', 'buttonText' ] ) } />;
