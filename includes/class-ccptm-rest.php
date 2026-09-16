<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intégration API REST pour le CPT :
 * - Ajoute un champ "meta_input" en écriture qui accepte un tableau associatif
 *   de champs personnalisés arbitraires (similaire à wp_insert_post).
 * - Ajoute un champ "custom_fields" en lecture qui retourne toutes les meta
 *   publiques du post.
 *
 * Cela permet de POSTer via l'API REST des champs personnalisés sans avoir
 * à déclarer chacun au préalable via register_post_meta(), tout en respectant
 * les règles de sécurité WordPress (meta protégées, droits utilisateur).
 *
 * Depuis la 1.2.0, les clés listées dans les réglages sont en plus déclarées
 * par CCPTM_Meta et donc utilisables dans l'objet "meta" standard de l'API REST.
 * Ce fichier reste la porte de sortie pour tout le reste : clés non déclarées
 * (custom_* propres à chaque client), valeurs multiples, suppression par null.
 *
 * Ordre d'écriture dans une même requête, imposé par WP_REST_Posts_Controller
 * (update_value() puis update_additional_fields_for_object(), qui itère dans
 * l'ordre d'enregistrement) :
 *
 *     meta  ->  custom_fields  ->  meta_input
 *
 * Le dernier écrit gagne : si la même clé arrive par plusieurs canaux, c'est la
 * valeur de meta_input, puis celle de custom_fields, qui l'emporte sur meta.
 */
