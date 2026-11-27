declare global {
	interface Window {
		newspackWizardsAdminHeader: {
			tabs: Array<{
				textContent: string;
				href: string;
				forceSelected: boolean;
			}>;
			breadcrumbs: Array<{ label: string; url?: string }>;
		};
		newspackAudience: {
			has_reader_activation: boolean;
			has_memberships: boolean;
			new_subscription_lists_url: string;
			reader_activation_url: string;
			// Setup screen (views/setup). `is_newspack_feature_enabled` gates the
			// Advanced Settings tab (Group labels); the rest feed the Setup form.
			is_newspack_feature_enabled?: boolean;
			can_use_salesforce?: boolean;
			// Salesforce OAuth redirect URL, used by the Salesforce settings component.
			salesforce_redirect_url?: string;
			available_newsletter_lists?:
				| import( '../../../packages/components/src/sortable-newsletter-list-control' ).NewsletterList[]
				| { errors: Record< string, string > };
			preview_query_keys: {
				[K in PromptOptionsBaseKey]: string;
			};
			preview_post: string;
			preview_archive: string;
			// Optional: only localized when the content-gifting and institutions
			// features are available, so every read guards with `?.`.
			available_products?: PurchasableProductOption[];
			content_gifting?: {
				has_metering?: boolean;
				can_use_gifting?: {
					errors?: Record< string, string[] >;
				};
			};
			institutional_access_url?: string;
			content_banner?: {
				supports_theme_style: boolean;
				uses_site_theme: boolean;
				theme_button_bg: string;
				theme_button_ink: string;
				theme_font_heading: string;
				theme_font_body: string;
				site_bg: string;
				site_text: string;
				site_button_bg: string;
				site_button_ink: string;
			};
			// Optional: consumers guard with `?.`/fallbacks because the
			// payload can be absent (plugin filter strips it, non-Audience
			// mount, HMR reseed) — keep the type honest about that.
			emails?: {
				dependencies: Record< string, boolean >;
				postType: string;
				initial?: {
					newspack_emails: Record< string, unknown >[];
					post_type: string;
				};
				isNewspackPlatform: boolean;
			};
		};
		newspackAudienceCampaigns: {
			api: string;
			preview_post: string;
			preview_archive: string;
			frontend_url: string;
			custom_placements: {
				[key: string]: string;
			};
			overlay_placements: string[];
			overlay_sizes: Array<{
				value: string;
				label: string;
			}>;
			preview_query_keys: {
				[K in PromptOptionsBaseKey]: string;
			};
			experimental: boolean;
			// Feature flags: present only when the campaign manager localizes them.
			contextual_prompts_enabled?: boolean;
			ab_testing_enabled?: boolean;
			criteria: CampaignsCriteriaConfig[];
		};
		newspackAudienceDonations: {
			can_use_name_your_price: boolean;
		};
		newspackAudienceSubscriptions: {
			tabs: Array<{
				slug: string;
				label: string;
				path: string;
			}>;
			memberships_url: string;
			memberships_active: boolean;
			primary_product: string;
			eligible_products: Array<{
				id: string;
				title: string;
			}>;
			upgrade_subscription_url: string;
			audience_management_enabled?: string;
			audience_management_url?: string;
		};
		newspackAudienceIntegrations: {
			esp_provider?: string;
		};
		newspackAudienceContentGates: {
			api: string;
			available_access_rules: AccessRules;
			available_content_rules: ContentRules;
			edit_gate_layout_url: string;
			// wp_localize_script() stringifies booleans ('1'/''); the wizard writes real booleans back.
			presave_checks_enabled: boolean | string;
			default_gate_status: GateStatus;
			feed_restriction_modes?: { value: FeedRestrictionMode; label: string }[];
			// True while WooCommerce Memberships governs feeds, which makes the feed
			// controls inert until cutover. Same wp_localize_script() stringification
			// as presave_checks_enabled above ('1'/''), so read it truthily.
			feeds_governed_by_memberships?: boolean | string;
			institutional_access_default_text?: string;
			comment_restriction_default_message?: string;
			// Audience Management is a prerequisite for content gates. Only ever the
			// string wp_localize_script() produced ('1' on, '' off) - nothing writes a
			// real boolean back, so typing it wider would invite a `=== true` that can
			// never hold. Read it via hasAudienceManagement() in audience/components/audience-management-required.
			//
			// Optional because both keys are absent on a page whose localized config
			// predates this feature, which the readers already handle: hasAudienceManagement()
			// fails closed through `?.`, and the prerequisite screen falls back to ''.
			audience_management_enabled?: string;
			audience_management_url?: string;
		};
		// Localized by Subscribers_Wizard::enqueue_scripts_and_styles(). Absent
		// unless the Subscribers wizard page is being rendered, and the tests set
		// it partially, so every field is optional and the readers guard with
		// `||`/`?.`.
		newspackSubscribers?: {
			// The publisher's group-label overrides, blank when unset.
			groupLabel?: string;
			groupLabelPlural?: string;
			// The translated default nouns, used when no override is set.
			groupLabelDefault?: string;
			groupLabelDefaultPlural?: string;
			// Sentence templates that wrap the group noun, translated server-side.
			groupPhrases?: Record< string, string >;
			showAvatars?: boolean;
			// The /avatars endpoint's batch cap, so the client batches to it.
			avatarBatchCap?: number;
		};
		newspack_aux_data?: {
			has_completed_setup?: boolean | string;
			is_e2e?: boolean;
		};
	}

	// Setup/Complete screens read the localized Audience payload as a bare global
	// (`/* global newspackAudience */`), the same object as `window.newspackAudience`.
	const newspackAudience: Window[ 'newspackAudience' ];
}

export { };
