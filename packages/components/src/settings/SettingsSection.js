/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import Grid from '../grid';
import InfoButton from '../info-button';

const SettingSection = ( { title, description, children } ) => (
	<Grid columns={ 1 } gutter={ 8 } className="newspack-settings__section">
		<Stack direction="row" align="flex-start" gap="xs" className="newspack-settings__section__title">
			<span>{ title }</span>
			{ description && (
				<InfoButton
					description={ description }
					triggerLabel={
						typeof title === 'string'
							? sprintf(
									// translators: %s is the name of the setting being explained.
									__( 'More information about %s', 'newspack-plugin' ),
									title
							  )
							: __( 'More information', 'newspack-plugin' )
					}
				/>
			) }
		</Stack>
		<div className="newspack-settings__section__content">{ children }</div>
	</Grid>
);

export default SettingSection;
