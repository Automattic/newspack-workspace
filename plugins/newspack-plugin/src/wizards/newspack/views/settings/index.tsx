/**
 * Newspack - Dashboard
 *
 * WP Admin Newspack Dashboard page.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import './style.scss';
import sections from './sections';
import Wizard from '../../../../../packages/components/src/wizard';
import { GlobalNotices, Notice } from '../../../../../packages/components/src/';

const {
	newspack_aux_data: { is_debug_mode: isDebugMode = false },
} = window;

function Settings() {
	return (
		<Fragment>
			{ isDebugMode && <Notice debugMode /> }
			<GlobalNotices />
			<Wizard
				className="newspack-admin__tabs"
				headerText={ __( 'Newspack / Settings', 'newspack' ) }
				sections={ sections }
				isInitialFetchTriggered={ false }
			/>
		</Fragment>
	);
}

export default Settings;
