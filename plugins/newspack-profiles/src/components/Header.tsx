import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { Logo } from './Logo';

/**
 * Header component for the Newspack Profile Collections plugin.
 *
 * @return JSX.Element The Header component.
 */
export const Header = () => {
	return (
		<div className="flex flex-col md:flex-row justify-between items-start md:items-center border-b border-gray-200">
			<div className="flex flex-row items-center gap-3">
				<div className="flex items-center justify-center size-22 bg-[#003da5]">
					<Logo className="p-4" />
				</div>
				<div className="my-2">
					<h1 className="text-2xl font-bold my-0">
						{ __(
							'Newspack Profile Collections',
							'newspack-profiles'
						) }
					</h1>
					<p className="mt-1 mb-0">
						{ __(
							'Create and manage profile collections from Google Sheets or Airtable.',
							'newspack-profiles'
						) }
					</p>
				</div>
			</div>
			<Button
				className="mx-8 bg-[#003da5] text-white hover:bg-[#002d7a] hover:text-white"
				href={
					window.NewspackProfilesSettingsConfig
						.profileCollectionsCreateURL
				}
			>
				{ __( 'Add Profile Collection', 'newspack-profiles' ) }
			</Button>
		</div>
	);
};
