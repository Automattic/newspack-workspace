/**
 * Internal dependencies.
 */
import { Grid, InfoButton } from '../';

const SettingSection = ( { title, description, children } ) => (
	<Grid columns={ 1 } gutter={ 8 } className="newspack-settings__section">
		<div className="newspack-settings__section__title">
			<span>{ title }</span>
			{ description && <InfoButton text={ description } /> }
		</div>
		<div className="newspack-settings__section__content">{ children }</div>
	</Grid>
);

export default SettingSection;
