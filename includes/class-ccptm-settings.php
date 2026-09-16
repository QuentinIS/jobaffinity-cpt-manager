<?php
/**
 * Central handling of the plugin settings.
 *
 * @package JobAffinity_CPT_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central handling of the plugin settings.
 */
class CCPTM_Settings {

	/**
	 * Sole instance of the class.
	 *
	 * @var CCPTM_Settings|null
	 */
	private static $instance = null;

	/**
	 * Returns the sole instance, creating it on first call.
	 *
	 * @return CCPTM_Settings
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
	private function __construct() {}

	/**
	 * Returns the settings, merged over the defaults.
	 */
	public static function get() {
		$defaults = array(
			'cpt_key'               => '',
			'singular'              => '',
			'plural'                => '',
			'menu_icon'             => 'dashicons-admin-post',
			'intercept_xmlrpc'      => false,
			'intercept_rest'        => false,
			'rest_base'             => '',
			'extra_meta_keys'       => array(),
			'register_meta_on_post' => true,
			'configured'            => false,
			'db_version'            => '',
		);

		$settings = get_option( CCPTM_OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( $defaults, $settings );
	}

	/**
	 * The effective REST route base.
	 *
	 * The fallback is resolved on read rather than frozen into the database, so
	 * that renaming the post type key moves the route with it instead of
	 * leaving a stale base behind.
	 *
	 * @return string
	 */
	public static function get_rest_base() {
		$settings = self::get();
		$base     = isset( $settings['rest_base'] ) ? trim( (string) $settings['rest_base'] ) : '';

		return ( '' !== $base ) ? $base : $settings['cpt_key'];
	}

	/**
	 * Sanitises and saves the settings.
	 *
	 * @param array $input Raw form values.
	 * @return array array( 'success' => bool, 'errors' => string[], 'data' => array )
	 */
	public static function save( $input ) {
		$errors  = array();
		$current = self::get();

		$raw_key = isset( $input['cpt_key'] ) ? $input['cpt_key'] : '';
		$cpt_key = self::sanitize_key( $raw_key );

		if ( '' === $cpt_key ) {
			$errors[] = __( 'The post type key is required and must be 1 to 20 lowercase alphanumeric characters. Hyphens and underscores are allowed.', 'jobaffinity-cpt-manager' );
		}

		// Avoid collisions with native post types and a few reserved keys.
		$reserved = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'action', 'author', 'order', 'theme' );
		if ( in_array( $cpt_key, $reserved, true ) ) {
			$errors[] = sprintf(
				/* translators: %s: the key that was entered */
				__( 'The key "%s" is reserved by WordPress. Please choose another value.', 'jobaffinity-cpt-manager' ),
				$cpt_key
			);
		}

		$singular = isset( $input['singular'] ) ? sanitize_text_field( $input['singular'] ) : '';
		$plural   = isset( $input['plural'] ) ? sanitize_text_field( $input['plural'] ) : '';

		if ( '' === $singular ) {
			$singular = ucfirst( $cpt_key );
		}
		if ( '' === $plural ) {
			$plural = $singular . 's';
		}

		$menu_icon = isset( $input['menu_icon'] ) ? sanitize_text_field( $input['menu_icon'] ) : 'dashicons-admin-post';
		if ( '' === $menu_icon ) {
			$menu_icon = 'dashicons-admin-post';
		}

		$intercept_xmlrpc = ! empty( $input['intercept_xmlrpc'] );
		$intercept_rest   = ! empty( $input['intercept_rest'] );

		$register_meta_on_post = ! empty( $input['register_meta_on_post'] );

		// REST route base. Empty means falling back to the post type key, resolved
		// on read by get_rest_base(). The effective base is validated regardless:
		// a key such as "search" would pass the $reserved filter above while
		// silently overwriting core's /wp/v2/search route.
		$rest_base = self::sanitize_rest_base( isset( $input['rest_base'] ) ? $input['rest_base'] : '' );

		if ( '' !== $cpt_key ) {
			$effective_base = ( '' !== $rest_base ) ? $rest_base : $cpt_key;

			// Exclude ourselves under both the old and the new key, otherwise simply
			// re-saving the form would report a conflict against our own, already
			// registered post type.
			$conflict = self::rest_base_conflict( $effective_base, array( $current['cpt_key'], $cpt_key ) );

			if ( '' !== $conflict ) {
				$errors[] = $conflict;
			}
		}

		// Only ADDITIONS are stored: the JobAffinity set is declared unconditionally
		// by CCPTM_Meta::get_keys(). A required key re-entered here is therefore
		// simply dropped, not duplicated.
		$extra_meta_keys = array_values(
			array_diff(
				self::sanitize_meta_keys( isset( $input['extra_meta_keys'] ) ? $input['extra_meta_keys'] : '' ),
				CCPTM_Meta::DEFAULT_KEYS
			)
		);

		if ( ! empty( $errors ) ) {
			return array(
				'success' => false,
				'errors'  => $errors,
				'data'    => $current,
			);
		}

		// A change of key means the rewrite rules have to be flushed.
		$key_changed = ( $current['cpt_key'] !== $cpt_key );

		$data = array(
			'cpt_key'               => $cpt_key,
			'singular'              => $singular,
			'plural'                => $plural,
			'menu_icon'             => $menu_icon,
			'intercept_xmlrpc'      => $intercept_xmlrpc,
			'intercept_rest'        => $intercept_rest,
			'rest_base'             => $rest_base,
			'extra_meta_keys'       => $extra_meta_keys,
			'register_meta_on_post' => $register_meta_on_post,
			'configured'            => true,
			'db_version'            => CCPTM_VERSION,
		);

		update_option( CCPTM_OPTION_KEY, $data );

		if ( $key_changed ) {
			set_transient( 'ccptm_flush_rewrite', 1, 60 );
		}

		return array(
			'success' => true,
			'errors'  => array(),
			'data'    => $data,
		);
	}

