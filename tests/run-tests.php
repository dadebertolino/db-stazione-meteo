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
		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			return $this->guasto ? false : 3;
		}
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
require __DIR__ . '/../inc/class-dbmt-serie.php';
require __DIR__ . '/../inc/class-dbmt-pulizia.php';
require __DIR__ . '/../inc/class-dbmt-token-lettura.php';
require __DIR__ . '/../inc/class-dbmt-api.php';

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

// ------------------------------------------------------------- Serie -----
echo "Serie per i grafici\n";
// Righe come le restituisce MySQL: stringhe, NULL per i valori mancanti
$righe = array(
	array( 'nodo' => '2', 'tipo' => 'bme280', 'b' => '2988241', 't' => '18.185', 'tmin' => '18.18', 'tmax' => '18.19', 'u' => '60.205', 'umin' => '60.2', 'umax' => '60.21', 'p' => '962.6', 'pmin' => '962.6', 'pmax' => '962.7' ),
	array( 'nodo' => '2', 'tipo' => 'bme280', 'b' => '2988243', 't' => null, 'tmin' => null, 'tmax' => null, 'u' => null, 'umin' => null, 'umax' => null, 'p' => '962.5', 'pmin' => '962.5', 'pmax' => '962.5' ),
	array( 'nodo' => '1', 'tipo' => 'sht45', 'b' => '2988241', 't' => '12.5', 'tmin' => '12.5', 'tmax' => '12.5', 'u' => '80.1', 'umin' => '80.1', 'umax' => '80.1', 'p' => null, 'pmin' => null, 'pmax' => null ),
);
$nodi = DBMT_Serie::componi( $righe, 600, 395 );
check( 'un elemento per nodo, nell\'ordine delle righe', 2 === count( $nodi ) && 2 === $nodi[0]['nodo'] && 'sht45' === $nodi[1]['tipo'] );
$p0 = $nodi[0]['punti'][0];
check( 'istante al centro dell\'intervallo', 2988241 * 600 + 300 === $p0[0] );
check( 'medie arrotondate: temperatura 2 decimali, umidità 1', 18.19 === $p0[1] && 60.2 === $p0[4] );
check( 'pressione riportata al livello del mare', $p0[7] > 1000 && $p0[7] < 1015 );
$p1 = $nodi[0]['punti'][1];
check( 'sensore guasto: null, non zero', null === $p1[1] && null === $p1[4] );
check( 'senza temperatura la pressione usa i 15 °C standard', round( DBMT_Meteo::pressione_slm( 962.5, 395 ), 1 ) === $p1[7] );
check( 'intervallo vuoto non inventato: punti 1 e 3, non 2', 2988243 * 600 + 300 === $p1[0] );
check( 'nodo sht45: pressione null', null === $nodi[1]['punti'][0][7] );

// ------------------------------------------------------------ Pulizia -----
echo "Pulizia\n";
$roma = new DateTimeZone( 'Europe/Rome' );
check( 'ora solare: 08:00 a Roma = 07:00 UTC', '2026-10-27 07:00:00' === DBMT_Pulizia::limite_utc( '2026-10-27T08:00', $roma ) );
check( 'ora legale: 08:00 a Roma = 06:00 UTC', '2026-07-15 06:00:00' === DBMT_Pulizia::limite_utc( '2026-07-15T08:00', $roma ) );
check( 'giorno del cambio d\'ora: 12:00 = 11:00 UTC', '2026-10-25 11:00:00' === DBMT_Pulizia::limite_utc( '2026-10-25T12:00', $roma ) );
check( 'data impossibile: rifiutata', null === DBMT_Pulizia::limite_utc( '2026-02-30T08:00', $roma ) );
check( 'testo qualsiasi: rifiutato', null === DBMT_Pulizia::limite_utc( 'ieri', $roma ) );
check( 'vuoto: rifiutato', null === DBMT_Pulizia::limite_utc( '', $roma ) );

check( 'tutte le misure: 3 eliminate', 3 === DBMT_Pulizia::elimina( null, 0 ) );
check( 'tutte: nessuna condizione', 'DELETE FROM wp_dbmt_misure WHERE 1=1' === $GLOBALS['wpdb']->ultima_query );
DBMT_Pulizia::elimina( '2026-10-27 07:00:00', 2 );
check( 'prima di un istante, un nodo', "DELETE FROM wp_dbmt_misure WHERE 1=1 AND ricevuto < '2026-10-27 07:00:00' AND nodo = 2" === $GLOBALS['wpdb']->ultima_query );
$GLOBALS['wpdb']->guasto = true;
check( 'errore del database: false, non 0', false === DBMT_Pulizia::elimina( null, 0 ) );
$GLOBALS['wpdb']->guasto = false;

