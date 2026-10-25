<?php
/**
 * Aggiornamento automatico da GitHub Releases.
 *
 * Componente condiviso della suite DB.
 *
 * Repository privati: se e' disponibile un token GitHub (costante
 * DB_GITHUB_TOKEN_<REPO>, poi DB_GITHUB_TOKEN, poi filtro
 * db_github_updater_token) le chiamate a api.github.com per QUESTO repository
 * sono autenticate e il pacchetto viene scaricato dall'endpoint API
 * dell'asset. Il token non viene mai salvato nel database, mai stampato e mai
 * inviato a host diversi da api.github.com. Senza token il comportamento e'
 * identico a quello dei repository pubblici.
 *
 * Nome DB_GitHub_Updater_V2: la vecchia DB_GitHub_Updater (senza token),
 * caricata prima da altri plugin DB, vincerebbe sulla guard class_exists e
 * ignorerebbe il token. Le chiavi transient dbgu_md5(owner/repo) restano
 * compatibili con la versione precedente.
 *
 * @package DB_Stazione_Meteo
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DB_GitHub_Updater_V2' ) ) {

	/**
	 * Class DB_GitHub_Updater_V2
	 */
	class DB_GitHub_Updater_V2 {

		/**
		 * Versione del componente condiviso.
		 */
		const VERSION = '1.1.0';

		/**
		 * Percorso del file principale del plugin.
		 *
		 * @var string
		 */
		private $file;

		/**
		 * Basename del plugin.
		 *
		 * @var string
		 */
		private $basename;

		/**
		 * Slug del plugin.
		 *
		 * @var string
		 */
		private $slug;

		/**
		 * Utente o organizzazione GitHub.
		 *
		 * @var string
		 */
		private $owner;

		/**
		 * Nome del repository.
		 *
		 * @var string
		 */
		private $repo;

		/**
		 * Dati del plugin.
		 *
		 * @var array
		 */
		private $plugin_data = array();

		/**
		 * Costruttore.
		 *
		 * @param string $file  File principale del plugin.
		 * @param string $owner Utente GitHub.
		 * @param string $repo  Repository.
		 */
		public function __construct( $file, $owner, $repo ) {
			$this->file     = $file;
			$this->basename = plugin_basename( $file );
			$this->slug     = dirname( $this->basename );
			$this->owner    = $owner;
			$this->repo     = $repo;

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
			add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
			add_filter( 'http_request_args', array( $this, 'add_auth_headers' ), 10, 2 );
			add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 4 );
			add_action( 'admin_notices', array( $this, 'private_repo_notice' ) );
		}

		/**
		 * Nome della costante specifica del repository, es.
		 * DB_GITHUB_TOKEN_DB_SECURE_AUTH per "db-secure-auth".
		 *
		 * @param string $repo Repository.
		 * @return string
		 */
		public static function token_constant_name( $repo ) {
			return 'DB_GITHUB_TOKEN_' . strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '_', (string) $repo ) );
		}

		/**
		 * Token GitHub, in ordine: costante del repository, costante generica,
		 * filtro db_github_updater_token. Stringa vuota se assente.
		 *
		 * @return string
		 */
		public function get_token() {
			foreach ( array( self::token_constant_name( $this->repo ), 'DB_GITHUB_TOKEN' ) as $name ) {
				if ( defined( $name ) ) {
					$value = constant( $name );
					if ( is_string( $value ) && '' !== trim( $value ) ) {
						return trim( $value );
					}
				}
			}

			$token = apply_filters( 'db_github_updater_token', '', $this->repo );

			return is_string( $token ) ? trim( $token ) : '';
		}

		/**
		 * Prefisso degli URL API di questo repository (con slash finale).
		 *
		 * @return string
		 */
		private function api_base() {
			return sprintf( 'https://api.github.com/repos/%s/%s/', rawurlencode( $this->owner ), rawurlencode( $this->repo ) );
		}

		/**
		 * True se l'URL appartiene all'API di QUESTO repository.
		 *
		 * @param string $url URL.
		 * @return bool
		 */
		private function is_repo_api_url( $url ) {
			$base = $this->api_base();
			return is_string( $url ) && 0 === strncasecmp( $url, $base, strlen( $base ) );
		}

		/**
		 * Aggiunge l'autenticazione alle richieste verso l'API di questo
		 * repository (filtro http_request_args). Nessun effetto senza token o
		 * per qualunque altro host/repository.
		 *
		 * @param array  $args Argomenti della richiesta.
		 * @param string $url  URL.
		 * @return array
		 */
		public function add_auth_headers( $args, $url ) {
			if ( ! is_array( $args ) || ! $this->is_repo_api_url( $url ) ) {
				return $args;
			}

			$token = $this->get_token();
			if ( '' === $token ) {
				return $args;
			}

			$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
			$path    = (string) wp_parse_url( $url, PHP_URL_PATH );

			$headers['Authorization'] = 'Bearer ' . $token;
			$headers['Accept']        = preg_match( '#/releases/assets/\d+$#', $path ) ? 'application/octet-stream' : 'application/vnd.github+json';
			if ( empty( $headers['User-Agent'] ) ) {
				$headers['User-Agent'] = 'DB-GitHub-Updater';
			}

			$args['headers'] = $headers;
			// Mai seguire un redirect portandosi dietro l'Authorization: i
			// download passano da pre_download(), che segue il redirect a mano
			// senza token.
			$args['redirection'] = 0;

			return $args;
		}

		/**
		 * Chiave del transient (non dipende dal token).
		 *
		 * @return string
		 */
		private function cache_key() {
			return 'dbgu_' . md5( $this->owner . '/' . $this->repo );
		}

		/**
		 * Recupera l'ultima release, con cache di 12 ore.
		 *
		 * @return array|false
		 */
		private function get_release() {
			$cache_key = $this->cache_key();
			$cached    = get_transient( $cache_key );

			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : false;
			}

			// Con token, Authorization viene aggiunto da add_auth_headers().
			$response = wp_remote_get(
				$this->api_base() . 'releases/latest',
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'DB-GitHub-Updater',
					),
				)
			);

			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			// Repository privato (o inesistente) interrogato senza token.
			if ( 404 === $code && '' === $this->get_token() ) {
				set_transient( $cache_key . '_404', 1, 12 * HOUR_IN_SECONDS );
			} else {
				delete_transient( $cache_key . '_404' );
			}

			if ( 200 !== $code ) {
				set_transient( $cache_key, 'none', HOUR_IN_SECONDS );
				return false;
			}

			$release = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
				set_transient( $cache_key, 'none', HOUR_IN_SECONDS );
				return false;
			}

			set_transient( $cache_key, $release, 12 * HOUR_IN_SECONDS );

			return $release;
		}

		/**
		 * Dati dell'header del plugin.
		 *
		 * @return array
		 */
		private function plugin_data() {
			if ( array() === $this->plugin_data ) {
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$this->plugin_data = get_plugin_data( $this->file, false, false );
			}
			return $this->plugin_data;
		}

		/**
		 * URL del pacchetto ZIP della release.
		 *
		 * Con token: URL API dell'asset (scaricato da pre_download()).
		 * Senza token: browser_download_url, come per i repository pubblici.
		 *
		 * @param array $release Release GitHub.
		 * @return string
		 */
		private function package_url( $release ) {
			$authenticated = '' !== $this->get_token();

			if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
				foreach ( $release['assets'] as $asset ) {
					if ( ! isset( $asset['browser_download_url'] ) || '.zip' !== substr( $asset['browser_download_url'], -4 ) ) {
						continue;
					}
					if ( $authenticated && isset( $asset['url'] ) && $this->is_repo_api_url( $asset['url'] ) ) {
						return $asset['url'];
					}
					return $asset['browser_download_url'];
				}
			}
			return isset( $release['zipball_url'] ) ? $release['zipball_url'] : '';
		}

		/**
		 * Scarica i pacchetti ospitati sull'API di questo repository (filtro
		 * upgrader_pre_download): asset privato o zipball.
		 *
		 * Richiesta autenticata senza seguire redirect; l'URL firmato del
		 * redirect (S3 / codeload) viene poi scaricato SENZA Authorization.
		 *
		 * @param mixed  $reply      Risposta corrente (false = procedi).
		 * @param string $package    URL del pacchetto.
		 * @param object $upgrader   Istanza upgrader.
		 * @param array  $hook_extra Dati aggiuntivi.
		 * @return mixed Percorso del file temporaneo, WP_Error o $reply.
		 */
		public function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
			if ( false !== $reply || ! $this->is_repo_api_url( $package ) || '' === $this->get_token() ) {
				return $reply;
			}

			// Authorization, Accept e redirection 0 da add_auth_headers().
			$response = wp_remote_get( $package, array( 'timeout' => 30 ) );

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'dbgu_download_failed', $response->get_error_message() );
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && 0 === strpos( $location, 'https://' ) ) {
				if ( ! function_exists( 'download_url' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				// URL firmato su un altro host: nessuna autenticazione.
				return download_url( $location, 300 );
			}

			if ( 200 === $code ) {
				if ( ! function_exists( 'wp_tempnam' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$tmp = wp_tempnam( 'dbgu-package.zip' );
				if ( $tmp && false !== file_put_contents( $tmp, wp_remote_retrieve_body( $response ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					return $tmp;
				}
			}

			return new WP_Error(
				'dbgu_download_failed',
				sprintf( 'Download del pacchetto da GitHub non riuscito (HTTP %d).', $code )
			);
		}

		/**
		 * Avviso nella schermata Plugin: repository non raggiungibile senza
		 * token (404 da /releases/latest).
		 *
		 * @return void
		 */
		public function private_repo_notice() {
			if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$screen = get_current_screen();
			if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
				return;
			}
			if ( ! get_transient( $this->cache_key() . '_404' ) || '' !== $this->get_token() ) {
				return;
			}

			$data = $this->plugin_data();
			$name = ! empty( $data['Name'] ) ? $data['Name'] : $this->slug;

			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong>: %2$s</p><p><code>define( \'%3$s\', \'github_pat_...\' );</code></p><p>%4$s</p></div>',
				esc_html( $name ),
				esc_html(
					sprintf(
						'il repository GitHub %s/%s non risponde senza autenticazione (404: repository privato), quindi gli aggiornamenti automatici non sono disponibili. Aggiungi in wp-config.php:',
						$this->owner,
						$this->repo
					)
				),
				esc_html( self::token_constant_name( $this->repo ) ),
				esc_html(
					sprintf(
						'Usa un fine-grained personal access token limitato al solo repository %s/%s, con permesso "Contents: Read-only". Il token resta in wp-config.php e viene inviato solo a api.github.com.',
						$this->owner,
						$this->repo
					)
				)
			);
		}

		/**
		 * Inietta l'aggiornamento disponibile.
		 *
		 * @param object $transient Transient degli aggiornamenti.
		 * @return object
		 */
		public function check_update( $transient ) {
			if ( ! is_object( $transient ) ) {
				return $transient;
			}

			$release = $this->get_release();

			if ( ! $release ) {
				return $transient;
			}

			$data    = $this->plugin_data();
			$current = isset( $data['Version'] ) ? $data['Version'] : '0';
			$remote  = ltrim( (string) $release['tag_name'], 'vV' );

			if ( version_compare( $remote, $current, '<=' ) ) {
				return $transient;
			}

			$item = array(
				'id'          => $this->basename,
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $remote,
				'url'         => isset( $release['html_url'] ) ? $release['html_url'] : '',
				'package'     => $this->package_url( $release ),
				'tested'      => get_bloginfo( 'version' ),
			);

			$transient->response[ $this->basename ] = (object) $item;

			return $transient;
		}

		/**
		 * Scheda informativa nel pannello aggiornamenti.
		 *
		 * @param mixed  $result Risultato corrente.
		 * @param string $action Azione richiesta.
		 * @param object $args   Argomenti.
		 * @return mixed
		 */
		public function plugin_info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
				return $result;
			}

			$release = $this->get_release();

			if ( ! $release ) {
				return $result;
			}

			$data = $this->plugin_data();

			return (object) array(
				'name'          => isset( $data['Name'] ) ? $data['Name'] : $this->slug,
				'slug'          => $this->slug,
				'version'       => ltrim( (string) $release['tag_name'], 'vV' ),
				'author'        => isset( $data['Author'] ) ? $data['Author'] : '',
				'homepage'      => isset( $data['PluginURI'] ) ? $data['PluginURI'] : '',
				'download_link' => $this->package_url( $release ),
				'sections'      => array(
					'description' => isset( $data['Description'] ) ? $data['Description'] : '',
					'changelog'   => isset( $release['body'] ) ? wpautop( wp_kses_post( $release['body'] ) ) : '',
				),
			);
		}

		/**
		 * Rinomina la cartella estratta (GitHub aggiunge hash o tag).
		 *
		 * @param string $source        Cartella sorgente.
		 * @param string $remote_source Cartella remota.
		 * @param object $upgrader      Istanza upgrader.
		 * @param array  $hook_extra    Dati aggiuntivi.
		 * @return string|WP_Error
		 */
		public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
			global $wp_filesystem;

			if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
				return $source;
			}

			$corrected = trailingslashit( $remote_source ) . $this->slug;

			if ( trailingslashit( $source ) === trailingslashit( $corrected ) ) {
				return $source;
			}

			if ( $wp_filesystem && $wp_filesystem->move( $source, $corrected, true ) ) {
				return trailingslashit( $corrected );
			}

			return $source;
		}
	}
}
