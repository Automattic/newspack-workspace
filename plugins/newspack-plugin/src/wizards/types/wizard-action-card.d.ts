/**
 * Wizard Action Card Props
 */
type WizardActionCardProps = ActionCardProps;

/**
 * Plugin callbacks for install, activate and init states
 */
type PluginCallbacks = {
	init: PluginWizardApiFetchCallback;
	activate: PluginWizardApiFetchCallback;
	deactivate: PluginWizardApiFetchCallback;
	install: PluginWizardApiFetchCallback;
	configure: PluginWizardApiFetchCallback;
};

/**
 * Plugin partial response
 */
type PluginResponse = { Status: string; Configured: boolean };

/**
 * Plugin Wizard API fetch callback
 */
type PluginWizardApiFetchCallback = (
	callbacks?: ApiFetchCallbacks<PluginResponse>
) => Promise<PluginResponse>;

/**
 * Plugin card action texts
 */
type PluginCardActionText = {
	complete?: string;
	configure?: string;
	activate?: string;
	install?: string;
};

/**
 * Plugin data type
 */
type PluginCard = {
	slug: string;
	actionText?: PluginCardActionText;
	editLink?: string;
	badge?: string;
	description?: string | React.ReactNode;
	title: string;
	subTitle?: string;
	statusDescription?: Partial<{
		uninstalled: string;
		inactive: string;
		notConfigured: string;
		connected: string;
	}>;
	isEnabled?: boolean;
	isManageable?: boolean;
	// Toggle card props
	toggleChecked?: boolean;
	toggleOnChange?: (value?: boolean) => void;
	isStatusPrepended?: boolean;
	error?: string | null;
	onStatusChange?: (statuses: Record<string, boolean>) => void;
	reloadOnActivation?: boolean;
	isConfigurable?: boolean;
	isTogglable?: boolean;
	isMedium?: boolean;
	disabled?: boolean;
};

/**
 * Wizard Toggle Header Card Props
 */
type WizardsToggleHeaderCardProps<T> = {
	title: string;
	description: string;
	namespace: string;
	path: string;
	defaultValue: T;
	fieldValidationMap: Array<
		[
			keyof T,
			{
				callback?: 'isIntegerId' | 'isId' | ((v: any) => string);
				dependsOn?: { [k in keyof T]?: string };
			},
		]
	>;
	renderProp: (props: {
		settingsUpdates: T;
		setSettingsUpdates: React.Dispatch<React.SetStateAction<T>>;
		isFetching: boolean;
	}) => React.ReactNode;
	/** Optional prop to override conditions for toggling. Default uses `active` prop to dictate if toggled on/off */
	onToggle?: (active: boolean, data: T) => T;
	/** Optional prop to override conditions for isToggled. Default uses `active` prop to dictate if toggled on/off */
	onChecked?: (data: T) => boolean;
};
