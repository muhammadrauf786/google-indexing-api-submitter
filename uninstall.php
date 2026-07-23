<?php
/**
 * Runs on plugin uninstall — removes all plugin data.
 *
 * @package GoogleIndexingApiSubmitter
 */

// Only run when called from WordPress uninstall mechanism.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin option
delete_option( 'google_indexing_api_json_key' );
