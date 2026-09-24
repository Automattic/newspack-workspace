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
import type { CardBadge } from '../../../../../packages/components/src/types';

const { useHistory } = Router;

type SettingsCardProps = {
	title: string;
	description?: string;
	enabled?: boolean;
	href?: string;
	requirements?: string;
	/** Omit for a setting that is always on and only ever configured, such as metering. */
	toggleEnabled?: () => void;
	badge?: CardBadge;
};

const SettingsCard = ( { title, description, enabled, requirements, toggleEnabled, href = '', badge }: SettingsCardProps ) => {
	const history = useHistory();
	const configure = () => history.push( href );

	return (
		<CardFeature
			headingLevel={ 3 }
			title={ title }
			description={ description }
			enabled={ enabled }
			requirements={ requirements }
			// Nothing to enable without a toggle, so both states lead to the same page.
			enableLabel={ toggleEnabled ? undefined : __( 'Configure', 'newspack-plugin' ) }
			onEnable={ toggleEnabled || configure }
			onConfigure={ configure }
			badge={ badge }
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
