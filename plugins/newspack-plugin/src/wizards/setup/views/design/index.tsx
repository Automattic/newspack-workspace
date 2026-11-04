import type { MouseEvent } from 'react';

import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import Button from '../../../../../packages/components/src/button';
import WebPreview from '../../../../../packages/components/src/web-preview';
import ThemeBrand from '../../../newspack/views/settings/theme-and-brand';
import NarrowLayout from '../../components/narrow-layout';
import StepHeading from '../../components/step-heading';
import StepFooter from '../../components/step-footer';
import type { SetupViewProps } from '../../steps';

export default function Design( { completeSetup }: SetupViewProps ) {
	return (
		<>
			<NarrowLayout>
				<StepHeading
					title={ __( 'Design', 'newspack-plugin' ) }
					lede={ __( 'A starting look for your site. Everything here stays editable in Settings › Theme and Brand.', 'newspack-plugin' ) }
				/>
			</NarrowLayout>
			<ThemeBrand
				isPartOfSetup
				onSetupComplete={ completeSetup }
				renderSetupSection={ section => <NarrowLayout>{ section }</NarrowLayout> }
				renderSetupFooter={ ( { finish, skip, preview, isBusy, isFinishing, isPreviewing } ) => (
					<NarrowLayout
						footer={
							<StepFooter>
								<Button variant="tertiary" onClick={ skip } disabled={ isBusy } accessibleWhenDisabled __next40pxDefaultSize>
									{ __( 'Skip for Now', 'newspack-plugin' ) }
								</Button>
								<WebPreview
									url={ addQueryArgs( window.newspack_urls?.site ?? '/', { newspack_design_preview: 1 } ) }
									renderButton={ ( { showPreview }: { showPreview: () => void } ) => (
										<Button
											variant="secondary"
											onClick={ ( event?: MouseEvent< HTMLElement > ) => preview( event ).then( showPreview, () => {} ) }
											loading={ isPreviewing }
											disabled={ isBusy }
											accessibleWhenDisabled
											__next40pxDefaultSize
										>
											{ __( 'Preview Site', 'newspack-plugin' ) }
										</Button>
									) }
								/>
								<Button
									variant="primary"
									onClick={ finish }
									loading={ isFinishing }
									disabled={ isBusy }
									accessibleWhenDisabled
									__next40pxDefaultSize
								>
									{ __( 'Finish', 'newspack-plugin' ) }
								</Button>
							</StepFooter>
						}
					/>
				) }
			/>
		</>
	);
}
