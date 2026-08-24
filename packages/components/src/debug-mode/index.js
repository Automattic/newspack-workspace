/**
 * Debug Mode
 */

/**
 * WordPress dependencies
 */
import { Icon, bug } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * Debug Mode.
 *
 * A fixed badge marking a site running in debug mode. Rendered by the wizard shells
 * whenever `newspack_aux_data.is_debug_mode` is set.
 */
const DebugMode = () => (
	<div className="newspack-debug-mode">
		<Icon icon={ bug } />
		<span className="screen-reader-text">{ __( 'Debug mode', 'newspack-plugin' ) }</span>
	</div>
);

export default DebugMode;