	/**
	 * Migrates the old "meta_keys" option to "extra_meta_keys".
	 *
	 * Up to 1.2.0, "meta_keys" held the COMPLETE list of declared keys and
	 * replaced the JobAffinity set. Since 1.3.0 that set is unconditional and the
	 * option only stores additions, so the old list is converted by removing the
	 * required keys from it: no custom key is lost, and the 22 keys are
	 * guaranteed again.
	 *
	 * The trigger is the PRESENCE of the old key, not a version number: the
	 * method is idempotent and stops on its own once the conversion has
	 * happened. On a multisite network it runs once per site, since the option
	 * is per site.
	 */
	public static function maybe_migrate() {
		$stored = get_option( CCPTM_OPTION_KEY, array() );

		if ( ! is_array( $stored ) || ! array_key_exists( 'meta_keys', $stored ) ) {
			return; // Fresh install, or the migration already ran.
		}

		$legacy = is_array( $stored['meta_keys'] ) ? $stored['meta_keys'] : array();
		$extra  = ( isset( $stored['extra_meta_keys'] ) && is_array( $stored['extra_meta_keys'] ) )
			? $stored['extra_meta_keys']
			: array();

		$stored['extra_meta_keys'] = array_values(
			array_diff(
				self::sanitize_meta_keys( array_merge( $extra, $legacy ) ),
				CCPTM_Meta::DEFAULT_KEYS
			)
		);

		unset( $stored['meta_keys'] );
		$stored['db_version'] = CCPTM_VERSION;

		update_option( CCPTM_OPTION_KEY, $stored );
	}

	/**
	 * Sanitises the post type key: lowercase alphanumerics plus underscore and hyphen, 20 characters max.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
		$key = substr( $key, 0, 20 );
		return $key;
	}

	/**
	 * Sanitises a REST route base.
	 *
	 * "/" is deliberately excluded: core uses it for sub-resources such as
	 * font-families/(?P<id>)/font-faces, and allowing it here would amount to
	 * letting a pattern be injected into register_rest_route(). 32 characters,
	 * because rest_base has no post type key length limit.
	 *
	 * @param string $base Raw route base.
	 * @return string
	 */
	public static function sanitize_rest_base( $base ) {
		$base = strtolower( (string) $base );
		$base = preg_replace( '/[^a-z0-9_\-]/', '', $base );

		return substr( $base, 0, 32 );
	}

