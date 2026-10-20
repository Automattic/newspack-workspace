import { __, sprintf } from '@wordpress/i18n';

/**
 * Profiles Yoast has no dedicated field for are stored in its catch-all `other_social_urls`
 * list, which the REST layer reads back by host, so only a URL on the network's own host
 * round-trips. A scheme other than http(s) is worse than unreadable: Yoast refuses the entry
 * and restores the whole list, taking the other profiles with it. The server refuses both, so
 * checking here turns a generic request error into a field-level message.
 */
const hostValidation = ( network: string, hosts: readonly string[] ) => ( inputValue: string ) => {
	if ( inputValue.length === 0 ) {
		return '';
	}
	let host = '';
	try {
		const { protocol, hostname } = new URL( inputValue );
		if ( protocol === 'http:' || protocol === 'https:' ) {
			host = hostname.replace( /^www\./, '' ).toLowerCase();
		}
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
	[ 'bluesky', 'Bluesky', 'https://bsky.app/profile/user', hostValidation( 'Bluesky', [ 'bsky.app' ] ) ],
	[ 'facebook', 'Facebook', 'https://facebook.com/page' ],
	[ 'instagram', 'Instagram', 'https://instagram.com/user' ],
	[ 'linkedin', 'LinkedIn', 'https://linkedin.com/user' ],
	[ 'pinterest', 'Pinterest', 'https://pinterest.com/user' ],
	[ 'threads', 'Threads', 'https://threads.com/@user', hostValidation( 'Threads', [ 'threads.com', 'threads.net' ] ) ],
	[ 'tiktok', 'TikTok', 'https://tiktok.com/@user', hostValidation( 'TikTok', [ 'tiktok.com' ] ) ],
	[
		'twitter',
		'X',
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
	[ 'youtube', 'YouTube', 'https://youtube.com/c/channel' ],
] as const;
