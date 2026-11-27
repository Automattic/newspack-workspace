/**
 * Globals consumed by the Reader Registration block's front-end script.
 *
 * Global script file — no top-level imports; use inline import() types only.
 */

/**
 * Config localized by src/blocks/reader-registration/index.php on the block's
 * front-end script handle. `verification_nonce` is mutable: it is refreshed
 * from the registration response because the session changes after login.
 */
declare const reader_registration_block_config: {
	verification_url: string;
	verification_nonce: string;
};

/*
 * The Audience Management front-end config (`newspack_ras_config`) and the
 * reader-activation client interface (NewspackReaderActivation) are declared
 * canonically in newspack-scripts/types/newspack-globals.d.ts, included via
 * tsconfig.
 */
