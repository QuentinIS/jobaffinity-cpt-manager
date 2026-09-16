<?php
/**
 * Désinstallation du plugin.
 *
 * On supprime uniquement les données créées par le plugin lui-même :
 * l'option de réglages et les transients de travail.
 *
 * On ne touche PAS aux contenus : les publications du Custom Post Type,
 * leurs métadonnées et leurs termes de taxonomie restent en base. Elles
 * appartiennent au site, pas au plugin, et une suppression silencieuse
 * serait irréversible. Réactiver le plugin avec la même clé de CPT les
 * rend immédiatement de nouveau visibles.
 *
 * @package Custom_CPT_Manager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Nettoie les données du plugin pour le site courant.
 */
function ccptm_uninstall_site() {
	global $wpdb;

	delete_option( 'ccptm_settings' );
	delete_transient( 'ccptm_flush_rewrite' );

	// Les transients d'erreurs sont nommés par identifiant utilisateur
	// (ccptm_errors_12) : pas de liste connue, donc balayage ciblé.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nettoyage ponctuel à la désinstallation, aucune API cœur ne permet d'énumérer des transients par préfixe.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ccptm_errors_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ccptm_errors_' ) . '%'
		)
	);
}

// L'option est stockée par site : sur un réseau multisite, il faut donc
// parcourir chaque site plutôt que de nettoyer uniquement le site courant.
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
