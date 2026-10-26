<?php
/**
 * DBMT_Pulizia — eliminazione delle misure dalla pagina di impostazioni.
 *
 * Tutte, oppure quelle ricevute prima di un istante (per esempio le prove
 * fatte prima dell'installazione a scuola), di tutti i nodi o di uno solo.
 * L'istante si scrive nel fuso orario del sito; le misure sono in UTC.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Pulizia {

	/**
	 * '2026-10-27T08:00' nel fuso del sito → '2026-10-27 07:00:00' UTC.
	 * Funzione pura; null se il valore non è una data e ora valida.
	 *
	 * @param string       $locale Valore di un campo datetime-local.
	 * @param DateTimeZone $fuso   Fuso orario del sito.
	 */
	public static function limite_utc( $locale, DateTimeZone $fuso ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', (string) $locale, $fuso );
		$errori = DateTimeImmutable::getLastErrors();
		if ( false === $dt || ( $errori && ( $errori['warning_count'] || $errori['error_count'] ) ) ) {
			return null;
		}
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Elimina le misure e svuota le cache delle pagine.
	 *
	 * @param string|null $prima_di_utc Solo le misure ricevute prima di questo istante; null = tutte.
	 * @param int         $nodo         Solo questo nodo; 0 = tutti.
	 * @return int|false Misure eliminate, false se il database dà errore.
	 */
	public static function elimina( $prima_di_utc, $nodo ) {
		global $wpdb;
		$dove   = array( '1=1' );
		$valori = array();
		if ( null !== $prima_di_utc ) {
			$dove[]   = 'ricevuto < %s';
			$valori[] = $prima_di_utc;
		}
		if ( $nodo > 0 ) {
			$dove[]   = 'nodo = %d';
			$valori[] = $nodo;
		}
		$sql = 'DELETE FROM ' . DBMT_DB::table() . ' WHERE ' . implode( ' AND ', $dove );
		$n   = $wpdb->query( $valori ? $wpdb->prepare( $sql, $valori ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( array( 'dbmt_ultime', 'dbmt_serie_24h', 'dbmt_serie_7g', 'dbmt_serie_30g' ) as $cache ) {
			delete_transient( $cache );
		}
		return false === $n ? false : (int) $n;
	}

	/**
	 * Quante misure ci sono, la prima e l'ultima, per nodo.
	 */
	public static function riepilogo() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT nodo, COUNT(*) AS n, MIN(ricevuto) AS prima, MAX(ricevuto) AS ultima FROM ' . DBMT_DB::table() . ' GROUP BY nodo ORDER BY nodo' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
