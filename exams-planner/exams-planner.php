<?php
/**
 * Plugin Name:       Exams Planner
 * Description:       Δήλωση διαγωνισμάτων ανά τμήμα με αυτόματο έλεγχο κανόνων. Προσοχή: η ΔΙΑΓΡΑΦΗ του πρόσθετου σβήνει ΟΛΑ τα δεδομένα (δηλώσεις, ιστορικό, ρυθμίσεις). Η απενεργοποίηση και η αναβάθμιση όχι.
 * Version:           1.9.73
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            5ο Γυμνάσιο Χανίων
 * License:           GPL-2.0-or-later
 * Text Domain:       exams-planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DGN_VERSION', '1.9.73' );
define( 'DGN_DB_VERSION', '1' );
define( 'DGN_FILE', __FILE__ );
define( 'DGN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DGN_URL', plugin_dir_url( __FILE__ ) );

require_once DGN_DIR . 'includes/settings.php';
require_once DGN_DIR . 'includes/store.php';
require_once DGN_DIR . 'includes/rest.php';
require_once DGN_DIR . 'includes/shortcode.php';
require_once DGN_DIR . 'includes/admin.php';
require_once DGN_DIR . 'includes/export.php';
require_once DGN_DIR . 'includes/sso.php';

register_activation_hook( __FILE__, 'dgn_activate' );

/**
 * Δημιουργία πινάκων και προεπιλεγμένων ρυθμίσεων.
 */
function dgn_activate() {
	dgn_install_tables();
	if ( false === get_option( DGN_OPTION, false ) ) {
		add_option( DGN_OPTION, dgn_default_settings(), '', false );
	}
}

/**
 * Αναβάθμιση πινάκων αν άλλαξε η έκδοση (π.χ. μετά από ενημέρωση χωρίς επανενεργοποίηση).
 */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'dgn_db_version' ) !== DGN_DB_VERSION ) {
		dgn_install_tables();
	}
} );

function dgn_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$exams   = $wpdb->prefix . 'dgn_exams';
	$log     = $wpdb->prefix . 'dgn_log';

	dbDelta( "CREATE TABLE $exams (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cls varchar(20) NOT NULL,
  exam_date date NOT NULL,
  week_start date NOT NULL,
  subject varchar(100) NOT NULL,
  teacher varchar(60) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  delete_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY cls_date (cls,exam_date),
  KEY cls_week (cls,week_start),
  KEY exam_date (exam_date)
) $charset;" );

	dbDelta( "CREATE TABLE $log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  action varchar(12) NOT NULL,
  cls varchar(20) NOT NULL,
  exam_date date NOT NULL,
  subject varchar(100) NOT NULL,
  teacher varchar(60) NOT NULL,
  actor varchar(100) NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) $charset;" );

	update_option( 'dgn_db_version', DGN_DB_VERSION, false );
}
