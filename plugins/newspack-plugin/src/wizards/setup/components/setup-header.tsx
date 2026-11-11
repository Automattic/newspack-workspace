import { Icon } from '@wordpress/icons';
import { newspack } from 'newspack-icons';

import Stepper from './stepper';
import type { StepKey } from '../steps';

type SetupHeaderProps = {
	current: StepKey;
	reachable: StepKey[];
	onGo: ( key: StepKey ) => void;
};

export default function SetupHeader( { current, reachable, onGo }: SetupHeaderProps ) {
	return (
		<header className="setup-header">
			<span className="setup-header__mark">
				<Icon icon={ newspack } size={ 32 } />
			</span>
			<Stepper current={ current } reachable={ reachable } onGo={ onGo } />
		</header>
	);
}
