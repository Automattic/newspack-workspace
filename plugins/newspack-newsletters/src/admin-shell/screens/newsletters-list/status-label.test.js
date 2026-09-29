import { STATUS_KIND_LABELS, statusKindLabel } from './status-label';

describe( 'status-label', () => {
	it( 'returns the same memoised object across calls', () => {
		// Module-level cache: per-row rendering must not re-allocate.
		expect( STATUS_KIND_LABELS() ).toBe( STATUS_KIND_LABELS() );
	} );

	it( 'covers every kind from the consolidated REST status enum', () => {
		const labels = STATUS_KIND_LABELS();
		expect( labels.sent ).toBe( 'Sent' );
		expect( labels.scheduled ).toBe( 'Scheduled' );
		expect( labels.draft ).toBe( 'Draft' );
		expect( labels.trash ).toBe( 'Trash' );
	} );

	it( 'statusKindLabel returns the matching label or falls back to draft', () => {
		expect( statusKindLabel( 'sent' ) ).toBe( 'Sent' );
		expect( statusKindLabel( 'scheduled' ) ).toBe( 'Scheduled' );
		expect( statusKindLabel( 'unknown' ) ).toBe( 'Draft' );
		expect( statusKindLabel( '' ) ).toBe( 'Draft' );
	} );

	it( 'labels the "sent" kind as "Published" for the manual provider', () => {
		// The label map is memoised on first read, so seed the provider global
		// and re-require the module in an isolated registry to get a fresh cache.
		jest.isolateModules( () => {
			window.newspackNewslettersAdmin = { serviceProvider: 'manual' };
			// eslint-disable-next-line global-require
			const { STATUS_KIND_LABELS: manualLabels } = require( './status-label' );
			expect( manualLabels().sent ).toBe( 'Published' );
			// Other kinds are unaffected by the provider.
			expect( manualLabels().scheduled ).toBe( 'Scheduled' );
			delete window.newspackNewslettersAdmin;
		} );
	} );
} );
