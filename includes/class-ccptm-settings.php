<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gestion centralisée des réglages.
 */
class CCPTM_Settings {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Récupère les réglages (avec fusion des valeurs par défaut).
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
     * Base de route REST effective.
     *
     * On resout le repli a la lecture plutot que de figer la valeur en base :
     * ainsi, renommer la cle du CPT deplace automatiquement la route au lieu de
     * laisser derriere une base obsolete.
     *
     * @return string
     */
    public static function get_rest_base() {
        $settings = self::get();
        $base     = isset( $settings['rest_base'] ) ? trim( (string) $settings['rest_base'] ) : '';

        return ( '' !== $base ) ? $base : $settings['cpt_key'];
    }

    /**
     * Sauvegarde les réglages après sanitisation.
     *
     * @param array $input
     * @return array resultat = array( 'success' => bool, 'errors' => string[], 'data' => array )
     */
    public static function save( $input ) {
        $errors  = array();
        $current = self::get();

        $raw_key  = isset( $input['cpt_key'] ) ? $input['cpt_key'] : '';
        $cpt_key  = self::sanitize_key( $raw_key );

        if ( '' === $cpt_key ) {
            $errors[] = __( 'La clé du CPT est obligatoire et doit contenir entre 1 et 20 caractères alphanumériques en minuscules (tirets et underscores autorisés).', 'custom-cpt-manager' );
        }

        // Évite les collisions avec les post types natifs et quelques clés réservées.
        $reserved = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'action', 'author', 'order', 'theme' );
        if ( in_array( $cpt_key, $reserved, true ) ) {
            $errors[] = sprintf(
                /* translators: %s: clé choisie */
                __( 'La clé "%s" est réservée par WordPress. Choisissez une autre valeur.', 'custom-cpt-manager' ),
                $cpt_key
            );
        }

        $singular = isset( $input['singular'] ) ? sanitize_text_field( $input['singular'] ) : '';
        $plural   = isset( $input['plural'] )   ? sanitize_text_field( $input['plural'] )   : '';

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

        // Base de route REST. Vide = repli sur la cle du CPT (resolu a la lecture
        // par get_rest_base()), mais on valide quand meme la base effective :
        // une cle comme "search" passerait le filtre $reserved ci-dessus tout en
        // ecrasant silencieusement la route /wp/v2/search du coeur.
        $rest_base = self::sanitize_rest_base( isset( $input['rest_base'] ) ? $input['rest_base'] : '' );

        if ( '' !== $cpt_key ) {
            $effective_base = ( '' !== $rest_base ) ? $rest_base : $cpt_key;

            // On s'exclut nous-memes, sous l'ancienne comme sous la nouvelle cle,
            // sinon un simple re-enregistrement du formulaire signalerait un
            // conflit contre notre propre CPT deja enregistre.
            $conflict = self::rest_base_conflict( $effective_base, array( $current['cpt_key'], $cpt_key ) );

            if ( '' !== $conflict ) {
                $errors[] = $conflict;
            }
        }

        // Seuls les AJOUTS sont stockes : le socle JobAffinity est declare
        // d'office par CCPTM_Meta::get_keys(). Une cle du socle ressaisie ici
        // est donc simplement retiree, pas dupliquee.
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

