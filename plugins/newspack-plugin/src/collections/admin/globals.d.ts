/**
 * Ambient declarations for globals consumed by the Collections admin scripts
 * under src/collections/admin/. This file is a global script: no top-level
 * imports, so every declaration lands in the global scope.
 */

/**
 * A meta field definition as serialized by
 * Meta_Handler::get_frontend_meta_definitions(). PHP `array_filter` strips
 * null members, hence everything but `key` and `type` is optional.
 */
interface CollectionMetaDefinition {
	key: string;
	/** Frontend field type: 'url', 'array', 'boolean', 'integer' or 'text'. */
	type: string;
	label?: string;
	help?: string;
	/** Only select-type fields declare a (string) default that these scripts read. */
	default?: string | number | boolean;
	field_type?: string;
	options?: {
		label: string;
		value: string;
	}[];
}

/**
 * WordPress core's inline (quick) edit controller for taxonomy screens,
 * exposed as `window.inlineEditTax` by the `inline-edit-tags` script. Only
 * the members consumed by these scripts are declared.
 */
interface WPInlineEditTax {
	/** Opens quick edit for a term; receives the term ID or a node within the term row. */
	edit( id: string | HTMLElement ): boolean | void;
	/** Saves the quick edit form. */
	save( ...args: unknown[] ): boolean | void;
	/** Extracts the term ID from a node within a term row. */
	getId( el: HTMLElement ): string;
}

/**
 * jQuery members used by the section-taxonomy quick edit, merged into the
 * shared minimal jQuery surface (src/shared/globals.d.ts).
 */
interface NewspackJQuery {
	one( events: string, handler: ( event: NewspackJQueryEvent, xhr: unknown, settings: { data?: string } ) => void ): NewspackJQuery;
}

interface Window {
	/**
	 * Data localized as `newspackCollections` by Collections\Enqueuer for the
	 * collections admin script. Each member is only present on its screen.
	 */
	newspackCollections?: {
		/** Collection editor screen (Post_Type::output_collection_meta_data_for_admin_scripts()). */
		collectionPostType?: {
			postType: string;
			metaDefinitions: Record< string, CollectionMetaDefinition >;
			panelTitle: string;
		};
		/** Post editor screen (Post_Meta::output_post_meta_data_for_admin_scripts()). */
		postMeta?: {
			metaDefinitions: Record< string, CollectionMetaDefinition >;
			panelTitle: string;
		};
		/** Section taxonomy screen (Collection_Section_Taxonomy::output_section_taxonomy_data_for_admin_scripts()). */
		sectionTaxonomy?: {
			metaDefinitions: Record< string, CollectionMetaDefinition >;
			orderColumnName: string;
		};
	};
	inlineEditTax?: WPInlineEditTax;
}
