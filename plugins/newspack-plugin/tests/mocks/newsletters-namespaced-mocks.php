<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace Newspack\Newsletters;

if ( ! class_exists( 'Newspack\Newsletters\Subscription_List' ) ) {
	class Subscription_List {
		private $id;

		public function __construct( int $id ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_public_id(): string {
			return 'list-' . $this->id;
		}
	}
}

if ( ! class_exists( 'Newspack\Newsletters\Subscription_Lists' ) ) {
	class Subscription_Lists {
		const CPT = 'np_newsletter_list';
	}
}
