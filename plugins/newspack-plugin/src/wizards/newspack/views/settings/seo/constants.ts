import { __ } from '@wordpress/i18n';

/**
 * Array of tupils where each tupil contains:
 * 1. Field key.
 * 2. Field label.
 * 3. Field placeholder.
 * 4. (Optional) Validation callback name.
 * 5. (Optional) Field error message.
 */
export const ACCOUNTS = [
	[
		'twitter',
		__( 'X (formerly Twitter) Handle', 'newspack-plugin' ),
		__( 'username', 'newspack-plugin' ),
		( inputValue: string ) => {
			if ( inputValue.length === 0 ) {
				return '';
			}
			if ( inputValue.length > 15 ) {
				return __( 'X handle cannot exceed 15 characters!', 'newspack-plugin' );
			}
			if ( ! /^[a-zA-Z0-9_]+$/.test( inputValue ) ) {
				return __( 'X handle may only contain letters, numbers, and underscores!', 'newspack-plugin' );
			}
			return '';
		},
	],
	[ 'facebook', __( 'Facebook', 'newspack-plugin' ), 'https://facebook.com/page' ],
	[ 'instagram', __( 'Instagram', 'newspack-plugin' ), 'https://instagram.com/user' ],
	[ 'youtube', __( 'YouTube', 'newspack-plugin' ), 'https://youtube.com/c/channel' ],
	[ 'linkedin', __( 'LinkedIn', 'newspack-plugin' ), 'https://linkedin.com/user' ],
	[ 'pinterest', __( 'Pinterest', 'newspack-plugin' ), 'https://pinterest.com/user' ],
] as const;
