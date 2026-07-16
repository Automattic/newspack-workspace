/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * External dependencies.
 */
import clsx from 'clsx';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Spinner, __experimentalHStack as HStack } from '@wordpress/components';
import { Icon, check, caution } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { useEffect } from '@wordpress/element';
import { getAuthorizationData, setCredentials } from '../utils/sites';

/**
 * `getAuthorizationData` is typed `@return {Object}` in `../utils/sites` (not yet
 * migrated), so its real shape is re-declared here for this call site.
 */
interface AuthorizationData {
	siteUrl: string | null;
	login: string | null;
	password: string | null;
	success: boolean;
}

export default function AuthorizingSite() {
	const { siteUrl, login, password, success } = getAuthorizationData() as AuthorizationData;
	useEffect( () => {
		if ( siteUrl && login && password ) {
			setCredentials( siteUrl, login, password );
		}
		const redirectUrl = new URL( window.location.href );
		redirectUrl.searchParams.delete( 'application_password' );
		redirectUrl.searchParams.delete( 'user_login' );
		redirectUrl.searchParams.delete( 'password' );
		redirectUrl.searchParams.delete( 'success' );
		window.location.href = redirectUrl.toString();
	}, [] );

	return (
		<div className="wrap">
			<div className="newspack-story-budget__loading">
				<span className="newspack-story-budget__loading-icon">
					<Icon icon={ success ? check : caution } />
				</span>
				<h3
					className={ clsx( 'newspack-story-budget__authorizing', {
						'newspack-story-budget__authorizing--success': success,
						'newspack-story-budget__authorizing--error': ! success,
					} ) }
				>
					{ success ? __( 'Connection authorized', 'newspack-story-budget' ) : __( 'Connection denied', 'newspack-story-budget' ) }
				</h3>
				<HStack justify="center">
					<Spinner style={ { margin: 0 } } />
					<span>{ __( 'Redirecting…', 'newspack-story-budget' ) }</span>
				</HStack>
			</div>
		</div>
	);
}
