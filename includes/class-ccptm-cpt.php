<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enregistrement du Custom Post Type configurable.
 *
 * Le CPT est enregistré de manière à être "identique" aux posts natifs :
 * - mêmes supports (title, editor, author, thumbnail, excerpt, comments, custom-fields, etc.)
 * - exposé dans l'API REST avec la même base de route que le post standard
 * - visible dans l'admin, avec ses propres capabilities basées sur celles du post
 */
class CCPTM_CPT {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ), 5 );
	}

	/**
	 * Enregistre le CPT si une clé est configurée.
	 */
	public function register_cpt() {
		$settings = CCPTM_Settings::get();
		$key      = $settings['cpt_key'];

		if ( empty( $key ) ) {
			return;
		}

		$singular = ! empty( $settings['singular'] ) ? $settings['singular'] : ucfirst( $key );
		$plural   = ! empty( $settings['plural'] )   ? $settings['plural']   : $singular . 's';

		$labels = array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'name_admin_bar'        => $singular,
			'add_new'               => __( 'Ajouter', 'custom-cpt-manager' ),
			'add_new_item'          => sprintf( __( 'Ajouter un nouveau %s', 'custom-cpt-manager' ), $singular ),
			'new_item'              => sprintf( __( 'Nouveau %s', 'custom-cpt-manager' ), $singular ),
			'edit_item'             => sprintf( __( 'Modifier %s', 'custom-cpt-manager' ), $singular ),
			'view_item'             => sprintf( __( 'Voir %s', 'custom-cpt-manager' ), $singular ),
			'all_items'             => sprintf( __( 'Tous les %s', 'custom-cpt-manager' ), $plural ),
			'search_items'          => sprintf( __( 'Rechercher des %s', 'custom-cpt-manager' ), $plural ),
			'not_found'             => sprintf( __( 'Aucun %s trouvé.', 'custom-cpt-manager' ), $singular ),
			'not_found_in_trash'    => sprintf( __( 'Aucun %s dans la corbeille.', 'custom-cpt-manager' ), $singular ),
			'featured_image'        => __( 'Image mise en avant', 'custom-cpt-manager' ),
			'set_featured_image'    => __( 'Définir l\'image mise en avant', 'custom-cpt-manager' ),
			'remove_featured_image' => __( 'Retirer l\'image mise en avant', 'custom-cpt-manager' ),
			'use_featured_image'    => __( 'Utiliser comme image mise en avant', 'custom-cpt-manager' ),
			'archives'              => sprintf( __( 'Archives des %s', 'custom-cpt-manager' ), $plural ),
			'attributes'            => sprintf( __( 'Attributs du %s', 'custom-cpt-manager' ), $singular ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => true,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			// Base de route REST découplée de la clé : permet d'exposer
			// /wp/v2/offer même si le CPT s'appelle "offer-intern".
			// Vide dans les réglages = repli sur la clé du CPT.
			'rest_base'           => CCPTM_Settings::get_rest_base(),
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'menu_position'       => 20,
			'menu_icon'           => $settings['menu_icon'],
			'capability_type'     => 'post', // on réutilise les caps de 'post' => éditeurs/auteurs/admins
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array(
				'title',
				'editor',
				'author',
				'thumbnail',
				'excerpt',
				'trackbacks',
				'custom-fields', // essentiel pour les champs personnalisés via l'API REST
				'comments',
				'revisions',
				'page-attributes',
				'post-formats',
			),
			'taxonomies'          => array( 'category', 'post_tag' ),
			'has_archive'         => true,
			'rewrite'             => array(
				'slug'       => $key,
				'with_front' => false,
			),
			'query_var'           => true,
			'can_export'          => true,
			'delete_with_user'    => false,
		);

		register_post_type( $key, $args );
	}

}
