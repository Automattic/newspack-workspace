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
	/** Omit for a setting that is always on and only ever configured, such as metering. */
	toggleEnabled?: () => void;
	badgeText?: string;
	badgeLevel?: 'default' | 'info' | 'success' | 'warning' | 'error';
};

const SettingsCard = ( { title, description, enabled, requirements, toggleEnabled, href = '', badgeText, badgeLevel }: SettingsCardProps ) => {
	const history = useHistory();
	const configure = () => history.push( href );

	return (
		<CardFeature
			title={ title }
			description={ description }
			enabled={ enabled }
			requirements={ requirements }
			// Without a toggle there is nothing to enable, so both button states
			// lead to the same settings page.
			enableLabel={ toggleEnabled ? undefined : __( 'Configure', 'newspack-plugin' ) }
			onEnable={ toggleEnabled || configure }
			onConfigure={ configure }
			badgeText={ badgeText }
			badgeLevel={ badgeLevel }
			moreControls={
				toggleEnabled
					? [
							{
								title: __( 'Disable', 'newspack-plugin' ),
								onClick: toggleEnabled,
							},
					  ]
					: undefined
			}
		/>
	);
};

export default SettingsCard;
