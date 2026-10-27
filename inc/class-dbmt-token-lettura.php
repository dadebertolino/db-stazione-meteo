<?php
/**
 * DBMT_Token_Lettura — token per leggere i dati dall'API.
 *
 * Separati dal token del bridge, che può scrivere: un token di lettura si può
 * dare a una classe o a un'altra applicazione senza permetterle di inviare
 * misure. Ognuno ha un nome, si revoca da solo, si vede una sola volta e nel
 * database resta solo l'hash. Il prefisso dbmtl_ li distingue a colpo
 * d'occhio da quello del bridge. Ognuno ha anche un limite di richieste
 * all'ora (DBMT_Api::conta), 60 se non indicato.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Token_Lettura {

	const OPTION   = 'dbmt_token_lettura';
	const PREFISSO = 'dbmtl_';

	const LIMITE_PREDEFINITO = 60;   // richieste all'ora
	const LIMITE_MASSIMO     = 3600;

	// L'ultimo utilizzo si aggiorna al massimo una volta all'ora: non una
	// scrittura nel database a ogni richiesta
	const USO_OGNI_S = 3600;

	public static function elenco() {
		$t = get_option( self::OPTION, array() );
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Nuovo token: salva nome, hash e limite, restituisce il token in chiaro.
	 */
	public static function genera( $nome, $limite_ora = self::LIMITE_PREDEFINITO ) {
		$token  = self::PREFISSO . bin2hex( random_bytes( 20 ) );
		$tokens = self::elenco();
		$id     = bin2hex( random_bytes( 4 ) );

		$tokens[ $id ] = array(
			'nome'       => $nome,
			'hash'       => hash( 'sha256', $token ),
			'creato'     => time(),
			'ultimo_uso' => 0,
			'limite_ora' => max( 1, min( self::LIMITE_MASSIMO, (int) $limite_ora ) ),
		);
		update_option( self::OPTION, $tokens, false );
		return $token;
	}

	/**
	 * Id del token, o false se non è un token di lettura valido.
	 */
	public static function verifica( $token ) {
		if ( ! is_string( $token ) || 0 !== strpos( $token, self::PREFISSO ) ) {
			return false;
		}
		$hash = hash( 'sha256', $token );
		foreach ( self::elenco() as $id => $t ) {
			if ( hash_equals( $t['hash'], $hash ) ) {
				return (string) $id;
			}
		}
		return false;
	}

	public static function limite( $id ) {
		$tokens = self::elenco();
		return isset( $tokens[ $id ]['limite_ora'] ) ? (int) $tokens[ $id ]['limite_ora'] : self::LIMITE_PREDEFINITO;
	}

	public static function registra_uso( $id, $adesso ) {
		$tokens = self::elenco();
		if ( isset( $tokens[ $id ] ) && $adesso - $tokens[ $id ]['ultimo_uso'] >= self::USO_OGNI_S ) {
			$tokens[ $id ]['ultimo_uso'] = $adesso;
			update_option( self::OPTION, $tokens, false );
		}
	}

	public static function revoca( $id ) {
		$tokens = self::elenco();
		unset( $tokens[ $id ] );
		update_option( self::OPTION, $tokens, false );
	}
}
