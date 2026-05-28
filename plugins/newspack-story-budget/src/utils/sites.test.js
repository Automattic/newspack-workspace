import { getCredentials, setCredentials, clearCredentials, isAuthorizingSite, getAuthorizationData, isRemoteSite, getLeaveSiteUrl } from './sites';

// Mock global window object and localStorage
const mockLocalStorageData = {};
const originalWindow = { ...window };

describe( 'sites utils', () => {
	beforeEach( () => {
		// Mock localStorage
		Object.defineProperty( window, 'localStorage', {
			value: {
				getItem: jest.fn( key => mockLocalStorageData[ key ] || null ),
				setItem: jest.fn( ( key, value ) => {
					mockLocalStorageData[ key ] = value;
				} ),
				removeItem: jest.fn( key => {
					delete mockLocalStorageData[ key ];
				} ),
			},
			writable: true,
		} );

		// Mock location and URL handling
		delete window.location;
		window.location = {
			href: 'https://newssite.com/story-budget',
			search: '',
			hash: '',
		};

		Object.keys( mockLocalStorageData ).forEach( key => {
			delete mockLocalStorageData[ key ];
		} );
	} );

	afterEach( () => {
		window = { ...originalWindow };
	} );

	describe( 'credentials management', () => {
		const testUrl = 'https://test.com';
		const testCredentials = 'dXNlcm5hbWU6cGFzc3dvcmQ='; // base64 of username:password

		it( 'should set credentials in localStorage', () => {
			setCredentials( testUrl, 'username', 'password' );
			expect( window.localStorage.setItem ).toHaveBeenCalledWith( 'newspack-story-budget-site-https://test.com', 'dXNlcm5hbWU6cGFzc3dvcmQ=' );
		} );

		it( 'should get credentials from localStorage', () => {
			mockLocalStorageData[ 'newspack-story-budget-site-https://test.com' ] = testCredentials;
			const credentials = getCredentials( testUrl );
			expect( credentials ).toBe( testCredentials );
			expect( window.localStorage.getItem ).toHaveBeenCalledWith( 'newspack-story-budget-site-https://test.com' );
		} );

		it( 'should clear credentials from localStorage', () => {
			mockLocalStorageData[ 'newspack-story-budget-site-https://test.com' ] = testCredentials;
			clearCredentials( testUrl );
			expect( window.localStorage.removeItem ).toHaveBeenCalledWith( 'newspack-story-budget-site-https://test.com' );
		} );
	} );

	describe( 'isAuthorizingSite', () => {
		it( 'should return true when application_password is 1', () => {
			window.location.search = '?application_password=1';
			expect( isAuthorizingSite() ).toBe( true );
		} );

		it( 'should return false when application_password is not 1', () => {
			window.location.search = '?application_password=0';
			expect( isAuthorizingSite() ).toBe( false );

			window.location.search = '';
			expect( isAuthorizingSite() ).toBe( false );
		} );
	} );

	describe( 'getAuthorizationData', () => {
		it( 'should extract authorization data from URL parameters', () => {
			window.location.search = '?site_url=https://test.com&user_login=admin&password=pass123&success=true';
			const authData = getAuthorizationData();
			expect( authData ).toEqual( {
				siteUrl: 'https://test.com',
				login: 'admin',
				password: 'pass123',
				success: true,
			} );
		} );

		it( 'should handle success=false correctly', () => {
			window.location.search = '?site_url=https://test.com&user_login=admin&password=pass123&success=false';
			const authData = getAuthorizationData();
			expect( authData.success ).toBe( false );
		} );

		it( 'should handle missing parameters', () => {
			window.location.search = '';
			const authData = getAuthorizationData();
			expect( authData ).toEqual( {
				siteUrl: null,
				login: null,
				password: null,
				success: true,
			} );
		} );
	} );

	describe( 'isRemoteSite', () => {
		it( 'should return true when site_url is in search params', () => {
			window.location.search = '?site_url=https://test.com';
			expect( isRemoteSite() ).toBe( true );
		} );

		it( 'should return false when site_url is not in search params', () => {
			window.location.search = '';
			expect( isRemoteSite() ).toBe( false );
		} );
	} );

	describe( 'getLeaveSiteUrl', () => {
		it( 'should remove site_url parameter from URL', () => {
			window.location.href = 'https://newssite.com/story-budget?site_url=https://test.com&other=param';
			const leaveUrl = getLeaveSiteUrl();
			expect( leaveUrl ).toBe( 'https://newssite.com/story-budget?other=param' );
		} );

		it( 'should keep other parameters when removing site_url', () => {
			window.location.href = 'https://newssite.com/story-budget?site_url=https://test.com&page=1&view=all';
			const leaveUrl = getLeaveSiteUrl();
			expect( leaveUrl.includes( 'page=1' ) ).toBe( true );
			expect( leaveUrl.includes( 'view=all' ) ).toBe( true );
			expect( leaveUrl.includes( 'site_url' ) ).toBe( false );
		} );

		it( 'should return URL without changes when no site_url is present', () => {
			window.location.href = 'https://newssite.com/story-budget?page=1';
			const leaveUrl = getLeaveSiteUrl();
			expect( leaveUrl ).toBe( 'https://newssite.com/story-budget?page=1' );
		} );
	} );
} );
