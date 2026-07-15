import { Icon } from '@wordpress/components';
import { AirtableIcon } from '../icons/AirtableIcon';
import { GoogleSheetsIcon } from '../icons/GoogleSheetsIcon';
import { database } from 'newspack-icons';

type SourceTypeIconProps = {
	sourceType: string;
	className?: string;
	size?: number;
};

/**
 * Returns the appropriate icon component based on the data source type.
 *
 * @param {string} type - The type of the data source.
 *
 * @return The icon component corresponding to the data source type.
 */
const getDataSourceIcon = ( type: string ) => {
	switch ( type ) {
		case 'google-sheet':
			return GoogleSheetsIcon;
		case 'airtable':
			return AirtableIcon;
		default:
			return database;
	}
};

/**
 * Component for displaying the icon of a data source type.
 *
 * @param {SourceTypeIconProps} props - Component props.
 *
 * @return JSX.Element The SourceTypeIcon component.
 */
export const SourceTypeIcon = ( {
	sourceType,
	...rest
}: SourceTypeIconProps ) => {
	return <Icon icon={ getDataSourceIcon( sourceType ) } { ...rest } />;
};
