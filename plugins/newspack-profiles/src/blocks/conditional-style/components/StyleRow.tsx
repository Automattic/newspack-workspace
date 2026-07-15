/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	Button,
	ColorIndicator,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { __ } from '@wordpress/i18n';
import { pencil, trash } from '@wordpress/icons';
import type { ColorStyle } from './types';

type StyleRowProps = {
	value: string;
	colorStyle: ColorStyle;
	onEdit: ( value: string ) => void;
	onRemove: ( value: string ) => void;
};

export const StyleRow = ( {
	value,
	colorStyle,
	onEdit,
	onRemove,
}: StyleRowProps ) => (
	<HStack
		justify="space-between"
		className="wp-block-newspack-profiles-conditional-style__row"
	>
		<VStack
			spacing={ 1 }
			className="wp-block-newspack-profiles-conditional-style__row-content"
		>
			<div className="wp-block-newspack-profiles-conditional-style__row-value">
				{ value }
			</div>

			<HStack
				justify="flex-start"
				spacing={ 2 }
				className="wp-block-newspack-profiles-conditional-style__row-detail"
			>
				<span className="wp-block-newspack-profiles-conditional-style__row-label">
					{ __( 'Text', 'newspack-profiles' ) }
				</span>
				<ColorIndicator colorValue={ colorStyle.textColor } />
				<span className="wp-block-newspack-profiles-conditional-style__row-code">
					{ colorStyle.textColor }
				</span>
			</HStack>

			<HStack
				justify="flex-start"
				spacing={ 2 }
				className="wp-block-newspack-profiles-conditional-style__row-detail"
			>
				<span className="wp-block-newspack-profiles-conditional-style__row-label">
					{ __( 'Background', 'newspack-profiles' ) }
				</span>
				<ColorIndicator colorValue={ colorStyle.backgroundColor } />
				<span className="wp-block-newspack-profiles-conditional-style__row-code">
					{ colorStyle.backgroundColor }
				</span>
			</HStack>
		</VStack>

		<HStack
			justify="flex-start"
			spacing={ 1 }
			className="wp-block-newspack-profiles-conditional-style__actions"
		>
			<Button
				variant="tertiary"
				icon={ pencil }
				label={ __( 'Edit style', 'newspack-profiles' ) }
				size="small"
				onClick={ () => onEdit( value ) }
			/>
			<Button
				variant="tertiary"
				icon={ trash }
				label={ __( 'Remove style', 'newspack-profiles' ) }
				isDestructive
				size="small"
				onClick={ () => onRemove( value ) }
			/>
		</HStack>
	</HStack>
);
