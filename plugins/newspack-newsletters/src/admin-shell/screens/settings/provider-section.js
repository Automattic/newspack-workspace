import {
	Card,
	CardBody,
	CardHeader,
	Notice,
	Button,
	SelectControl,
	TextControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { notifyError } from '../../notices';
import { getProviderCredentialFields } from './provider-credentials-schema';

export default function ProviderSection( {
	provider,
	providers,
	pendingSlug,
	pendingCredentials,
	onSlugChange,
	onCredentialChange,
	onAuthorized,
	onSave,
	isDirty,
	isSaving,
	disabled,
} ) {
	const slug = pendingSlug;
	const fields = getProviderCredentialFields( slug );
	const isManual = slug === 'manual';
	// Only trust the saved `credentials_set` flags when the local selector
	// still matches the saved provider — otherwise the field keys can
	// overlap (e.g. both Mailchimp and Constant Contact use `api_key`) and
	// the placeholder would lie about whether the new provider has stored
	// credentials.
	const credentialsSet = slug === ( provider?.selected || '' ) ? provider?.credentials_set || {} : {};

	const oauth = provider?.oauth;
	const showOAuthNotice = !! oauth && ! oauth.valid && oauth.auth_url;

	const handleAuthorize = () => {
		// Open `about:blank` first so the popup stays same-origin while we
		// install the minimal `{ verify }` opener; only then navigate to the
		// OAuth provider.
		const authWindow = window.open( 'about:blank', 'newspack_newsletters_oauth', 'width=500,height=600' );
		if ( ! authWindow ) {
			notifyError( __( 'Could not open the authorisation window. Allow popups for this site and try again.', 'newspack-newsletters' ) );
			return;
		}
		let verified = false;
		authWindow.opener = {
			verify: () => {
				if ( verified ) {
					return;
				}
				verified = true;
				if ( typeof onAuthorized === 'function' ) {
					onAuthorized();
				}
			},
		};
		authWindow.location = oauth.auth_url;
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Service provider', 'newspack-newsletters' ) }</h2>
				<Button variant="primary" onClick={ onSave } disabled={ ! isDirty || isSaving } isBusy={ isSaving }>
					{ __( 'Save', 'newspack-newsletters' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 4 }>
					{ showOAuthNotice && (
						<Notice status="warning" isDismissible={ false }>
							<p>{ __( 'Authorize this site to connect to the configured provider.', 'newspack-newsletters' ) }</p>
							<p>
								<Button variant="primary" onClick={ handleAuthorize }>
									{ __( 'Authorize', 'newspack-newsletters' ) }
								</Button>
							</p>
						</Notice>
					) }

					<SelectControl
						label={ __( 'Service Provider', 'newspack-newsletters' ) }
						value={ slug }
						options={ ( providers || [] ).map( option => ( {
							label: option.name,
							value: option.slug,
						} ) ) }
						onChange={ onSlugChange }
						disabled={ disabled }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>

					{ ! isManual && fields.length > 0 && (
						<VStack spacing={ 4 }>
							{ fields.map( field => {
								const isSet = !! credentialsSet[ field.key ];
								const placeholder = isSet
									? __( 'Set — enter a new value to replace.', 'newspack-newsletters' )
									: field.placeholder || '';
								const help =
									field.help && field.helpURL ? (
										<a href={ field.helpURL } target="_blank" rel="noreferrer noopener">
											{ field.help }
										</a>
									) : (
										field.help || ''
									);
								return (
									<TextControl
										key={ field.key }
										label={ field.label }
										value={ pendingCredentials[ field.key ] ?? '' }
										placeholder={ placeholder }
										help={ help }
										onChange={ value => onCredentialChange( field.key, value ) }
										disabled={ disabled }
										__nextHasNoMarginBottom
										__next40pxDefaultSize
									/>
								);
							} ) }
						</VStack>
					) }
				</VStack>
			</CardBody>
		</Card>
	);
}
