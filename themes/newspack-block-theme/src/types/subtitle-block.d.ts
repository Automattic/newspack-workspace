declare global {
	/**
	 * Localized by Subtitle_Block::enqueue_block_assets() (includes/blocks/subtitle-block/class-subtitle-block.php)
	 * via wp_localize_script( ..., 'newspack_block_theme_subtitle_block', $script_data ), for both the
	 * post-editor and site-editor subtitle block entries.
	 */
	interface NewspackBlockThemeSubtitleBlockData {
		post_meta_name: string;
	}

	/* eslint-disable-next-line no-var */
	var newspack_block_theme_subtitle_block: NewspackBlockThemeSubtitleBlockData;
}

export {};
