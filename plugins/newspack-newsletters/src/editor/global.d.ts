/**
 * Unit-local window globals for the newsletters block editor.
 *
 * This is a global script (no top-level imports; inline import() types only).
 * `newspack_email_editor_data` is localized by the plugin's PHP (see
 * Newspack_Newsletters_Editor) and read across the editor/ and
 * newsletter-editor/ bundles. Members are optional/loose because consumers
 * read them defensively and the localized payload varies by ESP and context.
 */

/** A merge tag offered by the merge-tags autocompleter. */
interface NewspackEmailEditorMergeTag {
	tag: string;
	label: string;
	keywords?: string[];
}

/** The `newspack_email_editor_data` localized payload. */
interface NewspackEmailEditorData {
	/** Meta key under which the rendered email HTML is stored. */
	email_html_meta: string;
	/** ESP slugs that support MJML rendering. */
	supported_esps?: string[];
	/** Social icon service names the editor allows. */
	supported_social_icon_services?: string[];
	/** Base URL for bundled sample assets (post inserter previews). */
	sample_assets_url?: string;
	sponsors_flag_hex?: string;
	sponsors_flag_text_color?: string;
	conditional_tag_support?: {
		support_url?: string;
		example?: {
			before?: string;
			after?: string;
		};
	};
	labels?: {
		continue_reading_label?: string;
		byline_prefix_label?: string;
		byline_connector_label?: string;
	};
	merge_tags?: {
		label?: string;
		trigger_prefix?: string;
		tags?: NewspackEmailEditorMergeTag[];
	};
	[ key: string ]: unknown;
}

declare const newspack_email_editor_data: NewspackEmailEditorData;

interface Window {
	newspack_email_editor_data?: NewspackEmailEditorData;
}
