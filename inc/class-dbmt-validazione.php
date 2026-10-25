<?php
/**
 * DBMT_Validazione — controllo di una misura inviata dal bridge.
 *
 * Funzione pura (niente WordPress, niente database): dalla misura in JSON
 * alla riga per la tabella, oppure il motivo per cui viene scartata.
 * Formato: README, "Formato dei dati (bridge → plugin)".
 *
 * Una misura scartata non torna più indietro: il bridge la segna come
 * rifiutata e non la rimanda. Per questo i campi sono controllati uno per
 * uno, con un motivo leggibile nel log del bridge.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Validazione {

	const TIPI = array( 'bme280', 'sht45' );

	// Margine sull'orologio del Raspberry rispetto a quello del sito.
	const MAX_FUTURO_S = 300;

	// Prima di questa data l'orologio del Raspberry non era sincronizzato.
	const MIN_RICEVUTO = '2026-01-01T00:00:00Z';

	/**
	 * @param mixed $m      Misura decodificata dal JSON (array associativo).
	 * @param int   $adesso Timestamp corrente, UTC.
	 * @return array [ riga per il database | null, motivo dello scarto ]
	 */
	public static function misura( $m, $adesso ) {
		if ( ! is_array( $m ) ) {
			return array( null, 'la misura non è un oggetto' );
		}

		$ts = self::ricevuto( $m['ricevuto'] ?? null );
		if ( null === $ts ) {
			return array( null, 'ricevuto non valido' );
		}
		if ( $ts > $adesso + self::MAX_FUTURO_S ) {
			return array( null, 'ricevuto nel futuro' );
		}
		if ( $ts < strtotime( self::MIN_RICEVUTO ) ) {
			return array( null, 'ricevuto troppo vecchio: orologio del Raspberry non sincronizzato?' );
		}

		$tipo = $m['tipo'] ?? null;
		if ( ! in_array( $tipo, self::TIPI, true ) ) {
			return array( null, 'tipo non valido' );
		}

		$riga = array(
			'ricevuto' => gmdate( 'Y-m-d H:i:s', $ts ),
			'tipo'     => $tipo,
		);

		// campo JSON => [ colonna, minimo, massimo, può mancare, decimale ]
		$campi = array(
			'nodo'       => array( 'nodo', 1, 15, false, false ),
			'seq'        => array( 'seq', 0, 255, false, false ),
			'reset'      => array( 'reset_causa', 1, 7, true, false ),
			'temp_c'     => array( 'temp_c', -40, 85, true, true ),
			'ur_pct'     => array( 'ur_pct', 0, 100, true, true ),
			'press_hpa'  => array( 'press_hpa', 300, 1100, true, true ),
			'vbat_mv'    => array( 'vbat_mv', 0, 5000, true, false ),
			'vbat_tx_mv' => array( 'vbat_tx_mv', 0, 5000, true, false ),
			'rssi'       => array( 'rssi', -200, 0, false, false ),
			'snr'        => array( 'snr', -40, 40, false, false ),
		);
		foreach ( $campi as $campo => list( $colonna, $min, $max, $nullabile, $decimale ) ) {
			$v = $m[ $campo ] ?? null;
			if ( null === $v ) {
				if ( ! $nullabile ) {
					return array( null, "$campo mancante" );
				}
				$riga[ $colonna ] = null;
				continue;
			}
			// JSON: 18 e 18.0 arrivano come int o float; i booleani non sono numeri
			if ( ! ( is_int( $v ) || ( $decimale && is_float( $v ) ) ) ) {
				return array( null, "$campo non numerico" );
			}
			if ( $v < $min || $v > $max ) {
				return array( null, "$campo fuori campo" );
			}
			$riga[ $colonna ] = $decimale ? (float) $v : $v;
		}

		if ( 'sht45' === $tipo && null !== $riga['press_hpa'] ) {
			return array( null, 'press_hpa presente per un nodo sht45' );
		}

		return array( $riga, '' );
	}

	/**
	 * '2026-10-25T17:42:07Z' → timestamp, oppure null.
	 */
	private static function ricevuto( $s ) {
		if ( ! is_string( $s ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/', $s, $p ) ) {
			return null;
		}
		list( , $a, $mese, $g, $h, $min, $sec ) = array_map( 'intval', $p );
		if ( ! checkdate( $mese, $g, $a ) || $h > 23 || $min > 59 || $sec > 59 ) {
			return null;
		}
		return gmmktime( $h, $min, $sec, $mese, $g, $a );
	}
}
