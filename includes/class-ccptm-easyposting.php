<?php
/**
 * REST API integration: the easyposting_fields field.
 *
 * @package JobAffinity_CPT_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "easyposting_fields" REST field, the channel JobAffinity publishes
 * through.
 *
 * Rather than keeping a list of keys in sync between JobAffinity and the site
 * (the declared keys of CCPTM_Meta), the names travel with the values: every
 * publication carries the complete state of the offer's fields, and this
 * class writes the post meta itself. A key added on the JobAffinity side
 * shows up on the next publication, with nothing to declare.
 *
 * The payload holds two baskets: "standard" carries the keys as they are
 * stored (job_*, apply_url), "custom" carries the client-defined fields
 * without their prefix, which is added here ("regions" becomes
 * "custom_regions"). Both are flattened into one set of keys before anything
 * else, so the rules below apply to the stored key names.
 *
 * An empty value is not sent as "": it is not sent at all. A key absent from
 * the payload therefore means "emptied or removed on the JobAffinity side",
 * never "unchanged", which is why every write is followed by a sweep of the
 * plugin's namespace (see sweep()).
 *
 * The safeguards:
 * - a key whitelist (job_*, custom_*, apply_url) is the real containment: no
 *   _yoast_*, ACF or WooCommerce key can be reached, even by a mistake on the
 *   sending side;
 * - is_protected_meta(), the filterable core function, rather than a test on
 *   a leading underscore;
 * - the edit_post_meta capability, which honours any auth callback;
 * - a constrained key format and a cap of 100 keys per request, counted
 *   after flattening.
 *
 * None of this grants a new privilege: an account that can edit the post can
 * already write any post meta through the Custom Fields panel or XML-RPC.
 *
 * The update_callback only runs when the request carries the field, so an edit
 * made from the admin, or through "meta" or "custom_fields" alone, never
 * sweeps anything. When the field is present it is authoritative for the
 * namespace: it is written last, after meta, custom_fields and meta_input,
 * because this class is instantiated after CCPTM_REST.
 *
 * The field is write-only and the keys are not declared: nothing is added to
 * the public REST output. Themes read the values with get_post_meta(), which
 * works on any row, declared or not.
 */
class CCPTM_Easyposting {

	/**
	 * REST field name, fixed by the JobAffinity contract.
	 */
	const FIELD = 'easyposting_fields';

	/**
	 * Maximum number of keys accepted in one request.
	 */
	const MAX_KEYS = 100;

	/**
	 * Keys that may be written.
	 */
	const KEY_PATTERN = '/^(job_[a-z0-9_]{1,50}|custom_[a-z0-9_]{1,50}|apply_url)$/';

	/**
	 * Keys the sweep is allowed to delete: the whole namespace, including keys
	 * in a format that could no longer be written.
	 */
	const SWEEP_PATTERN = '/^(job_|custom_|apply_url$)/';

	/**
	 * Sole instance of the class.
	 *
	 * @var CCPTM_Easyposting|null
	 */
	private static $instance = null;

	/**
	 * Returns the sole instance, creating it on first call.
	 *
	 * @return CCPTM_Easyposting
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
		add_action( 'rest_api_init', array( $this, 'register_fields' ) );
	}

	/**
	 * Registers the field on every post type JobAffinity publishes to.
	 *
	 * Two conditions per post type: show_in_rest, otherwise there is no
	 * endpoint at all, and support for custom-fields, otherwise the post type
	 * is not meant to carry post meta.
	 */
	public function register_fields() {
		foreach ( CCPTM_Meta::get_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( ! $object || empty( $object->show_in_rest ) || ! post_type_supports( $post_type, 'custom-fields' ) ) {
				continue;
			}

			register_rest_field(
				$post_type,
				self::FIELD,
				array(
					'get_callback'    => null,
					'update_callback' => array( $this, 'write_fields' ),
					// The schema is also turned into an endpoint argument, so a
					// malformed payload is rejected with a 400 BEFORE
					// wp_insert_post(), rather than leaving an empty post behind.
					'schema'          => array(
						'description' => __( 'Complete set of JobAffinity fields for the offer, in a "standard" and a "custom" basket. Absent keys are deleted.', 'jobaffinity-cpt-manager' ),
						'type'        => 'object',
						'properties'  => array(
							'standard' => array( 'type' => 'object' ),
							'custom'   => array( 'type' => 'object' ),
						),
						'context'     => array(),
						'arg_options' => array(
							'validate_callback' => array( $this, 'validate_fields' ),
						),
					),
				)
			);
		}
	}

