<?php
/**
 * REST API integration: the custom_fields field and optional interception.
 *
 * @package JobAffinity_CPT_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API integration for the custom post type:
 * - adds a writable "meta_input" field accepting an associative array of
 *   arbitrary custom fields, much like wp_insert_post;
 * - adds a readable "custom_fields" field returning every public meta of the
 *   post.
 *
 * This makes it possible to POST custom fields over the REST API without
 * declaring each one through register_post_meta() first, while still honouring
 * the WordPress security rules: protected meta, user capabilities.
 *
 * Since 1.2.0 the keys listed in the settings are also declared by CCPTM_Meta,
 * so they work in the standard "meta" object of the REST API. This file stays
 * the escape hatch for everything else: undeclared keys (the custom_* ones that
 * differ per client), multiple values, deletion by null.
 *
 * The write order within one request, imposed by WP_REST_Posts_Controller
 * (update_value(), then update_additional_fields_for_object(), which iterates
 * in registration order):
 *
 *     meta  ->  custom_fields  ->  meta_input
 *
 * Last write wins: when the same key arrives through several channels, the
 * meta_input value, then the custom_fields one, beats meta.
 */
class CCPTM_REST {

	/**
	 * Sole instance of the class.
	 *
	 * @var CCPTM_REST|null
	 */
	private static $instance = null;

	/**
	 * Returns the sole instance, creating it on first call.
	 *
	 * @return CCPTM_REST
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
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );

		$settings = CCPTM_Settings::get();

		if ( ! empty( $settings['cpt_key'] ) && ! empty( $settings['intercept_rest'] ) ) {
			// Two steps: detect on rest_pre_insert_post, where the request is
			// available, then apply on wp_insert_post_data. See flag_reroute().
			add_filter( 'rest_pre_insert_post', array( $this, 'flag_reroute' ), 10, 2 );
			add_filter( 'wp_insert_post_data', array( $this, 'apply_reroute' ), 10, 4 );
		}
	}

	/**
	 * Returns the current post type key, or null when unconfigured.
	 */
	private function get_cpt_key() {
		$settings = CCPTM_Settings::get();
		return ! empty( $settings['cpt_key'] ) ? $settings['cpt_key'] : null;
	}

