import { setupTabController } from './index';

const classnames = {
	list: 'newspack-ui__segmented-control__tabs',
	content: 'newspack-ui__segmented-control__content',
};

const render = ( listAttributes = '' ) => {
	document.body.innerHTML = `
		<div class="newspack-ui__segmented-control">
			<div class="newspack-ui__segmented-control__tabs" ${ listAttributes }>
				<button type="button" class="selected">Monthly</button>
				<button type="button">Annually</button>
			</div>
			<div class="newspack-ui__segmented-control__content">
				<div>Monthly panel</div>
				<div>Annual panel</div>
			</div>
		</div>
	`;
	const element = document.querySelector( '.newspack-ui__segmented-control' );
	setupTabController( element, classnames );
	return [ ...element.querySelectorAll( 'button' ) ];
};

describe( 'setupTabController', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'exposes the selected segment with aria-pressed', () => {
		const [ monthly, annually ] = render();
		expect( monthly ).toHaveAttribute( 'aria-pressed', 'true' );
		expect( annually ).toHaveAttribute( 'aria-pressed', 'false' );

		annually.click();
		expect( monthly ).toHaveAttribute( 'aria-pressed', 'false' );
		expect( annually ).toHaveAttribute( 'aria-pressed', 'true' );
	} );

	it( 'does not set aria-pressed on the tabs of a tablist', () => {
		const [ monthly, annually ] = render( 'role="tablist"' );
		expect( monthly ).not.toHaveAttribute( 'aria-pressed' );

		annually.click();
		expect( annually ).toHaveAttribute( 'aria-selected', 'true' );
		expect( annually ).not.toHaveAttribute( 'aria-pressed' );
		expect( monthly ).not.toHaveAttribute( 'aria-pressed' );
	} );
} );
