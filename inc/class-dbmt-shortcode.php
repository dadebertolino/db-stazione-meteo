<?php
/**
 * DBMT_Shortcode — [dbmt_meteo] e [dbmt_meteo nodo="2"]
 *
 * Ultimi valori di ogni nodo (temperatura, umidità, pressione riportata al
 * livello del mare) con l'ora della misura, e la temperatura minima e
 * massima delle ultime 24 ore. Se i dati sono vecchi lo dice in chiaro,
 * invece di mostrare valori fermi come se fossero attuali.
 *
 * Markup accessibile WCAG 2.1 AA: valori in una lista di definizioni, lo
 * stato "non aggiornato" è scritto, non solo colorato.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Shortcode {

	const CACHE       = 'dbmt_ultime';
	const CACHE_S     = 60;
	const VECCHIO_S   = 1800; // 30 minuti: tre invii saltati a 10 minuti

	public static function register() {
		add_shortcode( 'dbmt_meteo', array( __CLASS__, 'render' ) );
	}

	/**
	 * Ultima misura e temperature estreme delle ultime 24 ore, per nodo.
	 * In cache un minuto; l'endpoint la svuota quando arrivano misure nuove.
	 */
	public static function dati() {
		$dati = get_transient( self::CACHE );
		if ( false !== $dati ) {
			return $dati;
		}

		global $wpdb;
		$t = DBMT_DB::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ultime  = $wpdb->get_results(
			"SELECT m.* FROM {$t} m
			 JOIN ( SELECT nodo, MAX(ricevuto) AS r FROM {$t} GROUP BY nodo ) u
			   ON m.nodo = u.nodo AND m.ricevuto = u.r
			 ORDER BY m.nodo",
			ARRAY_A
		);
		$estremi = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT nodo, MIN(temp_c) AS tmin, MAX(temp_c) AS tmax FROM {$t} WHERE ricevuto >= %s GROUP BY nodo",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			),
			OBJECT_K
		);
		// phpcs:enable

		$dati = array();
		foreach ( (array) $ultime as $u ) {
			$n          = (int) $u['nodo'];
			$u['tmin']  = isset( $estremi[ $n ] ) ? $estremi[ $n ]->tmin : null;
			$u['tmax']  = isset( $estremi[ $n ] ) ? $estremi[ $n ]->tmax : null;
			$dati[ $n ] = $u;
		}
		set_transient( self::CACHE, $dati, self::CACHE_S );
		return $dati;
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'nodo' => 0 ), $atts, 'dbmt_meteo' );
		$nodo = absint( $atts['nodo'] );

		wp_enqueue_style( 'dbmt-frontend', DBMT_PLUGIN_URL . 'assets/css/frontend.css', array(), DBMT_VERSION );

		$dati = self::dati();
		if ( $nodo ) {
			$dati = isset( $dati[ $nodo ] ) ? array( $nodo => $dati[ $nodo ] ) : array();
		}
		if ( ! $dati ) {
			return '<p class="dbmt-meteo dbmt-vuoto">' . esc_html__( 'Nessuna misura disponibile.', 'db-stazione-meteo' ) . '</p>';
		}

		$out = '<div class="dbmt-meteo">';
		foreach ( $dati as $n => $d ) {
			$out .= self::scheda( $n, $d, count( $dati ) > 1 );
		}
		return $out . '</div>';
	}

	private static function valore( $v, $decimali, $unita ) {
		if ( null === $v ) {
			return esc_html__( 'non disponibile', 'db-stazione-meteo' );
		}
		return esc_html( number_format_i18n( (float) $v, $decimali ) . "\u{00A0}" . $unita );
	}

	private static function scheda( $n, $d, $piu_nodi ) {
		$ts      = strtotime( $d['ricevuto'] . ' UTC' );
		$quando  = wp_date( get_option( 'time_format' ), $ts ) . ', ' . wp_date( get_option( 'date_format' ), $ts );
		$vecchio = time() - $ts > self::VECCHIO_S;
		$id      = 'dbmt-nodo-' . $n;

		$press = null;
		if ( null !== $d['press_hpa'] ) {
			$press = DBMT_Meteo::pressione_slm( (float) $d['press_hpa'], DBMT_Admin::quota(), null === $d['temp_c'] ? null : (float) $d['temp_c'] );
		}

		$titolo = $piu_nodi
			/* translators: %d: numero del nodo */
			? sprintf( __( 'Stazione meteo — nodo %d', 'db-stazione-meteo' ), $n )
			: __( 'Stazione meteo', 'db-stazione-meteo' );

		ob_start();
		?>
		<section class="dbmt-scheda<?php echo $vecchio ? ' dbmt-vecchio' : ''; ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>">
			<h2 id="<?php echo esc_attr( $id ); ?>" class="dbmt-titolo"><?php echo esc_html( $titolo ); ?></h2>
			<?php if ( $vecchio ) : ?>
				<p class="dbmt-avviso" role="status">
					<?php
					/* translators: %s: ora e data dell'ultima misura */
					printf( esc_html__( 'Dati non aggiornati: l\'ultima misura è delle %s.', 'db-stazione-meteo' ), esc_html( $quando ) );
					?>
				</p>
			<?php endif; ?>
			<dl class="dbmt-valori">
				<div>
					<dt><?php esc_html_e( 'Temperatura', 'db-stazione-meteo' ); ?></dt>
					<dd><?php echo self::valore( $d['temp_c'], 1, '°C' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Umidità', 'db-stazione-meteo' ); ?></dt>
					<dd><?php echo self::valore( $d['ur_pct'], 0, '%' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
				</div>
				<?php if ( 'sht45' !== $d['tipo'] ) : ?>
					<div>
						<dt><?php esc_html_e( 'Pressione', 'db-stazione-meteo' ); ?></dt>
						<dd>
							<?php echo self::valore( $press, 1, 'hPa' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( null !== $press ) : ?>
								<span class="dbmt-nota"><?php esc_html_e( 'al livello del mare', 'db-stazione-meteo' ); ?></span>
							<?php endif; ?>
						</dd>
					</div>
				<?php endif; ?>
			</dl>
			<?php if ( null !== $d['tmin'] && null !== $d['tmax'] ) : ?>
				<p class="dbmt-estremi">
					<?php
					/* translators: 1: temperatura minima, 2: temperatura massima */
					printf( esc_html__( 'Ultime 24 ore: minima %1$s, massima %2$s', 'db-stazione-meteo' ), self::valore( $d['tmin'], 1, '°C' ), self::valore( $d['tmax'], 1, '°C' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! $vecchio ) : ?>
				<p class="dbmt-ora">
					<?php
					/* translators: %s: ora e data della misura */
					printf( esc_html__( 'Misura delle %s', 'db-stazione-meteo' ), esc_html( $quando ) );
					?>
				</p>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}
}
