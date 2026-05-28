/**
 * WordPress dependencies.
 */
import { createSlotFill } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { NewspackIcon } from 'newspack-components';

const { Slot, Fill } = createSlotFill( 'NewspackAppHeaderActions' );

export const AppHeaderActions = ( { children } ) => {
	return <Fill>{ children }</Fill>;
};

export default ( { headerText, subHeaderText } ) => {
	return (
		<div className="newspack-wizard__header">
			<div className="newspack-wizard__header__inner">
				<div className="newspack-wizard__title">
					<NewspackIcon size={ 36 } />
					<div>
						{ headerText && <h2>{ headerText }</h2> }
						{ subHeaderText && <span>{ subHeaderText }</span> }
					</div>
				</div>
				<div className="newspack-wizard__header__actions">
					<Slot />
				</div>
			</div>
		</div>
	);
};
