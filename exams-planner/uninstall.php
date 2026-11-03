<?php
/**
 * Εκτελείται μόνο όταν το πρόσθετο ΔΙΑΓΡΑΦΕΤΑΙ (όχι στην απενεργοποίηση).
 * Αφαιρεί δηλώσεις, ιστορικό και ρυθμίσεις.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dgn_exams" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dgn_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'dgn_settings' );
delete_option( 'dgn_db_version' );
