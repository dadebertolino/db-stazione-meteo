<?php
/**
 * DBMT_DB — tabella delle misure.
 *
 * Una riga per pacchetto ricevuto, con l'ora di arrivo assegnata dal
 * Raspberry. Tabella dedicata (non post né metadati): a 30 s sono circa
 * 2900 righe al giorno, a 10 min circa 150.
 *
 * La chiave unica (nodo, ora di arrivo) rende idempotenti i reinvii del
 * bridge: la stessa misura inviata due volte viene ignorata. L'ora è in UTC:
 * in ora locale l'ora ripetuta del cambio d'ora di ottobre produrrebbe
 * falsi doppioni.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_DB {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dbmt_misure';
	}

	/**
	 * Crea o aggiorna la tabella (dbDelta), all'attivazione del plugin.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ricevuto datetime NOT NULL,
				nodo tinyint(3) unsigned NOT NULL,
				seq tinyint(3) unsigned NOT NULL,
				reset_causa tinyint(3) unsigned DEFAULT NULL,
				temp_c decimal(5,2) DEFAULT NULL,
				ur_pct decimal(5,2) DEFAULT NULL,
				press_hpa decimal(5,1) DEFAULT NULL,
				vbat_mv smallint(5) unsigned DEFAULT NULL,
				vbat_tx_mv smallint(5) unsigned DEFAULT NULL,
				rssi smallint(6) DEFAULT NULL,
				snr tinyint(4) DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY nodo_ricevuto (nodo,ricevuto),
				KEY ricevuto (ricevuto)
			) {$charset};"
		);

		update_option( 'dbmt_db_version', self::DB_VERSION );
	}
}
