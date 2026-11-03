<?php
/**
 * REST API: /wp-json/exams-planner/v1/...
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	$ns = 'exams-planner/v1';

	register_rest_route( $ns, '/login', array(
		'methods'             => 'POST',
		'callback'            => 'dgn_rest_login',
		'permission_callback' => '__return_true',
	) );

	register_rest_route( $ns, '/exams', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'dgn_rest_list',
			'permission_callback' => '__return_true',
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'dgn_rest_create',
			'permission_callback' => '__return_true',
		),
	) );

	register_rest_route( $ns, '/exams/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'callback'            => 'dgn_rest_delete',
		'permission_callback' => '__return_true',
	) );
} );

function dgn_err( $code, $msg, $status ) {
	return new WP_Error( $code, $msg, array( 'status' => $status ) );
}

function dgn_not_teacher_error() {
	$s = dgn_settings();
	if ( 'sso' === $s['access'] ) {
		return dgn_err( 'dgn_sso_required', 'Συνδεθείτε με τον λογαριασμό σας στο ΠΣΔ (sch.gr) για να κάνετε δήλωση.', 401 );
	}
	if ( 'users' === $s['access'] ) {
		return dgn_err( 'dgn_login_required', 'Συνδεθείτε με τον λογαριασμό σας στην ιστοσελίδα του σχολείου για να κάνετε δήλωση.', 401 );
	}
	return dgn_err( 'dgn_code_required', 'Χρειάζεται ο κωδικός καθηγητών. Αν τον είχατε ήδη γράψει, μπορεί να έχει αλλάξει: πληκτρολογήστε τον ξανά.', 401 );
}

/** POST /login {code} → {token} */
function dgn_rest_login( WP_REST_Request $r ) {
	$s = dgn_settings();
	if ( 'code' !== $s['access'] ) {
		return dgn_err( 'dgn_no_code', 'Η είσοδος γίνεται με λογαριασμό WordPress.', 400 );
	}
	$key   = 'dgn_rl_' . md5( dgn_client_ip() );
	$tries = (int) get_transient( $key );
	if ( $tries >= 10 ) {
		return dgn_err( 'dgn_rate', 'Πολλές λανθασμένες προσπάθειες. Δοκιμάστε ξανά σε 15 λεπτά.', 429 );
	}
	$code = trim( (string) $r->get_param( 'code' ) );
	if ( '' === $code || ! hash_equals( (string) $s['code'], $code ) ) {
		set_transient( $key, $tries + 1, 15 * MINUTE_IN_SECONDS );
		return dgn_err( 'dgn_bad_code', 'Λάθος κωδικός καθηγητών.', 403 );
	}
	delete_transient( $key );
	return array( 'token' => dgn_make_token( $s, (bool) $r->get_param( 'remember' ) ) );
}

