/**
 * Newspack - Dashboard
 *
 * WP Admin Newspack Dashboard page.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import './style.scss';
import sections from './sections';
import Wizard from '../../../../../packages/components/src/wizard';

function Settings() {
	return (
		<Wizard
			className="newspack-admin__tabs"
			headerText={ __( 'Newspack / Settings', 'newspack' ) }
			sections={ sections }
			isInitialFetchTriggered={ false }
		/>
	);
}

export default Settings;
