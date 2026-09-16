<?php
/**
 * Declaration of the custom fields to the REST API.
 *
 * @package JobAffinity_CPT_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declaration of the custom fields to the REST API.
 *
 * Why this class exists: the standard "meta" object of the WordPress REST API
 * refuses to write a key that has not been declared server-side through
 * register_post_meta(), and it refuses it WITHOUT an error. You get a 201
 * Created, the post exists, and the fields are simply missing. The in-house
 * "custom_fields" field (see CCPTM_REST) has no such constraint, but clients
 * using the standard REST API, JobAffinity among them, send "meta".
 *
 *
 * So this class declares, on the custom post type and optionally on the native
 * "post" type, a required set of JobAffinity keys (DEFAULT_KEYS) that the
 * administrator can extend from the settings. The required set cannot be
 * removed: see get_keys().
 */
class CCPTM_Meta {

	/**
	 * The required set: the keys JobAffinity sends on every offer. Always
	 * declared, whatever the settings hold.
	 *
	 * All typed "string", salaries and GPS coordinates included: JobAffinity
	 * sends everything as a string, and declaring 'number' on job_salary_min
	 * would get the whole offer rejected with a rest_invalid_type error.
	 */
	const DEFAULT_KEYS = array(
		'job_id',
		'job_reference',
		'job_organisation',
		'job_client_remote_id',
		'job_client',
		'job_entity',
		'job_location',
		'job_address',
		'job_postalcode',
		'job_town',
		'job_country',
		'job_latitude',
		'job_longitude',
		'job_link',
		'job_contract_type',
		'job_contract_length',
		'job_contract_length_unit',
		'apply_url',
		'job_salary_min',
		'job_salary_max',
		'job_salary_currency',
		'job_salary_period',
	);

	/**
	 * Keys whose value is a URL, sanitised with esc_url_raw.
	 */
	const URL_KEYS = array( 'job_link', 'apply_url' );

	/**
	 * Sole instance of the class.
	 *
	 * @var CCPTM_Meta|null
	 */
	private static $instance = null;

	/**
	 * Returns the sole instance, creating it on first call.
	 *
	 * @return CCPTM_Meta
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
		// Priority 11: after CCPTM_CPT (priority 5) and after the priority 10 at
		// which most plugins register their post types, while staying well ahead
		// of rest_api_init, which builds the REST schema.
		add_action( 'init', array( $this, 'register_meta_keys' ), 11 );

		foreach ( self::get_coercion_post_types() as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'coerce_meta_types' ), 10, 2 );
		}
	}

	/**
	 * The post types the keys are declared on.
	 *
	 * Returns an empty array when the plugin is not configured: on a multisite
	 * network, sites with no ccptm_settings option must declare nothing at all.
	 *
	 * @return string[]
	 */
	public static function get_post_types() {
		$settings = CCPTM_Settings::get();
		$types    = array();

		if ( empty( $settings['cpt_key'] ) ) {
			return $types;
		}

		$types[] = $settings['cpt_key'];

		// REST interception lands the request on the "post" controller, so it is
		// the meta registry of "post" that gets consulted to write the "meta"
		// object. Without a declaration there the fields would be lost silently,
		// which is exactly the bug this class exists to fix.
		if ( ! empty( $settings['register_meta_on_post'] ) || ! empty( $settings['intercept_rest'] ) ) {
			$types[] = 'post';
		}

		/**
		 * Filters the post types the meta declarations are applied to.
		 *
		 * @param string[] $types
		 */
		$types = (array) apply_filters( 'ccptm_meta_post_types', $types );

		return array_values( array_unique( array_filter( $types ) ) );
	}

	/**
	 * The post types the type coercion filter is hooked on.
	 *
	 * "post" is always included, even when the keys are not declared on it: REST
	 * interception (CCPTM_REST) can re-route a request that arrived on
	 * /wp/v2/posts to the custom post type, and coercion must have happened by then.
	 *
	 * @return string[]
	 */
	public static function get_coercion_post_types() {
		$settings = CCPTM_Settings::get();

		if ( empty( $settings['cpt_key'] ) ) {
			return array();
		}

		return array_values( array_unique( array( $settings['cpt_key'], 'post' ) ) );
	}

