<?php
/**
 * XML-RPC interception of incoming JobAffinity publications.
 *
 * @package JobAffinity_CPT_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XML-RPC interception, re-routing incoming publications (typically from
 * JobAffinity) from the "post" post type to ours.
 *
 * Only active when the "intercept_xmlrpc" option is enabled in the plugin
 * settings.
 *
 * Detection criterion: a "job_id" meta (the JobAffinity signature), or at
 * least one "job_*" meta among the custom_fields being sent.
 */
class CCPTM_XMLRPC {

	/**
	 * Sole instance of the class.
	 *
	 * @var CCPTM_XMLRPC|null
	 */
	private static $instance = null;

	/**
	 * Returns the sole instance, creating it on first call.
	 *
	 * @return CCPTM_XMLRPC
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
		$settings = CCPTM_Settings::get();
		if ( empty( $settings['cpt_key'] ) || empty( $settings['intercept_xmlrpc'] ) ) {
			return;
		}

		// Covers wp.newPost / wp.editPost, the modern XML-RPC API.
		add_filter( 'xmlrpc_wp_insert_post_data', array( $this, 'reroute_post_type' ), 10, 2 );
	}

	/**
	 * When the incoming XML-RPC data looks like a JobAffinity offer, switch
	 * post_type to our custom post type before insertion.
	 *
	 * @param array $post_data      Data normalised for wp_insert_post.
	 * @param array $content_struct The original XML-RPC struct.
	 */
	public function reroute_post_type( $post_data, $content_struct = array() ) {
		$settings = CCPTM_Settings::get();
		$cpt      = $settings['cpt_key'];

		if ( empty( $cpt ) ) {
			return $post_data;
		}

		// Only publications aimed at the "post" post type are intercepted.
		if ( ! isset( $post_data['post_type'] ) || 'post' !== $post_data['post_type'] ) {
			return $post_data;
		}

		if ( $this->looks_like_jobaffinity( $content_struct ) ) {
			$post_data['post_type'] = $cpt;
		}

		return $post_data;
	}

	/**
	 * Detects whether the incoming XML-RPC content is a JobAffinity offer.
	 *
	 * The custom_fields in the struct are what we look at: JobAffinity always
	 * sends at least job_id and job_link.
	 *
	 * @param array $content_struct The XML-RPC struct received.
	 * @return bool
	 */
	private function looks_like_jobaffinity( $content_struct ) {
		if ( ! is_array( $content_struct ) ) {
			return false;
		}

		$custom = null;
		if ( isset( $content_struct['custom_fields'] ) && is_array( $content_struct['custom_fields'] ) ) {
			$custom = $content_struct['custom_fields'];
		} elseif ( isset( $content_struct['customFields'] ) && is_array( $content_struct['customFields'] ) ) {
			$custom = $content_struct['customFields'];
		}

		if ( ! is_array( $custom ) ) {
			return false;
		}

		foreach ( $custom as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			$key = (string) $field['key'];
			// The signature: job_id is always present on a JobAffinity offer.
			if ( 'job_id' === $key ) {
				return true;
			}
		}

		return false;
	}
}