	/**
	 * Registers the custom REST fields on the post type.
	 */
	public function register_rest_fields() {
		$cpt = $this->get_cpt_key();
		if ( ! $cpt ) {
			return;
		}

		// Read field: returns every unprotected meta.
		register_rest_field(
			$cpt,
			'custom_fields',
			array(
				'get_callback'    => array( $this, 'read_custom_fields' ),
				'update_callback' => array( $this, 'write_custom_fields' ),
				'schema'          => array(
					'description' => __( 'Custom fields (meta) attached to the post.', 'jobaffinity-cpt-manager' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);

		// A convenience alias, "meta_input", for clients that already use the
		// wp_insert_post naming.
		register_rest_field(
			$cpt,
			'meta_input',
			array(
				'get_callback'    => null,
				'update_callback' => array( $this, 'write_custom_fields' ),
				'schema'          => array(
					'description' => __( 'Write alias for custom_fields.', 'jobaffinity-cpt-manager' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
				),
			)
		);
	}

	/**
	 * GET: returns the custom fields, meaning every unprotected meta.
	 *
	 * @param array $post_array Post data prepared by the controller.
	 * @return stdClass
	 */
	public function read_custom_fields( $post_array ) {
		$post_id = isset( $post_array['id'] ) ? (int) $post_array['id'] : 0;
		if ( ! $post_id ) {
			return new stdClass();
		}

		$all = get_post_meta( $post_id );
		$out = array();

		foreach ( $all as $key => $values ) {
			// Protected meta (underscore-prefixed) is hidden.
			if ( is_protected_meta( $key, 'post' ) ) {
				continue;
			}

			if ( ! is_array( $values ) ) {
				continue;
			}

			if ( count( $values ) === 1 ) {
				$out[ $key ] = maybe_unserialize( $values[0] );
			} else {
				$out[ $key ] = array_map( 'maybe_unserialize', $values );
			}
		}

		// An object is returned so an empty set serialises as {} rather than [].
		return (object) $out;
	}

	/**
	 * POST / PUT: stores the custom fields received.
	 *
	 * Safeguards:
	 * - the user must hold edit_post on the resource;
	 * - protected keys ("_xxx") are refused UNLESS the user explicitly holds the
	 *   matching edit_post_meta capability;
	 * - invalid keys (non-string, empty, forbidden characters) are ignored.
	 *
	 * @param array|object $value      Key/value pairs received.
	 * @param WP_Post      $post       Post being written to.
	 * @param string       $field_name REST field name, part of the core callback signature and unused here.
	 * @return true|WP_Error
	 */
	public function write_custom_fields( $value, $post, $field_name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $field_name is imposed by the register_rest_field() update_callback signature.
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return new WP_Error(
				'ccptm_invalid_meta',
				__( 'The field must be an object of key/value pairs.', 'jobaffinity-cpt-manager' ),
				array( 'status' => 400 )
			);
		}

		$value = (array) $value;

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

		foreach ( $value as $meta_key => $meta_value ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key ) {
				continue;
			}

			// Basic key clean-up.
			$meta_key = sanitize_key( $meta_key );
			if ( '' === $meta_key ) {
				continue;
			}

			// Protected meta is refused without the specific capability.
			if ( is_protected_meta( $meta_key, 'post' ) ) {
				if ( ! current_user_can( 'edit_post_meta', $post_id, $meta_key ) ) {
					continue;
				}
			}

			// A null value means deletion.
			if ( null === $meta_value ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			// An indexed array is stored as several values; update_post_meta would overwrite.
			if ( is_array( $meta_value ) && $this->is_list( $meta_value ) ) {
				delete_post_meta( $post_id, $meta_key );
				foreach ( $meta_value as $v ) {
					add_post_meta( $post_id, $meta_key, $this->sanitize_meta_value( $v ) );
				}
				continue;
			}

			// Declared keys (CCPTM_Meta) already carry a sanitize_callback hooked on
			// update_metadata(), so they get the raw value to avoid sanitising
			// twice. Free-form keys (custom_*) are still handled here.
			$sanitized = CCPTM_Meta::is_registered_key( $meta_key )
				? $meta_value
				: $this->sanitize_meta_value( $meta_value );

			update_post_meta( $post_id, $meta_key, $sanitized );
		}

		return true;
	}

	/**
	 * Optional REST interception (the "intercept_rest" setting, off by default).
	 *
	 * The REST counterpart of CCPTM_XMLRPC: when a JobAffinity offer is created
	 * on /wp/v2/posts, its post_type is switched to ours before insertion.
	 *
	 * Why two steps rather than a single rest_pre_insert_post filter:
	 * WP_REST_Posts_Controller::create_item() reassigns
	 * `$prepared_post->post_type = $this->post_type` AFTER applying
	 * rest_pre_insert_{$post_type}. Any post_type change made in that filter is
	 * therefore overwritten by core. It is used for detection only, since that is
	 * where the request is reachable, and the change is applied in
	 * wp_insert_post_data, exactly as the XML-RPC path already does.
	 *
	 * Known limitations, worth understanding before enabling the option:
	 * - capabilities were already checked against the "post" post type, which is
	 *   equivalent here since the post type uses capability_type => 'post';
	 * - the response is still formatted by the "posts" controller;
	 * - the created item will not show up in a later GET /wp/v2/posts.
	 *
	 * @var bool
	 */
	private $reroute_pending = false;

	/**
	 * Step 1: detection. Changes nothing, only arms the flag.
	 *
	 * @param stdClass        $prepared_post Post about to be inserted.
	 * @param WP_REST_Request $request       The incoming request.
	 * @return stdClass
	 */
	public function flag_reroute( $prepared_post, $request ) {
		// Re-armed on every request: the flag must never leak from one insertion
		// to the next.
		$this->reroute_pending = false;

		$cpt = $this->get_cpt_key();

		if ( ! $cpt || ! post_type_exists( $cpt ) ) {
			return $prepared_post;
		}

		// Creation only: an existing post is never moved by a
		// POST /wp/v2/posts/123.
		if ( ! empty( $prepared_post->ID ) ) {
			return $prepared_post;
		}

		if ( $this->looks_like_jobaffinity( $request ) ) {
			$this->reroute_pending = true;
		}

		return $prepared_post;
	}

	/**
	 * Step 2: application, just before the database write.
	 *
	 * @param array $data                Sanitised data passed to wp_insert_post().
	 * @param array $postarr             Sanitised post array.
	 * @param array $unsanitized_postarr Unsanitised post array.
	 * @param bool  $update              Whether this is an update rather than an insert.
	 * @return array
	 */
	public function apply_reroute( $data, $postarr = array(), $unsanitized_postarr = array(), $update = false ) {
		if ( ! $this->reroute_pending || ! empty( $update ) ) {
			return $data;
		}

		// The flag is only consumed on the insertion we care about: a revision or
		// a nested auto-draft must not waste it.
		if ( ! isset( $data['post_type'] ) || 'post' !== $data['post_type'] ) {
			return $data;
		}

		$cpt = $this->get_cpt_key();

		if ( ! $cpt || ! post_type_exists( $cpt ) ) {
			return $data;
		}

		$this->reroute_pending = false;
		$data['post_type']     = $cpt;

		return $data;
	}

	/**
	 * The JobAffinity signature: a job_id, through whichever channel (standard
	 * meta, custom_fields or meta_input). Same criterion as
	 * CCPTM_XMLRPC::looks_like_jobaffinity().
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool
	 */
	private function looks_like_jobaffinity( $request ) {
		foreach ( array( 'meta', 'custom_fields', 'meta_input' ) as $param ) {
			$value = $request->get_param( $param );

			if ( is_object( $value ) ) {
				$value = (array) $value;
			}

			if ( is_array( $value ) && ! empty( $value['job_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Minimal sanitisation of a meta value, preserving types and structure.
	 * WordPress calls wp_unslash upstream anyway.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 *
	 * The rules:
	 * - arrays and objects are left as they are; update_post_meta will serialise;
	 * - booleans, integers and floats are preserved;
	 * - strings that look like a URL go through esc_url_raw, which preserves & and encodings;
	 * - other strings go through wp_kses_post, cleaning up without breaking legitimate HTML.
	 */
	private function sanitize_meta_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			// Recursive handling for arrays of strings.
			return map_deep( $value, array( $this, 'sanitize_scalar' ) );
		}
		return $this->sanitize_scalar( $value );
	}

	/**
	 * Sanitises a scalar value according to its type.
	 *
	 * @param mixed $value Raw scalar.
	 * @return mixed
	 */
	public static function sanitize_scalar( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		$str = (string) $value;

		// URL detection: anything starting with http(s):// or // is treated as a URL.
		if ( preg_match( '#^(https?:)?//#i', $str ) ) {
			return esc_url_raw( $str );
		}

		// Otherwise the standard HTML clean-up, which preserves text content including
		// accents, digits, punctuation and the HTML tags a post may carry.
		return wp_kses_post( $str );
	}

	/**
	 * Detects a "list" array: numerically indexed, starting at 0.
	 *
	 * @param mixed $arr Value to test.
	 * @return bool
	 */
	private function is_list( $arr ) {
		if ( ! is_array( $arr ) ) {
			return false;
		}
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		$i = 0;
		foreach ( $arr as $k => $_v ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}
}
