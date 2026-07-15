import type { DataSourceConfig } from './data-source';
import type { Pattern } from './pattern';

declare global {
	interface Window {
		NewspackProfilesSettingsConfig: {
			availableDataSources: DataSourceConfig[];
			patterns: Pattern[];
			editPageURL: string;
			remoteDataBlocksSettingsPageURL: string;
			placeholderImageURL: string;
			basePath: string;
			initialView: 'add' | 'list';
			profileCollectionsListURL: string;
			profileCollectionsCreateURL: string;
		};

		NewspackProfilesSettingsEditor: {
			data: any;
		};

		// From remote-data-blocks plugin.
		REMOTE_DATA_BLOCKS: {
			config: Record<
				string,
				{
					availableBindings: Record<
						string,
						{ name: string; type: string }
					>;
				}
			>;
		};
	}
}
