<?php
/**
 * DBMT_Rest — endpoint REST.
 *
 * POST /wp-json/dbmt/v1/misure   il bridge invia un gruppo di misure.
 *                                Token nell'header X-DBMT-Token (oppure
 *                                Authorization: Bearer).
 * GET  /wp-json/dbmt/v1/serie    serie aggregate per i grafici, pubbliche e
 *                                in sola lettura (?periodo=24h|7g|30g).
 *
 * Il token viaggia in un header proprio perché alcuni hosting Apache tolgono
 * Authorization prima di PHP, e alcuni plugin di sicurezza lo intercettano.
 * Formato e risposte: README, "Formato dei dati (bridge → plugin)".
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Rest {

	const NS         = 'dbmt/v1';
	const MAX_MISURE = 500;

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/misure',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ricevi' ),
				'permission_callback' => array( __CLASS__, 'autorizza' ),
			)
		);
		register_rest_route(
			self::NS,
			'/serie',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serie' ),
				'permission_callback' => '__return_true', // solo misure ambientali, nessun dato personale
				'args'                => array(
					'periodo' => array(
						'type'    => 'string', // senza type WordPress non controlla enum
						'default' => '24h',
						'enum'    => array_keys( DBMT_Serie::PERIODI ),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Richiesta.
	 */
	public static function serie( $request ) {
		// Alcuni PHP (anche Local) hanno serialize_precision = 17: 18.21 diventerebbe
		// 18.210000000000001 e la risposta raddoppierebbe. -1 = cifre minime esatte.
		ini_set( 'serialize_precision', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		return rest_ensure_response( DBMT_Serie::periodo( $request['periodo'] ) );
	}

	/**
	 * @param WP_REST_Request $request Richiesta.
	 */
	public static function autorizza( $request ) {
		$token = $request->get_header( 'x_dbmt_token' );
		if ( ! $token ) {
			$auth = (string) $request->get_header( 'authorization' );
			if ( 0 === stripos( $auth, 'Bearer ' ) ) {
				$token = trim( substr( $auth, 7 ) );
			}
		}
		if ( ! DBMT_Token::verifica( $token ) ) {
			return new WP_Error( 'dbmt_token', __( 'Token mancante o non valido.', 'db-stazione-meteo' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * @param WP_REST_Request $request Richiesta.
	 */
	public static function ricevi( $request ) {
		$corpo  = $request->get_json_params();
		$misure = is_array( $corpo ) ? ( $corpo['misure'] ?? null ) : null;
		if ( ! is_array( $misure ) || array_values( $misure ) !== $misure ) {
			return new WP_Error( 'dbmt_corpo', __( 'Corpo non valido: serve un elenco "misure".', 'db-stazione-meteo' ), array( 'status' => 400 ) );
		}
		if ( count( $misure ) > self::MAX_MISURE ) {
			/* translators: %d: numero massimo di misure */
			return new WP_Error( 'dbmt_troppe', sprintf( __( 'Al massimo %d misure per richiesta.', 'db-stazione-meteo' ), self::MAX_MISURE ), array( 'status' => 400 ) );
		}

		$adesso   = time();
		$inserite = 0;
		$doppioni = 0;
		$scartate = array();
		foreach ( $misure as $indice => $m ) {
			list( $riga, $motivo ) = DBMT_Validazione::misura( $m, $adesso );
			if ( null === $riga ) {
				$scartate[] = array(
					'indice' => $indice,
					'motivo' => $motivo,
				);
				continue;
			}
			$esito = DBMT_DB::inserisci( $riga );
			if ( null === $esito ) {
				// Il bridge rimanda il gruppo: le misure già inserite tornano come doppioni
				return new WP_Error( 'dbmt_database', __( 'Errore del database, riprovare.', 'db-stazione-meteo' ), array( 'status' => 500 ) );
			}
			if ( $esito ) {
				$inserite++;
			} else {
				$doppioni++;
			}
		}

		if ( $inserite > 0 ) {
			delete_transient( 'dbmt_ultime' ); // la pagina mostra subito le misure nuove
			delete_transient( 'dbmt_serie_24h' );
		}

		update_option(
			'dbmt_ultimo_invio',
			array(
				'ora'      => $adesso,
				'bridge'   => sanitize_text_field( (string) ( $corpo['bridge'] ?? '' ) ),
				'ricevute' => count( $misure ),
				'inserite' => $inserite,
				'scartate' => count( $scartate ),
			),
			false
		);

		return rest_ensure_response(
			array(
				'ricevute' => count( $misure ),
				'inserite' => $inserite,
				'doppioni' => $doppioni,
				'scartate' => $scartate,
			)
		);
	}
}
