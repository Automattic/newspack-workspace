// Add style to apply a border-radius (6px) to the Column block.
wp.domReady( () => {
	wp.blocks.registerBlockStyle( 'core/column', {
		name: 'rounded',
		label: 'Rounded',
	} );
} );
