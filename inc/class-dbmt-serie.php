<?php
/**
 * DBMT_Serie — serie aggregate per i grafici.
 *
 * Il browser riceve al massimo qualche centinaio di punti per nodo, anche
 * dopo anni di dati: ogni punto è media, minima e massima di un intervallo.
 *
 *   24h  intervalli di 10 minuti   144 punti
 *   7g   intervalli di 1 ora       168 punti
 *   30g  intervalli di 3 ore       240 punti
 *
 * Gli intervalli senza misure non compaiono: il grafico interrompe la linea
 * invece di unire i punti con una retta che finge misure mai fatte.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Serie {

	// periodo => [ durata in secondi, intervallo di aggregazione in secondi, cache in secondi ]
	const PERIODI = array(
		'24h' => array( 86400, 600, 60 ),
		'7g'  => array( 604800, 3600, 300 ),
		'30g' => array( 2592000, 10800, 900 ),
	);

	public static function cache( $periodo ) {
		return 'dbmt_serie_' . $periodo;
	}

	/**
	 * Serie del periodo, dalla cache o dal database.
	 */
	public static function periodo( $periodo ) {
		if ( ! isset( self::PERIODI[ $periodo ] ) ) {
			$periodo = '24h';
		}
		$serie = get_transient( self::cache( $periodo ) );
		if ( false !== $serie ) {
			return $serie;
		}

		list( $durata, $passo, $cache_s ) = self::PERIODI[ $periodo ];
		$a = time();
		$da = $a - $durata;

		global $wpdb;
		$t = DBMT_DB::table();
		// TIMESTAMPDIFF e non UNIX_TIMESTAMP: quest'ultimo legge la colonna nel
		// fuso orario della sessione MySQL, e le ore salvate sono già UTC
		$righe = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT nodo, MAX(tipo) AS tipo,
					FLOOR(TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', ricevuto) / %d) AS b,
					AVG(temp_c) AS t, MIN(temp_c) AS tmin, MAX(temp_c) AS tmax,
					AVG(ur_pct) AS u, MIN(ur_pct) AS umin, MAX(ur_pct) AS umax,
					AVG(press_hpa) AS p, MIN(press_hpa) AS pmin, MAX(press_hpa) AS pmax
				FROM {$t}
				WHERE ricevuto >= %s
				GROUP BY nodo, b
				ORDER BY nodo, b", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$passo,
				gmdate( 'Y-m-d H:i:s', $da )
			),
			ARRAY_A
		);

		$serie = array(
			'periodo' => $periodo,
			'passo'   => $passo,
			'da'      => $da,
			'a'       => $a,
			'quota'   => DBMT_Admin::quota(),
			'nodi'    => self::componi( (array) $righe, $passo, DBMT_Admin::quota() ),
		);
		set_transient( self::cache( $periodo ), $serie, $cache_s );
		return $serie;
	}

	/**
	 * Dalle righe aggregate di MySQL (stringhe o NULL) alla struttura per il
	 * grafico. Funzione pura.
	 *
	 * Ogni punto: [ istante centrale dell'intervallo, t, tmin, tmax, u, umin,
	 * umax, p, pmin, pmax ]; la pressione è riportata al livello del mare con
	 * la temperatura media dello stesso intervallo.
	 */
	public static function componi( array $righe, $passo, $quota ) {
		$nodi = array();
		foreach ( $righe as $r ) {
			$n = (int) $r['nodo'];
			if ( ! isset( $nodi[ $n ] ) ) {
				$nodi[ $n ] = array(
					'nodo'  => $n,
					'tipo'  => $r['tipo'],
					'punti' => array(),
				);
			}
			$t = self::num( $r['t'] );

			$p = array();
			foreach ( array( 'p', 'pmin', 'pmax' ) as $k ) {
				$v   = self::num( $r[ $k ] );
				$p[] = null === $v ? null : round( DBMT_Meteo::pressione_slm( $v, $quota, $t ), 1 );
			}

			$nodi[ $n ]['punti'][] = array_merge(
				array(
					(int) $r['b'] * $passo + intdiv( $passo, 2 ),
					self::num( $r['t'], 2 ),
					self::num( $r['tmin'], 2 ),
					self::num( $r['tmax'], 2 ),
					self::num( $r['u'], 1 ),
					self::num( $r['umin'], 1 ),
					self::num( $r['umax'], 1 ),
				),
				$p
			);
		}
		return array_values( $nodi );
	}

	private static function num( $v, $decimali = null ) {
		if ( null === $v ) {
			return null;
		}
		return null === $decimali ? (float) $v : round( (float) $v, $decimali );
	}
}
