/**
 * Internal dependencies.
 */
import { Grid, InfoButton } from '../';

type SettingSectionProps = {
	/** The section's title. */
	title: React.ReactNode;
	/** Description shown as an info-button tooltip next to the title. */
	description?: string;
	children?: React.ReactNode;
};

const SettingSection = ( { title, description, children }: SettingSectionProps ) => (
	<Grid columns={ 1 } gutter={ 8 } className="newspack-settings__section">
		<div className="newspack-settings__section__title">
			<span>{ title }</span>
			{ description && <InfoButton text={ description } /> }
		</div>
		<div className="newspack-settings__section__content">{ children }</div>
	</Grid>
);

export default SettingSection;
