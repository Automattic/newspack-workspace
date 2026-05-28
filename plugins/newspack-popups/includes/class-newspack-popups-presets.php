<?php
/**
 * Newspack Popups Presets class
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Presets class.
 */
final class Newspack_Popups_Presets {
	const NEWSPACK_POPUPS_RAS_PROMPTS_OPTION = 'newspack_popups_ras_prompts';
	const NEWSPACK_POPUPS_RAS_LAST_UPDATED   = 'newspack_popups_ras_prompts_last_updated';

	/**
	 * Retrieve popup preview preset prompt.
	 * Because presets don't exist yet as real WP posts, we need to mock a post object using the preset config.
	 *
	 * @param string $slug Preset slug.
	 * @return object|null Popup object, or null if no preset is found for the given slug.
	 */
	public static function retrieve_preset_popup( $slug ) {
		$presets = self::get_ras_presets();

		if ( \is_wp_error( $presets ) || ! $presets || ! isset( $presets['prompts'] ) ) {
			return null;
		}

		$preset = array_filter(
			$presets['prompts'],
			function( $preset ) use ( $slug ) {
				return $preset['slug'] === $slug;
			}
		);

		if ( empty( $preset ) ) {
			return null;
		}

		// Create a fake post object from the preset config.
		$preset                      = reset( $preset );
		$post_object                 = new stdClass();
		$post_object->ID             = \wp_rand( 10000000, 10001000 ); // Make the ID really high to avoid collision with real post IDs.
		$post_object->post_title     = $preset['title'];
		$post_object->post_author    = 1;
		$post_object->post_date      = current_time( 'mysql' );
		$post_object->post_date_gmt  = current_time( 'mysql', 1 );
		$post_object->post_content   = $preset['content'];
		$post_object->post_status    = 'publish';
		$post_object->comment_status = 'closed';
		$post_object->ping_status    = 'closed';
		$post_object->post_name      = $slug;
		$post_object->post_type      = Newspack_Popups::NEWSPACK_POPUPS_CPT;
		$post_object->filter         = 'raw'; // Don't try to fetch from the wp_posts table.
		$post_object                 = new \WP_Post( $post_object );

		$options = Newspack_Popups_Model::get_preview_query_options();

		if ( ! empty( $preset['featured_image_id'] ) ) {
			$options['featured_image_id'] = $preset['featured_image_id'];
		}

		return Newspack_Popups_Model::create_popup_object( $post_object, false, $options );
	}

	/**
	 * Get subscribable lists from the Newspack Newsletters plugin.
	 *
	 * @return array|WP_Error Array of lists.
	 */
	private static function get_newsletter_lists() {
		$provider_lists = method_exists( '\Newspack_Newsletters_Subscription', 'get_lists' ) ? \Newspack_Newsletters_Subscription::get_lists() : [];

		if ( \is_wp_error( $provider_lists ) ) {
			return $provider_lists;
		}

		$lists = array_reduce(
			$provider_lists,
			function( $acc, $list ) {
				if ( ! empty( $list['active'] ) ) {
					$acc[] = [
						'id'          => $list['id'] ?? 0,
						'label'       => $list['title'] ?? '',
						'description' => $list['description'] ?? '',
						'checked'     => false,
					];
				}

				return $acc;
			},
			[]
		);

		return $lists;
	}

