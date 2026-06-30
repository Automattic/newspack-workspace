import { STATUS_KIND_LABELS, statusKindLabel } from './status-label';

describe( 'ads status-label', () => {
	it( 'returns the same memoised object across calls', () => {
		expect( STATUS_KIND_LABELS() ).toBe( STATUS_KIND_LABELS() );
	} );

	it( 'covers every kind from the consolidated REST status enum', () => {
		const labels = STATUS_KIND_LABELS();
		expect( labels.active ).toBe( 'Active' );
		expect( labels.scheduled ).toBe( 'Scheduled' );
		expect( labels.expired ).toBe( 'Expired' );
		expect( labels.draft ).toBe( 'Draft' );
		expect( labels.trash ).toBe( 'Trash' );
	} );

	it( 'statusKindLabel returns the matching label or falls back to draft', () => {
		expect( statusKindLabel( 'active' ) ).toBe( 'Active' );
		expect( statusKindLabel( 'expired' ) ).toBe( 'Expired' );
		expect( statusKindLabel( 'unknown' ) ).toBe( 'Draft' );
		expect( statusKindLabel( '' ) ).toBe( 'Draft' );
	} );
} );