// -------------------------------------------------- Token di lettura -----
echo "Token di lettura\n";
DBMT_Token::genera(); // c'è anche il token del bridge
$bridge = DBMT_Token::genera();
$l1     = DBMT_Token_Lettura::genera( 'Classe 4AEE' );
$l2     = DBMT_Token_Lettura::genera( 'Foglio di calcolo' );
check( 'prefisso dbmtl_ e 40 cifre esadecimali', 1 === preg_match( '/^dbmtl_[0-9a-f]{40}$/', $l1 ) );
check( 'due token, con il loro nome', array( 'Classe 4AEE', 'Foglio di calcolo' ) === array_column( DBMT_Token_Lettura::elenco(), 'nome' ) );
check( 'nel database solo l\'hash', false === strpos( serialize( get_option( 'dbmt_token_lettura' ) ), $l1 ) );
$id1 = DBMT_Token_Lettura::verifica( $l1 );
check( 'token giusto: restituisce il suo id', is_string( $id1 ) && isset( DBMT_Token_Lettura::elenco()[ $id1 ] ) );
check( 'il token del bridge non vale per leggere', false === DBMT_Token_Lettura::verifica( $bridge ) );
check( 'token sbagliato o vuoto: rifiutato', false === DBMT_Token_Lettura::verifica( $l1 . 'x' ) && false === DBMT_Token_Lettura::verifica( null ) );
DBMT_Token_Lettura::registra_uso( $id1, 1000000 );
DBMT_Token_Lettura::registra_uso( $id1, 1000100 );
check( 'ultimo uso aggiornato al massimo una volta all\'ora', 1000000 === DBMT_Token_Lettura::elenco()[ $id1 ]['ultimo_uso'] );
DBMT_Token_Lettura::revoca( $id1 );
check( 'revocato: rifiutato, l\'altro vale ancora', false === DBMT_Token_Lettura::verifica( $l1 ) && false !== DBMT_Token_Lettura::verifica( $l2 ) );

// ---------------------------------------------------------------- API -----
echo "API di lettura\n";
$roma = new DateTimeZone( 'Europe/Rome' );
check( 'giorno: mezzanotte ora del sito', '2026-10-26 23:00:00' === DBMT_Api::istante( '2026-10-27', $roma ) );
check( 'ora del sito', '2026-10-27 07:00:00' === DBMT_Api::istante( '2026-10-27T08:00', $roma ) );
check( 'ora del sito con i secondi', '2026-10-27 07:00:30' === DBMT_Api::istante( '2026-10-27T08:00:30', $roma ) );
check( 'UTC con Z', '2026-10-27 07:00:00' === DBMT_Api::istante( '2026-10-27T07:00:00Z', $roma ) );
check( 'fuso esplicito', '2026-10-27 07:00:00' === DBMT_Api::istante( '2026-10-27T09:00:00+02:00', $roma ) );
check( '+ diventato spazio nell\'indirizzo', '2026-10-27 07:00:00' === DBMT_Api::istante( '2026-10-27T08:00:00 01:00', $roma ) );
check( 'data impossibile o testo: null', null === DBMT_Api::istante( '2026-02-30', $roma ) && null === DBMT_Api::istante( 'ieri', $roma ) );

$c = DBMT_Api::cursore( '2026-10-27 07:00:00', 123 );
check( 'cursore leggibile', '20261027070000-123' === $c && array( '2026-10-27 07:00:00', 123 ) === DBMT_Api::cursore_leggi( $c ) );
check( 'cursore manomesso: null', null === DBMT_Api::cursore_leggi( '20261327070000-1' ) && null === DBMT_Api::cursore_leggi( "1' OR 1=1" ) );

list( $sql, $val ) = DBMT_Api::query( 'wp_dbmt_misure', array( 'da' => '2026-10-26 23:00:00' ), 2, array( '2026-10-27 07:00:00', 123 ), 1000 );
check( 'query: filtri, cursore, ordine cronologico, una riga in più',
	"SELECT * FROM wp_dbmt_misure WHERE 1=1 AND ricevuto >= %s AND nodo = %d AND (ricevuto > %s OR (ricevuto = %s AND id > %d)) ORDER BY ricevuto, id LIMIT %d" === $sql
	&& array( '2026-10-26 23:00:00', 2, '2026-10-27 07:00:00', '2026-10-27 07:00:00', 123, 1001 ) === $val );

$riga = array( 'id' => '7', 'ricevuto' => '2026-10-27 07:00:00', 'nodo' => '2', 'tipo' => 'bme280', 'seq' => '41', 'reset_causa' => null,
	'temp_c' => '12.80', 'ur_pct' => '59.20', 'press_hpa' => '967.0', 'vbat_mv' => '3000', 'vbat_tx_mv' => null, 'rssi' => '-51', 'snr' => '12' );
$m = DBMT_Api::misura( $riga, 395, $roma );
check( 'misura: tipi giusti e null veri', 7 === $m['id'] && 12.8 === $m['temp_c'] && null === $m['reset'] && null === $m['vbat_tx_mv'] && -51 === $m['rssi'] );
check( 'misura: ora UTC e ora locale', '2026-10-27T07:00:00Z' === $m['ricevuto'] && '2026-10-27T08:00:00+01:00' === $m['ora_locale'] );
check( 'misura: pressione anche al livello del mare', $m['press_slm_hpa'] > 1010 && $m['press_slm_hpa'] < 1016 );

$csv = DBMT_Api::csv( array( $m ) );
$r   = explode( "\r\n", $csv );
check( 'CSV: intestazione e riga, virgola e punto', 0 === strpos( $r[0], 'id,ricevuto,ora_locale,nodo' ) && 0 === strpos( $r[1], '7,2026-10-27T07:00:00Z,2026-10-27T08:00:00+01:00,2,bme280,41,,12.8,59.2,967,' ) );
$csvit = DBMT_Api::csv( array( $m ), true );
$r     = explode( "\r\n", $csvit );
check( 'CSV italiano: BOM, punto e virgola, virgola decimale, data per Excel',
	"\u{FEFF}" === substr( $csvit, 0, 3 ) && false !== strpos( $r[1], ';2026-10-27 08:00:00;2;bme280;41;;12,8;59,2;967;' ) );

// ------------------------------------------------------------- Report -----
echo "\n$pass passati, $fail falliti\n";
exit( $fail > 0 ? 1 : 0 );
