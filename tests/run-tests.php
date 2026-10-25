<?php
/**
 * Test standalone (senza WordPress) per DB Stazione Meteo.
 * Uso: php tests/run-tests.php
 */

error_reporting( E_ALL );

// ---------------------------------------------------------------- Stub WP --
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['wp_options'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['wp_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['wp_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['wp_options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function rest_ensure_response( $r ) { return $r; }
function delete_transient( $k ) { return true; }

class WP_Error {
	public $code;
	public $data;
	public function __construct( $code, $msg, $data ) { $this->code = $code; $this->data = $data; }
}

class Finta_Richiesta {
	private $corpo;
	private $header;
	public function __construct( $corpo, $header = array() ) { $this->corpo = $corpo; $this->header = $header; }
	public function get_json_params() { return $this->corpo; }
	public function get_header( $k ) { return $this->header[ $k ] ?? null; }
}

// $wpdb finto: ricorda le righe per (nodo, ricevuto) come la chiave unica
class Finto_WPDB {
	public $prefix        = 'wp_';
	public $last_error    = '';
	public $rows_affected = 0;
	public $righe         = array();
	public $guasto        = false;
	public $ultima_query  = '';
	public function prepare( $sql, $valori ) {
		foreach ( $valori as $v ) {
			$sql = preg_replace( '/%[ds]/', is_int( $v ) ? (string) $v : "'" . addslashes( $v ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function query( $sql ) {
		$this->ultima_query = $sql;
		if ( $this->guasto ) {
			$this->last_error = 'MySQL server has gone away';
			return false;
		}
		$this->last_error = '';
		preg_match( "/VALUES \\('([^']+)','[a-z0-9]+',(\\d+),/", $sql, $p );
		$chiave = $p[2] . '|' . $p[1];
		$this->rows_affected = isset( $this->righe[ $chiave ] ) ? 0 : 1;
		$this->righe[ $chiave ] = $sql;
		return $this->rows_affected;
	}
}
$GLOBALS['wpdb'] = new Finto_WPDB();

require __DIR__ . '/../inc/class-dbmt-validazione.php';
require __DIR__ . '/../inc/class-dbmt-token.php';
require __DIR__ . '/../inc/class-dbmt-db.php';
require __DIR__ . '/../inc/class-dbmt-rest.php';
require __DIR__ . '/../inc/class-dbmt-meteo.php';

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ✓ $label\n"; }
	else { $fail++; echo "  ✗ $label\n"; }
}

// Misura come la invia il bridge (prove del 25/10/2026)
function misura( $diff = array() ) {
	return array_merge(
		array(
			'ricevuto'   => '2026-10-25T16:19:09Z',
			'nodo'       => 2,
			'tipo'       => 'bme280',
			'seq'        => 130,
			'reset'      => null,
			'temp_c'     => 18.19,
			'ur_pct'     => 60.21,
			'press_hpa'  => 962.6,
			'vbat_mv'    => 3000,
			'vbat_tx_mv' => 3000,
			'rssi'       => -58,
			'snr'        => 12,
		),
		$diff
	);
}

$adesso = gmmktime( 16, 30, 0, 10, 25, 2026 );
function valida( $m ) { global $adesso; return DBMT_Validazione::misura( $m, $adesso ); }

// --------------------------------------------------------- Validazione -----
echo "Validazione\n";
list( $r, $motivo ) = valida( misura() );
check( 'misura normale accettata', is_array( $r ) && '' === $motivo );
check( 'ora convertita in DATETIME UTC', '2026-10-25 16:19:09' === $r['ricevuto'] );
check( 'tipo del nodo salvato', 'bme280' === $r['tipo'] );
check( 'reset → colonna reset_causa', array_key_exists( 'reset_causa', $r ) && null === $r['reset_causa'] );
check( 'decimali come float', 18.19 === $r['temp_c'] && 962.6 === $r['press_hpa'] );

list( $r ) = valida( misura( array( 'seq' => 0, 'reset' => 1, 'vbat_tx_mv' => null ) ) );
check( 'primo pacchetto dopo un riavvio', 1 === $r['reset_causa'] && null === $r['vbat_tx_mv'] );

list( $r ) = valida( misura( array( 'temp_c' => null, 'ur_pct' => null, 'press_hpa' => null ) ) );
check( 'sensore guasto: misure null accettate', is_array( $r ) && null === $r['temp_c'] );

list( $r ) = valida( misura( array( 'temp_c' => 18 ) ) );
check( 'decimale arrivato come intero (18)', 18.0 === $r['temp_c'] );

list( $r ) = valida( misura( array( 'tipo' => 'sht45', 'press_hpa' => null ) ) );
check( 'nodo sht45 senza pressione', is_array( $r ) );

$scarti = array(
	'ricevuto mancante'            => array( misura( array( 'ricevuto' => null ) ), 'ricevuto non valido' ),
	'ricevuto in ora locale'       => array( misura( array( 'ricevuto' => '2026-10-25T17:19:09+01:00' ) ), 'ricevuto non valido' ),
	'ricevuto con data impossibile' => array( misura( array( 'ricevuto' => '2026-02-30T10:00:00Z' ) ), 'ricevuto non valido' ),
	'ricevuto nel futuro'          => array( misura( array( 'ricevuto' => '2026-10-25T16:40:00Z' ) ), 'ricevuto nel futuro' ),
	'orologio del Pi a 1970'       => array( misura( array( 'ricevuto' => '1970-01-01T00:01:00Z' ) ), 'ricevuto troppo vecchio: orologio del Raspberry non sincronizzato?' ),
	'tipo sconosciuto'             => array( misura( array( 'tipo' => 'dht22' ) ), 'tipo non valido' ),
	'nodo 0'                       => array( misura( array( 'nodo' => 0 ) ), 'nodo fuori campo' ),
	'nodo mancante'                => array( misura( array( 'nodo' => null ) ), 'nodo mancante' ),
	'seq come stringa'             => array( misura( array( 'seq' => '130' ) ), 'seq non numerico' ),
	'seq decimale'                 => array( misura( array( 'seq' => 130.5 ) ), 'seq non numerico' ),
	'temperatura fuori campo'      => array( misura( array( 'temp_c' => 120.0 ) ), 'temp_c fuori campo' ),
	'umidità come booleano'        => array( misura( array( 'ur_pct' => true ) ), 'ur_pct non numerico' ),
	'pressione per sht45'          => array( misura( array( 'tipo' => 'sht45' ) ), 'press_hpa presente per un nodo sht45' ),
	'non un oggetto'               => array( 'ciao', 'la misura non è un oggetto' ),
);
foreach ( $scarti as $label => list( $m, $atteso ) ) {
	list( $r, $motivo ) = valida( $m );
	check( "scartata: $label", null === $r && $atteso === $motivo );
}
list( $r ) = valida( misura( array( 'ricevuto' => '2026-10-25T16:33:00Z' ) ) );
check( 'tre minuti avanti: accettata (margine sull\'orologio)', is_array( $r ) );

// --------------------------------------------------------------- Token -----
echo "Token\n";
check( 'nessun token: nessuno passa', ! DBMT_Token::esiste() && ! DBMT_Token::verifica( '' ) );
$t = DBMT_Token::genera();
check( 'token di 48 caratteri esadecimali', 1 === preg_match( '/^[0-9a-f]{48}$/', $t ) );
check( 'nel database solo l\'hash', hash( 'sha256', $t ) === get_option( 'dbmt_token_hash' ) );
check( 'token giusto accettato', DBMT_Token::verifica( $t ) );
check( 'token sbagliato rifiutato', ! DBMT_Token::verifica( $t . 'x' ) && ! DBMT_Token::verifica( null ) );
$t2 = DBMT_Token::genera();
check( 'rigenerato: il vecchio non vale più', ! DBMT_Token::verifica( $t ) && DBMT_Token::verifica( $t2 ) );

check( 'header X-DBMT-Token', true === DBMT_Rest::autorizza( new Finta_Richiesta( array(), array( 'x_dbmt_token' => $t2 ) ) ) );
check( 'header Authorization: Bearer', true === DBMT_Rest::autorizza( new Finta_Richiesta( array(), array( 'authorization' => 'Bearer ' . $t2 ) ) ) );
$e = DBMT_Rest::autorizza( new Finta_Richiesta( array() ) );
check( 'senza token: 401', $e instanceof WP_Error && 401 === $e->data['status'] );
DBMT_Token::revoca();
check( 'revocato: rifiutato', ! DBMT_Token::verifica( $t2 ) );

// ---------------------------------------------------------- Inserimento -----
echo "Inserimento\n";
list( $r ) = valida( misura( array( 'reset' => null, 'vbat_tx_mv' => null ) ) );
check( 'prima volta: inserita', true === DBMT_DB::inserisci( $r ) );
$q = $GLOBALS['wpdb']->ultima_query;
check( 'NULL nella query, non stringa vuota', false !== strpos( $q, ',NULL,' ) && false === strpos( $q, "''" ) );
check( 'decimali senza arrotondamenti', false !== strpos( $q, "'18.19'" ) && false !== strpos( $q, "'962.6'" ) );
check( 'stessa misura: doppione', false === DBMT_DB::inserisci( $r ) );
$GLOBALS['wpdb']->guasto = true;
check( 'errore del database: null, non doppione', null === DBMT_DB::inserisci( $r ) );
$GLOBALS['wpdb']->guasto = false;

// -------------------------------------------------------------- Endpoint -----
echo "Endpoint\n";
$GLOBALS['wpdb']->righe = array();
$corpo = array(
	'bridge' => '0.1.0',
	'misure' => array(
		misura(),
		misura( array( 'ricevuto' => '2026-10-25T16:19:42Z', 'seq' => 131 ) ),
		misura( array( 'ricevuto' => '2026-10-25T16:20:13Z', 'seq' => 132, 'temp_c' => 999.0 ) ),
	),
);
$risp = DBMT_Rest::ricevi( new Finta_Richiesta( $corpo ) );
check( 'gruppo: 3 ricevute, 2 inserite, 1 scartata', 3 === $risp['ricevute'] && 2 === $risp['inserite'] && 0 === $risp['doppioni'] );
check( 'scartata con indice e motivo', array( array( 'indice' => 2, 'motivo' => 'temp_c fuori campo' ) ) === $risp['scartate'] );
check( 'ultimo invio registrato', 2 === get_option( 'dbmt_ultimo_invio' )['inserite'] && '0.1.0' === get_option( 'dbmt_ultimo_invio' )['bridge'] );

$risp = DBMT_Rest::ricevi( new Finta_Richiesta( $corpo ) );
check( 'stesso gruppo rimandato: 2 doppioni', 0 === $risp['inserite'] && 2 === $risp['doppioni'] );

$GLOBALS['wpdb']->guasto = true;
$e = DBMT_Rest::ricevi( new Finta_Richiesta( $corpo ) );
check( 'database guasto: 500, il bridge riprova', $e instanceof WP_Error && 500 === $e->data['status'] );
$GLOBALS['wpdb']->guasto = false;

foreach ( array(
	'corpo vuoto'          => null,
	'misure mancante'      => array( 'bridge' => '0.1.0' ),
	'misure non elenco'    => array( 'misure' => array( 'a' => misura() ) ),
	'più di 500 misure'    => array( 'misure' => array_fill( 0, 501, misura() ) ),
) as $label => $c ) {
	$e = DBMT_Rest::ricevi( new Finta_Richiesta( $c ) );
	check( "400: $label", $e instanceof WP_Error && 400 === $e->data['status'] );
}

// ------------------------------------------------- Pressione s.l.m. -----
echo "Pressione al livello del mare\n";
check( 'scuola, 395 m: 964,5 hPa a 18,3 °C → 1010,0', '1010.0' === sprintf( '%.1f', DBMT_Meteo::pressione_slm( 964.5, 395, 18.3 ) ) );
check( 'a quota 0 non cambia', abs( DBMT_Meteo::pressione_slm( 1013.25, 0, 20.0 ) - 1013.25 ) < 1e-9 );
check( 'più freddo: correzione maggiore', DBMT_Meteo::pressione_slm( 964.5, 395, -5.0 ) > DBMT_Meteo::pressione_slm( 964.5, 395, 30.0 ) );
check( 'senza temperatura: atmosfera standard (15 °C)', DBMT_Meteo::pressione_slm( 964.5, 395 ) === DBMT_Meteo::pressione_slm( 964.5, 395, 15.0 ) );

// ------------------------------------------------------------- Report -----
echo "\n$pass passati, $fail falliti\n";
exit( $fail > 0 ? 1 : 0 );
