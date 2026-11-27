import { act, renderHook } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import { saveInstallState, type PluginInfo } from '../../api';
import { MIN_ROW_MS, useInstall, type InstallOptions } from './use-install';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../api', () => ( { saveInstallState: jest.fn( () => Promise.resolve() ) } ) );

const STARTER_CONTENT = '/newspack/v1/wizard/newspack-setup-wizard/starter-content/';

type Request = { path: string; data?: Record< string, unknown > };
type Handler = ( request: Request ) => Promise< unknown >;

let requests: Request[] = [];

const mockApi = ( handlers: Record< string, Handler > = {} ) => {
	requests = [];
	( apiFetch as unknown as jest.Mock ).mockImplementation( ( { path, data }: Request ) => {
		const short = path.replace( STARTER_CONTENT, '' );
		requests.push( { path: short, data } );
		const handler = handlers[ short ];
		return handler ? handler( { path: short, data } ) : Promise.resolve( {} );
	} );
};

const paths = () => requests.map( request => request.path );

const inactive = ( slug: string ): PluginInfo => ( { Slug: slug, Name: slug.toUpperCase(), Status: 'inactive' } );

const OPTIONS: InstallOptions = { approach: 'generated', withStories: true, siteUrl: '', plugins: [] };

const setup = ( options: Partial< InstallOptions > = {} ) => {
	const hook = renderHook( () => useInstall( { ...OPTIONS, ...options } ) );
	act( () => hook.result.current.start() );
	return hook;
};

const advance = ( ms: number ) => act( () => jest.advanceTimersByTimeAsync( ms ) );

const finish = () => act( () => jest.runAllTimersAsync() );

const rowStatus = ( hook: ReturnType< typeof setup >, key: string ) => hook.result.current.rows.find( row => row.key === key )?.status;

