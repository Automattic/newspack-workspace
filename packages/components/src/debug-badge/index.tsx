/**
 * Debug Badge
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Icon, bug } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * Debug Badge component.
 *
 * Gates itself on `window.newspack_aux_data.is_debug_mode`, so consumers render
 * it unconditionally and it stays absent outside debug mode.
 */
const DebugBadge = () => {
	if ( ! window.newspack_aux_data?.is_debug_mode ) {
		return null;
	}

	return (
		<div className="newspack-debug-badge" role="img" aria-label={ __( 'Debug mode', 'newspack-plugin' ) }>
			<Icon icon={ bug } />
		</div>
	);
};

export default DebugBadge;
