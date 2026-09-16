<?php
/**
 * Plugin Name:       JobAffinity CPT Manager
 * Plugin URI:        https://github.com/quentinnicolet/jobaffinity-cpt-manager
 * Description:       Receives job offers pushed by JobAffinity over the REST API or XML-RPC into a dedicated custom post type, with full custom field support.
 * Version:           1.4.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            JobAffinity
 * Author URI:        https://jobaffinity.fr
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
	exit; // Pas d'accès direct.
}

define( 'CCPTM_VERSION', '1.4.0' );
define( 'CCPTM_OPTION_KEY', 'ccptm_settings' );
define( 'CCPTM_PLUGIN_FILE', __FILE__ );
define( 'CCPTM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-settings.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-cpt.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-meta.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-rest.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-xmlrpc.php';
require_once CCPTM_PLUGIN_DIR . 'includes/class-ccptm-admin.php';

/**
 * Activation : on prépare des options par défaut vides
 * (l'admin choisira la clé après activation via l'écran de config).
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

	// On flush après que le CPT sera enregistré à la prochaine requête.
	set_transient( 'ccptm_flush_rewrite', 1, 60 );
}
register_activation_hook( __FILE__, 'ccptm_activate' );

/**
 * Désactivation : on nettoie les règles de réécriture.
 */
function ccptm_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ccptm_deactivate' );

/**
 * Charge les traductions livrées dans /languages.
 *
 * Sur "init" priorité 0 et non "plugins_loaded" : depuis WordPress 6.7,
 * charger un text domain avant "init" déclenche une notice
 * _load_textdomain_just_in_time. La priorité 0 passe malgré tout devant
 * CCPTM_CPT::register_cpt() (init, priorité 5), premier consommateur de
 * chaînes traduites.
 */
function ccptm_load_textdomain() {
	load_plugin_textdomain(
		'jobaffinity-cpt-manager',
		false,
		dirname( plugin_basename( CCPTM_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'ccptm_load_textdomain', 0 );

/**
 * Bootstrap : on initialise les classes du plugin.
 */
function ccptm_bootstrap() {
	CCPTM_Settings::instance();

	// Avant CCPTM_Meta : la conversion de l'ancienne cle "meta_keys" doit avoir
	// eu lieu quand register_meta_keys() s'execute sur "init" (priorite 11).
	CCPTM_Settings::maybe_migrate();

	CCPTM_CPT::instance();
	CCPTM_Meta::instance();
	CCPTM_REST::instance();
	CCPTM_XMLRPC::instance();

	if ( is_admin() ) {
		CCPTM_Admin::instance();
	}

	// Flush des rewrite rules une seule fois après activation/modification de la clé.
	if ( get_transient( 'ccptm_flush_rewrite' ) ) {
		flush_rewrite_rules();
		delete_transient( 'ccptm_flush_rewrite' );
	}
}
add_action( 'plugins_loaded', 'ccptm_bootstrap' );

/**
 * Avertissement admin si le CPT n'est pas encore configuré.
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
