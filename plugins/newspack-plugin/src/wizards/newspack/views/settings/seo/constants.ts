import { __, sprintf } from '@wordpress/i18n';

/**
 * Profiles Yoast has no dedicated field for are stored in its catch-all
 * `other_social_urls` list, which the REST layer reads back by host. A URL on any
 * other host would save but never load again, so the host is validated here.
 */
const hostValidation = ( network: string, hosts: readonly string[] ) => ( inputValue: string ) => {
	if ( inputValue.length === 0 ) {
		return '';
	}
	let host = '';
	try {
		host = new URL( inputValue ).hostname.replace( /^www\./, '' ).toLowerCase();
	} catch {
		host = '';
	}
	if ( hosts.includes( host ) ) {
		return '';
	}
	return sprintf(
		/* translators: %1$s: network name, %2$s: expected domain */
		__( '%1$s profiles live on %2$s. Enter the full profile URL.', 'newspack-plugin' ),
		network,
		hosts[ 0 ]
	);
};

/**
 * Array of tupils where each tupil contains:
 * 1. Field key.
 * 2. Field label.
 * 3. Field placeholder.
 * 4. (Optional) Validation callback name.
 * 5. (Optional) Field error message.
 */
export const ACCOUNTS = [
	[ 'bluesky', __( 'Bluesky', 'newspack-plugin' ), 'https://bsky.app/profile/user', hostValidation( 'Bluesky', [ 'bsky.app' ] ) ],
	[ 'facebook', __( 'Facebook', 'newspack-plugin' ), 'https://facebook.com/page' ],
	[ 'instagram', __( 'Instagram', 'newspack-plugin' ), 'https://instagram.com/user' ],
	[ 'linkedin', __( 'LinkedIn', 'newspack-plugin' ), 'https://linkedin.com/user' ],
	[ 'mastodon', __( 'Mastodon', 'newspack-plugin' ), 'https://mastodon.social/@user' ],
	[ 'pinterest', __( 'Pinterest', 'newspack-plugin' ), 'https://pinterest.com/user' ],
	[ 'threads', __( 'Threads', 'newspack-plugin' ), 'https://threads.com/@user', hostValidation( 'Threads', [ 'threads.com', 'threads.net' ] ) ],
	[ 'tiktok', __( 'TikTok', 'newspack-plugin' ), 'https://tiktok.com/@user', hostValidation( 'TikTok', [ 'tiktok.com' ] ) ],
	[
		'twitter',
		__( 'X', 'newspack-plugin' ),
		__( 'username', 'newspack-plugin' ),
		( inputValue: string ) => {
			if ( inputValue.length === 0 ) {
				return '';
			}
			if ( inputValue.length > 15 ) {
				return __(
					'X handles can be up to 15 characters. Enter just the username, without the @ or the full profile URL.',
					'newspack-plugin'
				);
			}
			if ( ! /^[a-zA-Z0-9_]+$/.test( inputValue ) ) {
				return __(
					'X handles use only letters, numbers, and underscores. Enter just the username, without the @ or the full profile URL.',
					'newspack-plugin'
				);
			}
			return '';
		},
	],
	[ 'youtube', __( 'YouTube', 'newspack-plugin' ), 'https://youtube.com/c/channel' ],
] as const;
