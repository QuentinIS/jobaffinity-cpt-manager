<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page de configuration dans l'admin WordPress.
 */
class CCPTM_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_ccptm_save_settings', array( $this, 'handle_save' ) );
	}

	public function register_menu() {
		add_options_page(
			__( 'Custom CPT Manager', 'custom-cpt-manager' ),
			__( 'Custom CPT Manager', 'custom-cpt-manager' ),
			'manage_options',
			'ccptm-settings',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'custom-cpt-manager' ) );
		}

		$settings = CCPTM_Settings::get();
		$notices  = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own wp_safe_redirect(); nothing is mutated here.
		if ( isset( $_GET['ccptm_updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ccptm_updated'] ) ) ) {
			$notices[] = array( 'type' => 'success', 'msg' => __( 'Réglages enregistrés.', 'custom-cpt-manager' ) );
		}

		// Messages d'erreur transmis via transient.
		$err_transient = get_transient( 'ccptm_errors_' . get_current_user_id() );
		if ( is_array( $err_transient ) ) {
			foreach ( $err_transient as $e ) {
				$notices[] = array( 'type' => 'error', 'msg' => $e );
			}
			delete_transient( 'ccptm_errors_' . get_current_user_id() );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Custom CPT Manager', 'custom-cpt-manager' ); ?></h1>

			<?php foreach ( $notices as $n ) : ?>
				<div class="notice notice-<?php echo esc_attr( $n['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $n['msg'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<p>
				<?php esc_html_e( 'Configurez la clé de votre Custom Post Type. Cette clé est utilisée comme identifiant interne, comme slug d\'URL et comme base de route dans l\'API REST.', 'custom-cpt-manager' ); ?>
			</p>

			<?php if ( empty( $settings['cpt_key'] ) ) : ?>
				<div class="notice notice-info inline">
					<p><strong><?php esc_html_e( 'Première installation :', 'custom-cpt-manager' ); ?></strong>
					<?php esc_html_e( 'Choisissez la clé de votre CPT (ex : "offer") puis enregistrez. Un menu dédié apparaîtra dans l\'administration.', 'custom-cpt-manager' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ccptm_save_settings" />
				<?php wp_nonce_field( 'ccptm_save_settings', 'ccptm_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="ccptm_cpt_key"><?php esc_html_e( 'Clé du CPT', 'custom-cpt-manager' ); ?> <span style="color:#c00">*</span></label>
							</th>
							<td>
								<input
									name="cpt_key"
									id="ccptm_cpt_key"
									type="text"
									value="<?php echo esc_attr( $settings['cpt_key'] ); ?>"
									class="regular-text"
									pattern="[a-z0-9_\-]{1,20}"
									maxlength="20"
									placeholder="offer"
									required
								/>
								<p class="description">
									<?php esc_html_e( 'Lettres minuscules, chiffres, tirets et underscores (20 caractères max). Exemple : "offer".', 'custom-cpt-manager' ); ?><br />
									<?php esc_html_e( 'Attention : modifier la clé après création de contenus changera les URLs et cassera les références existantes.', 'custom-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_rest_base"><?php esc_html_e( 'Base de route API REST', 'custom-cpt-manager' ); ?></label>
							</th>
							<td>
								<input
									name="rest_base"
									id="ccptm_rest_base"
									type="text"
									value="<?php echo esc_attr( $settings['rest_base'] ); ?>"
									class="regular-text"
									pattern="[a-z0-9_\-]{1,32}"
									maxlength="32"
									placeholder="<?php echo esc_attr( $settings['cpt_key'] ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Vide = identique à la clé du CPT.', 'custom-cpt-manager' ); ?>
									<?php if ( ! empty( $settings['cpt_key'] ) ) : ?>
										<?php
										printf(
											/* translators: %s: URL de l'endpoint REST */
											esc_html__( 'Endpoint actuel : %s', 'custom-cpt-manager' ),
											'<code>' . esc_html( rest_url( 'wp/v2/' . CCPTM_Settings::get_rest_base() ) ) . '</code>'
										);
										?>
									<?php endif; ?>
									<br />
									<?php esc_html_e( 'Permet d\'exposer /wp/v2/offer même si la clé du CPT est "offer-intern". N\'affecte pas les URLs publiques du site.', 'custom-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_singular"><?php esc_html_e( 'Libellé singulier', 'custom-cpt-manager' ); ?></label>
							</th>
							<td>
								<input
									name="singular"
									id="ccptm_singular"
									type="text"
									value="<?php echo esc_attr( $settings['singular'] ); ?>"
									class="regular-text"
									placeholder="Offre"
								/>
								<p class="description"><?php esc_html_e( 'Vide = déduit automatiquement depuis la clé.', 'custom-cpt-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_plural"><?php esc_html_e( 'Libellé pluriel', 'custom-cpt-manager' ); ?></label>
							</th>
							<td>
								<input
									name="plural"
									id="ccptm_plural"
									type="text"
									value="<?php echo esc_attr( $settings['plural'] ); ?>"
									class="regular-text"
									placeholder="Offres"
								/>
								<p class="description"><?php esc_html_e( 'Vide = singulier + "s".', 'custom-cpt-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_menu_icon"><?php esc_html_e( 'Icône du menu', 'custom-cpt-manager' ); ?></label>
							</th>
							<td>
								<input
									name="menu_icon"
									id="ccptm_menu_icon"
									type="text"
									value="<?php echo esc_attr( $settings['menu_icon'] ); ?>"
									class="regular-text"
									placeholder="dashicons-admin-post"
								/>
								<p class="description">
									<?php
									printf(
										/* translators: %s: lien vers la liste des dashicons */
										esc_html__( 'Nom d\'une Dashicon WordPress. Voir la liste sur %s.', 'custom-cpt-manager' ),
										'<a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener">developer.wordpress.org</a>'
									);
									?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'Interception XML-RPC', 'custom-cpt-manager' ); ?>
							</th>
							<td>
								<label for="ccptm_intercept_xmlrpc">
									<input
										name="intercept_xmlrpc"
										id="ccptm_intercept_xmlrpc"
										type="checkbox"
										value="1"
										<?php checked( ! empty( $settings['intercept_xmlrpc'] ) ); ?>
									/>
									<?php esc_html_e( 'Rediriger automatiquement les publications JobAffinity (XML-RPC) vers ce CPT', 'custom-cpt-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'JobAffinity publie en XML-RPC vers le post type "post" par défaut. Cette option détecte les offres JobAffinity (via la présence de job_id dans les custom fields) et les redirige vers votre CPT au lieu d\'atterrir dans les Articles.', 'custom-cpt-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Interception API REST', 'custom-cpt-manager' ); ?>
							</th>
							<td>
								<label for="ccptm_intercept_rest">
									<input
										name="intercept_rest"
										id="ccptm_intercept_rest"
										type="checkbox"
										value="1"
										<?php checked( ! empty( $settings['intercept_rest'] ) ); ?>
									/>
									<?php esc_html_e( 'Rediriger vers ce CPT les offres créées via POST /wp/v2/posts', 'custom-cpt-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Équivalent REST de l\'option XML-RPC ci-dessus : détecte les offres JobAffinity (présence de job_id) postées sur l\'endpoint des articles natifs et les bascule vers votre CPT. Ne s\'applique qu\'à la création, jamais à la mise à jour d\'un article existant.', 'custom-cpt-manager' ); ?><br />
									<?php esc_html_e( 'Laissez décoché pour tester les deux endpoints séparément.', 'custom-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'Champs JobAffinity (obligatoires)', 'custom-cpt-manager' ); ?>
							</th>
							<td>
								<p>
									<?php
									printf(
										/* translators: %d: nombre de clés du socle */
										esc_html__( '%d clés déclarées en permanence :', 'custom-cpt-manager' ),
										count( CCPTM_Meta::DEFAULT_KEYS )
									);
									?>
								</p>
								<p><code><?php echo esc_html( implode( ', ', CCPTM_Meta::DEFAULT_KEYS ) ); ?></code></p>
								<p class="description">
									<strong><?php esc_html_e( 'Socle requis par JobAffinity :', 'custom-cpt-manager' ); ?></strong>
									<?php esc_html_e( 'ces clés ne peuvent pas être retirées. Ajouter des champs ci-dessous ne les remplace pas.', 'custom-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_extra_meta_keys"><?php esc_html_e( 'Champs supplémentaires', 'custom-cpt-manager' ); ?></label>
							</th>
							<td>
								<textarea
									name="extra_meta_keys"
									id="ccptm_extra_meta_keys"
									rows="8"
									class="large-text code"
									placeholder="custom_regions"
								><?php echo esc_textarea( implode( "\n", CCPTM_Meta::get_extra_keys() ) ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Une clé par ligne. Ces clés s\'ajoutent au socle ci-dessus : elles sont déclarées via register_post_meta() et deviennent utilisables dans l\'objet "meta" standard de l\'API REST.', 'custom-cpt-manager' ); ?><br />
									<strong><?php esc_html_e( 'Important :', 'custom-cpt-manager' ); ?></strong>
									<?php esc_html_e( 'WordPress ignore silencieusement, dans "meta", toute clé non déclarée — la requête répond 201 mais le champ est perdu. Une clé absente reste utilisable via "custom_fields" (ex. custom_regions).', 'custom-cpt-manager' ); ?><br />
									<?php esc_html_e( 'Format accepté : minuscules, chiffres, tirets et underscores. Les majuscules sont converties et les autres caractères supprimés (« Mon.Champ » devient « monchamp »). Une clé commençant par un underscore est refusée.', 'custom-cpt-manager' ); ?><br />
									<?php esc_html_e( 'Ressaisir une clé du socle ici est sans effet : le doublon est retiré. Retirer une clé de cette liste n\'efface pas les valeurs déjà enregistrées en base — la clé cesse simplement d\'être acceptée dans "meta".', 'custom-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'Articles natifs', 'custom-cpt-manager' ); ?>
							</th>
							<td>
								<label for="ccptm_register_meta_on_post">
									<input
										name="register_meta_on_post"
										id="ccptm_register_meta_on_post"
										type="checkbox"
										value="1"
										<?php checked( ! empty( $settings['register_meta_on_post'] ) ); ?>
									/>
									<?php esc_html_e( 'Déclarer aussi ces clés sur le post type « post »', 'custom-cpt-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Nécessaire pour publier des offres avec "meta" sur /wp/v2/posts. Contrepartie : chaque réponse de /wp/v2/posts embarque alors l\'objet "meta" avec ces clés (vides sur les articles ordinaires), et elles apparaissent dans l\'éditeur de blocs. Décochez si vous ne publiez que sur le CPT.', 'custom-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

					</tbody>
				</table>

				<?php submit_button( __( 'Enregistrer les réglages', 'custom-cpt-manager' ) ); ?>
			</form>

			<?php if ( ! empty( $settings['cpt_key'] ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Informations API REST', 'custom-cpt-manager' ); ?></h2>
				<?php
				$rest_base = CCPTM_Settings::get_rest_base();
				$base      = rest_url( 'wp/v2/' . $rest_base );
				$meta_keys = CCPTM_Meta::get_keys();

				// Deux post types partageant la même rest_base enregistrent la
				// même route : le second écrase le premier, sans aucune erreur.
				$conflict = CCPTM_Settings::rest_base_conflict( $rest_base, array( $settings['cpt_key'] ) );
				?>

				<?php if ( '' !== $conflict ) : ?>
					<div class="notice notice-warning inline">
						<p><?php echo esc_html( $conflict ); ?></p>
					</div>
				<?php endif; ?>

				<p><?php esc_html_e( 'Endpoint de votre CPT :', 'custom-cpt-manager' ); ?>
					<code><?php echo esc_html( $base ); ?></code>
				</p>

				<?php if ( $rest_base !== $settings['cpt_key'] ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: clé du CPT, 2: base de route REST */
							esc_html__( 'La clé du CPT (%1$s) et la base de route REST (%2$s) sont volontairement différentes.', 'custom-cpt-manager' ),
							'<code>' . esc_html( $settings['cpt_key'] ) . '</code>',
							'<code>' . esc_html( $rest_base ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Créer une offre : objet « meta » (recommandé)', 'custom-cpt-manager' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Canal standard de l\'API REST WordPress. Ne fonctionne que pour les clés déclarées ci-dessus : le socle JobAffinity et vos champs supplémentaires.', 'custom-cpt-manager' ); ?>
				</p>
<pre style="background:#f6f7f7;padding:12px;border:1px solid #dcdcde;overflow:auto;">POST <?php echo esc_html( $base ); ?>

{
  "title": "Vendeur H/F - Versailles",
  "content": "Description du poste...",
  "status": "publish",
  "meta": {
	"job_id": "1023736",
	"job_contract_type": "CDI",
	"job_salary_min": "28000",
	"job_link": "https://jobaffinity.fr/apply/976itcfhzqxldumwbv"
  },
  "custom_fields": {
	"custom_regions": "YVELINES SUD"
  }
}</pre>
				<p class="description">
					<?php esc_html_e( 'Les clés déclarées passent par "meta" ; les clés libres, multivaluées, ou supprimées par null passent par "custom_fields". Si une même clé arrive par les deux, c\'est la valeur de "custom_fields" qui est conservée.', 'custom-cpt-manager' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Authentification requise (Application Password, JWT, ou cookie nonce). L\'utilisateur doit avoir les droits d\'édition sur le post type.', 'custom-cpt-manager' ); ?>
				</p>

				<h3><?php esc_html_e( 'Champs actuellement déclarés', 'custom-cpt-manager' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: 1: total des clés, 2: clés du socle, 3: clés supplémentaires */
						esc_html__( '%1$d clés (%2$d obligatoires + %3$d supplémentaires), déclarées sur :', 'custom-cpt-manager' ),
						count( $meta_keys ),
						count( CCPTM_Meta::DEFAULT_KEYS ),
						count( CCPTM_Meta::get_extra_keys() )
					);
					?>
					<code><?php echo implode( '</code>, <code>', array_map( 'esc_html', CCPTM_Meta::get_post_types() ) ); ?></code>
				</p>
				<p><code><?php echo esc_html( implode( ', ', $meta_keys ) ); ?></code></p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: commande de vérification */
						esc_html__( 'Pour vérifier ce que l\'API expose réellement : %s', 'custom-cpt-manager' ),
						'<code>curl -X OPTIONS ' . esc_html( $base ) . '</code>'
					);
					?>
				</p>

				<h3><?php esc_html_e( 'Rôles autorisés à gérer le CPT', 'custom-cpt-manager' ); ?></h3>
				<ul style="list-style:disc;padding-left:1.5em;">
					<li><?php esc_html_e( 'Administrateur : accès complet.', 'custom-cpt-manager' ); ?></li>
					<li><?php esc_html_e( 'Éditeur : créer, modifier et publier tous les contenus.', 'custom-cpt-manager' ); ?></li>
					<li><?php esc_html_e( 'Auteur : créer et gérer ses propres contenus.', 'custom-cpt-manager' ); ?></li>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Traitement du formulaire.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'custom-cpt-manager' ) );
		}

		check_admin_referer( 'ccptm_save_settings', 'ccptm_nonce' );

		$input = array(
			'cpt_key'          => isset( $_POST['cpt_key'] )   ? sanitize_text_field( wp_unslash( $_POST['cpt_key'] ) )   : '',
			'singular'         => isset( $_POST['singular'] )  ? sanitize_text_field( wp_unslash( $_POST['singular'] ) )  : '',
			'plural'           => isset( $_POST['plural'] )    ? sanitize_text_field( wp_unslash( $_POST['plural'] ) )    : '',
			'menu_icon'        => isset( $_POST['menu_icon'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_icon'] ) ) : '',
			'intercept_xmlrpc' => isset( $_POST['intercept_xmlrpc'] ) ? '1' : '',
			'intercept_rest'   => isset( $_POST['intercept_rest'] )   ? '1' : '',
			'rest_base'        => isset( $_POST['rest_base'] ) ? sanitize_text_field( wp_unslash( $_POST['rest_base'] ) ) : '',
			// wp_unslash avant le découpage : sanitize_key mangerait les
			// antislashs ajoutés par WordPress. sanitize_textarea_field (et non
			// sanitize_text_field) pour préserver les retours à la ligne qui
			// séparent les clés.
			'extra_meta_keys'  => isset( $_POST['extra_meta_keys'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra_meta_keys'] ) ) : '',
			'register_meta_on_post' => isset( $_POST['register_meta_on_post'] ) ? '1' : '',
		);

		$result = CCPTM_Settings::save( $input );

		$redirect = admin_url( 'options-general.php?page=ccptm-settings' );

		if ( $result['success'] ) {
			$redirect = add_query_arg( 'ccptm_updated', '1', $redirect );
		} else {
			set_transient( 'ccptm_errors_' . get_current_user_id(), $result['errors'], 60 );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
