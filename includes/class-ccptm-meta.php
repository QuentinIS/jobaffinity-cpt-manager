<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Déclaration des champs personnalisés auprès de l'API REST.
 *
 * Pourquoi cette classe existe :
 * l'objet "meta" standard de l'API REST WordPress refuse d'écrire une clé qui
 * n'a pas été déclarée côté serveur via register_post_meta() — et il la refuse
 * SANS erreur. On reçoit un 201 Created, le post est créé, et les champs sont
 * simplement absents. Le champ maison "custom_fields" (voir CCPTM_REST) n'a pas
 * cette contrainte, mais les clients qui utilisent l'API REST standard (dont
 * JobAffinity) envoient "meta".
 *
 * Cette classe déclare donc, sur le CPT et optionnellement sur le post type
 * natif "post", un socle obligatoire de clés JobAffinity (DEFAULT_KEYS) auquel
 * l'administrateur peut ajouter ses propres clés via les réglages. Le socle ne
 * peut pas être retiré : voir get_keys().
 */
class CCPTM_Meta {

	/**
	 * Socle obligatoire : les clés envoyées par JobAffinity sur toutes les
	 * offres. Toujours déclarées, quoi que contiennent les réglages.
	 *
	 * Toutes en "string", y compris les salaires et les coordonnées GPS :
	 * JobAffinity envoie tout en chaîne, et déclarer 'number' sur job_salary_min
	 * ferait rejeter l'offre entière avec une erreur rest_invalid_type.
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
	 * Clés dont la valeur est une URL : sanitisées avec esc_url_raw.
	 */
	const URL_KEYS = array( 'job_link', 'apply_url' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priorité 11 : après CCPTM_CPT (priorité 5) et après la priorité 10 à
		// laquelle la plupart des plugins enregistrent leurs post types, tout en
		// restant très en amont de rest_api_init qui construit le schéma REST.
		add_action( 'init', array( $this, 'register_meta_keys' ), 11 );

		foreach ( self::get_coercion_post_types() as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'coerce_meta_types' ), 10, 2 );
		}
	}

	/**
	 * Post types sur lesquels les clés sont déclarées.
	 *
	 * Retourne un tableau vide si le plugin n'est pas configuré : sur un réseau
	 * multisite, les sites qui n'ont pas d'option ccptm_settings ne doivent rien
	 * déclarer du tout.
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

		// L'interception REST fait atterrir la requête sur le contrôleur de
		// "post" : c'est le registre de meta de "post" qui sera consulté pour
		// écrire l'objet "meta". Sans déclaration là, les champs seraient perdus
		// silencieusement — exactement le bug que cette classe corrige.
		if ( ! empty( $settings['register_meta_on_post'] ) || ! empty( $settings['intercept_rest'] ) ) {
			$types[] = 'post';
		}

		/**
		 * Filtre les post types recevant les déclarations de meta.
		 *
		 * @param string[] $types
		 */
		$types = (array) apply_filters( 'ccptm_meta_post_types', $types );

		return array_values( array_unique( array_filter( $types ) ) );
	}

	/**
	 * Post types sur lesquels le filtre de coercition de type est branché.
	 *
	 * On inclut toujours "post", même quand les clés n'y sont pas déclarées :
	 * l'interception REST (CCPTM_REST) peut rerouter une requête arrivée sur
	 * /wp/v2/posts vers le CPT, et la coercition doit alors avoir eu lieu.
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
	 * Nettoie une liste de clés : sanitisation, rejet du vide et des metas
	 * protégées, dédoublonnage. Partagée par get_keys() et get_extra_keys().
	 *
	 * @param array $keys
	 * @return string[]
	 */
	private static function filter_keys( $keys ) {
		$keys = array_map( 'sanitize_key', (array) $keys );
		$keys = array_filter(
			$keys,
			static function ( $key ) {
				// Une clé protégée ("_xxx") déclarée avec show_in_rest exposerait
				// publiquement une meta interne : on les refuse systématiquement.
				return '' !== $key && ! is_protected_meta( $key, 'post' );
			}
		);

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Clés supplémentaires ajoutées par l'administrateur dans les réglages.
	 *
	 * Le socle JobAffinity en est toujours exclu : il est déclaré d'office par
	 * get_keys() et n'a donc rien à faire dans la liste des ajouts.
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
	 * Liste des clés à déclarer : le socle JobAffinity, puis les ajouts.
	 *
	 * Le socle est un invariant : il est réinjecté APRÈS le filtre, de sorte
	 * qu'aucune saisie dans les réglages ni aucun filtre tiers ne puisse le
	 * vider. Sans cette garantie, une clé JobAffinity manquante ferait perdre
	 * le champ silencieusement (201 Created, meta absente) — précisément le bug
	 * que cette classe existe pour éviter.
	 *
	 * @return string[]
	 */
	public static function get_keys() {
		$keys = array_merge( self::DEFAULT_KEYS, self::get_extra_keys() );

		/**
		 * Filtre la liste des clés meta déclarées dans l'API REST.
		 *
		 * Peut ajouter des clés ; ne peut pas retirer le socle JobAffinity.
		 *
		 * @param string[] $keys
		 */
		$keys = self::filter_keys( (array) apply_filters( 'ccptm_meta_keys', $keys ) );

		return array_values( array_unique( array_merge( self::DEFAULT_KEYS, $keys ) ) );
	}

	/**
	 * La clé est-elle déclarée ? Utilisé par CCPTM_REST pour éviter une
	 * double sanitisation sur le chemin "custom_fields".
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function is_registered_key( $key ) {
		return in_array( $key, self::get_keys(), true );
	}

	/**
	 * Déclare chaque clé sur chaque post type concerné.
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
							/* translators: %s: clé du champ personnalisé */
							__( 'Champ personnalisé « %s », déclaré par Custom CPT Manager.', 'custom-cpt-manager' ),
							$key
						),
						'sanitize_callback' => array( __CLASS__, 'sanitize_meta' ),
						'auth_callback'     => array( __CLASS__, 'auth_meta' ),
						// Pas de 'default' : il forcerait get_post_meta() à
						// renvoyer array('') au lieu de array() sur tout le site,
						// y compris en dehors de l'API REST.
					)
				);
			}
		}
	}

	/**
	 * Sanitisation appliquée à TOUS les chemins d'écriture d'un coup
	 * (objet "meta" REST, custom_fields, XML-RPC, metabox "Champs personnalisés"),
	 * puisque register_post_meta branche ce callback sur update_metadata().
	 *
	 * @param mixed  $value
	 * @param string $key
	 * @return mixed
	 */
	public static function sanitize_meta( $value, $key = '', $object_type = '' ) {
		// single => true : on ne devrait jamais recevoir de structure ici.
		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}

		if ( self::is_url_key( $key ) ) {
			$sanitized = esc_url_raw( (string) $value );
		} else {
			// Parité avec le chemin "custom_fields" historique.
			$sanitized = CCPTM_REST::sanitize_scalar( $value );
		}

		/**
		 * Filtre la valeur sanitisée d'une meta déclarée.
		 *
		 * @param mixed  $sanitized
		 * @param mixed  $value     Valeur brute.
		 * @param string $key
		 */
		return apply_filters( 'ccptm_sanitize_meta_value', $sanitized, $value, $key );
	}

	/**
	 * La clé contient-elle une URL ?
	 *
	 * @param string $key
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
	 * Autorisation de lecture/écriture de la meta.
	 *
	 * Signature du filtre auth_{$object_type}_meta_{$key}_for_{$subtype} :
	 * ( $allowed, $meta_key, $object_id, $user_id, $cap, $caps ).
	 *
	 * On utilise user_can( $user_id, ... ) et non current_user_can() : le filtre
	 * est aussi atteignable depuis un contexte admin/cron/CLI où l'utilisateur
	 * évalué n'est pas l'utilisateur courant.
	 *
	 * Note : le cœur calcule déjà map_meta_cap( 'edit_post', ... ) avant
	 * d'appeler ce filtre, donc edit_post reste imposé de toute façon. Ce
	 * callback est une défense en profondeur, pas l'unique garde-fou.
	 *
	 * @param bool   $allowed
	 * @param string $meta_key
	 * @param int    $object_id
	 * @param int    $user_id
	 * @return bool
	 */
	public static function auth_meta( $allowed, $meta_key, $object_id, $user_id ) {
		if ( ! empty( $object_id ) ) {
			return user_can( $user_id, 'edit_post', (int) $object_id );
		}

		return user_can( $user_id, 'edit_posts' );
	}

	/**
	 * Convertit en chaîne les scalaires JSON non-chaînes reçus dans "meta".
	 *
	 * Indispensable : toutes nos clés sont déclarées 'string'. Sans cette
	 * coercition, un `"job_salary_min": 45000` (nombre JSON) déclenche un
	 * rest_invalid_type dans WP_REST_Meta_Fields::update_value()... qui
	 * s'exécute APRÈS wp_insert_post(). Résultat : réponse 400 mais post déjà
	 * créé en base, vide de toute meta — et le client qui réessaie crée des
	 * doublons.
	 *
	 * On se branche sur rest_pre_insert_{$post_type}, appelé depuis
	 * prepare_item_for_database(), donc avant wp_insert_post() et très en amont
	 * de update_value(). Ce hook est déjà limité à nos post types, ce qui évite
	 * de filtrer les routes à la main.
	 *
	 * @param stdClass        $prepared_post
	 * @param WP_REST_Request $request
	 * @return stdClass
	 */
	public function coerce_meta_types( $prepared_post, $request ) {
		$meta = $request->get_param( 'meta' );

		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return $prepared_post;
		}

		$changed = false;

		// On itère sur NOS clés, pas sur la charge utile : une clé non déclarée
		// ne nous regarde pas.
		foreach ( self::get_keys() as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				continue;
			}

			$value = $meta[ $key ];

			// null       => le cœur supprime la meta, on ne touche pas.
			// string     => déjà valide.
			// array/objet => ce n'est pas à nous d'aplatir : on laisse le cœur
			//                répondre 400 plutôt que de masquer un bug client.
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
