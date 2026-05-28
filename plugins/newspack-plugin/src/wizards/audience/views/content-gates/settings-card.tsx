/**
 * Content Gate settings card component.
 * Used for additional global settings.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { CardFeature, Router } from '../../../../../packages/components/src';

const { useHistory } = Router;

type SettingsCardProps = {
	title: string;
	description?: string;
	enabled?: boolean;
	href?: string;
	requirements?: string;
	toggleEnabled?: () => void;
};

const SettingsCard = ( { title, description, enabled, requirements, toggleEnabled = () => {}, href = '' }: SettingsCardProps ) => {
	const history = useHistory();

	return (
		<CardFeature
			title={ title }
			description={ description }
			enabled={ enabled }
			requirements={ requirements }
			onEnable={ toggleEnabled }
			onConfigure={ () => history.push( href ) }
			moreControls={ [
				{
					title: __( 'Disable', 'newspack-plugin' ),
					onClick: toggleEnabled,
				},
			] }
		/>
	);
};

export default SettingsCard;
