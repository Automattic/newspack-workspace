/* global jQuery */
/**
 * Collection Section Taxonomy quick edit functionality.
 */

import { domReady } from '../../utils';

class SectionTaxonomyQuickEdit {
	/**
	 * @param {Object} config                 Configuration object.
	 * @param {Object} config.metaDefinitions Meta definitions for the taxonomy.
	 * @param {string} config.orderColumnName Column name for the order field.
	 * @param {Object} config.inlineEditTax   WordPress inline edit tax object.
	 */
	constructor( { metaDefinitions, orderColumnName, inlineEditTax } ) {
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
	 * @param {string|Object} id Term ID or object.
	 */
	handleEdit( id ) {
		this.originalEdit.apply( this.inlineEditTax, arguments );

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
		const orderInput = editForm.querySelector( `input[name="${ this.metaDefinitions.section_order.key }"]` );
		if ( orderColumn && orderInput ) {
			orderInput.value = orderColumn.textContent.trim();
		}
	}

	/**
	 * Handle the save action.
	 */
	handleSave() {
		if ( this.isSortedByOrder ) {
			jQuery( document ).one( 'ajaxSuccess', ( event, xhr, settings ) => {
				if ( settings?.data?.includes( 'action=inline-save-tax' ) ) {
					window.location.reload();
				}
			} );
		}
		return this.originalSave.apply( this.inlineEditTax, arguments );
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
