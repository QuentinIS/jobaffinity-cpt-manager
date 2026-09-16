<?php
/**
 * Plugin Name: Custom CPT Manager
 * Plugin URI:  https://jobaffinity.fr
 * Description: Crée un Custom Post Type configurable (clé choisie à l'installation, ex. "offer") destiné à recevoir les offres publiées par JobAffinity. Identique aux articles natifs, exposé dans l'API REST WordPress avec support dynamique des champs personnalisés envoyés par JobAffinity (job_id, job_link, job_contract_type, champs custom_*, etc.). Accès administrateur, éditeur et auteur.
 * Version:     1.3.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author:      JobAffinity
 * Author URI:  https://jobaffinity.fr
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: custom-cpt-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Pas d'accès direct.
}

define( 'CCPTM_VERSION', '1.3.0' );
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
	echo esc_html__( 'Custom CPT Manager : veuillez configurer la clé du Custom Post Type. ', 'custom-cpt-manager' );
	echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ouvrir la configuration', 'custom-cpt-manager' ) . '</a>';
	echo '</p></div>';
}
add_action( 'admin_notices', 'ccptm_admin_notice_not_configured' );
