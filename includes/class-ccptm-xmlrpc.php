<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interception XML-RPC pour rediriger les publications entrantes
 * (typiquement JobAffinity) du post type "post" vers notre CPT.
 *
 * Déclenchement : uniquement si l'option "intercept_xmlrpc" est activée
 * dans les réglages du plugin.
 *
 * Critère de détection : présence d'une meta "job_id" (signature JobAffinity)
 * ou présence d'au moins une meta "job_*" dans les custom_fields envoyés.
 */
class CCPTM_XMLRPC {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = CCPTM_Settings::get();
        if ( empty( $settings['cpt_key'] ) || empty( $settings['intercept_xmlrpc'] ) ) {
            return;
        }

        // Hook pour wp.newPost / wp.editPost (API XML-RPC moderne).
        add_filter( 'xmlrpc_wp_insert_post_data', array( $this, 'reroute_post_type' ), 10, 2 );
    }

    /**
     * Si les données XML-RPC entrantes correspondent à une offre JobAffinity,
     * on change le post_type pour notre CPT avant insertion.
     *
     * @param array $post_data    Données normalisées pour wp_insert_post.
     * @param array $content_struct Struct XML-RPC d'origine.
     */
    public function reroute_post_type( $post_data, $content_struct = array() ) {
        $settings = CCPTM_Settings::get();
        $cpt      = $settings['cpt_key'];

        if ( empty( $cpt ) ) {
            return $post_data;
        }

        // On n'intercepte que les publications destinées au post type "post".
        if ( ! isset( $post_data['post_type'] ) || 'post' !== $post_data['post_type'] ) {
            return $post_data;
        }

        if ( $this->looks_like_jobaffinity( $content_struct ) ) {
            $post_data['post_type'] = $cpt;
        }

        return $post_data;
    }

    /**
     * Détecte si le contenu XML-RPC entrant est une offre JobAffinity.
     *
     * On regarde les custom_fields dans la struct : JobAffinity envoie
     * systématiquement job_id + job_link au minimum.
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
            // Signature : job_id est toujours présent dans une offre JobAffinity.
            if ( 'job_id' === $key ) {
                return true;
            }
        }

        return false;
    }
}
