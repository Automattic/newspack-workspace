/**
 * A local-list-modal extension: a plugin-provided contribution rendered inside
 * the local/ESP list modal. Extensions are open-ended contributions, so only
 * the fields this module reads are typed.
 */
export interface LocalListModalExtension {
	/** List kinds the extension applies to; defaults to `[ 'local' ]`. */
	appliesTo?: string[];
	[ key: string ]: unknown;
}

// `window` so both bundles importing this module share one Map (per-bundle copies would isolate registrations).
const REGISTRY_KEY = '__newspackNewslettersLocalListModalExtensions';

// Module-scoped fallback for SSR / non-jsdom — without this a fresh Map per call would drop every registration.
const FALLBACK_REGISTRY = new Map< string, LocalListModalExtension >();

function getRegistry(): Map< string, LocalListModalExtension > {
	if ( typeof window === 'undefined' ) {
		return FALLBACK_REGISTRY;
	}
	if ( ! window[ REGISTRY_KEY ] ) {
		window[ REGISTRY_KEY ] = new Map< string, LocalListModalExtension >();
	}
	return window[ REGISTRY_KEY ]!;
}

export function registerLocalListModalExtension( id: string, definition: LocalListModalExtension ) {
	const registry = getRegistry();
	if ( registry.has( id ) ) {
		// eslint-disable-next-line no-console
		console.warn( `[newspack-newsletters] Replacing local-list-modal extension "${ id }".` );
	}
	registry.set( id, definition );
}

// Default keeps pre-`appliesTo` extensions local-only.
const DEFAULT_APPLIES_TO = [ 'local' ];

function appliesToKind( definition: LocalListModalExtension, kind: string ) {
	const scope = Array.isArray( definition?.appliesTo ) && definition.appliesTo.length > 0 ? definition.appliesTo : DEFAULT_APPLIES_TO;
	return scope.includes( kind );
}

export function getLocalListModalExtensions( kind = 'local' ) {
	return Array.from( getRegistry().values() ).filter( ext => appliesToKind( ext, kind ) );
}

if ( typeof window !== 'undefined' ) {
	const np: NewspackGlobal = window.newspack || {};
	window.newspack = np;
	const newsletters: NewspackNewslettersNamespace = np.newsletters || {};
	np.newsletters = newsletters;

	const pending = newsletters._pendingExtensions || [];
	pending.forEach( ( [ id, definition ] ) => registerLocalListModalExtension( id, definition ) );
	newsletters._pendingExtensions = [];

	newsletters.registerLocalListModalExtension = registerLocalListModalExtension;
}
