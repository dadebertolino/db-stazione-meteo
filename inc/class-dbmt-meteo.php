<?php
/**
 * DBMT_Meteo — calcoli sulle misure (funzioni pure, senza WordPress).
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Meteo {

	// Temperatura dell'atmosfera standard, quando la misura manca.
	const T_STANDARD_C = 15.0;

	/**
	 * Pressione riportata al livello del mare (formula ipsometrica con
	 * gradiente termico standard, come le stazioni WMO senza barometro tarato
	 * sulla quota).
	 *
	 * @param float      $p_hpa  Pressione misurata sul posto.
	 * @param int        $quota  Metri sul livello del mare.
	 * @param float|null $temp_c Temperatura dell'aria nello stesso momento.
	 */
	public static function pressione_slm( $p_hpa, $quota, $temp_c = null ) {
		$t  = ( null === $temp_c ? self::T_STANDARD_C : (float) $temp_c ) + 273.15;
		$gh = 0.0065 * $quota;
		return $p_hpa * pow( 1 - $gh / ( $t + $gh ), -5.257 );
	}
}
