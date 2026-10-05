/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Grid, SectionHeader, TextControl } from '../../../../../packages/components/src';

export default function GroupLabels( { labels, singularDefault: singular, pluralDefault: plural, onChange, disabled } ) {
	const singularDefault = singular || __( 'Group', 'newspack-plugin' );
	const pluralDefault = plural || __( 'Groups', 'newspack-plugin' );

	return (
		<Grid columns={ 2 } gutter={ 32 } noMargin>
			<SectionHeader
				heading={ 2 }
				title={ __( 'Reader-Facing Labels', 'newspack-plugin' ) }
				description={ __(
					'Customize the term shown to readers in My Account when they manage a group subscription. Leave blank to use the default.',
					'newspack-plugin'
				) }
				noMargin
			/>
			<Stack direction="column" gap="lg">
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
			</Stack>
		</Grid>
	);
}