	/**
	 * Validates the field as an endpoint argument, before the post is written.
	 *
	 * The schema checks the shape of each basket; the cap on the number of keys
	 * spans both baskets, which JSON Schema cannot express.
	 *
	 * @param mixed           $value   Value received.
	 * @param WP_REST_Request $request Current request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_fields( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $valid ) {
			return $valid;
		}

		if ( count( self::flatten( $value ) ) > self::MAX_KEYS ) {
			return self::too_many_fields();
		}

		return true;
	}

	/**
	 * Flattens the "standard" and "custom" baskets into one set of keys.
	 *
	 * @param array|object $fields Field value received.
	 * @return array Stored key => value.
	 */
	private static function flatten( $fields ) {
		$fields   = (array) $fields;
		$incoming = array();

		if ( isset( $fields['standard'] ) && ( is_array( $fields['standard'] ) || is_object( $fields['standard'] ) ) ) {
			foreach ( (array) $fields['standard'] as $key => $value ) {
				$incoming[ $key ] = $value;
			}
		}

		if ( isset( $fields['custom'] ) && ( is_array( $fields['custom'] ) || is_object( $fields['custom'] ) ) ) {
			foreach ( (array) $fields['custom'] as $key => $value ) {
				$incoming[ 'custom_' . $key ] = $value;
			}
		}

		return $incoming;
	}

	/**
	 * Error returned when a payload carries more than MAX_KEYS keys.
	 *
	 * @return WP_Error
	 */
	private static function too_many_fields() {
		return new WP_Error(
			'ccptm_too_many_fields',
			/* translators: %d: maximum number of keys */
			sprintf( __( 'At most %d keys are accepted.', 'jobaffinity-cpt-manager' ), self::MAX_KEYS ),
			array( 'status' => 400 )
		);
	}

	/**
	 * POST / PUT: writes the fields received, then sweeps the absent ones.
	 *
	 * @param array|object $fields "standard" and "custom" baskets received.
	 * @param WP_Post      $post   Post being written to.
	 * @return true|WP_Error
	 */
	public function write_fields( $fields, $post ) {
		// Defence in depth: validate_fields() already enforces both rules.
		if ( ! is_array( $fields ) && ! is_object( $fields ) ) {
			return new WP_Error(
				'ccptm_invalid_fields',
				__( 'The field must be an object of key/value pairs.', 'jobaffinity-cpt-manager' ),
				array( 'status' => 400 )
			);
		}

		$incoming = self::flatten( $fields );

		if ( count( $incoming ) > self::MAX_KEYS ) {
			return self::too_many_fields();
		}

		$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		if ( ! $post_id ) {
			return true; // Nothing to do.
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'ccptm_forbidden',
				__( 'You are not allowed to edit this post.', 'jobaffinity-cpt-manager' ),
				array( 'status' => 403 )
			);
		}

		// Keys written, and keys present in the payload but refused because of
		// their value. Both are kept by the sweep: present is not absent.
		$keep = array();

		foreach ( $incoming as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( self::KEY_PATTERN, $key ) ) {
				continue;
			}

			if ( is_protected_meta( $key, 'post' ) || ! current_user_can( 'edit_post_meta', $post_id, $key ) ) {
				continue;
			}

			$keep[] = $key;

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = (string) $value;

			// Declared keys (CCPTM_Meta) already carry a sanitize_callback hooked on
			// update_metadata(), so they get the raw value to avoid sanitising twice.
			if ( ! CCPTM_Meta::is_registered_key( $key ) ) {
				$value = CCPTM_Meta::sanitize_meta( $value, $key );
			}

			// update_metadata() unslashes its input; REST values are not slashed.
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}

		$this->sweep( $post_id, $keep );

		return true;
	}

	/**
	 * Deletes the plugin's namespace keys that the payload did not carry.
	 *
	 * @param int      $post_id Post being written to.
	 * @param string[] $keep    Keys to leave in place.
	 */
	private function sweep( $post_id, $keep ) {
		/**
		 * Filters whether absent keys are deleted after an easyposting_fields write.
		 *
		 * Return false on a site that writes job_* or custom_* keys by other
		 * means, which the sweep would otherwise delete.
		 *
		 * @param bool $sweep   Whether to sweep. Default true.
		 * @param int  $post_id Post being written to.
		 */
		if ( ! apply_filters( 'ccptm_easyposting_sweep', true, $post_id ) ) {
			return;
		}

		$delete = array();

		foreach ( array_keys( get_post_meta( $post_id ) ) as $key ) {
			$key = (string) $key;

			if ( ! preg_match( self::SWEEP_PATTERN, $key ) || is_protected_meta( $key, 'post' ) ) {
				continue;
			}

			if ( ! in_array( $key, $keep, true ) ) {
				$delete[] = $key;
			}
		}

		/**
		 * Filters the keys the sweep is about to delete.
		 *
		 * Remove a key from the list to preserve it. Keys added to the list are
		 * ignored: the filter can only narrow the sweep, never widen it.
		 *
		 * @param string[] $delete  Keys to delete.
		 * @param int      $post_id Post being written to.
		 */
		$filtered = (array) apply_filters( 'ccptm_easyposting_sweep_keys', $delete, $post_id );

		foreach ( array_intersect( $delete, $filtered ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}
}
