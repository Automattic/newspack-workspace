/**
 * Unit-local window global for the Custom Placement and Single Prompt block
 * editor scripts (`src/blocks`), localized by
 * `Newspack_Popups::enqueue_block_assets()`.
 *
 * This is a global script (no top-level imports/exports); inline `import()`
 * types only.
 */

/** Localized data for the Campaigns blocks (`newspack-popups-blocks`). */
interface NewspackPopupsBlocksData {
	/** Custom placement slug to label. */
	custom_placements: Record< string, string >;
	/** REST route for fetching inline/manual prompts. */
	endpoint: string;
	/** The Campaigns/prompt custom post type slug. */
	post_type: string;
	/** Whether the current post being edited is itself a prompt. */
	is_prompt: boolean;
}

interface Window {
	newspack_popups_blocks_data?: NewspackPopupsBlocksData;
}
