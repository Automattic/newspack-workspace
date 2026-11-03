import { __ } from '@wordpress/i18n';
import { Icon, check } from '@wordpress/icons';
import { Stack, VisuallyHidden } from '@wordpress/ui';

import Button from '../../../../packages/components/src/button';
import { STEPS, type StepKey } from '../steps';

type StepperProps = {
	current: StepKey;
	reachable: StepKey[];
	onGo: ( key: StepKey ) => void;
};

export default function Stepper( { current, reachable, onGo }: StepperProps ) {
	const currentIndex = STEPS.findIndex( step => step.key === current );
	return (
		<nav aria-label={ __( 'Setup steps', 'newspack-plugin' ) } className="setup-stepper">
			<Stack render={ <ol /> } gap="md" className="setup-stepper__list">
				{ STEPS.map( ( step, index ) => {
					const isCurrent = index === currentIndex;
					const isDone = index < currentIndex || ( isCurrent && step.key === 'done' );
					const canGo = ! isCurrent && reachable.includes( step.key );
					const marker = (
						<span className="setup-stepper__marker" aria-hidden="true">
							{ isDone ? <Icon icon={ check } size={ 16 } /> : index + 1 }
						</span>
					);
					const label = (
						<span className="setup-stepper__label">
							{ step.label }
							{ isDone && <VisuallyHidden>{ ` ${ __( '(completed)', 'newspack-plugin' ) }` }</VisuallyHidden> }
						</span>
					);
					const classes = [ 'setup-stepper__item', isDone && ! isCurrent ? 'is-done' : '', isCurrent ? 'is-current' : '' ]
						.filter( Boolean )
						.join( ' ' );
					return (
						<Stack render={ <li /> } key={ step.key } align="center" gap="md" className={ classes }>
							{ canGo ? (
								<Button
									variant="tertiary"
									size="compact"
									className="setup-stepper__step setup-stepper__link"
									onClick={ () => onGo( step.key ) }
								>
									{ marker }
									{ label }
								</Button>
							) : (
								<span className="setup-stepper__step" aria-current={ isCurrent ? 'step' : undefined }>
									{ marker }
									{ label }
								</span>
							) }
						</Stack>
					);
				} ) }
			</Stack>
		</nav>
	);
}
