<?php
/**
 * Πρόσβαση στη βάση: δηλώσεις και ιστορικό.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dgn_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'dgn_' . $name;
}

/** Δηλώσεις σε εύρος ημερομηνιών (συμπεριλαμβανομένων). */
function dgn_exams_between( $from, $to ) {
	global $wpdb;
	$t = dgn_table( 'exams' );
	return $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM $t WHERE exam_date BETWEEN %s AND %s ORDER BY exam_date, cls", $from, $to ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
}

function dgn_exam_get( $id ) {
	global $wpdb;
	$t = dgn_table( 'exams' );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

function dgn_log( $action, $exam, $actor ) {
	global $wpdb;
	$wpdb->insert(
		dgn_table( 'log' ),
		array(
			'action'     => $action,
			'cls'        => $exam['cls'],
			'exam_date'  => $exam['exam_date'],
			'subject'    => $exam['subject'],
			'teacher'    => $exam['teacher'],
			'actor'      => mb_substr( $actor, 0, 100 ),
			'ip'         => dgn_client_ip(),
			'created_at' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	dgn_log_prune();
}

/** Το ιστορικό κρατά τις τελευταίες DGN_LOG_KEEP ενέργειες· οι παλαιότερες διαγράφονται. */
if ( ! defined( 'DGN_LOG_KEEP' ) ) {
	define( 'DGN_LOG_KEEP', 2000 );
}
function dgn_log_prune() {
	global $wpdb;
	$t   = dgn_table( 'log' );
	$cut = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t ORDER BY id DESC LIMIT 1 OFFSET %d", DGN_LOG_KEEP ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $cut > 0 ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE id <= %d", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

/**
 * Καταχώριση δήλωσης με έλεγχο κανόνων.
 * Το κλείδωμα ανά τμήμα+εβδομάδα (GET_LOCK) αποτρέπει δύο ταυτόχρονες δηλώσεις
 * να περάσουν μαζί το όριο της εβδομάδας. Το μοναδικό κλειδί (cls, exam_date)
 * εγγυάται μία δήλωση την ημέρα ακόμη και χωρίς κλείδωμα.
 *
 * @return array|WP_Error Η νέα δήλωση.
 */
function dgn_exam_create( $cls, DateTimeImmutable $date, $subject, $teacher, $user_id, $delete_hash, $actor ) {
	global $wpdb;
	$s      = dgn_settings();
	$t      = dgn_table( 'exams' );
	$day    = $date->format( 'Y-m-d' );
	$week   = dgn_week_start( $date )->format( 'Y-m-d' );
	$max    = max( 1, (int) $s['weekly_max'] );
	$lock   = 'dgn_' . md5( $cls . '|' . $week );
	$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
	if ( '0' === $locked ) {
		return new WP_Error( 'dgn_busy', 'Κάποιος συνάδελφος δηλώνει αυτή τη στιγμή για το ίδιο τμήμα. Δοκιμάστε ξανά σε λίγα δευτερόλεπτα.', array( 'status' => 409 ) );
	}

	try {
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT subject, teacher FROM $t WHERE cls = %s AND exam_date = %s", $cls, $day ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return new WP_Error(
				'dgn_day_taken',
				sprintf( 'Το %s έχει ήδη διαγώνισμα αυτή την ημέρα (%s).', $cls, $existing['subject'] ),
				array( 'status' => 409 )
			);
		}
		// Άλλο σύστημα (π.χ. το Draseis Planner) μπορεί να μπλοκάρει την ημέρα, π.χ. με εγκεκριμένη δράση.
		// Το φίλτρο επιστρέφει κενό '' (επιτρέπεται) ή το μήνυμα προς τον χρήστη (δεν επιτρέπεται).
		// Μόνο μη κενό κείμενο μπλοκάρει: ένα λάθος φίλτρο που επιστρέφει false/null δεν κλειδώνει όλες τις δηλώσεις.
		$blocked = apply_filters( 'dgn_exam_blocked', '', $cls, $day );
		if ( is_string( $blocked ) && '' !== trim( $blocked ) ) {
			return new WP_Error( 'dgn_blocked', trim( $blocked ), array( 'status' => 409 ) );
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE cls = %s AND week_start = %s", $cls, $week ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count >= $max ) {
			return new WP_Error(
				'dgn_week_full',
				sprintf( 'Το %s έχει ήδη %d διαγωνίσματα αυτή την εβδομάδα. Διαλέξτε άλλη εβδομάδα.', $cls, $count ),
				array( 'status' => 409 )
			);
		}
		$row = array(
			'cls'         => $cls,
			'exam_date'   => $day,
			'week_start'  => $week,
			'subject'     => $subject,
			'teacher'     => $teacher,
			'user_id'     => (int) $user_id,
			'delete_hash' => $delete_hash,
			'created_at'  => current_time( 'mysql' ),
		);
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( $t, $row, array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		$wpdb->suppress_errors( $suppress );
		if ( ! $ok && false === stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
			return new WP_Error( 'dgn_db', 'Η δήλωση δεν αποθηκεύτηκε λόγω σφάλματος της βάσης. Δοκιμάστε ξανά.', array( 'status' => 500 ) );
		}
		if ( ! $ok ) {
			// Το πιθανότερο: κάποιος άλλος πρόλαβε την ίδια ημέρα (μοναδικό κλειδί).
			return new WP_Error( 'dgn_day_taken', sprintf( 'Το %s μόλις δηλώθηκε από άλλον συνάδελφο για αυτή την ημέρα.', $cls ), array( 'status' => 409 ) );
		}
		$row['id'] = (int) $wpdb->insert_id;
		dgn_log( 'δήλωση', $row, $actor );
		return $row;
	} finally {
		if ( '1' === $locked ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}

function dgn_exam_delete( $exam, $actor ) {
	global $wpdb;
	$ok = $wpdb->delete( dgn_table( 'exams' ), array( 'id' => (int) $exam['id'] ), array( '%d' ) );
	if ( $ok ) {
		dgn_log( 'διαγραφή', $exam, $actor );
	}
	return (bool) $ok;
}
