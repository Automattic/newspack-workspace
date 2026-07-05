/**
 * `newspack-components` (`packages/components`) builds to `dist/esm` with no
 * accompanying `.d.ts` (no `types`/`typings` field in its package.json), so its
 * public shape is re-declared here for the components/HOCs this unit imports,
 * mirroring their real prop types in `packages/components/src`.
 */
declare module 'newspack-components' {
	/**
	 * Mirrors `packages/components/src/card`'s `CardProps`. The real type also
	 * forwards `__experimentalCoreCard`/`__experimentalCoreProps` (a WP Core
	 * `Card` passthrough) and the full `HTMLAttributes<HTMLDivElement>` set, but
	 * this unit's call sites only ever pass `noBorder`/`headerActions` plus
	 * children, so the narrower shape is used here.
	 */
	interface CardProps {
		buttonsCard?: boolean;
		headerActions?: boolean;
		isNarrow?: boolean;
		isMedium?: boolean;
		isSmall?: boolean;
		isWhite?: boolean;
		noBorder?: boolean;
		className?: string;
		id?: string | number | null;
		onClick?: import( 'react' ).MouseEventHandler< HTMLDivElement > | false;
		children?: import( 'react' ).ReactNode;
	}

	function Card( props: CardProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/grid`'s destructured props (the real
	 * component has no declared props interface — it's still untyped JS-shaped
	 * despite living in a `.tsx` file).
	 */
	interface GridProps {
		className?: string;
		borders?: boolean;
		columns?: number;
		gutter?: number;
		noMargin?: boolean;
		rowGap?: number;
		children?: import( 'react' ).ReactNode;
		[ propName: string ]: unknown;
	}

	function Grid( props: GridProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/button`'s `Props` (itself derived from
	 * `@wordpress/components`' `Button`). `className` is additionally widened to
	 * tolerate `false` (ignored, like `undefined`) so callers can write
	 * `className={ condition && 'x' }`, matching this unit's call sites and the
	 * same tolerance already established on `Card`'s `onClick`.
	 */
	type ButtonProps = Omit<
		Partial< import( 'react' ).ComponentProps< typeof import( '@wordpress/components' ).Button > >,
		'href' | 'onClick' | 'className'
	> & {
		href?: string;
		loading?: boolean;
		onClick?: () => void;
		target?: import( 'react' ).HTMLAttributeAnchorTarget;
		rel?: string;
		className?: string | false;
	};

	function Button( props: ButtonProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/section-header`'s `SectionHeaderProps`.
	 * This unit's call sites only ever pass `title`/`description`, so
	 * unrelated members (badges, menu, actions, icon, backNav) are omitted.
	 */
	interface SectionHeaderProps {
		title: string | ( () => import( 'react' ).ReactNode );
		description?: import( 'react' ).ReactNode | ( () => import( 'react' ).ReactNode );
		className?: string | null;
	}

	function SectionHeader( props: SectionHeaderProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/text-control`'s destructured props (the
	 * real component is untyped JS-shaped, forwarding an inline
	 * `{ onChange: (value: string) => void; value: string | number }` cast plus
	 * whatever other props are spread onto the underlying WP `TextControl`).
	 */
	interface TextControlProps {
		className?: string;
		required?: boolean;
		isWide?: boolean;
		withMargin?: boolean;
		label?: import( 'react' ).ReactNode;
		hideLabelFromVision?: boolean;
		value?: string | number;
		onChange?: ( value: string ) => void;
		onBlur?: import( 'react' ).FocusEventHandler< HTMLInputElement >;
		[ propName: string ]: unknown;
	}

	function TextControl( props: TextControlProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/image-upload`'s `ImageUploadProps`/
	 * `SelectedImageAttachment`.
	 */
	interface ImageUploadSelectedAttachment {
		id: number;
		url: string;
		[ key: string ]: unknown;
	}

	interface ImageUploadProps {
		className?: string;
		label?: import( 'react' ).ReactNode;
		image?: { id?: number; url?: string } | string | number | null;
		onChange: ( image: ImageUploadSelectedAttachment | null ) => void;
	}

	function ImageUpload( props: ImageUploadProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/color-picker`'s `ColorPickerProps`.
	 */
	interface ColorPickerProps {
		label: import( 'react' ).ReactNode;
		help?: import( 'react' ).ReactNode;
		color?: string;
		onChange: ( color: string ) => void;
		className?: string;
	}

	function ColorPicker( props: ColorPickerProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/select-control`'s `SelectControlProps`.
	 */
	interface SelectControlOption {
		label?: string;
		value: string | number;
		disabled?: boolean;
	}

	interface SelectControlProps {
		className?: string;
		label?: import( 'react' ).ReactNode;
		hideLabelFromVision?: boolean;
		required?: boolean;
		value?: string | number | boolean | string[];
		options?: SelectControlOption[];
		onChange?: ( value: never, extra: never ) => void;
		[ propName: string ]: unknown;
	}

	function SelectControl( props: SelectControlProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/radio-control`'s `RadioControlProps`
	 * (itself `React.ComponentProps< typeof import('@wordpress/components').RadioControl >`).
	 */
	type RadioControlProps = import( 'react' ).ComponentProps< typeof import( '@wordpress/components' ).RadioControl > & {
		className?: string;
	};

	function RadioControl( props: RadioControlProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/action-card`'s `ActionCardProps`. This
	 * unit's call sites only ever pass the members below, so unrelated members
	 * (drag/expand/toggle/handoff/etc.) are omitted.
	 */
	interface ActionCardProps {
		isSmall?: boolean;
		title?: import( 'react' ).ReactNode;
		actionText?: import( 'react' ).ReactNode | null;
		className?: string;
		key?: string | number;
	}

	function ActionCard( props: ActionCardProps ): import( 'react' ).JSX.Element;

	/**
	 * Mirrors `packages/components/src/popover`'s `PopoverProps` (itself
	 * `React.ComponentProps< typeof import('@wordpress/components').Popover > & { padding }`).
	 */
	type PopoverProps = import( 'react' ).ComponentProps< typeof import( '@wordpress/components' ).Popover > & {
		padding?: string | number | false;
	};

	function Popover( props: PopoverProps ): import( 'react' ).JSX.Element;

	/**
	 * A useState for an object, mirroring `packages/components/src/hooks/useObjectState`.
	 * Nested objects will be nested, but arrays replaced.
	 */
	type ObjectStateUpdate< T > = T extends readonly unknown[]
		? T
		: T extends object
		? { [ K in keyof T ]?: ObjectStateUpdate< T[ K ] > }
		: T;

	type ObjectStateSetter< T > = {
		< K extends keyof T >( key: K ): ( value: T[ K ] ) => void;
		( update: ObjectStateUpdate< T > ): void;
	};

	interface Hooks {
		useObjectState: < T extends object >( initial?: T ) => [ T, ObjectStateSetter< T > ];
	}

	const hooks: Hooks;

	/**
	 * Mirrors `packages/components/src/with-wizard-screen`'s `WithWizardScreenProps`.
	 * This unit's call sites only ever pass `headerText`/`subHeaderText`, so
	 * unrelated members (buttons, tabbed navigation, back nav) are omitted.
	 */
	interface WithWizardScreenProps {
		headerText?: string;
		subHeaderText?: string;
	}

	function withWizardScreen< P extends object >(
		WrappedComponent: import( 'react' ).ComponentType< P & { renderPrimaryButton: ( overridingProps?: Record< string, unknown > ) => import( 'react' ).JSX.Element } >
	): ( props: P & WithWizardScreenProps ) => import( 'react' ).JSX.Element;

	/**
	 * Members `withWizard` injects into the wrapped component at runtime (see
	 * `packages/components/src/with-wizard`'s `WithWizardInjectedProps`), so
	 * they're supplied internally rather than required from the wrapped
	 * component's external caller.
	 */
	interface WithWizardInjectedProps {
		confirmAction: ( options?: Record< string, unknown > ) => void;
		getError: () => import( 'react' ).ReactNode;
		errorData: unknown;
		setError: ( error?: unknown ) => Promise< void >;
		isLoading: number;
		startLoading: ( quiet?: boolean ) => void;
		doneLoading: ( quiet?: boolean ) => void;
		wizardApiFetch: ( args: Record< string, unknown > ) => Promise< unknown >;
	}

	function withWizard< P extends object >(
		WrappedComponent: import( 'react' ).ComponentType< P >
	): ( props: Omit< P, keyof WithWizardInjectedProps > & { simpleFooter?: boolean } ) => import( 'react' ).JSX.Element;
}
