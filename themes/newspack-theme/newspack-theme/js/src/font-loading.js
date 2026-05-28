const fontsToLoad = window.newspackFontLoading?.fonts || [];
Promise.all(
	fontsToLoad.map( fontName => {
		const escapedFontName = String( fontName ).replace( /"/g, '\\"' );
		return document.fonts.load( `1rem "${ escapedFontName }"` );
	} )
).then( res => {
	if ( res.length === fontsToLoad.length ) {
		document.body.classList.remove( 'newspack--font-loading' );
	}
} );
