/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, ExternalLink, SelectControl, Spinner, TextControl, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ENTER } from '@wordpress/keycodes';
import { dispatch } from '@wordpress/data';

/**
 * External dependencies
 */
import classnames from 'classnames';
import { values } from 'lodash';

const { savePost } = dispatch( 'core/editor' );

export default ( { onSetupStatus } ) => {
	const [ settings, setSettings ] = useState( {} );
	const [ inFlight, setInFlight ] = useState( false );
	const [ errors, setErrors ] = useState( {} );

	const commitSettings = () => {
		setInFlight( true );
		setErrors( {} );
		apiFetch( {
			path: '/newspack-newsletters/v1/settings',
			method: 'POST',
			data: settings,
		} )
			.then( results => {
				window.newspack_newsletters_data.service_provider = results.service_provider;
				savePost().then( () => {
					setInFlight( false );
					setSettings( results );
					onSetupStatus( ! results.status );
				} );
			} )
			.catch( handleErrors );
	};

	const handleErrors = error => {
		if ( 'newspack_rest_forbidden' === error.code ) {
			setInFlight( false );
			setErrors( {
				newspack_newsletters_invalid_keys: __( 'Only administrators can set API keys.', 'newspack-newsletters' ),
			} );
			return;
		}
		const allErrors = { [ error.code ]: error.message };
		( error.additional_errors || [] ).forEach( additionalError => ( allErrors[ additionalError.code ] = additionalError.message ) );
		setErrors( allErrors );
		setInFlight( false );
	};

	useEffect( () => {
		setInFlight( true );
		apiFetch( { path: `/newspack-newsletters/v1/settings` } )
			.then( results => {
				setInFlight( false );
				setSettings( results );
				onSetupStatus( ! results.status );
			} )
			.catch( handleErrors );
	}, [] );

	const { service_provider: serviceProvider = '', credentials = {} } = settings;

	const canSubmit = 'manual' === serviceProvider || values( credentials ).join( '' ).length;

	const classes = classnames(
		'newspack-newsletters-modal__content',
		'newspack-newsletters-modal__settings',
		inFlight && 'newspack-newsletters-modal__in-flight'
	);

	const handleKeyDown = event => {
		if ( canSubmit && ENTER === event.keyCode ) {
			event.preventDefault();
			commitSettings();
		}
	};

	const setCredentials = key => value => setSettings( { ...settings, credentials: { ...credentials, [ key ]: value } } );

	return (
		<Fragment>
			<div className={ classes }>
				<div className="newspack-newsletters-modal__settings-wrapper">
					{ inFlight && <Spinner /> }
					<h1>{ __( 'Configure plugin', 'newspack-newsletters' ) }</h1>
					<h4>{ __( 'Select your email service provider', 'newspack-newsletters' ) }</h4>
					<SelectControl
						label={ __( 'Service Provider', 'newspack-newsletters' ) }
						value={ serviceProvider }
						disabled={ inFlight }
						onChange={ value => setSettings( { ...settings, service_provider: value } ) }
						options={ [
							{
								value: '',
								disabled: true,
								label: __( 'Select service provider', 'newspack-newsletters' ),
							},
							{ value: 'mailchimp', label: 'Mailchimp' },
							{
								value: 'constant_contact',
								label: 'Constant Contact',
							},
							{
								value: 'campaign_monitor',
								label: 'Campaign Monitor',
							},
							{
								value: 'active_campaign',
								label: 'ActiveCampaign',
							},
							{ value: 'manual', label: __( 'Manual / Other', 'newspack-newsletters' ) },
						] }
						__next40pxDefaultSize
					/>
					{ 'mailchimp' === serviceProvider && (
						<Fragment>
							<h4>{ __( 'Enter your Mailchimp API key', 'newspack-newsletters' ) }</h4>
							<TextControl
								label={ __( 'Mailchimp API key', 'newspack-newsletters' ) }
								value={ credentials.api_key }
								onChange={ setCredentials( 'api_key' ) }
								disabled={ inFlight }
								onKeyDown={ handleKeyDown }
								className={ errors.newspack_newsletters_invalid_keys && 'has-error' }
								__next40pxDefaultSize
							/>
							{ errors.newspack_newsletters_invalid_keys && <p className="error">{ errors.newspack_newsletters_invalid_keys }</p> }

							<p>
								<ExternalLink href="https://mailchimp.com/help/about-api-keys/#Find_or_generate_your_API_key">
									{ __( 'Find or generate your API key', 'newspack-newsletters' ) }
								</ExternalLink>
							</p>
						</Fragment>
					) }
					{ 'constant_contact' === serviceProvider && (
						<Fragment>
							<h4>{ __( 'Enter your Constant Contact API key and access token', 'newspack-newsletters' ) }</h4>
							<TextControl
								label={ __( 'Constant Contact API key', 'newspack-newsletters' ) }
								value={ credentials.api_key }
								onChange={ setCredentials( 'api_key' ) }
								disabled={ inFlight }
								onKeyDown={ handleKeyDown }
								className={ errors.newspack_newsletters_invalid_keys && 'has-error' }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Constant Contact API Secret', 'newspack-newsletters' ) }
								value={ credentials.api_secret }
								onChange={ setCredentials( 'api_secret' ) }
								disabled={ inFlight }
								onKeyDown={ handleKeyDown }
								className={ errors.newspack_newsletters_invalid_keys && 'has-error' }
								__next40pxDefaultSize
							/>
							{ errors.newspack_newsletters_invalid_keys && <p className="error">{ errors.newspack_newsletters_invalid_keys }</p> }

							<p>
								<ExternalLink href="https://constantcontact.mashery.com/">
									{ __( 'Get Constant Contact API key', 'newspack-newsletters' ) }
								</ExternalLink>
								<span className="separator"> | </span>
								<ExternalLink href="https://constantcontact.mashery.com/io-docs">
									{ __( 'Get Constant Contact access token', 'newspack-newsletters' ) }
								</ExternalLink>
							</p>
						</Fragment>
					) }
					{ 'campaign_monitor' === serviceProvider && (
						<Fragment>
							<h4>{ __( 'Enter your Campaign Monitor API key and client ID', 'newspack-newsletters' ) }</h4>
							<TextControl
								label={ __( 'Campaign Monitor API key', 'newspack-newsletters' ) }
								value={ credentials.api_key }
								onChange={ setCredentials( 'api_key' ) }
								disabled={ inFlight }
								onKeyDown={ handleKeyDown }
								className={ errors.newspack_newsletters_invalid_keys && 'has-error' }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'Campaign Monitor Client ID', 'newspack-newsletters' ) }
								value={ credentials.client_id }
								onChange={ setCredentials( 'client_id' ) }
								disabled={ inFlight }
								onKeyDown={ handleKeyDown }
								className={ errors.newspack_newsletters_invalid_keys && 'has-error' }
								__next40pxDefaultSize
							/>
							{ errors.newspack_newsletters_invalid_keys && <p className="error">{ errors.newspack_newsletters_invalid_keys }</p> }

							<p>
								<ExternalLink href="https://help.campaignmonitor.com/api-keys">
									{ __( 'Get Campaign Monitor API key and Client ID', 'newspack-newsletters' ) }
								</ExternalLink>
							</p>
						</Fragment>
					) }
					{ 'active_campaign' === serviceProvider && (
						<Fragment>
							<h4>{ __( 'Enter your ActiveCampaign API URL and Key', 'newspack-newsletters' ) }</h4>
							<TextControl
								label={ __( 'ActiveCampaign API URL', 'newspack-newsletters' ) }
								value={ credentials.api_key }
								onChange={ setCredentials( 'url' ) }
								disabled={ inFlight }
								onKeyDown={ handleKeyDown }
								className={ errors.newspack_newsletters_invalid_keys && 'has-error' }
								__next40pxDefaultSize
							/>
							<TextControl
								label={ __( 'ActiveCampaign API Key', 'newspack-newsletters' ) }
								value={ credentials.client_id }
								onChange={ setCredentials( 'key' ) }
								disabled={ inFlight }
								onKeyDown={ handleKeyDown }
								className={ errors.newspack_newsletters_invalid_keys && 'has-error' }
								__next40pxDefaultSize
							/>
							{ errors.newspack_newsletters_invalid_keys && <p className="error">{ errors.newspack_newsletters_invalid_keys }</p> }

							<p>
								<ExternalLink href="https://help.activecampaign.com/hc/en-us/articles/207317590-Getting-started-with-the-API">
									{ __( 'Get ActiveCampaign API URL and Key', 'newspack-newsletters' ) }
								</ExternalLink>
							</p>
						</Fragment>
					) }
				</div>
			</div>
			<HStack align="center" className="newspack-newsletters-modal__action-buttons" justify="end">
				<Button variant="primary" onClick={ commitSettings } disabled={ inFlight || ! canSubmit }>
					{ __( 'Save settings', 'newspack-newsletter' ) }
				</Button>
			</HStack>
		</Fragment>
	);
};