	/**
	 * Retrieve default prompts + segments.
	 *
	 * @return array|WP_Error Array of prompt and segment default configs.
	 */
	public static function get_ras_presets() {
		$file = dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/presets/ras-defaults.json';

		if ( ! is_readable( $file ) ) {
			return new \WP_Error( 'newspack_popups_file_read_error', __( 'File not found or not readable.', 'newspack-popups' ) );
		}

		$data = wp_json_file_decode( $file, [ 'associative' => true ] );

		if ( empty( $data ) || empty( $data['prompts'] ) || empty( $data['segments'] ) ) {
			return new \WP_Error( 'newspack_popups_file_read_error', __( 'File is empty or invalid JSON.', 'newspack-popups' ) );
		}

		$saved_inputs = \get_option( self::NEWSPACK_POPUPS_RAS_PROMPTS_OPTION, [] );
		$lists        = self::get_newsletter_lists();

		if ( \is_wp_error( $lists ) ) {
			return $lists;
		}

		// Get override values if previewing a preset.
		$override_values = filter_input( INPUT_GET, 'values', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );

		// Populate prompt configs with saved inputs.
		$data['prompts'] = array_map(
			function( $prompt ) use ( $lists, $saved_inputs, $override_values ) {
				// Check for saved inputs.
				if ( ! empty( $saved_inputs[ $prompt['slug'] ] ) ) {
					$fields                      = array_map(
						function ( $field ) use ( $saved_inputs, $prompt ) {
							if ( ! empty( $saved_inputs[ $prompt['slug'] ][ $field['name'] ] ) ) {
								$field['value'] = $saved_inputs[ $prompt['slug'] ][ $field['name'] ];
							}
							return $field;
						},
						$prompt['user_input_fields']
					);
					$prompt['user_input_fields'] = $fields;

					// Mark as ready if all required inputs are filled.
					if ( ! empty( $saved_inputs[ $prompt['slug'] ]['ready'] ) ) {
						$prompt['ready'] = true;
					}
				}

				// Populate placeholder content with saved inputs or default values.
				foreach ( $prompt['user_input_fields'] as $field ) {
					$override = ! empty( $override_values[ $field['name'] ] ) ? $override_values[ $field['name'] ] : null;
					if ( 'array' === $field['type'] || 'string' === $field['type'] ) {
						$prompt['content'] = self::process_user_inputs( $prompt['content'], $field, $override );
					}
					if ( 'int' === $field['type'] && 'featured_image_id' === $field['name'] ) {
						if ( ! empty( $override_values['featured_image_id'] ) ) {
							$prompt['featured_image_id'] = $override_values['featured_image_id'];
						} elseif ( isset( $field['value'] ) ) {
							$prompt['featured_image_id'] = $field['value'];
						}
					}
				}

				// Append newsletter list IDs as a selectable field.
				if ( false !== strpos( $prompt['slug'], '_registration_' ) || false !== strpos( $prompt['slug'], '_newsletter_' ) ) {
					$fields                      = array_map(
						function( $field ) use ( $lists ) {
							if ( 'lists' === $field['name'] ) {
								$field['options'] = $lists;
								$field['default'] = array_map(
									function( $list ) {
										return $list['id'];
									},
									$lists
								);
							}

							return $field;
						},
						$prompt['user_input_fields']
					);
					$prompt['user_input_fields'] = $fields;
				}

				// Get full URLs for help info screenshots.
				if ( isset( $prompt['help_info']['screenshot'] ) ) {
					$file = dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/src/assets/' . $prompt['help_info']['screenshot'];
					if ( file_exists( $file ) ) {
						$prompt['help_info']['screenshot'] = \plugin_dir_url( NEWSPACK_POPUPS_PLUGIN_FILE ) . 'src/assets/' . $prompt['help_info']['screenshot'];
					} else {
						unset( $prompt['help_info']['screenshot'] ); // Avoid a 404 if the referenced file doesn't exist.
					}
				}

				return $prompt;
			},
			$data['prompts']
		);

		return $data;
	}

	/**
	 * Replace placeholders in a prompt's content with user input or default values.
	 *
	 * @param string $prompt_content Prompt content.
	 * @param array  $field Field config.
	 *               $field['name'] string Field name. Required.
	 *               $field['type'] string Field value type: array or string. Required.
	 *               $field['default'] string Field default value. Required.
	 *               $field['value'] string Field user input value.
	 *               $field['max_length'] int Max length of string-type user input value.
	 * @param string $value Optional. Override value to use instead of $field['value'].
	 *
	 * @return string Prompt content with placeholders replaced.
	 */
	public static function process_user_inputs( $prompt_content, $field, $value = null ) {
		if ( ! isset( $field['name'] ) || ! isset( $field['type'] ) || ! isset( $field['default'] ) ) {
			return $prompt_content;
		}

		$field_name = $field['name'];

		if ( ! $value ) {
			$value = isset( $field['value'] ) ? $field['value'] : $field['default'];
		}

		// If a string, crop to max_length if set.
		if ( 'string' === $field['type'] && isset( $field['max_length'] ) ) {
			$value = substr( trim( $value ), 0, $field['max_length'] );
		}
		// If an array, stringify with field name.
		if ( 'array' === $field['type'] ) {
			$value = '"' . $field_name . '": ' . \wp_json_encode( $value );
		}

		$prompt_content = str_replace( '{{' . $field_name . '}}', $value, $prompt_content );

		return $prompt_content;
	}

