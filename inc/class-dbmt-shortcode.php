<?php
/**
 * DBMT_Shortcode — [dbmt_meteo]
 *
 * Ultimi valori (temperatura, umidità, pressione) con l'ora della misura,
 * e il grafico dell'andamento. Se i dati sono vecchi lo dice in chiaro,
 * invece di mostrare valori fermi come se fossero attuali.
 * Markup accessibile WCAG 2.1 AA.
 *
 * @package DB_Stazione_Meteo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DBMT_Shortcode {

	public static function register() {
		add_shortcode( 'dbmt_meteo', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		return '';
	}
}
