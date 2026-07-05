/* global jQuery */
/**
 * Collection Section Taxonomy quick edit functionality.
 */

import { domReady } from '../../utils';

class SectionTaxonomyQuickEdit {
	metaDefinitions: Record< string, CollectionMetaDefinition >;
	orderColumnName: string;
	inlineEditTax: WPInlineEditTax;
	originalEdit: WPInlineEditTax[ 'edit' ];
	originalSave: WPInlineEditTax[ 'save' ];
	isSortedByOrder: boolean | undefined;

	/**
	 * @param config                 Configuration object.
	 * @param config.metaDefinitions Meta definitions for the taxonomy.
	 * @param config.orderColumnName Column name for the order field.
	 * @param config.inlineEditTax   WordPress inline edit tax object.
	 */
	constructor( {
		metaDefinitions,
		orderColumnName,
		inlineEditTax,
	}: {
		metaDefinitions: Record< string, CollectionMetaDefinition >;
		orderColumnName: string;
		inlineEditTax: WPInlineEditTax;
	} ) {
		this.metaDefinitions = metaDefinitions;
		this.orderColumnName = orderColumnName;
		this.inlineEditTax = inlineEditTax;
		this.originalEdit = inlineEditTax.edit;
		this.originalSave = inlineEditTax.save;
		this.isSortedByOrder = document.querySelector( `.wp-list-table #${ orderColumnName }` )?.classList.contains( 'sorted' );

		this.init();
	}

	/**
	 * Initialize the quick edit functionality.
	 */
	init() {
		this.inlineEditTax.edit = this.handleEdit.bind( this );
		this.inlineEditTax.save = this.handleSave.bind( this );
	}

	/**
	 * Handle the edit action.
	 *
	 * @param args Arguments forwarded to the original handler; the first is the term ID or a node within the term row.
	 */
	handleEdit( ...args: Parameters< WPInlineEditTax[ 'edit' ] > ) {
		this.originalEdit.apply( this.inlineEditTax, args );

		const [ id ] = args;
		const termId = parseInt( typeof id === 'object' ? this.inlineEditTax.getId( id ) : id, 10 );

		if ( ! termId ) {
			return;
		}

		const row = document.querySelector( `#tag-${ termId }` );
		const editForm = document.querySelector( `#edit-${ termId }` );
		if ( ! row || ! editForm ) {
			return;
		}

		const orderColumn = row.querySelector( `.column-${ this.orderColumnName }` );
		const orderInput = editForm.querySelector< HTMLInputElement >( `input[name="${ this.metaDefinitions.section_order.key }"]` );
		if ( orderColumn && orderInput && orderColumn.textContent !== null ) {
			orderInput.value = orderColumn.textContent.trim();
		}
	}

	/**
	 * Handle the save action.
	 *
	 * @param args Arguments forwarded to the original handler.
	 */
	handleSave( ...args: unknown[] ) {
		if ( this.isSortedByOrder ) {
			jQuery( document ).one( 'ajaxSuccess', ( event, xhr, settings ) => {
				if ( settings?.data?.includes( 'action=inline-save-tax' ) ) {
					window.location.reload();
				}
			} );
		}
		return this.originalSave.apply( this.inlineEditTax, args );
	}
}

// Initialize.
domReady( () => {
	const { sectionTaxonomy } = window.newspackCollections || {};
	const { inlineEditTax } = window;

	if ( sectionTaxonomy?.metaDefinitions && sectionTaxonomy?.orderColumnName && inlineEditTax ) {
		new SectionTaxonomyQuickEdit( {
			...sectionTaxonomy,
			inlineEditTax,
		} );
	}
} );
