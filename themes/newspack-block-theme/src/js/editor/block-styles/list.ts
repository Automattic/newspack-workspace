// Add style to insert a check icon before each item in the List block.
wp.domReady( () => {
	wp.blocks.registerBlockStyle( 'core/list', {
		name: 'checked',
		label: 'Checked',
	} );
} );
