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
};

/**
 * A value of a single segmentation criteria: scalar, list (e.g. subscription
 * lists), or a min/max range.
 */
type CampaignsSegmentCriteriaValue = string | number | Array< string | number > | { min?: number | string; max?: number | string };

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
 * Wizard-level props shared by the Campaigns wizard root
 * (views/campaigns/index) with every screen it renders.
 */
type CampaignsWizardSharedProps = {
	headerText: string;
	tabbedNavigation: Array< { label: string; path: string; exact: boolean } >;
	setError: ( error?: import( '../../../../packages/components/src/with-wizard' ).WizardError ) => Promise< void >;
	isLoading: number;
	startLoading: ( quiet?: boolean ) => void;
	doneLoading: ( quiet?: boolean ) => void;
	wizardApiFetch: import( '../../../../packages/components/src/with-wizard' ).WithWizardInjectedProps[ 'wizardApiFetch' ];
	prompts: CampaignsPrompt[];
	segments: CampaignsSegment[];
	settings: Record< string, unknown >[];
	duplicated: number | null;
	inFlight: boolean;
};

/**
 * Props of the Campaigns screen (views/campaigns/campaigns), as assembled by
 * the wizard root.
 */
type CampaignsScreenProps = CampaignsWizardSharedProps &
	CampaignsPopupManagement & {
		campaignId?: string;
		campaigns: CampaignGroup[];
		archiveCampaignGroup: ( id: number | string, status: boolean ) => Promise< void >;
		createCampaignGroup: ( name: string ) => Promise< void >;
		deleteCampaignGroup: ( id: number | string ) => Promise< void >;
		duplicateCampaignGroup: ( id: number | string, name: string ) => Promise< void >;
		renameCampaignGroup: ( id: number | string, name: string ) => Promise< void >;
	};

/**
 * Popup-management callbacks created by the Campaigns wizard root
 * (views/campaigns/index) and passed down to screens and components.
 */
type CampaignsPopupManagement = {
	manageCampaignGroup: ( campaigns: CampaignsPrompt[], method?: 'POST' | 'DELETE' ) => Promise< void >;
	updatePopup: ( prompt: CampaignsPrompt ) => Promise< void >;
	deletePopup: ( popupId: number ) => Promise< void >;
	restorePopup: ( popupId: number ) => Promise< void >;
	/**
	 * The `Promise< void >` variant reflects a pre-existing call-site bug in
	 * prompt-action-card, where an empty duplicate title falls back to the
	 * *promise* returned by an async default-title fetch. Kept in the type to
	 * avoid a runtime change; should be fixed separately.
	 */
	duplicatePopup: ( popupId: number, title: string | Promise< void > ) => Promise< void >;
	previewPopup: ( prompt: CampaignsPrompt ) => void;
	publishPopup: ( popupId: number ) => Promise< void >;
	unpublishPopup: ( popupId: number ) => Promise< void >;
	resetDuplicated: () => void;
	refetch: () => void;
};
