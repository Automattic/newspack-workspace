import { Button, Dropdown, DuotoneSwatch, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { arrowDown } from '@wordpress/icons';

const COLOR_PAIR_PRESETS = [
	{
		value: 'red-on-light',
		label: __( 'Red on light', 'newspack-profiles' ),
		textColor: '#c83234',
		backgroundColor: '#fcf5f5',
	},
	{
		value: 'blue-on-light',
		label: __( 'Blue on light', 'newspack-profiles' ),
		textColor: '#1374b6',
		backgroundColor: '#f3f8fb',
	},
	{
		value: 'yellow-on-light',
		label: __( 'Yellow on light', 'newspack-profiles' ),
		textColor: '#dfa601',
		backgroundColor: '#fffcf3',
	},
	{
		value: 'white-on-red',
		label: __( 'White on red', 'newspack-profiles' ),
		textColor: '#ffffff',
		backgroundColor: '#c83234',
	},
	{
		value: 'white-on-blue',
		label: __( 'White on blue', 'newspack-profiles' ),
		textColor: '#ffffff',
		backgroundColor: '#1374b6',
	},
	{
		value: 'white-on-yellow',
		label: __( 'White on yellow', 'newspack-profiles' ),
		textColor: '#ffffff',
		backgroundColor: '#dfa601',
	},
];

type PresetColorPairControlProps = {
	nextTextColor: string;
	nextBackgroundColor: string;
	onChangeTextColor: ( color: string ) => void;
	onChangeBackgroundColor: ( color: string ) => void;
};

type PresetLabelProps = {
	label: string;
	textColor: string;
	backgroundColor: string;
};

const PresetLabel = ( {
	label,
	textColor,
	backgroundColor,
}: PresetLabelProps ) => (
	<div
		style={ {
			display: 'flex',
			alignItems: 'center',
			gap: '8px',
		} }
	>
		<DuotoneSwatch values={ [ textColor, backgroundColor ] } />
		<span>{ label }</span>
	</div>
);

export const PresetColorPairControl = ( {
	nextTextColor,
	nextBackgroundColor,
	onChangeTextColor,
	onChangeBackgroundColor,
}: PresetColorPairControlProps ) => {
	const selectedPreset = COLOR_PAIR_PRESETS.find(
		( preset ) =>
			preset.textColor === nextTextColor &&
			preset.backgroundColor === nextBackgroundColor
	);

	const handlePresetChange = ( selectedPresetValue: string ) => {
		const preset = COLOR_PAIR_PRESETS.find(
			( item ) => item.value === selectedPresetValue
		);

		if ( ! preset ) {
			return;
		}

		onChangeTextColor( preset.textColor );
		onChangeBackgroundColor( preset.backgroundColor );
	};

	return (
		<div
			style={ {
				marginBottom: '8px',
			} }
		>
			<p
				style={ {
					margin: '0 0 6px',
					textTransform: 'uppercase',
					fontSize: '11px',
					fontWeight: '500',
				} }
			>
				{ __( 'Preset color pair', 'newspack-profiles' ) }
			</p>
			<Dropdown
				renderToggle={ ( { isOpen: isPresetOpen, onToggle } ) => (
					<Button
						variant="secondary"
						onClick={ onToggle }
						aria-expanded={ isPresetOpen }
					>
						{ selectedPreset ? (
							<PresetLabel
								label={ selectedPreset.label }
								textColor={ selectedPreset.textColor }
								backgroundColor={
									selectedPreset.backgroundColor
								}
							/>
						) : (
							__( 'Custom Colors', 'newspack-profiles' )
						) }
						<Icon icon={ arrowDown } />
					</Button>
				) }
				renderContent={ ( { onClose: closePresetDropdown } ) => (
					<div
						style={ {
							display: 'flex',
							flexDirection: 'column',
							minWidth: '220px',
							padding: '4px',
							gap: '2px',
						} }
					>
						{ COLOR_PAIR_PRESETS.map( ( preset ) => {
							const isActive =
								selectedPreset?.value === preset.value;

							return (
								<Button
									key={ preset.value }
									variant={
										isActive ? 'primary' : 'tertiary'
									}
									onClick={ () => {
										handlePresetChange( preset.value );
										closePresetDropdown();
									} }
								>
									<PresetLabel
										label={ preset.label }
										textColor={ preset.textColor }
										backgroundColor={
											preset.backgroundColor
										}
									/>
								</Button>
							);
						} ) }
					</div>
				) }
			/>
		</div>
	);
};
