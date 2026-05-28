<?php
/**
 * Newspack Network related constants
 *
 * @package Newspack
 */

namespace Newspack_Network\constants;

/**
 * Webhook error responses map.
 */
const WEBHOOK_RESPONSE_ERRORS = [
	// Encryption verification failure.
	'INVALID_SIGNATURE' => 'Invalid Signature.',
	'INVALID_DATA'      => 'Bad request. Invalid Data.',
];

const EVENT_LOG_PAGE_SLUG = 'newspack-network-event-log';
