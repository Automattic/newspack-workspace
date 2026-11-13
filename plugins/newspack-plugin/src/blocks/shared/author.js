/**
 * WordPress dependencies
 */
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const authorCustomFields = window.newspack_blocks_data?.author_custom_fields || [];

export const AuthorDisplaySettings = ( { attributes, setAttributes } ) => {
	const fields = [
		...[
			{
				name: 'Bio',
				label: __( 'Biographical info', 'newspack-plugin' ),
			},
			{
				name: 'Social',
				label: __( 'Social links', 'newspack-plugin' ),
			},
			{
				name: 'Email',
				label: __( 'Email address', 'newspack-plugin' ),
			},
			{
				name: 'ArchiveLink',
				label: __( 'Link to author archive', 'newspack-plugin' ),
			},
		],
		...authorCustomFields,
	];
	return (
		<>
			<p>{ __( 'Display the following fields:', 'newspack-plugin' ) }</p>
			{ fields.map( field => {
				const attributeName = `show${ field.name }`;
				return (
					<ToggleControl
						key={ field.name }
						label={ field.label }
						checked={ Boolean( attributes[ attributeName ] ) }
						onChange={ () => setAttributes( { [ attributeName ]: ! attributes[ attributeName ] } ) }
					/>
				);
			} ) }
		</>
	);
};
