/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Grid, SectionHeader, TextControl } from '../../../../../packages/components/src';

export default function GroupLabels( { labels, defaults, onChange, disabled } ) {
	const singularDefault = defaults.label_singular_default || __( 'Group', 'newspack-plugin' );
	const pluralDefault = defaults.label_plural_default || __( 'Groups', 'newspack-plugin' );

	return (
		<Grid columns={ 2 } gutter={ 32 }>
			<SectionHeader
				heading={ 2 }
				title={ __( 'Reader-Facing Labels', 'newspack-plugin' ) }
				description={ __(
					'Customize the term shown to readers in My Account when they manage a group subscription. Leave blank to use the default.',
					'newspack-plugin'
				) }
				noMargin
			/>
			<VStack spacing={ 4 }>
				<TextControl
					label={ __( 'Singular label', 'newspack-plugin' ) }
					placeholder={ singularDefault }
					help={ sprintf(
						/* translators: %s: default value (e.g. "Group"). */
						__( 'Default: %s', 'newspack-plugin' ),
						singularDefault
					) }
					value={ labels.label_singular }
					onChange={ value => onChange( 'label_singular', value ) }
					disabled={ disabled }
					withMargin={ false }
				/>
				<TextControl
					label={ __( 'Plural label', 'newspack-plugin' ) }
					placeholder={ pluralDefault }
					help={ sprintf(
						/* translators: %s: default value (e.g. "Groups"). */
						__( 'Default: %s', 'newspack-plugin' ),
						pluralDefault
					) }
					value={ labels.label_plural }
					onChange={ value => onChange( 'label_plural', value ) }
					disabled={ disabled }
					withMargin={ false }
				/>
			</VStack>
		</Grid>
	);
}
