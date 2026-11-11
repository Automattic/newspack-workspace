import { __ } from '@wordpress/i18n';

import type { InitialCheck, InstallState } from './api';

export type StepKey = 'start' | 'site-details' | 'design' | 'done';

export const STEPS: { key: StepKey; label: string; path: string }[] = [
	{ key: 'start', label: __( 'Get started', 'newspack-plugin' ), path: '/' },
	{ key: 'site-details', label: __( 'Site details', 'newspack-plugin' ), path: '/site-details' },
	{ key: 'design', label: __( 'Design', 'newspack-plugin' ), path: '/design' },
	{ key: 'done', label: __( 'Done', 'newspack-plugin' ), path: '/done' },
];

export type SetupViewProps = {
	initialCheck: InitialCheck;
	installState: InstallState;
	setInstallState: ( state: InstallState ) => void;
	goTo: ( key: StepKey ) => void;
	completeSetup: () => void;
};
