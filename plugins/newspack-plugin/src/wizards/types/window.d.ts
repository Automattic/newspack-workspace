declare global {
	interface Window {
		newspackWizardsAdminHeader: {
			tabs: Array<{
				textContent: string;
				href: string;
				forceSelected: boolean;
			}>;
			title: string;
		};
		newspackAudience: {
			has_reader_activation: boolean;
			has_memberships: boolean;
			new_subscription_lists_url: string;
			reader_activation_url: string;
			preview_query_keys: {
				[K in PromptOptionsBaseKey]: string;
			};
			preview_post: string;
			preview_archive: string;
			integrations_settings_enabled: boolean;
			// Whether the Newspack Content Gate / Group subscriptions feature is
			// enabled; gates the "Advanced settings" and Content Gating tabs.
			is_newspack_feature_enabled?: boolean;
			// Whether the Salesforce integration can be configured on this site.
			can_use_salesforce?: boolean;
			// Callback URL used by the Salesforce OAuth connection flow.
			salesforce_redirect_url?: string;
			// Newsletter lists available for the post-signup/checkout selector,
			// or an error map keyed by error code when they can't be loaded.
			available_newsletter_lists: import( '../../../../../packages/components/src/sortable-newsletter-list-control' ).NewsletterList[] | { errors: Record< string, string > };
			// ESP metadata field slugs offered for reader-data sync.
			esp_metadata_fields?: string[];
			// Content-gifting availability and metering context.
			content_gifting?: {
				has_metering?: boolean;
				can_use_gifting?: {
					errors?: Record< string, string[] >;
				};
			};
			// Products purchasable via gifting / countdown CTAs.
			available_products?: PurchasableProductOption[];
			// Front-end URL of the institutional (IP-range) access endpoint, localized
			// by the Content Gates wizard for the "Copy access page URL" action.
			institutional_access_url?: string;
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
			criteria: CampaignsCriteriaConfig[];
		};
		newspackAudienceDonations: {
			can_use_name_your_price: boolean;
		};
		newspackAudienceSubscriptions: {
			memberships_url: string;
			primary_product: string;
			eligible_products: Array<{
				id: string;
				title: string;
			}>;
			upgrade_subscription_url: string;
		};
		newspackAudienceIntegrations: {
			integrations_settings_enabled: boolean;
		};
		newspackAudienceContentGates: {
			api: string;
			available_access_rules: AccessRules;
			available_content_rules: ContentRules;
			edit_gate_layout_url: string;
		};
	}

	// Bare-global access to the Campaigns wizard payload (referenced without
	// the `window.` prefix in several Campaigns wizard files).
	const newspackAudienceCampaigns: Window[ 'newspackAudienceCampaigns' ];
}

export { };
