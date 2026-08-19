/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal, Notice } from '../..';
import { WIZARD_STORE_NAMESPACE } from '../store';
import type { WizardApiError, WizardsStoreSelectors } from '../store';

const parseError = ( { data, message, code }: NonNullable< WizardApiError > ) => {
	let level: string | undefined = 'fatal';
	if ( !! data && 'level' in data ) {
		level = data.level;
	} else if ( 'rest_invalid_param' === code ) {
		level = 'notice';
	}
	return {
		message,
		level,
	};
};

const WizardError = () => {
	const error: WizardApiError = useSelect( select => ( select( WIZARD_STORE_NAMESPACE ) as WizardsStoreSelectors ).getError() );
	if ( ! error ) {
		return null;
	}

	const { level, message } = parseError( error );
	if ( 'fatal' === level ) {
		const fallbackURL = typeof newspack_urls !== 'undefined' && newspack_urls.dashboard;
		return (
			<Modal
				title={ __( 'Unrecoverable error' ) }
				onRequestClose={ () => {
					if ( fallbackURL ) {
						window.location.href = fallbackURL;
					}
				} }
			>
				<Notice noticeText={ message } isError rawHTML />
				{ fallbackURL && (
					<HStack justify="flex-end" spacing={ 4 } wrap className="newspack-modal__footer">
						<Button isPrimary href={ fallbackURL }>
							{ __( 'Return to Dashboard', 'newspack-plugin' ) }
						</Button>
					</HStack>
				) }
			</Modal>
		);
	}

	return <Notice isError className="newspack-wizard__above-header" noticeText={ message } rawHTML />;
};

export default WizardError;
