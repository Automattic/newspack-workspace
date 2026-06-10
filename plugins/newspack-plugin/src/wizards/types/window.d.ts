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
}

export { };