        // Si la clé change, il faudra flush les rewrite rules.
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
     * Migration de l'ancienne cle "meta_keys" vers "extra_meta_keys".
     *
     * Jusqu'a la 1.2.0, "meta_keys" contenait la liste COMPLETE des cles
     * declarees et remplacait le socle JobAffinity. Depuis la 1.3.0, le socle
     * est inconditionnel et l'option ne stocke plus que les ajouts. On convertit
     * donc l'ancienne liste en retirant les cles du socle : aucune cle
     * personnalisee n'est perdue, et les 22 cles redeviennent garanties.
     *
     * Le declencheur est la PRESENCE de l'ancienne cle, pas un numero de
     * version : la methode est idempotente et s'arrete d'elle-meme des que la
     * conversion a eu lieu. Sur un reseau multisite, elle s'execute une fois par
     * site (l'option est par site).
     */
    public static function maybe_migrate() {
        $stored = get_option( CCPTM_OPTION_KEY, array() );

        if ( ! is_array( $stored ) || ! array_key_exists( 'meta_keys', $stored ) ) {
            return; // Installation neuve, ou migration deja faite.
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
     * Sanitise la clé du CPT : minuscules, alphanumérique + underscore/tiret, max 20 caractères.
     */
    public static function sanitize_key( $key ) {
        $key = strtolower( (string) $key );
        $key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
        $key = substr( $key, 0, 20 );
        return $key;
    }

    /**
     * Sanitise une base de route REST.
     *
     * Volontairement sans "/" : le coeur s'en sert pour des sous-ressources
     * (font-families/(?P<id>)/font-faces) et l'autoriser ici reviendrait a
     * laisser injecter du motif dans register_rest_route(). 32 caracteres :
     * rest_base n'a pas la limite de longueur d'une cle de post type.
     *
     * @param string $base
     * @return string
     */
    public static function sanitize_rest_base( $base ) {
        $base = strtolower( (string) $base );
        $base = preg_replace( '/[^a-z0-9_\-]/', '', $base );

        return substr( $base, 0, 32 );
    }

    /**
     * Sanitise une liste de cles meta saisie dans une zone de texte.
     *
     * @param string|array $raw Une cle par ligne (virgules acceptees aussi).
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

            // Une cle protegee ("_xxx") declaree avec show_in_rest exposerait
            // publiquement une meta interne.
            if ( '' === $key || is_protected_meta( $key, 'post' ) ) {
                continue;
            }

            $keys[] = $key;
        }

        return array_values( array_unique( $keys ) );
    }

    /**
     * Bases de route reservees par le coeur dans l'espace de noms wp/v2.
     *
     * @return string[]
     */
    private static function core_rest_bases() {
        return array(
            'posts', 'pages', 'media', 'blocks', 'templates', 'template-parts',
            'global-styles', 'navigation', 'font-families', 'font-collections',
            'menu-items', 'menus', 'menu-locations', 'categories', 'tags',
            'comments', 'users', 'search', 'types', 'statuses', 'taxonomies',
            'settings', 'themes', 'plugins', 'sidebars', 'widgets', 'widget-types',
            'block-types', 'block-directory', 'block-renderer', 'pattern-directory',
            'block-patterns', 'revisions', 'autosaves', 'oembed',
        );
    }

    /**
     * Detecte une collision de base de route REST.
     *
     * Deux post types partageant la meme rest_base enregistrent la meme route :
     * le second ecrase les gestionnaires du premier, silencieusement. D'ou une
     * erreur bloquante plutot qu'un avertissement.
     *
     * Appelee depuis handle_save(), donc bien apres "init" : le registre des
     * post types et des taxonomies est complet a ce moment-la.
     *
     * @param string   $rest_base
     * @param string[] $exclude   Post types a ignorer (les notres).
     * @return string Message d'erreur, ou chaine vide si aucun conflit.
     */
    public static function rest_base_conflict( $rest_base, $exclude = array() ) {
        if ( '' === $rest_base ) {
            return '';
        }

        if ( in_array( $rest_base, self::core_rest_bases(), true ) ) {
            return sprintf(
                /* translators: %s: base de route choisie */
                __( 'La base de route REST "%s" est reservee par le coeur de WordPress. Choisissez une autre valeur.', 'custom-cpt-manager' ),
                $rest_base
            );
        }

        foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
            if ( in_array( $post_type->name, (array) $exclude, true ) || empty( $post_type->show_in_rest ) ) {
                continue;
            }

            // Une collision ne compte que dans le meme espace de noms.
            $namespace = ! empty( $post_type->rest_namespace ) ? $post_type->rest_namespace : 'wp/v2';
            if ( 'wp/v2' !== $namespace ) {
                continue;
            }

            $base = ! empty( $post_type->rest_base ) ? $post_type->rest_base : $post_type->name;

            if ( $base === $rest_base ) {
                return sprintf(
                    /* translators: 1: base de route, 2: post type en conflit */
                    __( 'La base de route REST "%1$s" est deja utilisee par le post type "%2$s".', 'custom-cpt-manager' ),
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
                    /* translators: 1: base de route, 2: taxonomie en conflit */
                    __( 'La base de route REST "%1$s" est deja utilisee par la taxonomie "%2$s".', 'custom-cpt-manager' ),
                    $rest_base,
                    $taxonomy->name
                );
            }
        }

        return '';
    }
}
