/**
 * Ambient declarations for the front-end content-gate globals localized by PHP
 * (Content_Gate, Metering, Content_Gifting). Global script file: no top-level
 * imports; inline import() types only.
 *
 * wp_localize_script() casts top-level scalar members to strings, so those are
 * typed as strings here even where the PHP value is an int. Nested members are
 * JSON-encoded and keep their original JSON types.
 */

/**
 * Gate metadata carried in `newspack_content_gate.metadata`, localized by
 * Content_Gate::get_gate_metadata(). Nested (JSON-encoded), so the gate post ID
 * survives as a number (or `false` when there is no gate).
 */
interface NewspackContentGateMetadata {
	gate_post_id?: number | false;
	logged_in?: string;
	[ key: string ]: unknown;
}

/**
 * `newspack_content_gate`. Localized as `{ metadata }` on the front-end gate
 * script (Content_Gate::enqueue_scripts) and as `{ has_campaigns }` on the gate
 * editor script (trait-content-gate-layout). Members are optional because each
 * bundle localizes only its own subset.
 */
interface NewspackContentGate {
	metadata?: NewspackContentGateMetadata;
	has_campaigns?: boolean;
}

declare const newspack_content_gate: NewspackContentGate;

/**
 * Metering settings localized as `newspack_metering_settings` by
 * Metering::enqueue_scripts(). The bare global is declared canonically in
 * src/blocks/globals.d.ts (consumed by the Countdown view too); this interface
 * captures the full shape the metering entry casts it to. `article_view` and
 * `other_settings` are nested (JSON), so they keep their object/null types.
 */
interface NewspackMeteringSettings {
	visible_paragraphs?: number | string;
	use_more_tag?: boolean | string;
	count: number | string;
	period?: string;
	gate_id?: number | string;
	post_id: number | string;
	article_view?: { action: string; data: Record< string, unknown > } | null;
	excerpt: string;
	other_settings?: Record< string, unknown >;
}

/**
 * Content-gifting modal config localized as `newspack_content_gifting` by
 * Content_Gifting::localize_assets(). All members are top-level scalars, hence
 * strings.
 */
interface NewspackContentGifting {
	ajax_url: string;
	post_id: string;
	copied_label: string;
	expiration_time: string;
}

declare const newspack_content_gifting: NewspackContentGifting;

interface Window {
	/** Present on singular views when metering is enabled; seeded to `{}` by the content banner. */
	newspack_metering_settings?: NewspackMeteringSettings;
}
