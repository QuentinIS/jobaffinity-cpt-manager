<?php
/**
 * Plugin Name:       JobAffinity CPT Manager
 * Plugin URI:        https://github.com/QuentinIS/jobaffinity-cpt-manager
 * Description:       Receives job offers pushed by JobAffinity over the REST API or XML-RPC into a dedicated custom post type, with full custom field support.
 * Version:           1.5.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Intuition Software
 * Author URI:        https://www.intuition-software.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jobaffinity-cpt-manager
 * Domain Path:       /languages
 *
 * @package JobAffinity_CPT_Manager
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'CCPTM_VERSION', '1.5.0' );
define( 'CCPTM_OPTION_KEY', 'ccptm_settings' );
define( 'CCPTM_PLUGIN_FILE', __FILE__ );
define( 'CCPTM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-settings.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-cpt.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-meta.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-rest.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-easyposting.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-xmlrpc.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-admin.php';

/**
 * Activation: seed empty default options. The administrator picks the post
 * type key afterwards, on the settings screen.
 */
function ccptm_activate() {
	$existing = get_option( CCPTM_OPTION_KEY );

	if ( ! is_array( $existing ) ) {
		add_option(
			CCPTM_OPTION_KEY,
			array(
				'cpt_key'         => '',
				'singular'        => '',
				'plural'          => '',
				'menu_icon'       => 'dashicons-admin-post',
				'extra_meta_keys' => array(),
				'configured'      => false,
				'db_version'      => CCPTM_VERSION,
			)
		);
	}

	// Flushed on the next request, once the post type has been registered.
	set_transient( 'ccptm_flush_rewrite', 1, 60 );
}
register_activation_hook( __FILE__, 'ccptm_activate' );

/**
 * Deactivation: clear the rewrite rules.
 */
function ccptm_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ccptm_deactivate' );

/**
 * Bootstrap: instantiate the plugin's classes.
 */
function ccptm_bootstrap() {
	CCPTM_Settings::instance();

	// Before CCPTM_Meta: the old "meta_keys" option must have been converted by
	// the time register_meta_keys() runs on "init" (priority 11).
	CCPTM_Settings::maybe_migrate();

	CCPTM_CPT::instance();
	CCPTM_Meta::instance();
	CCPTM_REST::instance();
	// After CCPTM_REST: registration order is write order, and easyposting_fields
	// must be written last so its sweep sees the final state of the post.
	CCPTM_Easyposting::instance();
	CCPTM_XMLRPC::instance();

	if ( is_admin() ) {
		CCPTM_Admin::instance();
	}

	// Flush the rewrite rules once, after activation or a change of key.
	if ( get_transient( 'ccptm_flush_rewrite' ) ) {
		flush_rewrite_rules();
		delete_transient( 'ccptm_flush_rewrite' );
	}
}
add_action( 'plugins_loaded', 'ccptm_bootstrap' );

/**
 * Admin warning shown while the post type has not been configured yet.
 */
function ccptm_admin_notice_not_configured() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = get_option( CCPTM_OPTION_KEY );
	if ( is_array( $settings ) && ! empty( $settings['cpt_key'] ) ) {
		return;
	}

	$url = admin_url( 'options-general.php?page=ccptm-settings' );
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'JobAffinity CPT Manager: please configure the custom post type key. ', 'jobaffinity-cpt-manager' );
	echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open settings', 'jobaffinity-cpt-manager' ) . '</a>';
	echo '</p></div>';
}
add_action( 'admin_notices', 'ccptm_admin_notice_not_configured' );
