import { __ } from '@wordpress/i18n';
import {
	registerBlockBindingsSource,
	unregisterBlockBindingsSource,
} from '@wordpress/blocks';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editSiteStore } from '@wordpress/edit-site';
import { store as onboardingStore } from '../../stores/onboarding';
import { BlockEditorProvider } from '@wordpress/block-editor';
import { PatternPicker } from '../PatternPicker';
import { useEffect } from '@wordpress/element';
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	Notice,
	__experimentalDivider as Divider,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { useEditContext } from '../../context/EditContext';
import './PatternSelection.scss';

/**
 * Component for selecting block patterns for profile pages.
 *
 * @return JSX.Element The PatternSelection component.
 */
export const PatternSelection = () => {
	const isEdit = useEditContext();

	const { settings, selectedPattern } = useSelect(
		( select ) => ( {
			settings: ( select( editSiteStore ) as any ).getSettings(),
			selectedPattern: select( onboardingStore ).getBlockPattern(),
		} ),
		[]
	);

	const { setBlockPattern } = useDispatch( onboardingStore );

	useEffect( () => {
		/**
		 * Unregister existing remote data bindings source and
		 * registers a mock remote data bindings source to provide sample data
		 * for block previews during the pattern selection step.
		 */
		unregisterBlockBindingsSource( 'remote-data/binding' );
		registerBlockBindingsSource( {
			name: 'remote-data/binding',
			label: __( 'Remote Data', 'newspack-profiles' ),
			getValues( { bindings }: { bindings?: Record< string, any > } ) {
				const attributes = [ 'content', 'text', 'title', 'alt' ];

				const values = attributes.reduce( ( acc, attribute ) => {
					if ( bindings?.[ attribute ]?.args?.field ) {
						acc[ attribute ] = bindings?.[ attribute ]?.args?.field;
					}

					return acc;
				}, {} as any );

				if ( bindings?.url?.args?.field ) {
					values.url =
						window.NewspackProfilesSettingsConfig.placeholderImageURL;
				}

				return values;
			},
		} );
	}, [] );

	return (
		<div className="newspack-profiles__pattern-selection newspack-profile-pattern-selection-step">
			<BlockEditorProvider settings={ settings }>
				<div>
					<div className="newspack-profiles__pattern-selection__section">
						<h3>
							{ __(
								'Select Layout Pattern for Individual Profile Block',
								'newspack-profiles'
							) }
						</h3>
						<p>
							{ __(
								'Choose the layout design for how individual profiles will be displayed. This controls the arrangement of profile images, biographical details, and other personal information.',
								'newspack-profiles'
							) }
						</p>
						{ isEdit && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Warning: Selecting a different pattern will completely replace the current block layout and structure on the individual profile page. Any custom blocks, styling modifications, or content arrangements you have made will be permanently lost.',
									'newspack-profiles'
								) }
							</Notice>
						) }
					</div>
					<PatternPicker
						selectedPattern={ selectedPattern.single }
						patternType="single"
						onSelect={ ( items ) => {
							if ( items.length > 0 ) {
								setBlockPattern( { single: items[ 0 ] } );
							}
						} }
					/>
				</div>
				<div className="newspack-profiles__pattern-selection__list-section">
					<Divider />
					<div className="newspack-profiles__pattern-selection__section">
						<h3>
							{ __(
								'Select Layout Pattern for Profile List',
								'newspack-profiles'
							) }
						</h3>
						<p>
							{ __(
								'Choose the layout design for the list that displays multiple profiles in a list format. This controls how profile summaries appear when visitors browse through all available profiles.',
								'newspack-profiles'
							) }
						</p>
						{ isEdit && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Warning: Selecting a different pattern will completely replace the current block layout and structure on the profile list page. Any custom blocks, styling modifications, or content arrangements you have made will be permanently lost.',
									'newspack-profiles'
								) }
							</Notice>
						) }
					</div>
					<PatternPicker
						selectedPattern={ selectedPattern.list }
						patternType="list"
						onSelect={ ( items ) => {
							if ( items.length > 0 ) {
								setBlockPattern( { list: items[ 0 ] } );
							}
						} }
					/>
				</div>
			</BlockEditorProvider>
		</div>
	);
};
