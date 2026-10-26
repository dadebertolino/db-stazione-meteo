<?php
/**
 * DBMT_Admin — pagina di impostazioni (Impostazioni → Stazione Meteo).
 *
 * - token del bridge: generazione e revoca (mostrato una sola volta)
 * - quota della scuola (predefinita 395 m), per la pressione riportata al
 *   livello del mare: nel database resta quella misurata sul posto
 * - stato: ultimo invio del bridge, ultima misura per nodo
 * - pulizia: eliminazione delle misure, tutte o fino a un istante (DBMT_Pulizia)
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Admin {

	const SLUG          = 'db-stazione-meteo';
	const NONCE         = 'dbmt_settings_nonce';
	const NONCE_TOKEN   = 'dbmt_token_nonce';
	const NONCE_PULIZIA = 'dbmt_pulizia_nonce';
	const QUOTA_DEFAULT = 395;

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_dbmt_token', array( __CLASS__, 'gestisci_token' ) );
		add_action( 'admin_post_dbmt_pulizia', array( __CLASS__, 'gestisci_pulizia' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'Stazione Meteo', 'db-stazione-meteo' ),
			__( 'Stazione Meteo', 'db-stazione-meteo' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'db-admin-ui', DBMT_PLUGIN_URL . 'assets/css/db-admin-ui.css', array(), DBMT_VERSION );
		wp_enqueue_style( 'dbmt-admin', DBMT_PLUGIN_URL . 'assets/css/admin.css', array( 'db-admin-ui' ), DBMT_VERSION );
	}

	public static function quota() {
		$opts = get_option( 'dbmt_settings', array() );
		return isset( $opts['quota_m'] ) ? (int) $opts['quota_m'] : self::QUOTA_DEFAULT;
	}

	/**
	 * Genera o revoca il token (admin-post.php). Il token nuovo passa alla
	 * pagina in un transient di un minuto, legato all'utente, e si mostra una
	 * sola volta.
	 */
	public static function gestisci_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'db-stazione-meteo' ), 403 );
		}
		check_admin_referer( self::NONCE_TOKEN );

		$azione = isset( $_POST['azione'] ) ? sanitize_key( wp_unslash( $_POST['azione'] ) ) : '';
		if ( 'genera' === $azione ) {
			set_transient( 'dbmt_token_nuovo_' . get_current_user_id(), DBMT_Token::genera(), MINUTE_IN_SECONDS );
		} elseif ( 'revoca' === $azione ) {
			DBMT_Token::revoca();
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Elimina le misure (admin-post.php) e torna alla pagina con l'esito.
	 */
	public static function gestisci_pulizia() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'db-stazione-meteo' ), 403 );
		}
		check_admin_referer( self::NONCE_PULIZIA );

		$ambito = isset( $_POST['ambito'] ) ? sanitize_key( wp_unslash( $_POST['ambito'] ) ) : '';
		$nodo   = isset( $_POST['nodo'] ) ? absint( $_POST['nodo'] ) : 0;
		$limite = null;
		if ( 'prima' === $ambito ) {
			$valore = isset( $_POST['prima_di'] ) ? sanitize_text_field( wp_unslash( $_POST['prima_di'] ) ) : '';
			$limite = DBMT_Pulizia::limite_utc( $valore, wp_timezone() );
			if ( null === $limite ) {
				self::torna( array( 'dbmt_pulizia' => 'data' ) );
			}
		} elseif ( 'tutte' !== $ambito ) {
			self::torna( array( 'dbmt_pulizia' => 'ambito' ) );
		}

		$n = DBMT_Pulizia::elimina( $limite, $nodo );
		self::torna( false === $n ? array( 'dbmt_pulizia' => 'db' ) : array( 'dbmt_eliminate' => $n ) );
	}

	private static function torna( array $arg ) {
		wp_safe_redirect( add_query_arg( $arg, admin_url( 'options-general.php?page=' . self::SLUG ) ) . '#dbmt-pulizia' );
		exit;
	}

	public static function maybe_save() {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$raw   = isset( $_POST['dbmt_quota_m'] ) ? sanitize_text_field( wp_unslash( $_POST['dbmt_quota_m'] ) ) : '';
		$quota = ( is_numeric( $raw ) && (int) $raw >= -100 && (int) $raw <= 5000 ) ? (int) $raw : self::QUOTA_DEFAULT;

		$opts            = get_option( 'dbmt_settings', array() );
		$opts['quota_m'] = $quota;
		update_option( 'dbmt_settings', $opts );
		return true;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$saved = self::maybe_save();

		$chiave = 'dbmt_token_nuovo_' . get_current_user_id();
		$nuovo  = get_transient( $chiave );
		if ( false !== $nuovo ) {
			delete_transient( $chiave );
		}
		?>
		<div class="wrap dbmt-settings-wrap">
			<h1><?php esc_html_e( 'Stazione Meteo', 'db-stazione-meteo' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="db-ui-alert db-ui-alert-success"><?php esc_html_e( 'Impostazioni salvate.', 'db-stazione-meteo' ); ?></div>
			<?php endif; ?>

			<?php
			// Esito della pulizia, dopo il ritorno da admin-post.php (solo lettura)
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['dbmt_eliminate'] ) ) :
				?>
				<div class="db-ui-alert db-ui-alert-success">
					<?php
					$n = absint( $_GET['dbmt_eliminate'] );
					/* translators: %s: numero di misure */
					printf( esc_html( _n( 'Eliminata %s misura.', 'Eliminate %s misure.', $n, 'db-stazione-meteo' ) ), esc_html( number_format_i18n( $n ) ) );
					?>
				</div>
			<?php elseif ( isset( $_GET['dbmt_pulizia'] ) ) : ?>
				<div class="db-ui-alert db-ui-alert-danger">
					<?php
					echo 'db' === $_GET['dbmt_pulizia']
						? esc_html__( 'Errore del database: nessuna misura eliminata.', 'db-stazione-meteo' )
						: esc_html__( 'Data e ora non valide: nessuna misura eliminata.', 'db-stazione-meteo' );
					?>
				</div>
				<?php
			endif;
			// phpcs:enable
			?>

			<?php if ( '' === get_option( 'timezone_string' ) && 0.0 === (float) get_option( 'gmt_offset' ) ) : ?>
				<div class="db-ui-alert db-ui-alert-warning">
					<?php
					printf(
						/* translators: %s: link a Impostazioni → Generali */
						esc_html__( 'Il fuso orario del sito è UTC: le ore delle misure vengono mostrate un\'ora o due indietro. Scegli "Roma" in %s.', 'db-stazione-meteo' ),
						'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Impostazioni → Generali', 'db-stazione-meteo' ) . '</a>'
					);
					?>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Collegamento del bridge', 'db-stazione-meteo' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Indirizzo', 'db-stazione-meteo' ); ?></th>
					<td>
						<code><?php echo esc_html( rest_url( DBMT_Rest::NS . '/misure' ) ); ?></code>
						<p class="description"><?php esc_html_e( 'Da copiare in [sito] url nel config.toml del Raspberry.', 'db-stazione-meteo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Token', 'db-stazione-meteo' ); ?></th>
					<td>
						<?php if ( false !== $nuovo ) : ?>
							<div class="db-ui-alert db-ui-alert-success">
								<p><strong><?php esc_html_e( 'Nuovo token, copialo adesso: non verrà più mostrato.', 'db-stazione-meteo' ); ?></strong></p>
								<p><code class="dbmt-token"><?php echo esc_html( $nuovo ); ?></code></p>
							</div>
						<?php elseif ( DBMT_Token::esiste() ) : ?>
							<p><?php esc_html_e( 'Impostato. Per motivi di sicurezza non si può rileggere: se l\'hai perso, generane uno nuovo.', 'db-stazione-meteo' ); ?></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Nessun token: il bridge non può inviare misure.', 'db-stazione-meteo' ); ?></p>
						<?php endif; ?>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="db-ui-actions">
							<?php wp_nonce_field( self::NONCE_TOKEN ); ?>
							<input type="hidden" name="action" value="dbmt_token">
							<button type="submit" name="azione" value="genera" class="db-ui-btn db-ui-btn-primary"
								<?php if ( DBMT_Token::esiste() ) : ?>
									onclick="return confirm('<?php echo esc_js( __( 'Il token attuale smetterà di funzionare: andrà aggiornato sul Raspberry. Continuare?', 'db-stazione-meteo' ) ); ?>');"
								<?php endif; ?>>
								<?php DBMT_Token::esiste() ? esc_html_e( 'Genera un nuovo token', 'db-stazione-meteo' ) : esc_html_e( 'Genera il token', 'db-stazione-meteo' ); ?>
							</button>
							<?php if ( DBMT_Token::esiste() ) : ?>
								<button type="submit" name="azione" value="revoca" class="db-ui-btn"
									onclick="return confirm('<?php echo esc_js( __( 'Il bridge non potrà più inviare misure. Continuare?', 'db-stazione-meteo' ) ); ?>');">
									<?php esc_html_e( 'Revoca', 'db-stazione-meteo' ); ?>
								</button>
							<?php endif; ?>
						</form>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Stato', 'db-stazione-meteo' ); ?></h2>
			<?php self::render_stato(); ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
				<h2><?php esc_html_e( 'Stazione', 'db-stazione-meteo' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="dbmt_quota_m"><?php esc_html_e( 'Quota (m s.l.m.)', 'db-stazione-meteo' ); ?></label></th>
						<td>
							<input type="number" step="1" min="-100" max="5000" id="dbmt_quota_m" name="dbmt_quota_m" value="<?php echo esc_attr( self::quota() ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Serve per mostrare la pressione riportata al livello del mare. Nel database resta quella misurata sul posto.', 'db-stazione-meteo' ); ?></p>
						</td>
					</tr>
				</table>
				<div class="db-ui-actions">
					<button type="submit" class="db-ui-btn db-ui-btn-primary"><?php esc_html_e( 'Salva', 'db-stazione-meteo' ); ?></button>
				</div>
			</form>

			<?php self::render_pulizia(); ?>
		</div>
		<?php
	}

	private static function render_pulizia() {
		$nodi = DBMT_Pulizia::riepilogo();
		$fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<h2 id="dbmt-pulizia"><?php esc_html_e( 'Pulizia dati', 'db-stazione-meteo' ); ?></h2>
		<?php if ( ! $nodi ) : ?>
			<p><?php esc_html_e( 'Non ci sono misure salvate.', 'db-stazione-meteo' ); ?></p>
			<?php
			return;
		endif;
		?>
		<p>
			<?php
			foreach ( $nodi as $n ) {
				printf(
					/* translators: 1: numero del nodo, 2: misure, 3: data e ora della prima, 4: data e ora dell'ultima */
					esc_html__( 'Nodo %1$d: %2$s misure, dal %3$s al %4$s.', 'db-stazione-meteo' ) . '<br>',
					(int) $n->nodo,
					esc_html( number_format_i18n( (int) $n->n ) ),
					esc_html( wp_date( $fmt, strtotime( $n->prima . ' UTC' ) ) ),
					esc_html( wp_date( $fmt, strtotime( $n->ultima . ' UTC' ) ) )
				);
			}
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('<?php echo esc_js( __( 'Le misure eliminate non si possono recuperare. Continuare?', 'db-stazione-meteo' ) ); ?>');">
			<?php wp_nonce_field( self::NONCE_PULIZIA ); ?>
			<input type="hidden" name="action" value="dbmt_pulizia">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Quali misure', 'db-stazione-meteo' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="ambito" value="prima" checked>
								<?php esc_html_e( 'ricevute prima di', 'db-stazione-meteo' ); ?></label>
							<input type="datetime-local" name="prima_di" value="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>" aria-label="<?php esc_attr_e( 'Data e ora', 'db-stazione-meteo' ); ?>">
							<br>
							<label><input type="radio" name="ambito" value="tutte">
								<?php esc_html_e( 'tutte', 'db-stazione-meteo' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Per esempio, per togliere le misure delle prove fatte prima dell\'installazione: la data e l\'ora da cui tenerle.', 'db-stazione-meteo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="dbmt_pulizia_nodo"><?php esc_html_e( 'Nodo', 'db-stazione-meteo' ); ?></label></th>
					<td>
						<select id="dbmt_pulizia_nodo" name="nodo">
							<option value="0"><?php esc_html_e( 'tutti', 'db-stazione-meteo' ); ?></option>
							<?php foreach ( $nodi as $n ) : ?>
								<?php /* translators: %d: numero del nodo */ ?>
								<option value="<?php echo (int) $n->nodo; ?>"><?php echo esc_html( sprintf( __( 'Nodo %d', 'db-stazione-meteo' ), (int) $n->nodo ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<div class="db-ui-actions">
				<button type="submit" class="db-ui-btn db-ui-btn-danger"><?php esc_html_e( 'Elimina le misure', 'db-stazione-meteo' ); ?></button>
			</div>
		</form>
		<?php
	}

	private static function render_stato() {
		global $wpdb;
		$invio = get_option( 'dbmt_ultimo_invio' );
		$nodi  = $wpdb->get_results( 'SELECT nodo, MAX(ricevuto) AS ultima, COUNT(*) AS n FROM ' . DBMT_DB::table() . ' GROUP BY nodo ORDER BY nodo' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<p>
			<?php if ( is_array( $invio ) ) : ?>
				<?php
				printf(
					/* translators: 1: data e ora, 2: misure ricevute, 3: inserite, 4: scartate, 5: versione del bridge */
					esc_html__( 'Ultimo invio del bridge: %1$s — %2$d misure, %3$d nuove, %4$d scartate (bridge %5$s).', 'db-stazione-meteo' ),
					esc_html( wp_date( $fmt, $invio['ora'] ) ),
					(int) $invio['ricevute'],
					(int) $invio['inserite'],
					(int) $invio['scartate'],
					esc_html( $invio['bridge'] ? $invio['bridge'] : '?' )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Il bridge non ha ancora inviato misure.', 'db-stazione-meteo' ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $nodi ) : ?>
			<table class="widefat striped dbmt-stato">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Nodo', 'db-stazione-meteo' ); ?></th>
						<th><?php esc_html_e( 'Ultima misura', 'db-stazione-meteo' ); ?></th>
						<th><?php esc_html_e( 'Misure salvate', 'db-stazione-meteo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $nodi as $n ) : ?>
						<tr>
							<td><?php echo (int) $n->nodo; ?></td>
							<td><?php echo esc_html( wp_date( $fmt, strtotime( $n->ultima . ' UTC' ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $n->n ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