/** GET /exams?from=&to= */
function dgn_rest_list( WP_REST_Request $r ) {
	$s   = dgn_settings();
	$who = dgn_whoami( $r );
	if ( 'public' === $who['level'] && ! $s['public_view'] ) {
		return dgn_not_teacher_error();
	}
	$from = dgn_parse_date( (string) $r->get_param( 'from' ) );
	$to   = dgn_parse_date( (string) $r->get_param( 'to' ) );
	// Φίλτρο τμήματος: επιτρέπει μεγαλύτερο εύρος (λίστα «Επόμενα διαγωνίσματα» ενός τμήματος).
	$cls_filter = (string) $r->get_param( 'cls' );
	if ( '' !== $cls_filter && ! in_array( $cls_filter, dgn_classes( $s ), true ) ) {
		return dgn_err( 'dgn_cls', 'Άγνωστο τμήμα.', 400 );
	}
	$max_days = '' !== $cls_filter ? 320 : 70;
	if ( ! $from || ! $to || $to < $from || $from->diff( $to )->days > $max_days ) {
		return dgn_err( 'dgn_range', 'Μη έγκυρο εύρος ημερομηνιών.', 400 );
	}
	$rows = dgn_exams_between( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
	if ( '' !== $cls_filter ) {
		$rows = array_values( array_filter( $rows, function ( $row ) use ( $cls_filter ) {
			return $row['cls'] === $cls_filter;
		} ) );
	}
	$out  = array();
	foreach ( $rows as $row ) {
		$item = array(
			'id'      => (int) $row['id'],
			'cls'     => $row['cls'],
			'date'    => $row['exam_date'],
			'subject' => $row['subject'],
		);
		// Τα ονόματα καθηγητών εμφανίζονται μόνο σε καθηγητές και διαχειριστές.
		if ( 'public' !== $who['level'] ) {
			$item['teacher'] = $row['teacher'];
			$item['mine']    = ( $who['user_id'] > 0 && (int) $row['user_id'] === $who['user_id'] )
				|| ( '' !== $who['uidh'] && hash_equals( (string) $row['delete_hash'], $who['uidh'] ) );
		}
		$out[] = $item;
	}
	$payload = array(
		'level' => $who['level'],
		'exams' => $out,
	);
	// Η λίστα καθηγητών στέλνεται μόνο σε όσους μπορούν να κάνουν δήλωση.
	if ( 'public' !== $who['level'] ) {
		$payload['teachers'] = '' !== $who['uidh'] ? array() : dgn_teachers( $s );
		$payload['me']       = $who['name'];
	}
	$res = rest_ensure_response( $payload );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

/** POST /exams {cls, date, subject, teacher} */
function dgn_rest_create( WP_REST_Request $r ) {
	$s   = dgn_settings();
	$who = dgn_whoami( $r );
	if ( 'public' === $who['level'] ) {
		return dgn_not_teacher_error();
	}

	$cls = (string) $r->get_param( 'cls' );
	if ( ! in_array( $cls, dgn_classes( $s ), true ) ) {
		return dgn_err( 'dgn_cls', 'Άγνωστο τμήμα.', 400 );
	}
	$subject = (string) $r->get_param( 'subject' );
	if ( ! array_key_exists( $subject, dgn_subjects( $s ) ) ) {
		return dgn_err( 'dgn_subject', 'Επιλέξτε μάθημα από τη λίστα.', 400 );
	}
	$teacher = trim( sanitize_text_field( (string) $r->get_param( 'teacher' ) ) );
	if ( '' === $teacher && $who['user_id'] ) {
		$teacher = $who['name'];
	}
	$teacher  = mb_substr( $teacher, 0, 40 );
	$teachers = dgn_teachers( $s );
	if ( '' !== $who['uidh'] ) {
		// Είσοδος μέσω ΠΣΔ: το επώνυμο έρχεται από τον λογαριασμό, όχι από τη φόρμα.
		$teacher = $who['name'];
	} elseif ( $teachers ) {
		if ( ! in_array( $teacher, $teachers, true ) ) {
			return dgn_err( 'dgn_teacher', 'Επιλέξτε το επώνυμό σας από τη λίστα.', 400 );
		}
	} elseif ( mb_strlen( $teacher ) < 2 ) {
		return dgn_err( 'dgn_teacher', 'Γράψτε το επώνυμό σας.', 400 );
	}

	$date = dgn_parse_date( (string) $r->get_param( 'date' ) );
	if ( ! $date ) {
		return dgn_err( 'dgn_date', 'Μη έγκυρη ημερομηνία.', 400 );
	}
	$day = $date->format( 'Y-m-d' );
	if ( (int) $date->format( 'N' ) > 5 ) {
		return dgn_err( 'dgn_weekend', 'Η ημερομηνία πέφτει Σαββατοκύριακο.', 400 );
	}
	$hol = dgn_holidays( $s );
	if ( isset( $hol[ $day ] ) ) {
		return dgn_err( 'dgn_holiday', sprintf( 'Η ημέρα είναι αργία (%s).', $hol[ $day ] ), 400 );
	}
	if ( 'admin' !== $who['level'] && ! $s['allow_past'] && $day < dgn_today() ) {
		return dgn_err( 'dgn_past', 'Η ημερομηνία έχει περάσει.', 400 );
	}
	$notice = (int) $s['min_notice'];
	if ( 'admin' !== $who['level'] && $notice > 0 && $day >= dgn_today() ) {
		$earliest = dgn_earliest_allowed( $notice, $s );
		if ( $day < $earliest ) {
			$days = array( 1 => 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο', 'Κυριακή' );
			$e    = dgn_parse_date( $earliest );
			return dgn_err(
				'dgn_notice',
				sprintf( 'Τα διαγωνίσματα δηλώνονται τουλάχιστον %d εργάσιμες ημέρες πριν. Η νωρίτερη ημερομηνία που μπορείτε να δηλώσετε είναι η %s %s.', $notice, $days[ (int) $e->format( 'N' ) ], $e->format( 'j/n' ) ),
				400
			);
		}
	}
	list( $sy_start, $sy_end ) = dgn_school_year();
	if ( 'admin' !== $who['level'] && ( $day < $sy_start || $day > $sy_end ) ) {
		return dgn_err(
			'dgn_school_year',
			sprintf(
				'Η ημερομηνία είναι εκτός του τρέχοντος σχολικού έτους (%s – %s).',
				dgn_parse_date( $sy_start )->format( 'j/n/Y' ),
				dgn_parse_date( $sy_end )->format( 'j/n/Y' )
			),
			400
		);
	}

	$delete_key  = '';
	$delete_hash = '';
	if ( '' !== $who['uidh'] ) {
		$delete_hash = $who['uidh'];
	} elseif ( 0 === $who['user_id'] ) {
		$delete_key  = bin2hex( random_bytes( 16 ) );
		$delete_hash = hash( 'sha256', $delete_key );
	}
	$actor = $who['user_id'] ? ( 'admin' === $who['level'] ? 'Διαχειριστής: ' : 'Χρήστης: ' ) . $who['name'] : ( '' !== $who['uidh'] ? 'ΠΣΔ: ' . $who['name'] : 'Κωδικός καθηγητών' );

	$row = dgn_exam_create( $cls, $date, $subject, $teacher, $who['user_id'], $delete_hash, $actor );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	return new WP_REST_Response( array(
		'id'        => $row['id'],
		'deleteKey' => $delete_key,
	), 201 );
}

/** DELETE /exams/{id}  (κεφαλίδα X-DGN-Delete-Key για δηλώσεις με κοινό κωδικό) */
function dgn_rest_delete( WP_REST_Request $r ) {
	$who = dgn_whoami( $r );
	if ( 'public' === $who['level'] ) {
		return dgn_not_teacher_error();
	}
	$exam = dgn_exam_get( (int) $r['id'] );
	if ( ! $exam ) {
		return dgn_err( 'dgn_gone', 'Η δήλωση δεν υπάρχει πια.', 404 );
	}
	$allowed = 'admin' === $who['level'];
	if ( ! $allowed && $who['user_id'] && (int) $exam['user_id'] === $who['user_id'] ) {
		$allowed = true;
	}
	if ( ! $allowed && '' !== $who['uidh'] && hash_equals( (string) $exam['delete_hash'], $who['uidh'] ) ) {
		$allowed = true;
	}
	if ( ! $allowed && '' !== $exam['delete_hash'] ) {
		$key = (string) $r->get_header( 'x_dgn_delete_key' );
		if ( '' !== $key && hash_equals( $exam['delete_hash'], hash( 'sha256', $key ) ) ) {
			$allowed = true;
		}
	}
	// Περασμένες δηλώσεις: μόνο η Διεύθυνση (είναι πλέον καταγραφή του τι έγινε).
	if ( $allowed && 'admin' !== $who['level'] && (string) $exam['exam_date'] < dgn_today() ) {
		return dgn_err( 'dgn_past', 'Δηλώσεις με περασμένη ημερομηνία μπορεί να τις διαγράψει μόνο η Διεύθυνση.', 403 );
	}
	if ( ! $allowed ) {
		return dgn_err( 'dgn_forbidden', 'Μόνο όποιος έκανε τη δήλωση (από την ίδια συσκευή) ή η διεύθυνση μπορεί να τη διαγράψει.', 403 );
	}
	$actor = $who['user_id'] ? ( 'admin' === $who['level'] ? 'Διαχειριστής: ' : 'Χρήστης: ' ) . $who['name'] : ( '' !== $who['uidh'] ? 'ΠΣΔ: ' . $who['name'] : 'Κωδικός καθηγητών' );
	if ( ! dgn_exam_delete( $exam, $actor ) ) {
		return dgn_err( 'dgn_db', 'Η διαγραφή δεν έγινε. Δοκιμάστε ξανά.', 500 );
	}
	return array( 'deleted' => true );
}
