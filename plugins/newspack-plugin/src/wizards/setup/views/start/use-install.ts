import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { saveInstallState, type PluginInfo } from '../../api';

export const MIN_ROW_MS = 1200;

const STARTER_CONTENT = '/newspack/v1/wizard/newspack-setup-wizard/starter-content';
const ARTICLE_ESTIMATE = 40;
const POST_CONCURRENCY = 5;

export type Approach = 'generated' | 'import';
export type RowKey = 'plugins' | 'identity' | 'items' | 'homepage';
export type RowStatus = 'waiting' | 'running' | 'done' | 'failed';
export type InstallStatus = 'idle' | 'running' | 'done' | 'partial' | 'blocked';
export type SaveStatus = 'idle' | 'saving' | 'saved' | 'failed';

export type InstallRow = {
	key: RowKey;
	label: string;
	status: RowStatus;
	done?: number;
	total?: number;
};

export type InstallOptions = {
	approach: Approach;
	withStories: boolean;
	siteUrl: string;
	plugins: PluginInfo[];
};

export type InstallFailures = {
	plugins: string[];
	theme: boolean;
	identity: boolean;
	items: number;
	homepage: boolean;
};

type Pending = {
	plugins: PluginInfo[];
	theme: boolean;
	init: boolean;
	identity: boolean;
	items: number[];
	homepage: boolean;
};

type Attempt = 'theme' | 'identity' | 'items' | 'homepage';

type Result = 'done' | 'failed' | 'skip' | 'abort';

type Step = {
	key?: RowKey;
	blocking?: boolean;
	work: () => Promise< Result >;
};

const NO_FAILURES: InstallFailures = { plugins: [], theme: false, identity: false, items: 0, homepage: false };

const storyCount = () => ( window.newspack_aux_data?.is_e2e ? 12 : 40 );

const range = ( count: number ) => Array.from( { length: count }, ( _, index ) => index );

const sleep = ( ms: number ) => new Promise< void >( resolve => setTimeout( resolve, ms ) );

const starterContent = < T = unknown >( endpoint: string, data?: Record< string, unknown > ): Promise< T > =>
	apiFetch< T >( { path: `${ STARTER_CONTENT }/${ endpoint }`, method: 'POST', data } );

const errorMessage = ( error: unknown, approach: Approach ) =>
	( error as { message?: string } )?.message ||
	( approach === 'import'
		? __( 'Couldn’t reach that site. Check the address and try again.', 'newspack-plugin' )
		: __( 'Couldn’t start the install. Try again.', 'newspack-plugin' ) );

