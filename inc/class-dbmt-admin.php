<?php
/**
 * DBMT_Admin — pagina di impostazioni (Impostazioni → Stazione Meteo).
 *
 * - token del bridge: generazione e revoca (mostrato una sola volta)
 * - nomi dei nodi (es. 2 = "Cortile")
 * - quota della scuola (predefinita 395 m), per la pressione riportata al
 *   livello del mare: nel database resta quella misurata sul posto
 * - stato: ultimo pacchetto ricevuto per nodo, nodi muti
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Admin {

	const SLUG = 'db-stazione-meteo';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'Stazione Meteo', 'db-stazione-meteo' ),
			__( 'Stazione Meteo', 'db-stazione-meteo' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'db-admin-ui', DBMT_PLUGIN_URL . 'assets/css/db-admin-ui.css', array(), DBMT_VERSION );
		wp_enqueue_style( 'dbmt-admin', DBMT_PLUGIN_URL . 'assets/css/admin.css', array( 'db-admin-ui' ), DBMT_VERSION );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap dbmt-settings-wrap"><h1>' . esc_html__( 'Stazione Meteo', 'db-stazione-meteo' ) . '</h1></div>';
	}
}
