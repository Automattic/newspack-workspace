/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Grid, SectionHeader, TextControl } from '../../../../packages/components/src';

const MetadataFields = ( { availableFields, getSharedProps, selectedFields, updateConfig } ) => {
	return (
		<>
			<SectionHeader
				title={ __( 'Metadata field settings', 'newspack-plugin' ) }
				description={ __( 'Select which data to sync for each contact.', 'newspack-plugin' ) }
			/>
			<TextControl
				label={ __( 'Metadata field prefix', 'newspack-plugin' ) }
				help={ __(
					'A string to prefix metadata fields attached to each contact synced to the ESP. Required to ensure that metadata field names are unique. Default: NP_',
					'newspack-plugin'
				) }
				{ ...getSharedProps( 'metadata_prefix', 'text' ) }
			/>
			<Grid columns={ 3 } rowGap={ 16 }>
				{ availableFields.map( ( field, index ) => (
					<CheckboxControl
						className="newspack-checkbox-control"
						key={ index }
						label={ field.replace( ': ', '' ) }
						checked={ selectedFields.includes( field ) }
						onChange={ value => {
							const newFields = [ ...selectedFields ];
							updateConfig(
								'metadata_fields',
								value ? [ ...newFields, field ] : newFields.filter( selectedField => selectedField !== field )
							);
						} }
					/>
				) ) }
			</Grid>
		</>
	);
};

export default MetadataFields;
