<?php
/**
 * Newspack Hub Canonical Url Updated Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\Node\Canonical_Url;
use Newspack_Network\Utils\Users as User_Utils;

/**
 * Class to handle the Donation New
 *
 * This will update the local "network_donor" reader data with the information that they are donors in another site in the network
 */
class Donation_New extends Abstract_Incoming_Event {

	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->process_donation();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->process_donation();
	}

	/**
	 * Process donation
	 *
	 * @return void
	 */
	public function process_donation() {
		$email = $this->get_email();
		Debugger::log( 'Processing donation_new with email: ' . $email );
		if ( ! $email ) {
			return;
		}

		$existing_user = User_Utils::get_or_create_user_by_email( $email, $this->get_site(), $this->data->user_id ?? '' );

		if ( is_wp_error( $existing_user ) ) {
			return;
		}

		$is_renewal = $this->get_data()->is_renewal;
		if ( $is_renewal ) {
			Debugger::log( 'Ignoring donation subscription renewal with email: ' . $email );
			return;
		}

		$node               = $this->get_site();
		$recurrence         = $this->get_data()->recurrence;
		$network_donor_data = \Newspack\Reader_Data::get_data( $existing_user->ID, 'network_donor' );

		if ( $network_donor_data ) {
			$network_donor_data = json_decode( $network_donor_data, true );
		} else {
			$network_donor_data = [];
		}
		if ( ! isset( $network_donor_data[ $node ] ) ) {
			$network_donor_data[ $node ] = [];
		}
		$network_donor_data[ $node ][ $this->get_timestamp() ] = $recurrence;
		\Newspack\Reader_Data::update_item( $existing_user->ID, 'network_donor', wp_json_encode( $network_donor_data ) );
		Debugger::log( 'Updated ' . $email . ' network donor status with "' . $recurrence . '" for node ' . $node );
	}
}
