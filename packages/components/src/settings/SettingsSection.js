/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import Grid from '../grid';
import InfoButton from '../info-button';

const SettingSection = ( { title, description, children } ) => (
	<Grid columns={ 1 } gutter={ 8 } className="newspack-settings__section">
		<div className="newspack-settings__section__title">
			<span>{ title }</span>
			{ description && (
				<InfoButton
					description={ description }
					triggerLabel={ sprintf(
						// translators: %s is the name of the setting being explained.
						__( 'More information about %s', 'newspack-plugin' ),
						title
					) }
				/>
			) }
		</div>
		<div className="newspack-settings__section__content">{ children }</div>
	</Grid>
);

export default SettingSection;
