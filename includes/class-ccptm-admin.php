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
			__( 'JobAffinity CPT Manager', 'jobaffinity-cpt-manager' ),
			__( 'JobAffinity CPT Manager', 'jobaffinity-cpt-manager' ),
			'manage_options',
			'ccptm-settings',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'jobaffinity-cpt-manager' ) );
		}

		$settings = CCPTM_Settings::get();
		$notices  = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own wp_safe_redirect(); nothing is mutated here.
		if ( isset( $_GET['ccptm_updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ccptm_updated'] ) ) ) {
			$notices[] = array( 'type' => 'success', 'msg' => __( 'Settings saved.', 'jobaffinity-cpt-manager' ) );
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
			<h1><?php echo esc_html__( 'JobAffinity CPT Manager', 'jobaffinity-cpt-manager' ); ?></h1>

			<?php foreach ( $notices as $n ) : ?>
				<div class="notice notice-<?php echo esc_attr( $n['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $n['msg'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<p>
				<?php esc_html_e( 'Set the key for your custom post type. The key is used as the internal identifier, as the URL slug and as the route base in the REST API.', 'jobaffinity-cpt-manager' ); ?>
			</p>

			<?php if ( empty( $settings['cpt_key'] ) ) : ?>
				<div class="notice notice-info inline">
					<p><strong><?php esc_html_e( 'First run:', 'jobaffinity-cpt-manager' ); ?></strong>
					<?php esc_html_e( 'Pick your post type key (for example "offer") and save. A dedicated menu then appears in the admin.', 'jobaffinity-cpt-manager' ); ?>
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
								<label for="ccptm_cpt_key"><?php esc_html_e( 'Post type key', 'jobaffinity-cpt-manager' ); ?> <span style="color:#c00">*</span></label>
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
									<?php esc_html_e( 'Lowercase letters, digits, hyphens and underscores (20 characters max). For example "offer".', 'jobaffinity-cpt-manager' ); ?><br />
									<?php esc_html_e( 'Careful: changing the key once content exists will change its URLs and break existing references.', 'jobaffinity-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_rest_base"><?php esc_html_e( 'REST API route base', 'jobaffinity-cpt-manager' ); ?></label>
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
									<?php esc_html_e( 'Empty = same as the post type key.', 'jobaffinity-cpt-manager' ); ?>
									<?php if ( ! empty( $settings['cpt_key'] ) ) : ?>
										<?php
										printf(
											/* translators: %s: URL de l'endpoint REST */
											esc_html__( 'Current endpoint: %s', 'jobaffinity-cpt-manager' ),
											'<code>' . esc_html( rest_url( 'wp/v2/' . CCPTM_Settings::get_rest_base() ) ) . '</code>'
										);
										?>
									<?php endif; ?>
									<br />
									<?php esc_html_e( 'Lets you expose /wp/v2/offer even when the post type key is "offer-intern". Public site URLs are unaffected.', 'jobaffinity-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_singular"><?php esc_html_e( 'Singular label', 'jobaffinity-cpt-manager' ); ?></label>
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
								<p class="description"><?php esc_html_e( 'Empty = derived from the key.', 'jobaffinity-cpt-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_plural"><?php esc_html_e( 'Plural label', 'jobaffinity-cpt-manager' ); ?></label>
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
								<p class="description"><?php esc_html_e( 'Empty = singular + "s".', 'jobaffinity-cpt-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_menu_icon"><?php esc_html_e( 'Menu icon', 'jobaffinity-cpt-manager' ); ?></label>
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
										esc_html__( 'Name of a WordPress Dashicon. See the full list at %s.', 'jobaffinity-cpt-manager' ),
										'<a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener">developer.wordpress.org</a>'
									);
									?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'XML-RPC interception', 'jobaffinity-cpt-manager' ); ?>
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
									<?php esc_html_e( 'Automatically route JobAffinity posts sent over XML-RPC to this post type', 'jobaffinity-cpt-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'JobAffinity publishes over XML-RPC to the "post" post type by default. This option detects JobAffinity offers, by the presence of job_id in the custom fields, and routes them to your post type instead of the Posts list.', 'jobaffinity-cpt-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'REST API interception', 'jobaffinity-cpt-manager' ); ?>
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
									<?php esc_html_e( 'Route offers created through POST /wp/v2/posts to this post type', 'jobaffinity-cpt-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'The REST counterpart of the XML-RPC option above: it detects JobAffinity offers, by the presence of job_id, posted to the native posts endpoint and moves them to your post type. It applies to creation only, never to an update of an existing post.', 'jobaffinity-cpt-manager' ); ?><br />
									<?php esc_html_e( 'Leave unchecked to test both endpoints separately.', 'jobaffinity-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'JobAffinity fields (required)', 'jobaffinity-cpt-manager' ); ?>
							</th>
							<td>
								<p>
									<?php
									printf(
										/* translators: %d: nombre de clés du socle */
										esc_html__( '%d keys are always declared:', 'jobaffinity-cpt-manager' ),
										count( CCPTM_Meta::DEFAULT_KEYS )
									);
									?>
								</p>
								<p><code><?php echo esc_html( implode( ', ', CCPTM_Meta::DEFAULT_KEYS ) ); ?></code></p>
								<p class="description">
									<strong><?php esc_html_e( 'Required by JobAffinity:', 'jobaffinity-cpt-manager' ); ?></strong>
									<?php esc_html_e( 'these keys cannot be removed. Adding fields below does not replace them.', 'jobaffinity-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="ccptm_extra_meta_keys"><?php esc_html_e( 'Additional fields', 'jobaffinity-cpt-manager' ); ?></label>
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
									<?php esc_html_e( 'One key per line. These are added to the required set above: they are declared through register_post_meta() and become usable in the standard "meta" object of the REST API.', 'jobaffinity-cpt-manager' ); ?><br />
									<strong><?php esc_html_e( 'Important:', 'jobaffinity-cpt-manager' ); ?></strong>
									<?php esc_html_e( 'WordPress silently ignores any undeclared key inside "meta": the request still answers 201, but the field is lost. An undeclared key remains usable through "custom_fields" (custom_regions, for example).', 'jobaffinity-cpt-manager' ); ?><br />
									<?php esc_html_e( 'Accepted format: lowercase letters, digits, hyphens and underscores. Uppercase is folded and any other character is dropped, so "My.Field" becomes "myfield". A key starting with an underscore is rejected.', 'jobaffinity-cpt-manager' ); ?><br />
									<?php esc_html_e( 'Re-entering a required key here does nothing: the duplicate is dropped. Removing a key from this list does not erase values already stored in the database, the key simply stops being accepted in "meta".', 'jobaffinity-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<?php esc_html_e( 'Native posts', 'jobaffinity-cpt-manager' ); ?>
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
									<?php esc_html_e( 'Declare these keys on the "post" post type as well', 'jobaffinity-cpt-manager' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Required to publish offers with "meta" on /wp/v2/posts. The trade-off: every /wp/v2/posts response then carries the "meta" object with these keys, empty on ordinary posts, and they show up in the block editor. Uncheck if you only publish to the custom post type.', 'jobaffinity-cpt-manager' ); ?>
								</p>
							</td>
						</tr>

					</tbody>
				</table>

				<?php submit_button( __( 'Save settings', 'jobaffinity-cpt-manager' ) ); ?>
			</form>

			<?php if ( ! empty( $settings['cpt_key'] ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'REST API reference', 'jobaffinity-cpt-manager' ); ?></h2>
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

				<p><?php esc_html_e( 'Your post type endpoint:', 'jobaffinity-cpt-manager' ); ?>
					<code><?php echo esc_html( $base ); ?></code>
				</p>

				<?php if ( $rest_base !== $settings['cpt_key'] ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: clé du CPT, 2: base de route REST */
							esc_html__( 'The post type key (%1$s) and the REST route base (%2$s) differ on purpose.', 'jobaffinity-cpt-manager' ),
							'<code>' . esc_html( $settings['cpt_key'] ) . '</code>',
							'<code>' . esc_html( $rest_base ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Creating an offer: the "meta" object (recommended)', 'jobaffinity-cpt-manager' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'The standard WordPress REST API channel. It only works for the keys declared above: the JobAffinity set and your additional fields.', 'jobaffinity-cpt-manager' ); ?>
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
					<?php esc_html_e( 'Declared keys go through "meta"; free-form, multi-valued or null-deleted keys go through "custom_fields". If the same key arrives through both, the "custom_fields" value wins.', 'jobaffinity-cpt-manager' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Authentication is required: application password, JWT or cookie nonce. The user must have editing rights on the post type.', 'jobaffinity-cpt-manager' ); ?>
				</p>

				<h3><?php esc_html_e( 'Currently declared fields', 'jobaffinity-cpt-manager' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: 1: total des clés, 2: clés du socle, 3: clés supplémentaires */
						esc_html__( '%1$d keys (%2$d required + %3$d additional), declared on:', 'jobaffinity-cpt-manager' ),
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
						esc_html__( 'To check what the API actually exposes: %s', 'jobaffinity-cpt-manager' ),
						'<code>curl -X OPTIONS ' . esc_html( $base ) . '</code>'
					);
					?>
				</p>

				<h3><?php esc_html_e( 'Roles allowed to manage the post type', 'jobaffinity-cpt-manager' ); ?></h3>
				<ul style="list-style:disc;padding-left:1.5em;">
					<li><?php esc_html_e( 'Administrator: full access.', 'jobaffinity-cpt-manager' ); ?></li>
					<li><?php esc_html_e( 'Editor: create, edit and publish all content.', 'jobaffinity-cpt-manager' ); ?></li>
					<li><?php esc_html_e( 'Author: create and manage their own content.', 'jobaffinity-cpt-manager' ); ?></li>
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
			wp_die( esc_html__( 'Access denied.', 'jobaffinity-cpt-manager' ) );
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
