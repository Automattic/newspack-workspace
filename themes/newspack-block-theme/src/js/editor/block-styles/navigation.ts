// Add style to 'flatten' the Navigation block for mobile screens.
// TODO: Restrict this to just the vertical block vatiation.
// See: https://github.com/WordPress/gutenberg/issues/40621
wp.domReady( () => {
	wp.blocks.registerBlockStyle( 'core/navigation', {
		name: 'flatten',
		label: 'Flatten Vertical Navigation',
	} );
} );