export function useInstall( options: InstallOptions ) {
	const [ rows, setRows ] = useState< InstallRow[] >( [] );
	const [ status, setStatus ] = useState< InstallStatus >( 'idle' );
	const [ units, setUnits ] = useState( { done: 0, total: 0 } );
	const [ failures, setFailures ] = useState< InstallFailures >( NO_FAILURES );
	const [ initError, setInitError ] = useState< string | null >( null );
	const [ save, setSave ] = useState< SaveStatus >( 'idle' );

	const runRef = useRef( 0 );
	const plan = useRef< InstallOptions >( options );
	const pending = useRef< Pending >( { plugins: [], theme: false, init: false, identity: false, items: [], homepage: false } );
	const attempted = useRef( new Set< Attempt >() );

	useEffect(
		() => () => {
			runRef.current++;
		},
		[]
	);

	const updateRow = ( key: RowKey, patch: Partial< InstallRow > | ( ( row: InstallRow ) => Partial< InstallRow > ) ) =>
		setRows( current => current.map( row => ( row.key === key ? { ...row, ...( typeof patch === 'function' ? patch( row ) : patch ) } : row ) ) );

	const countRow = ( key: RowKey ) => {
		updateRow( key, row => ( { done: ( row.done ?? 0 ) + 1 } ) );
		setUnits( current => ( { ...current, done: current.done + 1 } ) );
	};

	const buildSteps = ( run: number ): Step[] => {
		const alive = () => run === runRef.current;
		const { approach, siteUrl } = plan.current;
		const todo = pending.current;
		const steps: Step[] = [];

		const pushInit = () => {
			if ( todo.init ) {
				steps.push( {
					work: async () => {
						try {
							const response = await starterContent< { post_count?: number } >( 'init', { type: approach, site: siteUrl } );
							if ( ! alive() ) {
								return 'abort';
							}
							todo.init = false;
							if ( approach === 'import' ) {
								const count = Math.max( 0, Number( response?.post_count ) || 0 );
								todo.items = range( count );
								setUnits( current => ( { ...current, total: current.total - ARTICLE_ESTIMATE + count } ) );
								if ( count ) {
									updateRow( 'items', { total: count, done: 0 } );
								} else {
									setRows( current => current.filter( row => row.key !== 'items' ) );
								}
							}
							setUnits( current => ( { ...current, done: current.done + 1 } ) );
							return 'done';
						} catch ( error ) {
							if ( alive() ) {
								setInitError( errorMessage( error, approach ) );
							}
							return 'abort';
						}
					},
				} );
			}
		};

		if ( approach === 'import' ) {
			pushInit();
		}

		if ( todo.plugins.length || todo.theme ) {
			steps.push( {
				key: 'plugins',
				blocking: true,
				work: async () => {
					const failed: PluginInfo[] = [];
					for ( const plugin of todo.plugins ) {
						if ( ! alive() ) {
							return 'abort';
						}
						try {
							await apiFetch( { path: `/newspack/v1/plugins/${ plugin.Slug }/configure/`, method: 'POST' } );
							countRow( 'plugins' );
						} catch {
							failed.push( plugin );
						}
					}
					todo.plugins = failed;
					if ( failed.length ) {
						return 'failed';
					}
					attempted.current.add( 'theme' );
					try {
						await starterContent( 'theme' );
						todo.theme = false;
						setUnits( current => ( { ...current, done: current.done + 1 } ) );
					} catch {
						return 'failed';
					}
					return 'done';
				},
			} );
		}

		if ( approach !== 'import' ) {
			pushInit();
		}

		if ( todo.identity ) {
			steps.push( {
				key: 'identity',
				work: async () => {
					attempted.current.add( 'identity' );
					try {
						await starterContent( 'identity' );
						todo.identity = false;
						setUnits( current => ( { ...current, done: current.done + 1 } ) );
						return 'done';
					} catch {
						return 'failed';
					}
				},
			} );
		}

		if ( todo.items.length || ( todo.init && approach === 'import' ) ) {
			steps.push( {
				key: 'items',
				work: async () => {
					const queue = [ ...todo.items ];
					if ( ! queue.length ) {
						return 'skip';
					}
					attempted.current.add( 'items' );
					const failed: number[] = [];
					const worker = async () => {
						for ( let index = queue.shift(); index !== undefined && alive(); index = queue.shift() ) {
							try {
								await starterContent( `post/${ index }` );
								if ( alive() ) {
									countRow( 'items' );
								}
							} catch {
								failed.push( index );
							}
						}
					};
					await Promise.all( range( POST_CONCURRENCY ).map( worker ) );
					todo.items = failed.sort( ( a, b ) => a - b );
					return failed.length ? 'failed' : 'done';
				},
			} );
		}

		if ( todo.homepage ) {
			const layoutOnly = approach === 'generated' && ! plan.current.withStories;
			steps.push( {
				key: 'homepage',
				work: async () => {
					attempted.current.add( 'homepage' );
					try {
						await starterContent( 'homepage', layoutOnly ? { layout_only: true } : undefined );
						todo.homepage = false;
						setUnits( current => ( { ...current, done: current.done + 1 } ) );
						return 'done';
					} catch {
						return 'failed';
					}
				},
			} );
		}

		return steps;
	};

	const runSteps = async ( run: number, steps: Step[] ): Promise< 'finished' | 'blocked' | 'abort' > => {
		const alive = () => run === runRef.current;
		let shown: Promise< void > = Promise.resolve();
		for ( const step of steps ) {
			const work = step.work();
			const { key } = step;
			if ( key ) {
				const previous = shown;
				shown = ( async () => {
					await previous;
					if ( ! alive() ) {
						return;
					}
					updateRow( key, { status: 'running' } );
					const startedAt = Date.now();
					const result = await work;
					const remaining = startedAt + MIN_ROW_MS - Date.now();
					if ( result === 'abort' || result === 'skip' ) {
						return;
					}
					if ( remaining > 0 ) {
						await sleep( remaining );
					}
					if ( alive() ) {
						updateRow( key, { status: result === 'failed' ? 'failed' : 'done' } );
					}
				} )();
			}
			const result = await work;
			if ( result === 'abort' || ! alive() ) {
				return 'abort';
			}
			if ( result === 'failed' && step.blocking ) {
				await shown;
				return 'blocked';
			}
		}
		await shown;
		return alive() ? 'finished' : 'abort';
	};

	const persist = async () => {
		setSave( 'saving' );
		try {
			await saveInstallState( { approach: plan.current.approach, complete: true } );
			setSave( 'saved' );
			return true;
		} catch {
			setSave( 'failed' );
			return false;
		}
	};

	const execute = async ( run: number ) => {
		const outcome = await runSteps( run, buildSteps( run ) );
		if ( run !== runRef.current ) {
			return;
		}
		const todo = pending.current;
		if ( outcome === 'abort' ) {
			setRows( [] );
			setStatus( 'idle' );
			return;
		}
		const tried = attempted.current;
		setFailures( {
			plugins: todo.plugins.map( plugin => plugin.Name ),
			theme: tried.has( 'theme' ) && todo.theme,
			identity: tried.has( 'identity' ) && todo.identity,
			items: tried.has( 'items' ) ? todo.items.length : 0,
			homepage: tried.has( 'homepage' ) && todo.homepage,
		} );
		if ( outcome === 'blocked' ) {
			setStatus( 'blocked' );
			return;
		}
		if ( todo.identity || todo.items.length || todo.homepage ) {
			setStatus( 'partial' );
			return;
		}
		setStatus( 'done' );
		persist();
	};

	const start = () => {
		const run = ++runRef.current;
		plan.current = options;
		const { approach, withStories, plugins } = options;
		const isMove = approach === 'import';
		const active = plugins.filter( plugin => plugin.Status === 'active' ).length;
		const items = ! isMove && withStories ? storyCount() : 0;

		attempted.current = new Set();
		pending.current = {
			plugins: plugins.filter( plugin => plugin.Status !== 'active' ),
			theme: true,
			init: isMove || withStories,
			identity: isMove,
			items: range( items ),
			homepage: true,
		};

		const nextRows: InstallRow[] = [
			{ key: 'plugins', label: __( 'Plugins and theme', 'newspack-plugin' ), status: 'waiting', done: active, total: plugins.length },
		];
		if ( isMove ) {
			nextRows.push( { key: 'identity', label: __( 'Logo, icon, title and tagline', 'newspack-plugin' ), status: 'waiting' } );
			nextRows.push( { key: 'items', label: __( 'Articles', 'newspack-plugin' ), status: 'waiting' } );
		} else if ( items ) {
			nextRows.push( { key: 'items', label: __( 'Sample stories', 'newspack-plugin' ), status: 'waiting', done: 0, total: items } );
		}
		nextRows.push( { key: 'homepage', label: __( 'Homepage', 'newspack-plugin' ), status: 'waiting' } );

		const total = plugins.length + 1 + ( pending.current.init ? 1 : 0 ) + ( isMove ? 1 + ARTICLE_ESTIMATE : items ) + 1;

		setRows( nextRows );
		setUnits( { done: active, total } );
		setFailures( NO_FAILURES );
		setInitError( null );
		setSave( 'idle' );
		setStatus( 'running' );
		execute( run );
	};

	const retry = () => {
		const run = ++runRef.current;
		setRows( current => current.map( row => ( row.status === 'failed' ? { ...row, status: 'waiting' } : row ) ) );
		setFailures( NO_FAILURES );
		setSave( 'idle' );
		setStatus( 'running' );
		execute( run );
	};

	const clearInitError = useCallback( () => setInitError( null ), [] );

	const progress = units.total ? Math.min( 100, Math.round( ( units.done / units.total ) * 100 ) ) : 0;

	return { rows, progress, status, failures, initError, clearInitError, save, persist, start, retry };
}

export function useCountdown( active: boolean, seconds: number, onZero: () => void ): number {
	const [ remaining, setRemaining ] = useState( seconds );
	const onZeroRef = useRef( onZero );
	onZeroRef.current = onZero;

	useEffect( () => {
		if ( ! active ) {
			setRemaining( seconds );
			return;
		}
		const timer = setInterval( () => setRemaining( current => Math.max( 0, current - 1 ) ), 1000 );
		return () => clearInterval( timer );
	}, [ active, seconds ] );

	useEffect( () => {
		if ( active && remaining === 0 ) {
			onZeroRef.current();
		}
	}, [ active, remaining ] );

	return remaining;
}
