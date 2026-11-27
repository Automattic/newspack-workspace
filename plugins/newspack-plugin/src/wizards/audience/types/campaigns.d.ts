/**
 * Ambient types for the Audience Campaigns wizard domain: prompts, campaign
 * groups and reader segments as returned by the Campaigns wizard REST API
 * (backed by newspack-popups).
 *
 * Global script file: no top-level imports — an import would turn this file
 * into a module and strip every type below of its global scope. Use inline
 * import() types if needed.
 */

/**
 * A term reference (category, tag, campaign group or segment) attached to a
 * prompt.
 */
type CampaignsTermRef = {
	term_id: number;
	name: string;
};

/**
 * A campaign group (`newspack_popups_taxonomy` term).
 */
type CampaignGroup = {
	term_id: number;
	name: string;
	status?: string;
};

/**
 * Options of a prompt as returned by the Campaigns wizard API.
 */
type CampaignsPromptOptions = PromptOptionsBase & {
	excluded_categories?: number[];
	excluded_tags?: number[];
	// Only overlays set to appear on manual placement carry this.
	manual_only?: boolean;
};

/**
 * A prompt as returned by the Campaigns wizard API.
 */
type CampaignsPrompt = {
	id: number;
	title: string;
	status: string;
	content?: string;
	edit_link?: string;
	duplicate_of?: number;
	campaign_groups: CampaignsTermRef[] | null;
	categories: CampaignsTermRef[];
	tags: CampaignsTermRef[];
	segments: CampaignsTermRef[];
	options: CampaignsPromptOptions;
	// Present only while the prompt is one published arm of a running A/B test.
	ab_test_id?: number | null;
	ab_variant?: string;
};

/**
 * One end of a date-range segment criterion.
 */
type CampaignsSegmentDateBound = {
	type?: string;
	date?: string;
	days?: number;
};

/**
 * A min/max or date range criterion value.
 */
type CampaignsSegmentRangeValue = {
	min?: number | string;
	max?: number | string;
	start?: CampaignsSegmentDateBound;
	end?: CampaignsSegmentDateBound;
};

/**
 * A value of a single segmentation criteria: scalar, list (e.g. subscription
 * lists), or a min/max or date range.
 */
type CampaignsSegmentCriteriaValue = string | number | Array< string | number > | CampaignsSegmentRangeValue;

/**
 * A single segmentation criteria of a reader segment.
 */
type CampaignsSegmentCriteria = {
	criteria_id: string;
	value?: CampaignsSegmentCriteriaValue;
};

/**
 * Configuration of a reader segment.
 */
type CampaignsSegmentConfig = {
	is_disabled?: boolean;
};

/**
 * A reader segment.
 */
type CampaignsSegment = {
	id: string;
	name: string;
	criteria: CampaignsSegmentCriteria[];
	configuration: CampaignsSegmentConfig;
	priority?: number;
	is_criteria_duplicated?: boolean;
};

/**
 * A segment with its prompts, as grouped for display by the Campaigns view
 * and rendered by the SegmentGroup component. The "Everyone" group carries an
 * empty `id` and no criteria.
 */
type CampaignsSegmentGroup = {
	label: string;
	id: string;
	configuration: CampaignsSegmentConfig;
	criteria?: CampaignsSegmentCriteria[];
	prompts: CampaignsPrompt[];
	// The prompts of this segment under the full status filter, so a conflict
	// warning can look past the currently-visible set.
	statusPrompts?: CampaignsPrompt[];
};

/**
 * Criteria configuration registered for segmentation
 * (`window.newspackAudienceCampaigns.criteria`).
 */
type CampaignsCriteriaConfig = {
	category: string;
	description: string;
	help?: string;
	id: string;
	matching_attribute: string;
	matching_function: string;
	name: string;
	options?: Array< { label: string; value: string | number } >;
	placeholder?: string;
};

/**
 * One selectable option of a Campaigns setting rendered as a select control.
 */
type CampaignsSettingOption = {
	value: string | number;
	name?: string;
	label?: string;
};

