import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { ReactElement, ReactNode } from 'react';
import type { PostItem } from '../../types';

interface ConfirmModalProps< Item = PostItem > {
	/** Items DataViews passed into the action. */
	items: Item[];
	/** Close handler supplied by DataViews (always provided at runtime; optional per the DataViews action contract). */
	closeModal?: () => void;
	/** Label for the confirm button at rest. */
	confirmLabel: string;
	/** Label shown while the confirm action is in flight. */
	confirmingLabel: string;
	/** Body content rendered above the buttons. */
	question: ReactNode;
	/** Apply destructive styling to the confirm button. */
	isDestructive?: boolean;
	/** Async handler invoked with `items`. Expected to surface its own error UI; rejections only flip the busy state. */
	onConfirm: ( items: Item[] ) => Promise< unknown >;
}

/**
 * Errors thrown by `onConfirm` are caught and silently flip `isBusy=false` — the modal expects
 * the handler to surface its own UI signal (e.g. a `runBulk` snackbar). Direct-async consumers
 * that don't wrap their work in `runBulk` must catch + report inside `onConfirm` themselves.
 */
export default function ConfirmModal< Item = PostItem >( {
	items,
	closeModal,
	confirmLabel,
	confirmingLabel,
	question,
	isDestructive,
	onConfirm,
}: ConfirmModalProps< Item > ): ReactElement {
	const [ isBusy, setIsBusy ] = useState( false );
	return (
		<div>
			{ /* Wrap raw strings in a <p>; pass ReactNodes through to avoid nested-paragraph markup. */ }
			{ 'string' === typeof question ? <p>{ question }</p> : question }
			<div style={ { display: 'flex', gap: '8px', justifyContent: 'flex-end' } }>
				<Button variant="tertiary" onClick={ closeModal } disabled={ isBusy }>
					{ __( 'Cancel', 'newspack-newsletters' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive={ isDestructive }
					isBusy={ isBusy }
					disabled={ isBusy }
					onClick={ async () => {
						setIsBusy( true );
						try {
							await onConfirm( items );
							closeModal?.();
						} catch ( error ) {
							setIsBusy( false );
						}
					} }
				>
					{ isBusy ? confirmingLabel : confirmLabel }
				</Button>
			</div>
		</div>
	);
}
