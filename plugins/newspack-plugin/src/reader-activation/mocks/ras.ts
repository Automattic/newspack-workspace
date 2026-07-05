/**
 * Creates a mock RAS (Reader Activation System) object for testing.
 *
 * @return {Object} Mock RAS with store, event handlers, and activity helpers.
 */
export function createMockRAS() {
	const storeData = {};
	const activities = [];
	const handlers = {};

	const ras = {
		store: {
			get: jest.fn( key => storeData[ key ] ?? null ),
			set: jest.fn( ( key, value ) => {
				storeData[ key ] = value;
			} ),
			register: jest.fn(),
		},
		on: jest.fn( ( event, callback ) => {
			handlers[ event ] = callback;
		} ),
		getActivities: jest.fn( () => activities ),
		getUniqueActivitiesBy: jest.fn( () => {
			const seen = {};
			return activities.filter( a => {
				if ( seen[ a.data.post_id ] ) {
					return false;
				}
				seen[ a.data.post_id ] = true;
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
		 * @param {string} action    Activity action name.
		 * @param {Object} data      Activity data.
		 * @param {number} timestamp Optional timestamp.
		 */
		addActivity( action, data, timestamp = Date.now() ) {
			activities.push( { action, data, timestamp } );
		},
		/**
		 * Trigger a registered event handler.
		 *
		 * @param {string} event  Event name.
		 * @param {Object} detail Event detail payload.
		 */
		trigger( event, detail ) {
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