class CCPTM_REST {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );

		$settings = CCPTM_Settings::get();

		if ( ! empty( $settings['cpt_key'] ) && ! empty( $settings['intercept_rest'] ) ) {
			// Deux temps : on détecte sur rest_pre_insert_post (où la requête est
			// disponible), on applique sur wp_insert_post_data. Voir flag_reroute().
			add_filter( 'rest_pre_insert_post', array( $this, 'flag_reroute' ), 10, 2 );
			add_filter( 'wp_insert_post_data', array( $this, 'apply_reroute' ), 10, 4 );
		}
	}

	/**
	 * Récupère la clé du CPT courant ou retourne null si non configuré.
	 */
	private function get_cpt_key() {
		$settings = CCPTM_Settings::get();
		return ! empty( $settings['cpt_key'] ) ? $settings['cpt_key'] : null;
	}

	/**
	 * Enregistre les champs REST personnalisés sur le CPT.
	 */
	public function register_rest_fields() {
		$cpt = $this->get_cpt_key();
		if ( ! $cpt ) {
			return;
		}

		// Champ en lecture : retourne l'ensemble des meta non protégées.
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

		// Alias pratique : "meta_input" pour ceux qui utilisent la même nomenclature
		// que wp_insert_post (optionnel, mais pratique côté client).
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
	 * GET : retourne les champs personnalisés (meta non protégées).
	 */
	public function read_custom_fields( $post_array ) {
		$post_id = isset( $post_array['id'] ) ? (int) $post_array['id'] : 0;
		if ( ! $post_id ) {
			return new stdClass();
		}

		$all  = get_post_meta( $post_id );
		$out  = array();

		foreach ( $all as $key => $values ) {
			// On masque les meta protégées (préfixées par _).
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

		// On retourne un objet pour garantir un {} en JSON même si vide.
		return (object) $out;
	}

	/**
	 * POST / PUT : enregistre les champs personnalisés reçus.
	 *
	 * Sécurités :
	 * - L'utilisateur doit avoir la capacité edit_post sur la ressource.
	 * - Les clés protégées ("_xxx") sont refusées SAUF si l'utilisateur
	 *   possède explicitement la capacité edit_post_meta associée.
	 * - Les clés non valides (non string, vide, caractères interdits) sont ignorées.
	 */
	public function write_custom_fields( $value, $post, $field_name ) {
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
			return true; // rien à faire
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

			// Nettoyage basique de la clé.
			$meta_key = sanitize_key( $meta_key );
			if ( '' === $meta_key ) {
				continue;
			}

			// Refus des meta protégées sauf droits spécifiques.
			if ( is_protected_meta( $meta_key, 'post' ) ) {
				if ( ! current_user_can( 'edit_post_meta', $post_id, $meta_key ) ) {
					continue;
				}
			}

			// Valeur null => suppression.
			if ( null === $meta_value ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			// Tableau indexé => on enregistre plusieurs valeurs (update_post_meta écraserait).
			if ( is_array( $meta_value ) && $this->is_list( $meta_value ) ) {
				delete_post_meta( $post_id, $meta_key );
				foreach ( $meta_value as $v ) {
					add_post_meta( $post_id, $meta_key, $this->sanitize_meta_value( $v ) );
				}
				continue;
			}

			// Les clés déclarées (CCPTM_Meta) portent déjà un sanitize_callback
			// branché sur update_metadata() : on leur passe la valeur brute pour
			// ne pas sanitiser deux fois. Les clés libres (custom_*) restent
			// traitées ici.
			$sanitized = CCPTM_Meta::is_registered_key( $meta_key )
				? $meta_value
				: $this->sanitize_meta_value( $meta_value );

			update_post_meta( $post_id, $meta_key, $sanitized );
		}

		return true;
	}

	/**
	 * Interception REST optionnelle (réglage "intercept_rest", désactivé par défaut).
	 *
	 * Pendant REST de CCPTM_XMLRPC : si une offre JobAffinity est créée sur
	 * /wp/v2/posts, on bascule son post_type vers le CPT avant insertion.
	 *
	 * Pourquoi en deux temps plutôt qu'un simple filtre rest_pre_insert_post :
	 * WP_REST_Posts_Controller::create_item() réaffecte
	 * `$prepared_post->post_type = $this->post_type` APRÈS avoir appliqué
	 * rest_pre_insert_{$post_type}. Toute modification du post_type faite dans ce
	 * filtre est donc écrasée par le cœur. On s'en sert uniquement pour détecter
	 * (c'est là qu'on a accès à la requête), et on applique dans
	 * wp_insert_post_data, exactement comme le fait déjà le chemin XML-RPC.
	 *
	 * Limites assumées, à connaître avant d'activer l'option :
	 * - les capacités ont déjà été vérifiées contre le post type "post"
	 *   (identiques ici, le CPT utilise capability_type => 'post') ;
	 * - la réponse reste formatée par le contrôleur de "posts" ;
	 * - l'élément créé n'apparaîtra pas dans un GET /wp/v2/posts ultérieur.
	 *
	 * @var bool
	 */
	private $reroute_pending = false;

	/**
	 * Étape 1 : détection. Ne modifie rien, arme seulement le drapeau.
	 *
	 * @param stdClass        $prepared_post
	 * @param WP_REST_Request $request
	 * @return stdClass
	 */
	public function flag_reroute( $prepared_post, $request ) {
		// Réarmé à chaque requête : le drapeau ne doit jamais fuir d'une
		// insertion à la suivante.
		$this->reroute_pending = false;

		$cpt = $this->get_cpt_key();

		if ( ! $cpt || ! post_type_exists( $cpt ) ) {
			return $prepared_post;
		}

		// Création uniquement : on ne déplace jamais un article existant lors
		// d'un POST /wp/v2/posts/123.
		if ( ! empty( $prepared_post->ID ) ) {
			return $prepared_post;
		}

		if ( $this->looks_like_jobaffinity( $request ) ) {
			$this->reroute_pending = true;
		}

		return $prepared_post;
	}

	/**
	 * Étape 2 : application, juste avant l'écriture en base.
	 *
	 * @param array $data      Données assainies passées à wp_insert_post().
	 * @param array $postarr
	 * @param array $unsanitized_postarr
	 * @param bool  $update
	 * @return array
	 */
	public function apply_reroute( $data, $postarr = array(), $unsanitized_postarr = array(), $update = false ) {
		if ( ! $this->reroute_pending || ! empty( $update ) ) {
			return $data;
		}

		// On ne consomme le drapeau que sur l'insertion qui nous intéresse :
		// une révision ou un auto-draft imbriqué ne doit pas le gaspiller.
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
	 * Signature JobAffinity : présence de job_id, quel que soit le canal utilisé
	 * (meta standard, custom_fields ou meta_input). Même critère que
	 * CCPTM_XMLRPC::looks_like_jobaffinity().
	 *
	 * @param WP_REST_Request $request
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
	 * Sanitisation minimale d'une valeur de meta (on préserve types et structure).
	 * WordPress appelle de toute façon wp_unslash en amont.
	 *
	 * Logique :
	 * - tableaux/objets : laissés tels quels (update_post_meta sérialisera).
	 * - booléens / entiers / floats : préservés.
	 * - chaînes ressemblant à une URL : esc_url_raw (préserve les & et encodages).
	 * - autres chaînes : wp_kses_post pour nettoyer sans casser HTML légitime.
	 */
	private function sanitize_meta_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			// Traitement récursif pour les tableaux de chaînes.
			return map_deep( $value, array( $this, 'sanitize_scalar' ) );
		}
		return $this->sanitize_scalar( $value );
	}

	/**
	 * Sanitise une valeur scalaire en fonction de son type.
	 */
	public static function sanitize_scalar( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		$str = (string) $value;

		// Détection d'URL : si ça commence par http(s):// ou //, on traite comme URL.
		if ( preg_match( '#^(https?:)?//#i', $str ) ) {
			return esc_url_raw( $str );
		}

		// Sinon, nettoyage HTML standard (préserve le contenu texte y compris accents,
		// chiffres, ponctuation et balises HTML autorisées pour un post).
		return wp_kses_post( $str );
	}

	/**
	 * Détecte un tableau "liste" (indexé numériquement, commençant à 0).
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
