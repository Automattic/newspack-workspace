/**
 * Test-only helper for mocking `useSelect` from `@wordpress/data`.
 *
 * Consumers must have module-mocked `@wordpress/data` (with `useSelect: jest.fn()`)
 * before calling the helper.
 */

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';

/** A fake `select` implementation, keyed by store name. */
export type FakeSelect = ( storeName: string ) => unknown;

/**
 * The callback form of `useSelect` as exercised by hooks under test. The real
 * `mapSelect` union also admits store descriptors; the tested hooks only ever
 * pass callbacks, so the received value is narrowed to this local shape at the
 * mock boundary instead of reaching into `@wordpress/data` internals.
 */
type MapSelectCallback = ( select: FakeSelect ) => unknown;

/**
 * Install a `useSelect` implementation that invokes the mapSelect callback with
 * the given fake `select`.
 *
 * @param fakeSelect Fake `select` returning per-store stubs.
 */
export const mockUseSelectWith = ( fakeSelect: FakeSelect ) => {
	jest.mocked( useSelect ).mockImplementation( mapSelect => ( mapSelect as MapSelectCallback )( fakeSelect ) );
};
