/* globals jQuery */

/**
 * File customize-preview.js.
 *
 * Brings logo resizing technology to the Customizer.
 *
 * Contains handlers to make Customizer preview changes asynchronously.
 */
( function ( $: NewspackThemeJQueryStatic ) {
	const api = wp.customize;
	// Called without `new` -- NewspackLogo() never uses `this` and always returns an explicit
	// object, so invoking it as a plain function is behaviorally identical.
	const Logo = NewspackLogo();
	let resizeTimer: ReturnType< typeof setTimeout > | undefined;

	api( 'custom_logo', function ( value: NewspackCustomizeValue< string > ) {
		handleLogoDetection( value() );
		value.bind( handleLogoDetection );
	} );

	api( 'logo_size', function ( value: NewspackCustomizeValue< number > ) {
		Logo.resize( value() );
		value.bind( Logo.resize );
	} );

	/**
	 */
	function handleLogoDetection( to: string, initial?: string ) {
		if ( '' === to ) {
			Logo.remove();
		} else if ( undefined === initial ) {
			Logo.add();
		} else {
			Logo.change();
		}
		initial = to; // Note: reassigning the local parameter here has no observable effect (dead store); pre-existing, left as-is.
	}

	/** A resizable custom logo instance, keyed off whether a logo is currently detected in the DOM. */
	interface LogoResizer {
		resize: ( to: number ) => void;
		add: () => void;
		change: () => void;
		remove: () => void;
	}

	/**
	 */
	function NewspackLogo(): LogoResizer {
		let hasLogo: boolean | null = null;
		const min = 48;

		const self: LogoResizer = {
			resize( to ) {
				if ( hasLogo ) {
					const img = new Image();
					const logo = $( '.site-header .custom-logo' );

					let size = {
						width: parseInt( logo.attr( 'width' ), 10 ),
						height: parseInt( logo.attr( 'height' ), 10 ),
					};

					const cssMax = {
						width: parseInt( logo.css( 'max-width' ), 10 ),
						height: parseInt( logo.css( 'max-height' ), 10 ),
					};

					const max = {
						width: $.isNumeric( cssMax.width ) ? cssMax.width : 600,
						height: $.isNumeric( cssMax.height ) ? cssMax.height : size.height,
					};

					img.onload = function () {
						if ( size.width >= size.height ) {
							// landscape or square, calculate height as short side
							const output = logo_min_max( size.height, size.width, max.height, max.width, to, min );
							size = {
								height: output.a,
								width: output.b,
							};
						} else if ( size.width < size.height ) {
							// portrait, calculate height as long side
							const output = logo_min_max( size.width, size.height, max.width, max.height, to, min );
							size = {
								height: output.b,
								width: output.a,
							};
						}

						logo.css( {
							width: size.width,
							height: size.height,
						} );
					};

					img.src = logo.attr( 'src' );

					clearTimeout( resizeTimer );
					resizeTimer = setTimeout( function () {
						$( document.body ).resize();
					}, 500 );
				}
			},

			add() {
				const intId = setInterval( function () {
					const logo = $( '.custom-logo[src]' );
					if ( logo.length ) {
						clearInterval( intId );
						hasLogo = true;
					}
				}, 500 );
			},

			change() {
				const oldlogo = $( '.custom-logo' ).attr( 'src' );
				const intId = setInterval( function () {
					const logo = $( '.custom-logo' ).attr( 'src' );
					if ( logo !== oldlogo ) {
						clearInterval( intId );
						hasLogo = true;
						self.resize( 50 );
					}
				}, 100 );
			},

			remove() {
				hasLogo = null;
			},
		};

		return self;
	}

	/**
	 * Get logo size
	 *
	 * @param {number} a    short side,
	 * @param {number} b    long side
	 * @param {number} amax short css max
	 * @param {number} bmax long css max
	 * @param {number} p    percent
	 * @param {number} m    minimum short side
	 */
	function logo_min_max( a: number, b: number, amax: number, bmax: number, p: number, m: number ): { a: number; b: number } {
		const ratio = b / a;
		const maxB = bmax >= b ? b : bmax;
		const maxA = amax >= maxB / ratio ? Math.floor( maxB / ratio ) : amax;
		const max = { a: maxA, b: maxB };

		const pixelsPerPercentagePoint = ( max.a - m ) / 100;

		// at 0%, the minimum is set, scale up from there
		const sizeA = Math.floor( m + p * pixelsPerPercentagePoint );
		// long side is calculated from the image ratio
		const sizeB = Math.floor( sizeA * ratio );

		return { a: sizeA, b: sizeB };
	}
} )( jQuery );
