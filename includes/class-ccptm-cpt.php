<?php
/**
 * Registration of the configurable custom post type.
 *
 * @package JobAffinity_CPT_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration of the configurable custom post type.
 *
 * The post type is registered to behave exactly like native posts:
 * - the same supports (title, editor, author, thumbnail, excerpt, comments, custom-fields, and so on)
 * - exposed in the REST API the same way a standard post is
 * - visible in the admin, with capabilities derived from those of 'post'
 */
class CCPTM_CPT {

	/**
	 * Sole instance of the class.
	 *
	 * @var CCPTM_CPT|null
	 */
	private static $instance = null;

	/**
	 * Returns the sole instance, creating it on first call.
	 *
	 * @return CCPTM_CPT
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks the class into WordPress. Private: use instance().
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ), 5 );
	}

	/**
	 * Registers the post type, if a key has been configured.
	 */
	public function register_cpt() {
		$settings = CCPTM_Settings::get();
		$key      = $settings['cpt_key'];

		if ( empty( $key ) ) {
			return;
		}

		$singular = ! empty( $settings['singular'] ) ? $settings['singular'] : ucfirst( $key );
		$plural   = ! empty( $settings['plural'] ) ? $settings['plural'] : $singular . 's';

		$labels = array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'name_admin_bar'        => $singular,
			'add_new'               => __( 'Add New', 'jobaffinity-cpt-manager' ),
			/* translators: %s: singular post type label. */
			'add_new_item'          => sprintf( __( 'Add new %s', 'jobaffinity-cpt-manager' ), $singular ),
			/* translators: %s: singular post type label. */
			'new_item'              => sprintf( __( 'New %s', 'jobaffinity-cpt-manager' ), $singular ),
			/* translators: %s: singular post type label. */
			'edit_item'             => sprintf( __( 'Edit %s', 'jobaffinity-cpt-manager' ), $singular ),
			/* translators: %s: singular post type label. */
			'view_item'             => sprintf( __( 'View %s', 'jobaffinity-cpt-manager' ), $singular ),
			/* translators: %s: plural post type label. */
			'all_items'             => sprintf( __( 'All %s', 'jobaffinity-cpt-manager' ), $plural ),
			/* translators: %s: plural post type label. */
			'search_items'          => sprintf( __( 'Search %s', 'jobaffinity-cpt-manager' ), $plural ),
			/* translators: %s: singular post type label. */
			'not_found'             => sprintf( __( 'No %s found.', 'jobaffinity-cpt-manager' ), $singular ),
			/* translators: %s: singular post type label. */
			'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'jobaffinity-cpt-manager' ), $singular ),
			'featured_image'        => __( 'Featured image', 'jobaffinity-cpt-manager' ),
			'set_featured_image'    => __( 'Set featured image', 'jobaffinity-cpt-manager' ),
			'remove_featured_image' => __( 'Remove featured image', 'jobaffinity-cpt-manager' ),
			'use_featured_image'    => __( 'Use as featured image', 'jobaffinity-cpt-manager' ),
			/* translators: %s: plural post type label. */
			'archives'              => sprintf( __( '%s archives', 'jobaffinity-cpt-manager' ), $plural ),
			/* translators: %s: singular post type label. */
			'attributes'            => sprintf( __( '%s attributes', 'jobaffinity-cpt-manager' ), $singular ),
		);

		$args = array(
			'labels'                => $labels,
			'public'                => true,
			'publicly_queryable'    => true,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'show_in_nav_menus'     => true,
			'show_in_admin_bar'     => true,
			'show_in_rest'          => true,
			// The REST route base is decoupled from the key, so /wp/v2/offer can be
			// served even when the post type is keyed "offer-intern".
			// Empty in the settings means falling back to the post type key.
			'rest_base'             => CCPTM_Settings::get_rest_base(),
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'menu_position'         => 20,
			'menu_icon'             => $settings['menu_icon'],
			'capability_type'       => 'post', // Reuse the caps of 'post': admins, editors and authors.
			'map_meta_cap'          => true,
			'hierarchical'          => false,
			'supports'              => array(
				'title',
				'editor',
				'author',
				'thumbnail',
				'excerpt',
				'trackbacks',
				'custom-fields', // Essential for custom fields over the REST API.
				'comments',
				'revisions',
				'page-attributes',
				'post-formats',
			),
			'taxonomies'            => array( 'category', 'post_tag' ),
			'has_archive'           => true,
			'rewrite'               => array(
				'slug'       => $key,
				'with_front' => false,
			),
			'query_var'             => true,
			'can_export'            => true,
			'delete_with_user'      => false,
		);

		register_post_type( $key, $args );
	}
}
