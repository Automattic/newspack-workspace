/**
 * `select( NAMESPACE )`/`dispatch( NAMESPACE )` (this store is registered under
 * its namespace string, not an exported `StoreDescriptor`) resolve through
 * `@wordpress/data`'s generic-string overload, which -- unlike the registry
 * instance's own loose `Record<string, ...>` fallback -- evaluates to `never`
 * for a plain string. Every call site therefore casts the result to one of
 * the shapes below (mirrors the same `select( 'core/editor' ) as { ... }`
 * pattern used for un-exported core stores elsewhere in the workspace).
 *
 * These are derived from the real `actions.ts`/`selectors.ts` modules (rather
 * than hand-duplicated) so they can't drift out of sync.
 */
import type * as actionsModule from './actions';
import type * as selectorsModule from './selectors';

/** Removes the first parameter (the injected `state`) from a selector's signature. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type CurriedSelector< F > = F extends ( state: any, ...rest: infer P ) => infer R ? ( ...args: P ) => R : never;

/** `select( NAMESPACE )`'s shape: every export of `selectors.ts`, minus its `state` parameter. */
export type StoreSelectors = { [ K in keyof typeof selectorsModule ]: CurriedSelector< ( typeof selectorsModule )[ K ] > };

/** A dispatched action creator always resolves to a promise of its generator's return value (or its plain return value). */
type ActionDispatcher< F > = F extends ( ...args: infer P ) => unknown ? ( ...args: P ) => Promise< unknown > : never;

/** `dispatch( NAMESPACE )`'s shape: every export of `actions.ts`, dispatch-wrapped. */
export type StoreActions = { [ K in keyof typeof actionsModule ]: ActionDispatcher< ( typeof actionsModule )[ K ] > };
