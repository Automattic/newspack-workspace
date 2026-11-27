/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { SplitSection, TextControl } from '../../../../../packages/components/src';

type GroupLabelsProps = {
	labels: { label_singular?: string; label_plural?: string };
	singularDefault?: string;
	pluralDefault?: string;
	onChange: ( key: 'label_singular' | 'label_plural', value: string ) => void;
	disabled?: boolean;
};

export default function GroupLabels( { labels, singularDefault: singular, pluralDefault: plural, onChange, disabled }: GroupLabelsProps ) {
	const singularDefault = singular || __( 'Group', 'newspack-plugin' );
	const pluralDefault = plural || __( 'Groups', 'newspack-plugin' );

	return (
		<SplitSection.Root>
			<SplitSection.Header
				title={ __( 'Reader-Facing Labels', 'newspack-plugin' ) }
				description={ __(
					'Customize the term shown to readers in My Account when they manage a group subscription. Leave blank to use the default.',
					'newspack-plugin'
				) }
			/>
			<SplitSection.Content>
				<TextControl
					label={ __( 'Singular label', 'newspack-plugin' ) }
					placeholder={ singularDefault }
					help={ sprintf(
						/* translators: %s: default value (e.g. "Group"). */
						__( 'Default: %s', 'newspack-plugin' ),
						singularDefault
					) }
					value={ labels.label_singular }
					onChange={ ( value: string ) => onChange( 'label_singular', value ) }
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
					onChange={ ( value: string ) => onChange( 'label_plural', value ) }
					disabled={ disabled }
					withMargin={ false }
				/>
			</SplitSection.Content>
		</SplitSection.Root>
	);
}
