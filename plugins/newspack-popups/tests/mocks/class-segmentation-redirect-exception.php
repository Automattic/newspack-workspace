<?php
/**
 * Test helper exception for capturing redirects.
 *
 * @package Newspack_Popups
 */

/**
 * Thrown from a `wp_redirect` filter to stand in for the `exit` that follows a
 * redirect, which would otherwise take the test runner down with it.
 */
class Segmentation_Redirect_Exception extends Exception {}
