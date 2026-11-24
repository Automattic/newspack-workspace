"use client"

import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Icon, fullscreen } from '@wordpress/icons';
import { connectFrame, readState, writeState } from 'newspack-playground/bridge';
import { Button } from '@/components/ui/button';
import { SegmentedControl, SegmentedControlItem } from '@/components/ui/segmented-control';

export interface FlowStep {
	title: string;
	note: string;
}

export interface FlowEntry {
	id: string;
	label: string;
	note: string;
}

export interface FlowFrameProps {
	src: string;
	steps: FlowStep[];
	entries?: FlowEntry[];
	contextNotes?: Record< string, FlowStep >;
	params?: string[];
	title: string;
	renderControls?: ( state: Record< string, string >, setState: ( next: Record< string, string > ) => void ) => ReactNode;
}

interface FrameState {
	step: string;
	entry?: string;
	context?: string;
	params: Record< string, string >;
}

const DEFAULT_HEIGHT = 640;

function pickParams( source: Record< string, string | undefined >, keys: string[] ) {
	return Object.fromEntries( keys.filter( ( key ) => source[ key ] ).map( ( key ) => [ key, source[ key ] as string ] ) );
}

function frameQuery( state: FrameState ) {
	return writeState( { step: state.step, entry: state.entry, ...state.params } );
}

function blockSearchShortcut( event: KeyboardEvent ) {
	if ( ( event.metaKey || event.ctrlKey ) && event.key.toLowerCase() === 'k' ) {
		event.preventDefault();
		event.stopImmediatePropagation();
	}
}