describe( 'useInstall', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		( saveInstallState as jest.Mock ).mockClear();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'checks the address first, then runs plugins, identity, articles and homepage for a move', async () => {
		mockApi( { init: () => Promise.resolve( { status: 200, post_count: 3 } ) } );
		const hook = setup( { approach: 'import', siteUrl: 'https://example.test', plugins: [ inactive( 'one' ), inactive( 'two' ) ] } );
		await finish();

		expect( paths() ).toEqual( [
			'init',
			'/newspack/v1/plugins/one/configure/',
			'/newspack/v1/plugins/two/configure/',
			'theme',
			'identity',
			'post/0',
			'post/1',
			'post/2',
			'homepage',
		] );
		expect( requests.find( request => request.path === 'init' )?.data ).toEqual( { type: 'import', site: 'https://example.test' } );
		expect( requests.find( request => request.path === 'homepage' )?.data ).toBeUndefined();
		expect( hook.result.current.rows.map( row => [ row.key, row.status ] ) ).toEqual( [
			[ 'plugins', 'done' ],
			[ 'identity', 'done' ],
			[ 'items', 'done' ],
			[ 'homepage', 'done' ],
		] );
		expect( hook.result.current.rows.find( row => row.key === 'items' ) ).toMatchObject( { label: 'Articles', done: 3, total: 3 } );
		expect( hook.result.current.status ).toBe( 'done' );
	} );

	it( 'skips the Articles row when the old site has no articles', async () => {
		mockApi( { init: () => Promise.resolve( { status: 200, post_count: 0 } ) } );
		const hook = setup( { approach: 'import', siteUrl: 'https://example.test' } );
		await finish();

		expect( paths() ).toEqual( [ 'init', 'theme', 'identity', 'homepage' ] );
		expect( hook.result.current.rows.map( row => row.key ) ).toEqual( [ 'plugins', 'identity', 'homepage' ] );
		expect( hook.result.current.status ).toBe( 'done' );
	} );

	it( 'sets up theme and homepage when sample stories are off', async () => {
		mockApi();
		const hook = setup( { withStories: false } );
		await finish();

		expect( paths() ).toEqual( [ 'theme', 'homepage' ] );
		expect( requests[ 1 ].data ).toEqual( { layout_only: true } );
		expect( hook.result.current.rows.map( row => [ row.key, row.label ] ) ).toEqual( [
			[ 'plugins', 'Plugins and theme' ],
			[ 'homepage', 'Homepage' ],
		] );
		expect( hook.result.current.status ).toBe( 'done' );
	} );

	it( 'holds a quick row in progress for at least MIN_ROW_MS', async () => {
		mockApi();
		const hook = setup( { withStories: false } );

		await advance( 0 );
		expect( paths() ).toEqual( [ 'theme', 'homepage' ] );
		expect( rowStatus( hook, 'plugins' ) ).toBe( 'running' );
		expect( rowStatus( hook, 'homepage' ) ).toBe( 'waiting' );

		await advance( MIN_ROW_MS - 1 );
		expect( rowStatus( hook, 'plugins' ) ).toBe( 'running' );

		await advance( 1 );
		expect( rowStatus( hook, 'plugins' ) ).toBe( 'done' );
		expect( rowStatus( hook, 'homepage' ) ).toBe( 'running' );

		await advance( MIN_ROW_MS - 1 );
		expect( rowStatus( hook, 'homepage' ) ).toBe( 'running' );
		expect( hook.result.current.status ).toBe( 'running' );

		await advance( 1 );
		expect( rowStatus( hook, 'homepage' ) ).toBe( 'done' );
		expect( hook.result.current.status ).toBe( 'done' );
	} );

	it( 'blocks Continue and skips the countdown when a plugin fails', async () => {
		mockApi( { '/newspack/v1/plugins/two/configure/': () => Promise.reject( { message: 'Nope' } ) } );
		const hook = setup( { plugins: [ inactive( 'one' ), inactive( 'two' ), inactive( 'three' ) ] } );
		await finish();

		expect( hook.result.current.status ).toBe( 'blocked' );
		expect( rowStatus( hook, 'plugins' ) ).toBe( 'failed' );
		expect( hook.result.current.failures ).toEqual( { plugins: [ 'TWO' ], theme: false, identity: false, items: 0, homepage: false } );
		expect( hook.result.current.rows.map( row => row.status ) ).toEqual( [ 'failed', 'waiting', 'waiting' ] );
		expect( paths() ).not.toContain( 'init' );
		expect( paths() ).not.toContain( 'homepage' );

		mockApi();
		act( () => hook.result.current.retry() );
		await finish();

		expect( paths() ).toEqual( [
			'/newspack/v1/plugins/two/configure/',
			'theme',
			'init',
			...Array.from( { length: 40 }, ( _, i ) => `post/${ i }` ),
			'homepage',
		] );
		expect( hook.result.current.status ).toBe( 'done' );
	} );

	it( 'lets the user continue past failed stories, and retries only those', async () => {
		mockApi( {
			'post/3': () => Promise.reject( { message: 'Nope' } ),
			'post/7': () => Promise.reject( { message: 'Nope' } ),
		} );
		const hook = setup();
		await finish();

		expect( hook.result.current.status ).toBe( 'partial' );
		expect( hook.result.current.failures.items ).toBe( 2 );
		expect( hook.result.current.rows.find( row => row.key === 'items' ) ).toMatchObject( { status: 'failed', done: 38, total: 40 } );
		expect( rowStatus( hook, 'homepage' ) ).toBe( 'done' );
		expect( saveInstallState ).not.toHaveBeenCalled();

		mockApi();
		act( () => hook.result.current.retry() );
		await finish();

		expect( paths() ).toEqual( [ 'post/3', 'post/7' ] );
		expect( hook.result.current.rows.find( row => row.key === 'items' ) ).toMatchObject( { status: 'done', done: 40 } );
		expect( hook.result.current.status ).toBe( 'done' );
	} );

	it( 'saves install state as complete when everything succeeded', async () => {
		mockApi();
		setup( { approach: 'import', siteUrl: 'https://example.test' } );
		await finish();

		expect( saveInstallState ).toHaveBeenCalledWith( { approach: 'import', complete: true } );
	} );

	it( 'reports a failed save, and saves again on request', async () => {
		mockApi();
		( saveInstallState as jest.Mock ).mockRejectedValueOnce( { message: 'Nope' } );
		const hook = setup( { withStories: false } );
		await finish();

		expect( hook.result.current.status ).toBe( 'done' );
		expect( hook.result.current.save ).toBe( 'failed' );

		await act( () => hook.result.current.persist() );
		expect( saveInstallState ).toHaveBeenCalledTimes( 2 );
		expect( hook.result.current.save ).toBe( 'saved' );
	} );
} );
