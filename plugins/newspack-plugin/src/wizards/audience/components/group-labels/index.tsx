/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useDispatch, useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { Button, Grid, SectionHeader, TextControl } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';

const DATA_STORE_KEY = 'newspack-audience/group-labels';

export default function GroupLabels() {
	const settings = useWizardData( DATA_STORE_KEY );
	const { updateWizardSettings, saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const isQuietLoading = useSelect( select => select( WIZARD_STORE_NAMESPACE ).isQuietLoading() ?? false, [] );

	const change = ( key, value ) =>
		updateWizardSettings( {
			slug: DATA_STORE_KEY,
			path: [ key ],
			value,
		} );

	const onSave = () => saveWizardSettings( { slug: DATA_STORE_KEY } );

	const singularDefault = settings.label_singular_default || __( 'Group', 'newspack-plugin' );
	const pluralDefault = settings.label_plural_default || __( 'Groups', 'newspack-plugin' );

	return (
		<Grid columns={ 2 } gutter={ 32 }>
			<SectionHeader
				heading={ 2 }
				title={ __( 'Reader-facing labels', 'newspack-plugin' ) }
				description={ __(
					'Customize the term shown to readers in My Account when they manage a group subscription. Leave blank to use the default.',
					'newspack-plugin'
				) }
				noMargin
			/>
			<VStack spacing={ 4 } className={ isQuietLoading ? 'is-fetching' : '' }>
				<TextControl
					label={ __( 'Singular label', 'newspack-plugin' ) }
					placeholder={ singularDefault }
					help={ sprintf(
						/* translators: %s: default value (e.g. "Group"). */
						__( 'Default: %s', 'newspack-plugin' ),
						singularDefault
					) }
					value={ settings.label_singular ?? '' }
					onChange={ value => change( 'label_singular', value ) }
					disabled={ isQuietLoading }
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
					value={ settings.label_plural ?? '' }
					onChange={ value => change( 'label_plural', value ) }
					disabled={ isQuietLoading }
					withMargin={ false }
				/>
				<HStack alignment="right">
					<Button variant="primary" onClick={ onSave } disabled={ isQuietLoading }>
						{ isQuietLoading ? __( 'Saving…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Grid>
	);
}