	/**
	 * Sanitises a list of meta keys entered in a textarea.
	 *
	 * @param string|array $raw One key per line; commas are accepted too.
	 * @return string[]
	 */
	public static function sanitize_meta_keys( $raw ) {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = preg_split( '/[\r\n,]+/', (string) $raw );
		}

		$keys = array();

		foreach ( (array) $parts as $key ) {
			$key = sanitize_key( trim( (string) $key ) );

			// A protected key ("_xxx") declared with show_in_rest would publicly
			// expose an internal meta value.
			if ( '' === $key || is_protected_meta( $key, 'post' ) ) {
				continue;
			}

			$keys[] = $key;
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Route bases reserved by core in the wp/v2 namespace.
	 *
	 * @return string[]
	 */
	private static function core_rest_bases() {
		return array(
			'posts',
			'pages',
			'media',
			'blocks',
			'templates',
			'template-parts',
			'global-styles',
			'navigation',
			'font-families',
			'font-collections',
			'menu-items',
			'menus',
			'menu-locations',
			'categories',
			'tags',
			'comments',
			'users',
			'search',
			'types',
			'statuses',
			'taxonomies',
			'settings',
			'themes',
			'plugins',
			'sidebars',
			'widgets',
			'widget-types',
			'block-types',
			'block-directory',
			'block-renderer',
			'pattern-directory',
			'block-patterns',
			'revisions',
			'autosaves',
			'oembed',
		);
	}

	/**
	 * Detects a REST route base collision.
	 *
	 * Two post types sharing a rest_base register the same route: the second
	 * silently overwrites the first one's handlers. Hence a blocking error
	 * rather than a warning.
	 *
	 * Called from handle_save(), well after "init", so the post type and
	 * taxonomy registries are complete by then.
	 *
	 * @param string   $rest_base Effective route base to test.
	 * @param string[] $exclude   Post types to ignore, namely our own.
	 * @return string Error message, or an empty string when there is no conflict.
	 */
	public static function rest_base_conflict( $rest_base, $exclude = array() ) {
		if ( '' === $rest_base ) {
			return '';
		}

		if ( in_array( $rest_base, self::core_rest_bases(), true ) ) {
			return sprintf(
				/* translators: %s: the route base that was entered */
				__( 'The REST route base "%s" is reserved by WordPress core. Please choose another value.', 'jobaffinity-cpt-manager' ),
				$rest_base
			);
		}

		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			if ( in_array( $post_type->name, (array) $exclude, true ) || empty( $post_type->show_in_rest ) ) {
				continue;
			}

			// A collision only counts within the same namespace.
			$namespace = ! empty( $post_type->rest_namespace ) ? $post_type->rest_namespace : 'wp/v2';
			if ( 'wp/v2' !== $namespace ) {
				continue;
			}

			$base = ! empty( $post_type->rest_base ) ? $post_type->rest_base : $post_type->name;

			if ( $base === $rest_base ) {
				return sprintf(
					/* translators: 1: route base, 2: conflicting post type */
					__( 'The REST route base "%1$s" is already used by the "%2$s" post type.', 'jobaffinity-cpt-manager' ),
					$rest_base,
					$post_type->name
				);
			}
		}

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->show_in_rest ) ) {
				continue;
			}

			$namespace = ! empty( $taxonomy->rest_namespace ) ? $taxonomy->rest_namespace : 'wp/v2';
			if ( 'wp/v2' !== $namespace ) {
				continue;
			}

			$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;

			if ( $base === $rest_base ) {
				return sprintf(
					/* translators: 1: route base, 2: conflicting taxonomy */
					__( 'The REST route base "%1$s" is already used by the "%2$s" taxonomy.', 'jobaffinity-cpt-manager' ),
					$rest_base,
					$taxonomy->name
				);
			}
		}

		return '';
	}
}
