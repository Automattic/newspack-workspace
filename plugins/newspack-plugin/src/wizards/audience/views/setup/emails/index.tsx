/**
 * Audience > Configuration > Emails
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Button, withWizardScreen } from '../../../../../../packages/components/src';
import { default as EmailsSection } from './emails';
import SettingsModal from './settings-modal';

const EmailsScreen = withWizardScreen( ( { children }: { children: React.ReactNode } ) => <>{ children }</> );

export default function Emails( props: Record< string, unknown > ) {
	const [ showSettingsModal, setShowSettingsModal ] = useState( false );

	const headerActions = (
		<Button variant="secondary" onClick={ () => setShowSettingsModal( true ) }>
			{ __( 'Settings', 'newspack-plugin' ) }
		</Button>
	);

	return (
		<EmailsScreen { ...props } headerActions={ headerActions }>
			<EmailsSection />
			<SettingsModal showModal={ showSettingsModal } closeModal={ () => setShowSettingsModal( false ) } />
		</EmailsScreen>
	);
}
