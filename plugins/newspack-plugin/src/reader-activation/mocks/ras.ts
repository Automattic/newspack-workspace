/**
 * An event handler as recorded by the mock's `on()`. The reader-activation
 * setup helpers only subscribe to the `activity` event, so the mock models
 * that single detail shape.
 */
type MockActivityHandler = ( event: { detail: NewspackReaderActivity } ) => void;

/**
 * Creates a mock RAS (Reader Activation System) object for testing.
 *
 * @return Mock RAS with store, event handlers, and activity helpers.
 */
export function createMockRAS() {
	const storeData: Record< string, unknown > = {};
	const activities: NewspackReaderActivity[] = [];
	const handlers: Record< string, MockActivityHandler > = {};

	const ras = {
		store: {
			get: jest.fn( ( key: string ) => storeData[ key ] ?? null ),
			set: jest.fn( ( key: string, value: unknown ) => {
				storeData[ key ] = value;
			} ),
			register: jest.fn(),
		},
		on: jest.fn( ( event: 'activity', callback: MockActivityHandler ) => {
			handlers[ event ] = callback;
		} ),
		getActivities: jest.fn( () => activities ),
		getUniqueActivitiesBy: jest.fn( () => {
			const seen: Record< string, boolean > = {};
			return activities.filter( a => {
				const postId = a.data.post_id as string;
				if ( seen[ postId ] ) {
					return false;
				}
				seen[ postId ] = true;
				return true;
			} );
		} ),
	};

	return {
		ras,
		/**
		 * Get the current store data.
		 */
		storeData,
		/**
		 * Add an activity to the internal activities array.
		 *
		 * @param action    Activity action name.
		 * @param data      Activity data.
		 * @param timestamp Optional timestamp.
		 */
		addActivity( action: string, data: Record< string, unknown >, timestamp = Date.now() ) {
			activities.push( { action, data, timestamp } );
		},
		/**
		 * Trigger a registered event handler.
		 *
		 * @param event  Event name.
		 * @param detail Event detail payload.
		 */
		trigger( event: string, detail: NewspackReaderActivity ) {
			if ( handlers[ event ] ) {
				handlers[ event ]( { detail } );
			}
		},
		/**
		 * Reset all state between tests.
		 */
		reset() {
			for ( const key in storeData ) {
				delete storeData[ key ];
			}
			for ( const event in handlers ) {
				delete handlers[ event ];
			}
			activities.length = 0;
			jest.clearAllMocks();
		},
	};
}
