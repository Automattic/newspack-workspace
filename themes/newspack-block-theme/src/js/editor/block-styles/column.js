// Add style to apply a border-radius to the Column block.
wp.domReady( () => {
	wp.blocks.registerBlockStyle( 'core/column', {
		name: 'rounded',
		label: 'Rounded',
	} );
} );
