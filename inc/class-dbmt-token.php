<?php
/**
 * DBMT_Token — token con cui il bridge si autentica.
 *
 * Generato dalla pagina di impostazioni e mostrato una sola volta: nel
 * database resta solo l'hash, così chi legge il database non può inviare
 * misure. Rigenerarlo invalida quello vecchio.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Token {

	const OPTION = 'dbmt_token_hash';

	/**
	 * Nuovo token: salva l'hash e restituisce il token in chiaro.
	 */
	public static function genera() {
		$token = bin2hex( random_bytes( 24 ) );
		update_option( self::OPTION, hash( 'sha256', $token ), false );
		return $token;
	}

	public static function verifica( $token ) {
		$hash = get_option( self::OPTION, '' );
		return is_string( $token ) && '' !== $token && is_string( $hash ) && '' !== $hash
			&& hash_equals( $hash, hash( 'sha256', $token ) );
	}

	public static function esiste() {
		return '' !== get_option( self::OPTION, '' );
	}

	public static function revoca() {
		delete_option( self::OPTION );
	}
}
