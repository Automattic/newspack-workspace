describe( 'relative-time', () => {
	const HOUR = 3600;
	const DAY = 86400;

	let dateNowSpy;

	const NOW = new Date( '2026-03-17T12:00:00Z' ).getTime();

	beforeEach( () => {
		document.body.innerHTML = '';
		delete window.newspackRelativeTime;
		dateNowSpy = jest.spyOn( Date, 'now' ).mockReturnValue( NOW );
	} );

	afterEach( () => {
		dateNowSpy.mockRestore();
		jest.resetModules();
	} );

	function createTimeElement( datetime, text, { parentClass = 'wp-block-post-date', isLink = false } = {} ) {
		const div = document.createElement( 'div' );
		div.className = parentClass;
		const time = document.createElement( 'time' );
		time.setAttribute( 'datetime', datetime );
		if ( isLink ) {
			const a = document.createElement( 'a' );
			a.href = '/test/';
			a.textContent = text;
			time.appendChild( a );
		} else {
			time.textContent = text;
		}
		div.appendChild( time );
		document.body.appendChild( div );
		return time;
	}

	function loadScript( config ) {
		window.newspackRelativeTime = config;
		jest.isolateModules( () => {
			require( './index' );
		} );
	}

	it( 'should replace date text with relative time when within cutoff', () => {
		const twoHoursAgo = new Date( NOW - 2 * HOUR * 1000 ).toISOString();
		createTimeElement( twoHoursAgo, 'March 17, 2026' );
		loadScript( { cutoff: 14 * DAY, locale: 'en_US' } );

		const time = document.querySelector( 'time' );
		expect( time.textContent ).toContain( 'ago' );
	} );

	it( 'should not replace date text beyond cutoff', () => {
		const twentyDaysAgo = new Date( NOW - 20 * DAY * 1000 ).toISOString();
		createTimeElement( twentyDaysAgo, 'February 25, 2026' );
		loadScript( { cutoff: 14 * DAY, locale: 'en_US' } );

		const time = document.querySelector( 'time' );
		expect( time.textContent ).toBe( 'February 25, 2026' );
	} );

	it( 'should skip future dates', () => {
		const tomorrow = new Date( NOW + DAY * 1000 ).toISOString();
		createTimeElement( tomorrow, 'March 18, 2026' );
		loadScript( { cutoff: 14 * DAY, locale: 'en_US' } );

		const time = document.querySelector( 'time' );
		expect( time.textContent ).toBe( 'March 18, 2026' );
	} );

	it( 'should set title attribute with localized date', () => {
		const twoHoursAgo = new Date( NOW - 2 * HOUR * 1000 ).toISOString();
		createTimeElement( twoHoursAgo, 'March 17, 2026' );
		loadScript( { cutoff: 14 * DAY, locale: 'en_US' } );

		const time = document.querySelector( 'time' );
		expect( time.getAttribute( 'title' ) ).toBeTruthy();
	} );

	it( 'should preserve anchor tag when isLink is enabled', () => {
		const twoHoursAgo = new Date( NOW - 2 * HOUR * 1000 ).toISOString();
		createTimeElement( twoHoursAgo, 'March 17, 2026', { isLink: true } );
		loadScript( { cutoff: 14 * DAY, locale: 'en_US' } );

		const anchor = document.querySelector( 'time a' );
		expect( anchor ).not.toBeNull();
		expect( anchor.textContent ).toContain( 'ago' );
		expect( anchor.getAttribute( 'href' ) ).toBe( '/test/' );
	} );

	it( 'should skip text replacement for modified date blocks with CSS class', () => {
		const twoHoursAgo = new Date( NOW - 2 * HOUR * 1000 ).toISOString();
		createTimeElement( twoHoursAgo, 'Updated 2 hours ago', {
			parentClass: 'wp-block-post-date__modified-date wp-block-post-date',
		} );
		loadScript( { cutoff: 14 * DAY, locale: 'en_US' } );

		const time = document.querySelector( 'time' );
		expect( time.textContent ).toBe( 'Updated 2 hours ago' );
	} );

	it( 'should skip text replacement for modified date blocks with data attribute', () => {
		const twoHoursAgo = new Date( NOW - 2 * HOUR * 1000 ).toISOString();
		const time = createTimeElement( twoHoursAgo, 'Updated 2 hours ago' );
		time.parentElement.setAttribute( 'data-newspack-modified', '' );
		loadScript( { cutoff: 14 * DAY, locale: 'en_US' } );

		expect( time.textContent ).toBe( 'Updated 2 hours ago' );
	} );

	it( 'should still set title on modified date blocks', () => {
		const twoHoursAgo = new Date( NOW - 2 * HOUR * 1000 ).toISOString();
		createTimeElement( twoHoursAgo, 'Updated 2 hours ago', {
			parentClass: 'wp-block-post-date__modified-date wp-block-post-date',
		} );
		loadScript( { cutoff: 14 * DAY, locale: 'en_US' } );

		const time = document.querySelector( 'time' );
		expect( time.getAttribute( 'title' ) ).toBeTruthy();
	} );

	it( 'should not run when config is missing', () => {
		const twoHoursAgo = new Date( NOW - 2 * HOUR * 1000 ).toISOString();
		createTimeElement( twoHoursAgo, 'March 17, 2026' );

		jest.isolateModules( () => {
			require( './index' );
		} );

		const time = document.querySelector( 'time' );
		expect( time.textContent ).toBe( 'March 17, 2026' );
	} );
} );
