import { addFilter } from '@wordpress/hooks';

addFilter( 'newspack.wizards.campaigns.conflictingPrompts', 'newspack/multibranded-site/brand-selector-filter', ( conflicts, prompt ) => {
	// If there are conflicting prompts, see if they are for different brands, in which case there will be no conflict.
	if ( conflicts.length ) {
		const promptBrandsIds = prompt.brand.length ? prompt.brand.map( b => b.term_id ) : [];

		if ( 0 === promptBrandsIds.length ) {
			// If the current prompt is not assigned to any brands, the conflicts will remain because
			// this prompt will be displayed in all pages, including brand pages.
			return conflicts;
		}

		const conflictingBrands = conflicts.filter( conflict => {
			if ( conflict.brand.length ) {
				let stillHasConflict = false;
				conflict.brand.forEach( brand => {
					// if the prompt has a brand that is also in the conflicting prompt, they are still conflicting.
					if ( promptBrandsIds.includes( brand.term_id ) ) {
						stillHasConflict = true;
					}
				} );
				return stillHasConflict;
			}
			// if current prompt has a brand and conflicting prompt has no brand, they are still conflicting.
			return true;
		} );
		return conflictingBrands;
	}
	return conflicts;
} );
