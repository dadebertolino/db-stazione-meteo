<?php
/**
 * DBMT_Rest — endpoint REST.
 *
 * POST /wp-json/dbmt/v1/misure   il bridge invia un gruppo di misure.
 *                                Autenticato con un token (header
 *                                Authorization: Bearer), solo HTTPS.
 * GET  /wp-json/dbmt/v1/ultime   ultimi valori per nodo, pubblico, in sola
 *                                lettura, per il refresh della pagina.
 *
 * Il token è generato dalla pagina di impostazioni e salvato solo come hash.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Rest {

	const NS = 'dbmt/v1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		// Le route si aggiungono con il formato JSON definito insieme al bridge.
	}
}
