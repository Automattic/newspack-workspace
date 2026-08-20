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
			preview_query_keys: {
				[K in PromptOptionsBaseKey]: string;
			};
			preview_post: string;
			preview_archive: string;
			integrations_settings_enabled: boolean;
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
			criteria: Array<{
				category: string;
				description: string;
				id: string;
				matching_attribute: string;
				matching_function: string;
				name: string;
			}>;
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
			integrations_settings_enabled: boolean;
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
	}
}

export { };
