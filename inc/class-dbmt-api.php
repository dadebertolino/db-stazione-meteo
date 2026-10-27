<?php
/**
 * DBMT_Api — lettura dei dati con token di lettura (DBMT_Token_Lettura).
 *
 * GET /wp-json/dbmt/v1/dati   misure grezze, filtri da, a, nodo; formato
 *                             json, csv (virgola e punto) o csv-it (punto e
 *                             virgola e virgola decimale, per Excel in
 *                             italiano); al massimo 5000 per pagina, in
 *                             ordine cronologico
 * GET /wp-json/dbmt/v1/nodi   nodi con numero di misure, prima e ultima
 *
 * Token nell'header X-DBMT-Token, in Authorization: Bearer, oppure nel
 * parametro ?token= per i fogli di calcolo che non sanno inviare header
 * (IMPORTDATA di Fogli Google, Excel da Web). Il token del bridge non vale:
 * può scrivere, e non va dato a chi deve solo leggere.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Api {

	const MAX_LIMITE = 5000;
	const FORMATI    = array( 'json', 'csv', 'csv-it' );
	const CAMPI      = array( 'id', 'ricevuto', 'ora_locale', 'nodo', 'tipo', 'seq', 'reset', 'temp_c', 'ur_pct', 'press_hpa', 'press_slm_hpa', 'vbat_mv', 'vbat_tx_mv', 'rssi', 'snr' );

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'servi_csv' ), 10, 4 );
		// Per le applicazioni web di altri siti: l'header del token deve poter passare
		add_filter(
			'rest_allowed_cors_headers',
			function ( $h ) {
				$h[] = 'X-DBMT-Token';
				return $h;
			}
		);
		add_filter(
			'rest_exposed_cors_headers',
			function ( $h ) {
				$h[] = 'X-DBMT-Successivo';
				return $h;
			}
		);
	}

	public static function routes() {
		register_rest_route(
			DBMT_Rest::NS,
			'/dati',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'dati' ),
				'permission_callback' => array( __CLASS__, 'autorizza' ),
				'args'                => array(
					'da'      => array( 'type' => 'string' ),
					'a'       => array( 'type' => 'string' ),
					'nodo'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 15,
					),
					'formato' => array(
						'type'    => 'string',
						'enum'    => self::FORMATI,
						'default' => 'json',
					),
					'limite'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_LIMITE,
						'default' => 1000,
					),
					'dopo'    => array( 'type' => 'string' ),
					'token'   => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			DBMT_Rest::NS,
			'/nodi',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'nodi' ),
				'permission_callback' => array( __CLASS__, 'autorizza' ),
				'args'                => array( 'token' => array( 'type' => 'string' ) ),
			)
		);
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
		if ( ! $token ) {
			$token = $request['token'];
		}
		$id = DBMT_Token_Lettura::verifica( $token );
		if ( false === $id ) {
			return new WP_Error( 'dbmt_token', __( 'Token di lettura mancante o non valido.', 'db-stazione-meteo' ), array( 'status' => 401 ) );
		}
		DBMT_Token_Lettura::registra_uso( $id, time() );
		return true;
	}

	/**
	 * @param WP_REST_Request $request Richiesta.
	 */
	public static function dati( $request ) {
		ini_set( 'serialize_precision', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- vedi DBMT_Rest::serie()
		$fuso   = wp_timezone();
		$filtri = array();
		foreach ( array( 'da', 'a' ) as $k ) {
			if ( null !== $request[ $k ] && '' !== $request[ $k ] ) {
				$filtri[ $k ] = self::istante( $request[ $k ], $fuso );
				if ( null === $filtri[ $k ] ) {
					/* translators: %s: nome del parametro */
					return new WP_Error( 'dbmt_data', sprintf( __( 'Data non valida in "%s": usare 2026-10-27, 2026-10-27T08:00 (ora del sito) o 2026-10-27T07:00:00Z (UTC).', 'db-stazione-meteo' ), $k ), array( 'status' => 400 ) );
				}
			}
		}
		$dopo = null;
		if ( $request['dopo'] ) {
			$dopo = self::cursore_leggi( $request['dopo'] );
			if ( null === $dopo ) {
				return new WP_Error( 'dbmt_dopo', __( 'Parametro "dopo" non valido: usare il valore di "successivo" della risposta precedente.', 'db-stazione-meteo' ), array( 'status' => 400 ) );
			}
		}
		$limite = (int) $request['limite'];

		list( $sql, $valori ) = self::query( DBMT_DB::table(), $filtri, (int) $request['nodo'], $dopo, $limite );
		global $wpdb;
		$righe = $wpdb->get_results( $wpdb->prepare( $sql, $valori ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$successivo = null;
		if ( count( $righe ) > $limite ) {
			array_pop( $righe );
			$ultima     = end( $righe );
			$successivo = self::cursore( $ultima['ricevuto'], (int) $ultima['id'] );
		}
		$quota  = DBMT_Admin::quota();
		$misure = array_map(
			function ( $r ) use ( $quota, $fuso ) {
				return self::misura( $r, $quota, $fuso );
			},
			$righe
		);

		$prossima = null;
		if ( null !== $successivo ) {
			$parametri = array_filter( $request->get_query_params(), 'is_scalar' );
			unset( $parametri['token'] ); // il token non torna mai indietro nella risposta
			$parametri['dopo'] = $successivo;
			$prossima          = add_query_arg( array_map( 'rawurlencode', $parametri ), rest_url( DBMT_Rest::NS . '/dati' ) );
		}

		$formato = $request['formato'];
		if ( 'json' !== $formato ) {
			$risposta = new WP_REST_Response( array( '__dbmt_csv' => self::csv( $misure, 'csv-it' === $formato ) ) );
		} else {
			$risposta = new WP_REST_Response(
				array(
					'misure'            => $misure,
					'quota_m'           => $quota,
					'successivo'        => $successivo,
					'pagina_successiva' => $prossima,
				)
			);
		}
		if ( null !== $successivo ) {
			$risposta->header( 'X-DBMT-Successivo', $successivo );
			$risposta->header( 'Link', '<' . esc_url_raw( $prossima ) . '>; rel="next"' );
		}
		return $risposta;
	}

	/**
	 * @param WP_REST_Request $request Richiesta.
	 */
	public static function nodi( $request ) {
		$fuso = wp_timezone();
		$out  = array();
		foreach ( DBMT_Pulizia::riepilogo() as $n ) {
			$out[] = array(
				'nodo'   => (int) $n->nodo,
				'tipo'   => $n->tipo,
				'misure' => (int) $n->n,
				'prima'  => self::iso_utc( $n->prima ),
				'ultima' => self::iso_utc( $n->ultima ),
			);
		}
		return rest_ensure_response( array( 'nodi' => $out ) );
	}

	/**
	 * Il CSV esce così com'è, non codificato come stringa JSON.
	 */
	public static function servi_csv( $servito, $risultato, $request, $server ) {
		if ( $servito || '/' . DBMT_Rest::NS . '/dati' !== $request->get_route() ) {
			return $servito;
		}
		$d = $risultato->get_data();
		if ( ! is_array( $d ) || ! isset( $d['__dbmt_csv'] ) ) {
			return $servito;
		}
		$server->send_header( 'Content-Type', 'text/csv; charset=utf-8' );
		$server->send_header( 'Content-Disposition', 'inline; filename="misure.csv"' );
		echo $d['__dbmt_csv']; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV, non HTML
		return true;
	}

	// ------------------------------------------------- funzioni pure -----

	/**
	 * Istante del filtro → 'Y-m-d H:i:s' UTC, o null.
	 *   2026-10-27            mezzanotte, ora del sito
	 *   2026-10-27T08:00      ora del sito (anche con i secondi)
	 *   2026-10-27T07:00:00Z  UTC; accettato anche un fuso come +01:00
	 */
	public static function istante( $s, DateTimeZone $fuso ) {
		$s = trim( (string) $s );
		// In un indirizzo il + non codificato arriva come spazio: 08:00 01:00 → 08:00+01:00
		$s = preg_replace( '/ (\d{2}:\d{2})$/', '+$1', $s );
		if ( 'Z' === substr( $s, -1 ) ) {
			$s = substr( $s, 0, -1 ) . '+00:00';
		}
		$formati = array(
			array( '!Y-m-d', $fuso ),
			array( '!Y-m-d\TH:i', $fuso ),
			array( '!Y-m-d\TH:i:s', $fuso ),
			array( '!Y-m-d\TH:iP', null ),
			array( '!Y-m-d\TH:i:sP', null ),
		);
		foreach ( $formati as list( $f, $z ) ) {
			$dt     = $z ? DateTimeImmutable::createFromFormat( $f, $s, $z ) : DateTimeImmutable::createFromFormat( $f, $s );
			$errori = DateTimeImmutable::getLastErrors();
			if ( false !== $dt && ! ( $errori && ( $errori['warning_count'] || $errori['error_count'] ) ) ) {
				return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
		}
		return null;
	}

	/**
	 * Cursore della pagina successiva: istante dell'ultima misura e suo id.
	 */
	public static function cursore( $ricevuto_utc, $id ) {
		return gmdate( 'YmdHis', strtotime( $ricevuto_utc . ' UTC' ) ) . '-' . $id;
	}

	public static function cursore_leggi( $s ) {
		if ( ! preg_match( '/^(\d{14})-(\d{1,20})$/', (string) $s, $p ) ) {
			return null;
		}
		$dt = DateTimeImmutable::createFromFormat( '!YmdHis', $p[1], new DateTimeZone( 'UTC' ) );
		if ( false === $dt || $dt->format( 'YmdHis' ) !== $p[1] ) {
			return null;
		}
		return array( $dt->format( 'Y-m-d H:i:s' ), (int) $p[2] );
	}

	/**
	 * Query e valori per prepare(). Ordine cronologico, a parità di istante
	 * per id; si chiede una riga in più per sapere se c'è un'altra pagina.
	 */
	public static function query( $tabella, array $filtri, $nodo, $dopo, $limite ) {
		$dove   = array( '1=1' );
		$valori = array();
		if ( isset( $filtri['da'] ) ) {
			$dove[]   = 'ricevuto >= %s';
			$valori[] = $filtri['da'];
		}
		if ( isset( $filtri['a'] ) ) {
			$dove[]   = 'ricevuto < %s';
			$valori[] = $filtri['a'];
		}
		if ( $nodo > 0 ) {
			$dove[]   = 'nodo = %d';
			$valori[] = $nodo;
		}
		if ( null !== $dopo ) {
			$dove[]   = '(ricevuto > %s OR (ricevuto = %s AND id > %d))';
			$valori[] = $dopo[0];
			$valori[] = $dopo[0];
			$valori[] = $dopo[1];
		}
		$valori[] = $limite + 1;
		return array(
			"SELECT * FROM {$tabella} WHERE " . implode( ' AND ', $dove ) . ' ORDER BY ricevuto, id LIMIT %d',
			$valori,
		);
	}

	/**
	 * Riga del database (stringhe, NULL) → misura con i tipi giusti.
	 */
	public static function misura( array $r, $quota, DateTimeZone $fuso ) {
		$num = function ( $v, $int = false ) {
			if ( null === $v ) {
				return null;
			}
			return $int ? (int) $v : (float) $v;
		};
		$temp  = $num( $r['temp_c'] );
		$press = $num( $r['press_hpa'] );
		$ts    = strtotime( $r['ricevuto'] . ' UTC' );
		return array(
			'id'            => (int) $r['id'],
			'ricevuto'      => gmdate( 'Y-m-d\TH:i:s\Z', $ts ),
			'ora_locale'    => ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $fuso )->format( 'Y-m-d\TH:i:sP' ),
			'nodo'          => (int) $r['nodo'],
			'tipo'          => $r['tipo'],
			'seq'           => (int) $r['seq'],
			'reset'         => $num( $r['reset_causa'], true ),
			'temp_c'        => $temp,
			'ur_pct'        => $num( $r['ur_pct'] ),
			'press_hpa'     => $press,
			'press_slm_hpa' => null === $press ? null : round( DBMT_Meteo::pressione_slm( $press, $quota, $temp ), 1 ),
			'vbat_mv'       => $num( $r['vbat_mv'], true ),
			'vbat_tx_mv'    => $num( $r['vbat_tx_mv'], true ),
			'rssi'          => $num( $r['rssi'], true ),
			'snr'           => $num( $r['snr'], true ),
		);
	}

	/**
	 * Misure → CSV. Con $italiano: punto e virgola, virgola decimale e BOM,
	 * come se lo aspetta Excel in italiano; altrimenti virgola e punto.
	 */
	public static function csv( array $misure, $italiano = false ) {
		$sep  = $italiano ? ';' : ',';
		$righe = array( implode( $sep, self::CAMPI ) );
		foreach ( $misure as $m ) {
			$celle = array();
			foreach ( self::CAMPI as $c ) {
				$v = $m[ $c ];
				if ( null === $v ) {
					$v = '';
				} elseif ( is_float( $v ) ) {
					$v = (string) $v;
					if ( $italiano ) {
						$v = str_replace( '.', ',', $v );
					}
				} elseif ( 'ora_locale' === $c && $italiano ) {
					$v = str_replace( 'T', ' ', substr( $v, 0, 19 ) ); // Excel la riconosce come data
				}
				$celle[] = (string) $v;
			}
			$righe[] = implode( $sep, $celle );
		}
		return ( $italiano ? "\u{FEFF}" : '' ) . implode( "\r\n", $righe ) . "\r\n";
	}

	private static function iso_utc( $ricevuto ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $ricevuto . ' UTC' ) );
	}
}
