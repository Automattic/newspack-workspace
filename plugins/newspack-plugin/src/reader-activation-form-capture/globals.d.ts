/**
 * Ambient declarations for the reader-activation-form-capture entry
 * (src/reader-activation-form-capture). These globals are consumed only by
 * this unit's modules.
 *
 * Global script file — no top-level imports; use inline import() types only.
 */

/**
 * A capturing integration's config entry: the selectors that match its forms
 * and the integration ID passed to readerActivation.register().
 */
interface NewspackFormCaptureEntry {
	selectors?: string[];
	integration: string;
}

/**
 * Front-end config, localized as `newspack_form_capture` by Form_Capture. An
 * integration appears only when it is active and capturing.
 */
interface NewspackFormCaptureConfig {
	gravity_forms?: NewspackFormCaptureEntry;
	other_forms?: NewspackFormCaptureEntry;
}

/**
 * Editor config, localized as `newspack_form_capture_editor` for the Gravity
 * Forms block's capture toggle.
 */
interface NewspackFormCaptureEditorConfig {
	active?: boolean;
	integrations_url?: string;
	[ key: string ]: unknown;
}

/**
 * Google reCAPTCHA's pre-load config. api.js drains `fns` on load, so callbacks
 * queued before the script lands still run.
 */
interface NewspackGrecaptchaCfg {
	fns?: Array< () => void >;
	[ key: string ]: unknown;
}

interface Window {
	newspack_form_capture?: NewspackFormCaptureConfig;
	newspack_form_capture_editor?: NewspackFormCaptureEditorConfig;
	___grecaptcha_cfg?: NewspackGrecaptchaCfg;
}