	/**
	 * Update saved inputs for a prompt preset.
	 *
	 * @param string $slug Slug name of the preset.
	 * @param array  $inputs Array of user inputs, keyed by field name.
	 * @return boolean True if updated, false if not.
	 */
	public static function update_preset_prompt( $slug, $inputs ) {
		$defaults         = self::get_ras_presets();
		$saved_inputs     = \get_option( self::NEWSPACK_POPUPS_RAS_PROMPTS_OPTION, [] );
		$updated          = false;
		$ready            = true;
		$missing_required = [];
		$default_slugs    = array_map(
			function( $default_prompt ) {
				return $default_prompt['slug'];
			},
			$defaults['prompts']
		);
		$default_fields   = array_reduce(
			$defaults['prompts'],
			function( $acc, $prompt ) use ( $slug ) {
				if ( $prompt['slug'] === $slug ) {
					$acc = $prompt['user_input_fields'];
				}
				return $acc;
			},
			[]
		);

		// Validate prompt slug.
		if ( ! in_array( $slug, $default_slugs, true ) ) {
			return new \WP_Error( 'newspack_popups_update_ras_prompt_error', __( 'Invalid prompt slug.', 'newspack-popups' ) );
		}

		foreach ( $inputs as $field_name => $input ) {
			$field_info = array_values(
				array_filter(
					$default_fields,
					function( $field ) use ( $field_name ) {
						return $field['name'] === $field_name;
					}
				)
			);

			// Validate prompt fields.
			if ( empty( $field_info ) ) {
				return new \WP_Error( 'newspack_popups_update_ras_prompt_error', __( 'Invalid field name.', 'newspack-popups' ) );
			}
			$field_info = $field_info[0];

			// Save input value.
			if ( isset( $input ) ) {
				$saved_inputs[ $slug ][ $field_name ] = $input;
			}

			// Determine ready state.
			if ( isset( $field_info['required'] ) && $field_info['required'] && empty( $input ) ) {
				$ready              = false;
				$missing_required[] = $field_info['label'];
			}
		}

		if ( 0 < count( $missing_required ) ) {
			return new \WP_Error(
				'newspack_popups_update_ras_prompt_required_missing',
				sprintf(
					// Translators: %s is a list of missing required fields.
					__( 'Missing required fields: %s', 'newspack-popups' ),
					implode( ', ', $missing_required )
				)
			);
		}

		if ( $ready ) {
			$saved_inputs[ $slug ]['ready'] = true;
		} else {
			unset( $saved_inputs[ $slug ]['ready'] );
		}

		\update_option( self::NEWSPACK_POPUPS_RAS_PROMPTS_OPTION, $saved_inputs );

		return self::get_ras_presets();
	}

	/**
	 * Publish RAS preset prompts and segments with user inputted content, and unpublish all other prompts and segments.
	 *
	 * @return boolean|WP_Error True if successful, WP_Error if not.
	 */
	public static function activate_ras_presets() {
		// Deactivate all existing segments.
		foreach ( Newspack_Segments_Model::get_segments() as $existing_segment ) {
			$existing_segment['configuration']['is_disabled'] = true;
			Newspack_Segments_Model::update_segment( $existing_segment );
		}

		// Deactivate all existing prompts.
		$existing_prompts = Newspack_Popups_Model::retrieve_active_popups();
		foreach ( $existing_prompts as $prompt ) {
			$updated = \wp_update_post(
				[
					'ID'          => $prompt['id'],
					'post_status' => 'draft',
				]
			);

			if ( \is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		// Get RAS presets with user input.
		$presets = self::get_ras_presets();
		if ( empty( $presets['prompts'] || empty( $presets['segments'] ) ) ) {
			return new \WP_Error( 'newspack_popups_activate_ras_prompts_error', __( 'Error creating preset prompts and segments. Please try again.', 'newspack-popups' ) );
		}

		// Set each prompt to "publish" status.
		$presets['prompts'] = array_map(
			function( $prompt ) {
				$prompt['status'] = 'publish';
				return $prompt;
			},
			$presets['prompts']
		);

		$importer = new Newspack_Popups_Importer( $presets );

		// Run the importer.
		$result = $importer->import();

		if ( ! empty( $result['errors'] ) && ! empty( array_filter( $result['errors'] ) ) ) {
			if ( class_exists( '\Newspack\Logger' ) ) {
				\Newspack\Logger::error( $result['errors'] );
			} else {
				error_log( \wp_json_encode( $result['errors'], JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new \WP_Error( 'newspack_popups_activate_ras_prompts_error', __( 'Error creating preset prompts and segments. Please try again.', 'newspack-popups' ) );
		}

		// Set the last updated timestamp.
		\update_option( self::NEWSPACK_POPUPS_RAS_LAST_UPDATED, time() );

		return true;
	}
}
