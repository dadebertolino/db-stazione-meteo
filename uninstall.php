<?php
/**
 * Disinstallazione: rimuove tabella e opzioni del plugin.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dbmt_misure" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Elenco esplicito, mai LIKE 'dbmt_%': in SQL '_' è un carattere jolly.
foreach ( array( 'dbmt_db_version', 'dbmt_settings', 'dbmt_token_hash', 'dbmt_ultimo_invio', 'dbmt_token_lettura' ) as $dbmt_option ) {
	delete_option( $dbmt_option );
}
foreach ( array( 'dbmt_ultime', 'dbmt_serie_24h', 'dbmt_serie_7g', 'dbmt_serie_30g' ) as $dbmt_transient ) {
	delete_transient( $dbmt_transient );
}
