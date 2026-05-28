import React from 'react';
import { __ } from '@wordpress/i18n';
import WizardsActionCard from '../../../../wizards-action-card';
import { TextControl, Grid } from '../../../../../../packages/components/src';

interface CustomNamingCardProps {
	settings: Partial< CollectionsSettingsData >;
	isSaving: boolean;
	onChange: FieldChangeHandler< CollectionsSettingsData >;
}

const CustomNamingCard: React.FC< CustomNamingCardProps > = ( { settings, isSaving, onChange } ) => (
	<WizardsActionCard
		isMedium
		title={ __( 'Customize collections naming schema', 'newspack-plugin' ) }
		description={ __( 'Override labels, messages and other reader-facing elements with custom naming.', 'newspack-plugin' ) }
		disabled={ isSaving }
		toggleChecked={ !! settings.custom_naming_enabled }
		toggleOnChange={ ( value: boolean ) => onChange( 'custom_naming_enabled', value ) }
		hasGreyHeader={ !! settings.custom_naming_enabled }
	>
		{ settings.custom_naming_enabled && (
			<Grid columns={ 2 } gutter={ 24 }>
				<TextControl
					label={ __( 'Name', 'newspack-plugin' ) }
					help={ __( 'Name to be used instead of "Collections" (e.g., "Issues", "Magazines")', 'newspack-plugin' ) }
					value={ settings.custom_name }
					onChange={ ( value: string ) => onChange( 'custom_name', value ) }
					placeholder="Collections"
				/>
				<TextControl
					label={ __( 'Singular name', 'newspack-plugin' ) }
					help={ __( 'Singular name to be used instead of "Collection" (e.g., "Issue", "Magazine")', 'newspack-plugin' ) }
					value={ settings.custom_singular_name }
					onChange={ ( value: string ) => onChange( 'custom_singular_name', value ) }
					placeholder="Collection"
				/>
				<TextControl
					label={ __( 'Permalink base slug', 'newspack-plugin' ) }
					help={ __(
						'Base slug to be used in permalinks and the REST API (e.g., "issues", "magazine"). Default: "collections".',
						'newspack-plugin'
					) }
					value={ settings.custom_slug }
					onChange={ ( value: string ) => onChange( 'custom_slug', value ) }
					placeholder="collections"
				/>
			</Grid>
		) }
	</WizardsActionCard>
);

export default CustomNamingCard;
