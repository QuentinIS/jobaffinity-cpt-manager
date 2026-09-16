<?php
/**
 * Plugin uninstall routine.
 *
 * Only data the plugin created itself is removed: the settings option and
 * its working transients.
 *
 * Content is deliberately left alone. The custom post type's posts, their
 * meta values and their taxonomy terms stay in the database: they belong to
 * the site, not to the plugin, and deleting them silently would be
 * irreversible. Reactivating the plugin with the same post type key makes
 * them visible again immediately.
 *
 * @package JobAffinity_CPT_Manager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Cleans up the plugin's data for the current site.
 */
function ccptm_uninstall_site() {
	global $wpdb;

	delete_option( 'ccptm_settings' );
	delete_transient( 'ccptm_flush_rewrite' );

	// Error transients are named after a user id (ccptm_errors_12), so there
	// is no known list to iterate: sweep them by prefix instead.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup at uninstall; no core API enumerates transients by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ccptm_errors_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ccptm_errors_' ) . '%'
		)
	);
}

// The option is stored per site, so on a multisite network every site has to
// be visited rather than just the current one.
if ( is_multisite() ) {
	$ccptm_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ccptm_sites as $ccptm_site_id ) {
		switch_to_blog( $ccptm_site_id );
		ccptm_uninstall_site();
		restore_current_blog();
	}

	unset( $ccptm_sites, $ccptm_site_id );
} else {
	ccptm_uninstall_site();
}
