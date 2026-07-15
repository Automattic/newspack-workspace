import type { DataSource, DataSourceConfig } from './types/data-source';

export const isSameDataSource = (
	source1: DataSourceConfig | DataSource | null,
	source2: DataSourceConfig | DataSource | null
) => {
	return (
		source1?.name === source2?.name &&
		source1?.type === source2?.type &&
		source1?.base === source2?.base &&
		source1?.spreadsheet === source2?.spreadsheet &&
		source1?.table === source2?.table &&
		source1?.sheet === source2?.sheet
	);
};

export function sanitizeSlugLenient( value: string ): string {
	return value
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /-+/g, '-' )
		.replace( /^-/g, '' );
}

export function sanitizeSlug( value: string ): string {
	return sanitizeSlugLenient( value ).replace( /-$/g, '' );
}

export function sanitizeNameLenient( value: string ): string {
	return value
		.replace( /[^a-zA-Z0-9 _-]+/g, ' ' )
		.replace( / +/g, ' ' )
		.replace( /^ /g, '' );
}

export function sanitizeName( value: string ): string {
	return sanitizeNameLenient( value ).trim();
}

export const getUniqueFields = ( fields: { name: string; type: string }[] ) => {
	const seenFields = new Set< string >();

	return fields.filter( ( field ) => {
		if ( seenFields.has( field.name ) ) {
			return false;
		}

		seenFields.add( field.name );

		return true;
	} );
};

export const sanitizeSlugFields = (
	slugFields: string[] | undefined,
	availableFields: string[]
): string[] => {
	if ( ! Array.isArray( slugFields ) ) {
		return [];
	}

	return slugFields.filter( ( field ) => availableFields.includes( field ) );
};

export const sanitizeDataSource = (
	dataSourceConfig: DataSourceConfig
): DataSource => {
	if ( ! dataSourceConfig ) {
		return {} as DataSource;
	}

	const { fields, ...dataSource } = dataSourceConfig;

	return dataSource;
};

export const sanitizeSEOFieldToken = ( value?: string ): string => {
	if ( ! value ) {
		return '';
	}

	return value.replace( /[^a-zA-Z0-9 _()&:/,|-]+/g, '' ).trim();
};
