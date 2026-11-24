import { getNoticeIcon } from './icons';

describe( 'getNoticeIcon', () => {
	afterEach( () => {
		delete window.newspackUIData;
	} );

	it( 'returns a fresh copy of the icon PHP printed for the type', () => {
		window.newspackUIData = {
			noticeIcons: { error: '<svg class="newspack-ui__svg-icon--error" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" /></svg>' },
		};
		const first = getNoticeIcon( 'error' );
		const second = getNoticeIcon( 'error' );
		expect( first.tagName.toLowerCase() ).toBe( 'svg' );
		expect( first.classList.contains( 'newspack-ui__svg-icon--error' ) ).toBe( true );
		expect( first ).not.toBe( second );
	} );

	it( 'returns null when the type has no icon', () => {
		window.newspackUIData = { noticeIcons: {} };
		expect( getNoticeIcon( 'info' ) ).toBeNull();
		delete window.newspackUIData;
		expect( getNoticeIcon( 'error' ) ).toBeNull();
	} );
} );