/**
 * A single Campaigns plugin setting, or a section-info row (`key` empty or
 * `'active'`), as returned by the Campaigns settings endpoint
 * (`Newspack_Popups_Settings::get_settings( true )`). Section-info rows carry a
 * `null` value that no screen reads, so `value` is modelled only for the
 * editable rows the fields render.
 */
type CampaignsSetting = {
	key?: string;
	type?: string;
	control?: string;
	value?: string | number | boolean;
	description?: string;
	help?: string;
	selected?: { label: string; value: string | number } | null;
	options?: CampaignsSettingOption[];
};

/**
 * The Campaigns settings endpoint response: editable sections keyed by slug.
 */
type CampaignsSettingsSections = Record< string, CampaignsSetting[] >;

/**
 * Payload returned by the Campaigns wizard API endpoints that respond with
 * the full wizard state.
 */
type CampaignsData = {
	campaigns: CampaignGroup[];
	prompts: CampaignsPrompt[];
	segments: CampaignsSegment[];
	settings: Record< string, unknown >[];
	duplicated?: number | null;
	term_id?: number;
};

/**
 * One entry of the Campaigns wizard's tabbed navigation.
 */
type CampaignsTab = {
	label: string;
	path: string;
	exact: boolean;
	breadcrumbs?: Array< { label: string; url?: string } >;
};

/**
 * Wizard-level props the Campaigns wizard root (views/campaigns/index) shares
 * with every screen it renders.
 */
type CampaignsWizardSharedProps = {
	headerText: string;
	tabbedNavigation: CampaignsTab[];
	setError: ( error?: import( '../../../../../../packages/components/src/with-wizard' ).WizardError ) => Promise< void >;
	isLoading: number;
	startLoading: ( quiet?: boolean ) => void;
	doneLoading: ( quiet?: boolean ) => void;
	wizardApiFetch: import( '../../../../../../packages/components/src/with-wizard' ).WithWizardInjectedProps[ 'wizardApiFetch' ];
	prompts: CampaignsPrompt[];
	segments: CampaignsSegment[];
	settings: Record< string, unknown >[];
	duplicated: number | null;
	inFlight: boolean;
};

/**
 * Popup-management callbacks created by the Campaigns wizard root and passed
 * down to screens and components.
 */
type CampaignsPopupManagement = {
	manageCampaignGroup: ( campaigns: CampaignsPrompt[], method?: 'POST' | 'DELETE' ) => Promise< void >;
	updatePopup: ( prompt: CampaignsPrompt ) => Promise< void >;
	deletePopup: ( popupId: number ) => Promise< void >;
	restorePopup: ( popupId: number ) => Promise< void >;
	/**
	 * The `Promise< void >` variant reflects a pre-existing call-site bug in
	 * prompt-action-card, where an empty duplicate title falls back to the
	 * *promise* returned by an async default-title fetch. Honored in the type to
	 * avoid a runtime change; see the bug note in the agent report.
	 */
	duplicatePopup: ( popupId: number, title: string | Promise< void > ) => Promise< void >;
	previewPopup: ( prompt: CampaignsPrompt ) => void;
	publishPopup: ( popupId: number ) => Promise< void >;
	unpublishPopup: ( popupId: number ) => Promise< void >;
	resetDuplicated: () => void;
	refetch: ( options?: { quiet?: boolean } ) => void;
};

/**
 * The campaign-group handlers the Campaigns wizard root builds per route and
 * passes to the Campaigns screen and its actions.
 */
type CampaignsGroupManagement = {
	archiveCampaignGroup: ( id: number | string, status: boolean ) => Promise< void >;
	createCampaignGroup: ( name: string ) => Promise< void >;
	deleteCampaignGroup: ( id: number | string ) => Promise< void >;
	duplicateCampaignGroup: ( id: number | string, name: string ) => Promise< void >;
	renameCampaignGroup: ( id: number | string, name: string ) => Promise< void >;
};

/**
 * Props of the Campaigns screen (views/campaigns/campaigns), as assembled by
 * the wizard root.
 */
type CampaignsScreenProps = CampaignsWizardSharedProps &
	CampaignsPopupManagement & {
		campaignId?: string;
		campaigns: CampaignGroup[];
		subHeaderText?: string;
		headerActions?: React.ReactNode;
		campaignActions?: React.ReactNode;
	};
