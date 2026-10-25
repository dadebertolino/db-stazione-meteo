<?php
/**
 * Plugin Name: DB Stazione Meteo
 * Plugin URI: https://www.davidebertolino.it/progetti/
 * Description: Riceve le misure della stazione meteo LoRa della scuola, inviate dal Raspberry, e le mostra sul sito: ultimi valori e andamento.
 * Version: 0.1.0
 * Author: Davide Bertolino
 * Author URI: https://www.davidebertolino.it
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: db-stazione-meteo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DBMT_VERSION', '0.1.0' );
define( 'DBMT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBMT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DBMT_PLUGIN_FILE', __FILE__ );

// Shared components — GitHub auto-updater
require_once DBMT_PLUGIN_DIR . 'inc/class-updater.php';
if ( class_exists( 'DB_GitHub_Updater_V2' ) ) {
	new DB_GitHub_Updater_V2( __FILE__, 'dadebertolino', 'db-stazione-meteo' );
}

require_once DBMT_PLUGIN_DIR . 'inc/class-dbmt-db.php';
require_once DBMT_PLUGIN_DIR . 'inc/class-dbmt-validazione.php';
require_once DBMT_PLUGIN_DIR . 'inc/class-dbmt-token.php';
require_once DBMT_PLUGIN_DIR . 'inc/class-dbmt-meteo.php';
require_once DBMT_PLUGIN_DIR . 'inc/class-dbmt-rest.php';
require_once DBMT_PLUGIN_DIR . 'inc/class-dbmt-admin.php';
require_once DBMT_PLUGIN_DIR . 'inc/class-dbmt-shortcode.php';

register_activation_hook( __FILE__, array( 'DBMT_DB', 'install' ) );
add_action( 'plugins_loaded', array( 'DBMT_DB', 'maybe_upgrade' ) );

DBMT_Rest::register();
DBMT_Admin::register();
add_action( 'init', array( 'DBMT_Shortcode', 'register' ) );