	/**
	 * Cleans a list of keys: sanitisation, rejection of empty and protected meta,
	 * deduplication. Shared by get_keys() and get_extra_keys().
	 *
	 * @param array $keys Raw keys to clean.
	 * @return string[]
	 */
	private static function filter_keys( $keys ) {
		$keys = array_map( 'sanitize_key', (array) $keys );
		$keys = array_filter(
			$keys,
			static function ( $key ) {
				// A protected key ("_xxx") declared with show_in_rest would publicly
				// expose an internal meta value, so they are always refused.
				return '' !== $key && ! is_protected_meta( $key, 'post' );
			}
		);

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Additional keys added by the administrator in the settings.
	 *
	 * The JobAffinity set is always excluded from it: get_keys() declares it
	 * unconditionally, so it has no business in the list of additions.
	 *
	 * @return string[]
	 */
	public static function get_extra_keys() {
		$settings = CCPTM_Settings::get();

		$extra = ( ! empty( $settings['extra_meta_keys'] ) && is_array( $settings['extra_meta_keys'] ) )
			? $settings['extra_meta_keys']
			: array();

		return self::filter_keys( array_diff( (array) $extra, self::DEFAULT_KEYS ) );
	}

	/**
	 * The keys to declare: the JobAffinity set, then the additions.
	 *
	 * The set is an invariant: it is re-injected AFTER the filter, so that no
	 * settings input and no third-party filter can empty it. Without that
	 * guarantee a missing JobAffinity key would lose the field silently (201
	 * Created, meta absent), precisely the bug this class exists to avoid.
	 *
	 * @return string[]
	 */
	public static function get_keys() {
		$keys = array_merge( self::DEFAULT_KEYS, self::get_extra_keys() );

		/**
		 * Filters the list of meta keys declared in the REST API.
		 *
		 * Can add keys; cannot remove the JobAffinity set.
		 *
		 * @param string[] $keys
		 */
		$keys = self::filter_keys( (array) apply_filters( 'ccptm_meta_keys', $keys ) );

		return array_values( array_unique( array_merge( self::DEFAULT_KEYS, $keys ) ) );
	}

	/**
	 * Is the key declared? Used by CCPTM_REST to avoid sanitising twice on the
	 * "custom_fields" path.
	 *
	 * @param string $key Meta key to look up.
	 * @return bool
	 */
	public static function is_registered_key( $key ) {
		return in_array( $key, self::get_keys(), true );
	}

	/**
	 * Declares every key on every relevant post type.
	 */
	public function register_meta_keys() {
		$post_types = self::get_post_types();
		if ( empty( $post_types ) ) {
			return;
		}

		$keys = self::get_keys();

		foreach ( $post_types as $post_type ) {
			if ( 'post' !== $post_type && ! post_type_exists( $post_type ) ) {
				continue;
			}

			foreach ( $keys as $key ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'show_in_rest'      => true,
						'single'            => true,
						'type'              => 'string',
						'description'       => sprintf(
							/* translators: %s: the custom field key */
							__( 'Custom field "%s", declared by JobAffinity CPT Manager.', 'jobaffinity-cpt-manager' ),
							$key
						),
						'sanitize_callback' => array( __CLASS__, 'sanitize_meta' ),
						'auth_callback'     => array( __CLASS__, 'auth_meta' ),
						// No 'default': it would force get_post_meta() to return
						// array('') instead of array() across the whole site, REST
						// API or not.
					)
				);
			}
		}
	}

	/**
	 * Sanitisation applied to EVERY write path at once (the REST "meta" object,
	 * custom_fields, XML-RPC, the Custom Fields metabox), since
	 * register_post_meta hooks this callback onto update_metadata().
	 *
	 * @param mixed  $value       Raw value being written.
	 * @param string $key         Meta key the value belongs to.
	 * @param string $object_type Object type, part of the core callback signature and unused here.
	 * @return mixed
	 */
	public static function sanitize_meta( $value, $key = '', $object_type = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $object_type is imposed by the register_post_meta() sanitize_callback signature.
		// single => true, so a structure should never reach this point.
		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}

		if ( self::is_url_key( $key ) ) {
			$sanitized = esc_url_raw( (string) $value );
		} else {
			// Parity with the historical "custom_fields" path.
			$sanitized = CCPTM_REST::sanitize_scalar( $value );
		}

		/**
		 * Filters the sanitised value of a declared meta.
		 *
		 * @param mixed  $sanitized
		 * @param mixed  $value     The raw value.
		 * @param string $key
		 */
		return apply_filters( 'ccptm_sanitize_meta_value', $sanitized, $value, $key );
	}

	/**
	 * Does the key hold a URL?
	 *
	 * @param string $key Meta key to test.
	 * @return bool
	 */
	private static function is_url_key( $key ) {
		if ( '' === $key ) {
			return false;
		}

		if ( in_array( $key, self::URL_KEYS, true ) ) {
			return true;
		}

		return (bool) preg_match( '/_(url|link)$/', $key );
	}

	/**
	 * Read and write authorisation for the meta.
	 *
	 * Signature of the auth_{$object_type}_meta_{$key}_for_{$subtype} filter:
	 * ( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ).
	 *
	 * user_can( $user_id, ... ) rather than current_user_can(): the filter is
	 * also reachable from an admin, cron or CLI context where the user being
	 * evaluated is not the current user.
	 *
	 * Note that core already resolves map_meta_cap( 'edit_post', ... ) before
	 * calling this filter, so edit_post is enforced regardless. This callback is
	 * defence in depth, not the only safeguard.
	 *
	 * @param bool   $allowed   Whether access is currently granted.
	 * @param string $meta_key  Meta key being accessed.
	 * @param int    $object_id Post the meta belongs to.
	 * @param int    $user_id   User the check applies to.
	 * @return bool
	 */
	public static function auth_meta( $allowed, $meta_key, $object_id, $user_id ) {
		if ( ! empty( $object_id ) ) {
			return user_can( $user_id, 'edit_post', (int) $object_id );
		}

		return user_can( $user_id, 'edit_posts' );
	}

	/**
	 * Casts non-string JSON scalars received in "meta" to strings.
	 *
	 * Required, because every key is declared 'string'. Without this coercion a
	 * `"job_salary_min": 45000` (a JSON number) raises a rest_invalid_type in
	 * WP_REST_Meta_Fields::update_value(), which runs AFTER wp_insert_post(). The
	 * result: a 400 response, but the post is already in the database with no
	 * meta at all, and a client that retries creates duplicates.
	 *
	 *
	 * Hooked on rest_pre_insert_{$post_type}, called from
	 * prepare_item_for_database(), hence before wp_insert_post() and well ahead
	 * of update_value(). That hook is already scoped to our post types, which
	 * saves filtering routes by hand.
	 *
	 * @param stdClass        $prepared_post Post about to be inserted.
	 * @param WP_REST_Request $request       The incoming request.
	 * @return stdClass
	 */
	public function coerce_meta_types( $prepared_post, $request ) {
		$meta = $request->get_param( 'meta' );

		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return $prepared_post;
		}

		$changed = false;

		// Iterate over OUR keys, not over the payload: an undeclared key is none
		// of our business.
		foreach ( self::get_keys() as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				continue;
			}

			$value = $meta[ $key ];

			// null         => core deletes the meta; leave it alone.
			// string       => already valid.
			// array/object => not ours to flatten; let core answer 400 rather than
			// hide a client bug.
			if ( null === $value || is_string( $value ) || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$meta[ $key ] = $value ? '1' : '';
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$meta[ $key ] = (string) $value;
			} else {
				continue;
			}

			$changed = true;
		}

		if ( $changed ) {
			$request->set_param( 'meta', $meta );
		}

		return $prepared_post;
	}
}