export function FlowFrame( { src, steps, entries, contextNotes, params = [], title, renderControls }: FlowFrameProps ) {
	const defaultEntry = entries?.[ 0 ]?.id;
	const headingId = useId();
	const [ frameSrc, setFrameSrc ] = useState< string | null >( null );
	const [ state, setState ] = useState< FrameState >( { step: '1', entry: defaultEntry, params: {} } );
	const [ height, setHeight ] = useState( DEFAULT_HEIGHT );
	const [ loaded, setLoaded ] = useState( false );
	const iframeRef = useRef< HTMLIFrameElement >( null );
	const bridgeRef = useRef< ReturnType< typeof connectFrame > | null >( null );
	const [ enlarged, setEnlarged ] = useState( false );
	const [ placeholder, setPlaceholder ] = useState< number | null >( null );
	const panelRef = useRef< HTMLDivElement >( null );
	const enlargeRef = useRef< HTMLButtonElement >( null );
	const closeRef = useRef< HTMLButtonElement >( null );
	const enlargedBefore = useRef( false );

	const isEntry = useCallback( ( value: unknown ): value is string => !! entries?.some( ( entry ) => entry.id === value ), [ entries ] );

	const fromSearch = useCallback( ( search: string ): FrameState => {
		const raw = readState( search );
		const step = Number( raw.step );
		return {
			step: Number.isInteger( step ) && step >= 1 && step <= steps.length ? String( step ) : '1',
			entry: isEntry( raw.entry ) ? raw.entry : defaultEntry,
			params: pickParams( raw, params ),
		};
	}, [ steps, params, defaultEntry, isEntry ] );

	const normalize = useCallback( ( reported: Record< string, string > | null | undefined ): FrameState => {
		const value = reported ?? {};
		const step = Number( value.step );
		return {
			step: Number.isInteger( step ) && step >= 0 && step <= steps.length ? String( step ) : '1',
			entry: isEntry( value.entry ) ? value.entry : defaultEntry,
			...( value.context ? { context: value.context } : {} ),
			params: pickParams( value, params ),
		};
	}, [ steps, params, defaultEntry, isEntry ] );

	const mirrorUrl = useCallback( ( next: FrameState ) => {
		const { pathname, hash } = window.location;
		window.history.replaceState( null, '', pathname + frameQuery( next ) + hash );
	}, [] );

	const closeOnEscape = useCallback( ( event: KeyboardEvent ) => {
		if ( event.key === 'Escape' && ! event.defaultPrevented ) {
			setEnlarged( false );
		}
	}, [] );

	const enlarge = useCallback( () => {
		setPlaceholder( panelRef.current?.offsetHeight ?? null );
		setEnlarged( true );
	}, [] );
	const close = useCallback( () => setEnlarged( false ), [] );

	useEffect( () => {
		if ( ! enlarged ) {
			if ( enlargedBefore.current ) {
				enlargeRef.current?.focus( { preventScroll: true } );
			}
			setPlaceholder( null );
			return;
		}
		enlargedBefore.current = true;
		const { body } = document;
		const previousOverflow = body.style.overflow;
		const previousPadding = body.style.paddingRight;
		const scrollbar = window.innerWidth - document.documentElement.clientWidth;
		body.style.overflow = 'hidden';
		if ( scrollbar > 0 ) {
			body.style.paddingRight = `${ scrollbar }px`;
		}
		closeRef.current?.focus( { preventScroll: true } );

		const onKeyDown = closeOnEscape;
		const onFocusIn = ( event: FocusEvent ) => {
			if ( panelRef.current && event.target instanceof Node && ! panelRef.current.contains( event.target ) ) {
				closeRef.current?.focus( { preventScroll: true } );
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		document.addEventListener( 'focusin', onFocusIn );
		window.addEventListener( 'keydown', blockSearchShortcut, true );
		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			document.removeEventListener( 'focusin', onFocusIn );
			window.removeEventListener( 'keydown', blockSearchShortcut, true );
			body.style.overflow = previousOverflow;
			body.style.paddingRight = previousPadding;
		};
	}, [ enlarged ] );

	useEffect( () => {
		const iframeWindow = iframeRef.current?.contentWindow;
		if ( ! enlarged || ! loaded || ! iframeWindow ) {
			return;
		}
		iframeWindow.addEventListener( 'keydown', closeOnEscape );
		iframeWindow.addEventListener( 'keydown', blockSearchShortcut, true );
		return () => {
			iframeWindow.removeEventListener( 'keydown', closeOnEscape );
			iframeWindow.removeEventListener( 'keydown', blockSearchShortcut, true );
		};
	}, [ enlarged, loaded, closeOnEscape ] );

	const onPanelKeyDown = ( event: React.KeyboardEvent ) => {
		if ( enlarged && event.key === 'Tab' && event.shiftKey && document.activeElement === closeRef.current ) {
			event.preventDefault();
			iframeRef.current?.focus();
		}
	};

	useEffect( () => {
		const initial = fromSearch( window.location.search );
		setState( initial );
		setFrameSrc( src + frameQuery( initial ) );
	}, [] );

	useEffect( () => {
		const iframe = iframeRef.current;
		if ( ! frameSrc || ! iframe ) {
			return;
		}
		const bridge = connectFrame( iframe, {
			onState: ( reported: Record< string, string > ) => {
				const next = normalize( reported );
				setState( next );
				if ( next.step !== '0' ) {
					mirrorUrl( next );
				}
			},
			onHeight: ( value: number ) => setHeight( Math.ceil( value ) ),
		} );
		bridgeRef.current = bridge;
		return () => {
			bridge.dispose();
			bridgeRef.current = null;
		};
	}, [ frameSrc ] );

	const jump = useCallback( ( step: number, entry: string | undefined, keep: Record< string, string > ) => {
		const next: FrameState = { step: String( step ), entry, params: keep };
		setState( next );
		mirrorUrl( next );
		bridgeRef.current?.setState( { step: next.step, entry: next.entry, ...next.params } );
	}, [ mirrorUrl ] );

	const controlState: Record< string, string > = { step: state.step, ...( state.entry ? { entry: state.entry } : {} ), ...state.params };
	const setControlState = ( next: Record< string, string > ) => {
		const nextStep = Number( next.step );
		jump(
			Number.isInteger( nextStep ) && nextStep >= 1 && nextStep <= steps.length ? nextStep : Number( state.step ) || 1,
			isEntry( next.entry ) ? next.entry : state.entry,
			pickParams( next, params )
		);
	};

	if ( steps.length === 0 ) {
		return null;
	}

	const step = Number( state.step );
	const fallbackContext = contextNotes ? Object.values( contextNotes )[ 0 ] : undefined;
	const context = ( state.context ? contextNotes?.[ state.context ] : undefined ) ?? fallbackContext ?? steps[ 0 ];
	const current = step ? steps[ step - 1 ] : context;
	const heading = step ? `${ step }. ${ current.title }` : current.title;
	const entryNote = entries?.find( ( entry ) => entry.id === state.entry )?.note;

	return (
		<div className="not-prose flex flex-col gap-(--newspack-ui-spacer-4)">
			<nav aria-label="Flow steps">
				<ol className="m-0 flex list-none flex-wrap gap-(--newspack-ui-spacer-1) p-0">
					{ steps.map( ( item, index ) => {
						const active = step === index + 1;
						return (
							<li key={ item.title } className="m-0 p-0">
								<Button
									type="button"
									size="sm"
									variant={ active ? 'default' : 'outline' }
									aria-current={ active ? 'step' : undefined }
									onClick={ () => jump( index + 1, defaultEntry, {} ) }
								>
									<span className="font-(weight:--newspack-ui-font-weight-strong)">{ index + 1 }</span>
									{ item.title }
								</Button>
							</li>
						);
					} ) }
				</ol>
			</nav>
			<div aria-live="polite" className="flex flex-col gap-(--newspack-ui-spacer-3)">
				<h3 className="m-0 text-(length:--newspack-ui-font-size-title-m) font-(weight:--newspack-ui-font-weight-strong) text-(color:--newspack-ui-color-text)">
					{ heading }
				</h3>
				<p className="m-0 text-(color:--newspack-ui-color-text)">{ current.note }</p>
				{ step === 1 && entries && entries.length > 0 && (
					<>
						<SegmentedControl
							aria-label="Entry point"
							value={ state.entry }
							onValueChange={ ( value ) => isEntry( value ) && value !== state.entry && jump( 1, value, state.params ) }
						>
							{ entries.map( ( entry ) => (
								<SegmentedControlItem key={ entry.id } value={ entry.id }>
									{ entry.label }
								</SegmentedControlItem>
							) ) }
						</SegmentedControl>
						<p className="m-0 text-(color:--newspack-ui-color-text)">{ entryNote }</p>
					</>
				) }
				{ renderControls?.( controlState, setControlState ) }
			</div>
			<div style={ placeholder ? { height: placeholder } : undefined }>
				{ enlarged && (
					<div
						aria-hidden="true"
						className="fixed inset-0 z-50 bg-(--newspack-ui-color-overlay-50) duration-(--newspack-ui-duration-sheet) motion-safe:animate-in motion-safe:fade-in-0"
					/>
				) }
				<div
					ref={ panelRef }
					role={ enlarged ? 'dialog' : undefined }
					aria-modal={ enlarged ? true : undefined }
					aria-labelledby={ enlarged ? headingId : undefined }
					onKeyDown={ onPanelKeyDown }
					className={
						enlarged
							? 'fixed inset-0 z-50 flex flex-col overflow-hidden bg-(--newspack-ui-color-surface) md:inset-(--newspack-ui-spacer-6) md:rounded-(--newspack-ui-radius-surface) md:[box-shadow:var(--nui-elevation-4)]'
							: 'overflow-hidden rounded-(--newspack-ui-radius-surface) border border-(color:--newspack-ui-color-border) bg-(--newspack-ui-color-surface)'
					}
				>
					<div
						className={ `flex shrink-0 items-center gap-(--newspack-ui-spacer-3) border-b border-(color:--newspack-ui-color-border) p-(--newspack-ui-spacer-2) ${
							enlarged ? 'justify-between pl-(--newspack-ui-spacer-4)' : 'justify-end'
						}` }
					>
						{ enlarged ? (
							<>
								<span id={ headingId } className="min-w-0 truncate font-(weight:--newspack-ui-font-weight-strong) text-(color:--newspack-ui-color-text)">{ heading }</span>
								<Button ref={ closeRef } type="button" size="sm" variant="outline" onClick={ close }>
									Close
								</Button>
							</>
						) : (
							<Button ref={ enlargeRef } type="button" size="sm" variant="outline" onClick={ enlarge }>
								<Icon icon={ fullscreen } />
								Enlarge
							</Button>
						) }
					</div>
					{ frameSrc && (
						<iframe
							ref={ iframeRef }
							src={ frameSrc }
							title={ title }
							onLoad={ () => setLoaded( true ) }
							style={ enlarged ? undefined : { height } }
							className={ `block w-full border-0 [color-scheme:light] ${ enlarged ? 'min-h-0 flex-1' : '' } ${ loaded ? '' : 'invisible' }` }
						/>
					) }
					{ enlarged && <div tabIndex={ 0 } onFocus={ () => closeRef.current?.focus( { preventScroll: true } ) } /> }
				</div>
			</div>
		</div>
	);
}
