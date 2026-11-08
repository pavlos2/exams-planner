<?php
/**
 * Ρυθμίσεις, ανάλυση πεδίων, κωδικός καθηγητών και ταυτοποίηση.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DGN_OPTION', 'dgn_settings' );

function dgn_default_settings() {
	return array(
		'school_name' => '', // κενό = το όνομα του ιστότοπου
		'classes'     => "Α1\nΑ2\nΑ3\nΑ4\nΑ5\nΒ1\nΒ2\nΒ3\nΒ4\nΓ1\nΓ2\nΓ3\nΓ4\nΓ5",
		'subjects'    => implode( "\n", array(
			'Αρχαία Ελληνική Γλώσσα | ΑΡΧ',
			'Αρχαία Ελληνικά Κείμενα από Μετάφραση | ΜΤΦ',
			'Νέα Ελληνική Γλώσσα | ΝΕΓ',
			'Νεοελληνική Λογοτεχνία | ΛΟΓ',
			'Ιστορία | ΙΣΤ',
			'Θρησκευτικά | ΘΡΗ',
			'Μαθηματικά | ΜΑΘ',
			'Φυσική | ΦΥΣ',
			'Χημεία | ΧΗΜ',
			'Βιολογία | ΒΙΟ',
			'Γεωγραφία | ΓΕΩ',
			'Αγγλικά | ΑΓΓ',
			'Γαλλικά | ΓΑΛ',
			'Γερμανικά | ΓΕΡ',
			'Πληροφορική | ΠΛΗ',
			'Τεχνολογία | ΤΕΧ',
			'Κοινωνική και Πολιτική Αγωγή | ΚΠΑ',
			'Οικονομικά | ΟΙΚΝ',
			'Οικιακή Οικονομία | ΟΙΚ',
		) ),
		// Πανελλαδικές αργίες: συμπληρώνονται αυτόματα για κάθε σχολική χρονιά (βλ. dgn_national_holidays_text()).
		'holidays'       => dgn_national_holidays_text( (int) substr( dgn_school_year()[0], 0, 4 ) ),
		'holidays_year'  => (int) substr( dgn_school_year()[0], 0, 4 ), // για ποια χρονιά συμπληρώθηκαν
		'holidays_auto'  => 1,  // 1 = νέα συμπλήρωση αυτόματα σε κάθε νέα σχολική χρονιά
		'holidays_local' => '', // τοπικές αργίες· «ΜΜ-ΗΗ» = κάθε χρόνο
		'holidays_eves_done' => 1, // οι σχολικές εορτές περιλαμβάνονται ήδη (βλ. dgn_add_missing_eves())
		'weekly_max'  => 3,
		'access'      => 'code',   // 'code' = κοινός κωδικός, 'users' = λογαριασμοί WordPress, 'sso' = λογαριασμοί ΠΣΔ
		'sso_service' => '',       // Διεύθυνση υπηρεσίας όπως δηλώθηκε στο ΠΣΔ (κενό = η σελίδα της εφαρμογής)
		'sso_units'   => '',       // Κωδικός/οί σχολικής μονάδας (ένας ή περισσότεροι, με κόμμα)
		'sso_roles'   => 'teacher, ateacher, pteacher',
		'sso_server'  => 'prod',   // 'prod' = sso.sch.gr, 'test' = sso-01-test.sch.gr (δοκιμαστικός διακομιστής ΠΣΔ)
		'sso_ver'     => 1,
		'code'        => dgn_generate_code(),
		'code_ver'    => 1,
		'public_view' => 1,
		// Λίστα καθηγητών για τη φόρμα δήλωσης. Κενή λίστα = ελεύθερο κείμενο.
		'teachers'    => '',
		'allow_past'  => 0,
		'min_notice'  => 0,        // ελάχιστη προειδοποίηση σε εργάσιμες ημέρες (0 = χωρίς όριο)
	);
}

function dgn_settings() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$s = get_option( DGN_OPTION, false );
	if ( ! is_array( $s ) ) {
		// Πρώτη χρήση: αποθήκευση προεπιλογών, ώστε ο κωδικός να μένει σταθερός.
		$s = dgn_default_settings();
		update_option( DGN_OPTION, $s, false );
	}
	$changed = false;
	if ( ! isset( $s['holidays_year'] ) ) {
		$s       = dgn_migrate_holidays( $s ); // πρώτη φορά μετά την αναβάθμιση σε 1.9.82
		$changed = true;
	}
	$sy = (int) substr( dgn_school_year()[0], 0, 4 );
	if ( ! empty( $s['holidays_auto'] ) && (int) $s['holidays_year'] !== $sy ) {
		// Νέα σχολική χρονιά: οι πανελλαδικές αργίες ξαναγράφονται για τη νέα χρονιά.
		$s['holidays']           = dgn_national_holidays_text( $sy );
		$s['holidays_year']      = $sy;
		$s['holidays_refreshed'] = $sy; // για μήνυμα στις Ρυθμίσεις
		$changed                 = true;
	}
	if ( empty( $s['holidays_eves_done'] ) ) {
		// Μία φορά (από 1.9.84): οι σχολικές εορτές 28ης Οκτωβρίου / 25ης Μαρτίου προστίθενται
		// στις φετινές πανελλαδικές αργίες όσων είχαν ήδη το πρόσθετο. Δεν αντικαθιστά τίποτα.
		$s['holidays_eves_done'] = 1;
		$changed                 = true;
		if ( ! empty( $s['holidays_auto'] ) ) {
			$added = dgn_add_missing_eves( $s['holidays'], (int) $s['holidays_year'] );
			if ( null !== $added ) {
				$s['holidays']           = $added;
				$s['holidays_eves_note'] = 1; // μήνυμα στις Ρυθμίσεις
			}
		}
	}
	if ( $changed ) {
		update_option( DGN_OPTION, $s, false );
	}
	$cache = array_merge( dgn_default_settings(), $s );
	return $cache;
}

/**
 * Ορθόδοξο Πάσχα ενός έτους (αλγόριθμος Meeus για το Ιουλιανό ημερολόγιο + 13 ημέρες, ισχύει 1900–2099).
 */
function dgn_orthodox_easter( $y ) {
	$a = $y % 4;
	$b = $y % 7;
	$c = $y % 19;
	$d = ( 19 * $c + 15 ) % 30;
	$e = ( 2 * $a + 4 * $b - $d + 34 ) % 7;
	$m = intdiv( $d + $e + 114, 31 );
	$n = ( ( $d + $e + 114 ) % 31 ) + 1;
	return ( new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $y, $m, $n ), new DateTimeZone( 'UTC' ) ) )->modify( '+13 days' );
}

/** Οι πανελλαδικές σχολικές αργίες της χρονιάς $sy–($sy+1), ως κείμενο (μία ανά γραμμή, κατά ημερομηνία). */
function dgn_national_holidays_text( $sy ) {
	$sy = (int) $sy;
	$n  = $sy + 1;
	$e  = dgn_orthodox_easter( $n );
	$f  = function ( DateTimeImmutable $d ) { return $d->format( 'Y-m-d' ); };
	// Σχολική εορτή: η τελευταία εργάσιμη (Δευτέρα–Παρασκευή) πριν από την επέτειο.
	$eve = function ( $date ) {
		$d = ( new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) ) )->modify( '-1 day' );
		while ( (int) $d->format( 'N' ) > 5 ) {
			$d = $d->modify( '-1 day' );
		}
		return $d->format( 'Y-m-d' );
	};
	$e28 = $eve( "$sy-10-28" );
	$e25 = $eve( "$n-03-25" );
	$l  = array(
		array( $e28, "$e28 | Σχολική εορτή (28η Οκτωβρίου)" ),
		array( $e25, "$e25 | Σχολική εορτή (25η Μαρτίου)" ),
		array( "$sy-10-28", "$sy-10-28 | 28η Οκτωβρίου" ),
		array( "$sy-11-17", "$sy-11-17 | Πολυτεχνείο" ),
		array( "$sy-12-24", "$sy-12-24 έως $n-01-07 | Χριστούγεννα" ),
		array( "$n-01-30", "$n-01-30 | Τριών Ιεραρχών" ),
		array( $f( $e->modify( '-48 days' ) ), $f( $e->modify( '-48 days' ) ) . ' | Καθαρά Δευτέρα' ),
		array( "$n-03-25", "$n-03-25 | 25η Μαρτίου" ),
		array( $f( $e->modify( '-6 days' ) ), $f( $e->modify( '-6 days' ) ) . ' έως ' . $f( $e->modify( '+7 days' ) ) . ' | Πάσχα' ),
		array( "$n-05-01", "$n-05-01 | Πρωτομαγιά" ),
		array( $f( $e->modify( '+50 days' ) ), $f( $e->modify( '+50 days' ) ) . ' | Αγίου Πνεύματος' ),
	);
	usort( $l, function ( $x, $y ) { return strcmp( $x[0], $y[0] ); } );
	return implode( "\n", array_column( $l, 1 ) );
}

/**
 * Μετάβαση από το ενιαίο πεδίο αργιών (έως 1.9.81): οι πανελλαδικές συμπληρώνονται για τη φετινή χρονιά
 * και όσες γραμμές δεν καλύπτονται από αυτές μεταφέρονται στις τοπικές. Καμία ημέρα δεν «ξεμπλοκάρει».
 * Αν το παλιό πεδίο δεν μοιάζει με ελληνικό σχολικό ημερολόγιο, μένει όπως είναι και η αυτόματη ενημέρωση κλείνει.
 */
function dgn_migrate_holidays( $s ) {
	$sy  = (int) substr( dgn_school_year()[0], 0, 4 );
	$old = isset( $s['holidays'] ) ? (string) $s['holidays'] : '';
	$nat = dgn_national_holidays_text( $sy );
	list( $natmap ) = dgn_parse_holidays( $nat, $sy );
	list( $oldmap ) = dgn_parse_holidays( $old, $sy );
	$s['holidays_year']  = $sy;
	$s['holidays_local'] = isset( $s['holidays_local'] ) ? (string) $s['holidays_local'] : '';
	if ( '' !== trim( $old ) && count( array_intersect_key( $natmap, $oldmap ) ) * 2 < count( $natmap ) ) {
		$s['holidays_auto'] = 0; // άλλο ημερολόγιο: κρατάμε το κείμενο όπως είναι
		return $s;
	}
	$local = array();
	foreach ( preg_split( '/\R/u', $old ) as $line ) {
		list( $m, $bad ) = dgn_parse_holidays( $line, $sy );
		if ( '' === trim( $line ) ) {
			continue;
		}
		if ( $bad || array_diff_key( $m, $natmap ) ) {
			$local[] = trim( $line ); // τοπική (ή μη αναγνωρίσιμη): μεταφέρεται, δεν χάνεται
		}
	}
	$s['holidays']       = $nat;
	$s['holidays_auto']  = 1;
	$s['holidays_local'] = trim( implode( "\n", array_filter( array( trim( $s['holidays_local'] ), implode( "\n", $local ) ) ) ) );
	return $s;
}

/** Λίστα τμημάτων (μία ανά γραμμή, χωρίς διπλότυπα). */
function dgn_classes( $s = null ) {
	$s   = $s ? $s : dgn_settings();
	$out = array();
	foreach ( preg_split( '/\R/u', (string) $s['classes'] ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line && ! in_array( $line, $out, true ) && mb_strlen( $line ) <= 20 ) {
			$out[] = $line;
		}
	}
	return $out;
}

/** Μαθήματα ως [όνομα => συντομογραφία]. */
/** Καθηγητές (ένας ανά γραμμή, χωρίς διπλότυπα), με τη σειρά των ρυθμίσεων. */
function dgn_teachers( $s = null ) {
	$s   = $s ? $s : dgn_settings();
	$out = array();
	foreach ( preg_split( '/\R/u', (string) $s['teachers'] ) as $line ) {
		$line = trim( preg_replace( '/\s+/u', ' ', $line ) );
		if ( '' !== $line && mb_strlen( $line ) <= 40 && ! in_array( $line, $out, true ) ) {
			$out[] = $line;
		}
	}
	return $out;
}

function dgn_subjects( $s = null ) {
	$s   = $s ? $s : dgn_settings();
	$out = array();
	foreach ( preg_split( '/\R/u', (string) $s['subjects'] ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$name  = $parts[0];
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			continue;
		}
		$abbr         = isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : mb_strtoupper( mb_substr( $name, 0, 3 ) );
		$out[ $name ] = mb_substr( $abbr, 0, 5 );
	}
	return $out;
}

/**
 * Αργίες ως [YYYY-MM-DD => όνομα]. Επιστρέφει και τις γραμμές που δεν αναγνωρίστηκαν.
 *
 * @return array{0: array<string,string>, 1: string[]}
 */
/**
 * Αργίες από κείμενο: «YYYY-MM-DD | Όνομα» ή «YYYY-MM-DD έως YYYY-MM-DD | Όνομα».
 * Και χωρίς έτος, «ΜΜ-ΗΗ | Όνομα» (ή διάστημα): ισχύει κάθε χρόνο, μέσα στη σχολική χρονιά $sy–($sy+1)
 * (Σεπτέμβριος–Δεκέμβριος στο $sy, Ιανουάριος–Αύγουστος στο $sy+1).
 */
function dgn_parse_holidays( $text, $sy = null ) {
	$sy   = null === $sy ? (int) substr( dgn_school_year()[0], 0, 4 ) : (int) $sy;
	$map  = array();
	$bad  = array();
	foreach ( preg_split( '/\R/u', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( ! preg_match( '/^((?:\d{4}-)?\d{2}-\d{2})(?:\s*(?:έως|-|–)\s*((?:\d{4}-)?\d{2}-\d{2}))?\s*(?:\|\s*(.*))?$/u', $line, $m ) ) {
			$bad[] = $line;
			continue;
		}
		foreach ( array( 1, 2 ) as $i ) { // «ΜΜ-ΗΗ» → πλήρης ημερομηνία μέσα στη σχολική χρονιά
			if ( ! empty( $m[ $i ] ) && 5 === strlen( $m[ $i ] ) ) {
				$m[ $i ] = ( (int) substr( $m[ $i ], 0, 2 ) >= 9 ? $sy : $sy + 1 ) . '-' . $m[ $i ];
			}
		}
		$from = dgn_parse_date( $m[1] );
		$to   = ! empty( $m[2] ) ? dgn_parse_date( $m[2] ) : $from;
		$name = isset( $m[3] ) && '' !== trim( $m[3] ) ? trim( $m[3] ) : 'Αργία';
		if ( ! $from || ! $to || $to < $from || $from->diff( $to )->days > 120 ) {
			$bad[] = $line;
			continue;
		}
		for ( $d = $from; $d <= $to; $d = $d->modify( '+1 day' ) ) {
			$map[ $d->format( 'Y-m-d' ) ] = mb_substr( $name, 0, 40 );
		}
	}
	return array( $map, $bad );
}

/** Όλες οι αργίες: πανελλαδικές + τοπικές. */
function dgn_holidays( $s = null ) {
	$s = $s ? $s : dgn_settings();
	list( $map )   = dgn_parse_holidays( $s['holidays'] );
	list( $local ) = dgn_parse_holidays( isset( $s['holidays_local'] ) ? $s['holidays_local'] : '' );
	return $local + $map;
}

/** Αυστηρή ανάγνωση ημερομηνίας YYYY-MM-DD. */
function dgn_parse_date( $str ) {
	if ( ! is_string( $str ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $str, $m ) ) {
		return null;
	}
	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return null;
	}
	return new DateTimeImmutable( $str . ' 00:00:00', new DateTimeZone( 'UTC' ) );
}

/** Δευτέρα της εβδομάδας μιας ημερομηνίας. */
function dgn_week_start( DateTimeImmutable $d ) {
	$n = (int) $d->format( 'N' ); // 1 = Δευτέρα … 7 = Κυριακή
	return $d->modify( '-' . ( $n - 1 ) . ' days' );
}

/**
 * Η νωρίτερη ημερομηνία που επιτρέπεται με προειδοποίηση $n εργάσιμων ημερών:
 * από σήμερα, προχωράμε $n εργάσιμες (χωρίς Σαββατοκύριακα και αργίες).
 */
function dgn_earliest_allowed( $n, $s = null ) {
	$s   = $s ? $s : dgn_settings();
	$d   = dgn_parse_date( dgn_today() );
	$n   = max( 0, (int) $n );
	$hol = dgn_holidays( $s );
	while ( $n > 0 ) {
		$d = $d->modify( '+1 day' );
		if ( (int) $d->format( 'N' ) <= 5 && ! isset( $hol[ $d->format( 'Y-m-d' ) ] ) ) {
			$n--;
		}
	}
	return $d->format( 'Y-m-d' );
}

function dgn_today() {
	return wp_date( 'Y-m-d' );
}

/**
 * Τρέχον σχολικό έτος: 1 Σεπτεμβρίου – 30 Ιουνίου.
 * Από 1 Ιουλίου η σελίδα περνά ήδη στη νέα χρονιά, ώστε να μπορεί να γίνει προγραμματισμός πριν ανοίξουν τα σχολεία.
 *
 * @return array{0: string, 1: string} [αρχή, τέλος] σε YYYY-MM-DD
 */
function dgn_school_year( $today = null ) {
	$today = $today ? $today : dgn_today();
	$y     = (int) substr( $today, 0, 4 );
	$m     = (int) substr( $today, 5, 2 );
	$start = $m >= 7 ? $y : $y - 1;
	return array( $start . '-09-01', ( $start + 1 ) . '-06-30' );
}

/* ---------- Κωδικός καθηγητών: υπογεγραμμένο διακριτικό ---------- */

/**
 * @param bool $remember true: ισχύει 200 ημέρες (η συσκευή το θυμάται). false: 12 ώρες.
 */
function dgn_make_token( $s = null, $remember = true ) {
	$s   = $s ? $s : dgn_settings();
	$exp = time() + ( $remember ? 200 * DAY_IN_SECONDS : 12 * HOUR_IN_SECONDS );
	$ver = (int) $s['code_ver'];
	$sig = hash_hmac( 'sha256', $ver . '|' . $exp, wp_salt( 'auth' ) . '|dgn' );
	return $ver . '.' . $exp . '.' . $sig;
}

function dgn_check_token( $token, $s = null ) {
	$s = $s ? $s : dgn_settings();
	if ( ! is_string( $token ) || ! preg_match( '/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $m ) ) {
		return false;
	}
	if ( (int) $m[1] !== (int) $s['code_ver'] || (int) $m[2] < time() ) {
		return false;
	}
	$sig = hash_hmac( 'sha256', $m[1] . '|' . $m[2], wp_salt( 'auth' ) . '|dgn' );
	return hash_equals( $sig, $m[3] );
}

/**
 * Ποιος κάνει το αίτημα.
 *
 * @return array{level: string, user_id: int, name: string}
 *   level: 'admin' | 'teacher' | 'public'
 */
function dgn_whoami( $request = null ) {
	$s   = dgn_settings();
	$uid = get_current_user_id();
	if ( $uid && current_user_can( 'manage_options' ) ) {
		return array( 'level' => 'admin', 'user_id' => $uid, 'name' => wp_get_current_user()->display_name, 'uidh' => '' );
	}
	if ( 'users' === $s['access'] && $uid && current_user_can( 'read' ) ) {
		return array( 'level' => 'teacher', 'user_id' => $uid, 'name' => wp_get_current_user()->display_name, 'uidh' => '' );
	}
	if ( $request instanceof WP_REST_Request ) {
		$token = $request->get_header( 'x_dgn_token' );
		if ( 'code' === $s['access'] && dgn_check_token( $token, $s ) ) {
			return array( 'level' => 'teacher', 'user_id' => 0, 'name' => '', 'uidh' => '' );
		}
		if ( 'sso' === $s['access'] ) {
			$sso = dgn_check_sso_token( $token, $s );
			if ( $sso ) {
				return array( 'level' => 'teacher', 'user_id' => 0, 'name' => $sso['name'], 'uidh' => $sso['uidh'] );
			}
		}
	}
	return array( 'level' => 'public', 'user_id' => 0, 'name' => '', 'uidh' => '' );
}

/* ---------- Είσοδος μέσω ΠΣΔ: υπογεγραμμένο διακριτικό ---------- */

/** Ανώνυμο αναγνωριστικό χρήστη ΠΣΔ: δεν αποθηκεύουμε το όνομα χρήστη, μόνο αυτό το hash. */
function dgn_uid_hash( $uid ) {
	return hash_hmac( 'sha256', strtolower( trim( (string) $uid ) ), wp_salt( 'auth' ) . '|dgn-uid' );
}

function dgn_b64u( $s ) {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
}

function dgn_b64u_dec( $s ) {
	return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
}

/** Διακριτικό 12 ωρών για καθηγητή που μπήκε μέσω ΠΣΔ. */
function dgn_make_sso_token( $uidh, $name, $s = null ) {
	$s       = $s ? $s : dgn_settings();
	$exp     = time() + 12 * HOUR_IN_SECONDS;
	$payload = 's.' . (int) $s['sso_ver'] . '.' . $exp . '.' . $uidh . '.' . dgn_b64u( $name );
	return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . '|dgn-sso' );
}

/** @return array{uidh: string, name: string}|false */
function dgn_check_sso_token( $token, $s = null ) {
	$s = $s ? $s : dgn_settings();
	if ( ! is_string( $token ) || ! preg_match( '/^s\.(\d+)\.(\d+)\.([a-f0-9]{64})\.([A-Za-z0-9_-]*)\.([a-f0-9]{64})$/', $token, $m ) ) {
		return false;
	}
	$payload = 's.' . $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
	if ( ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . '|dgn-sso' ), $m[5] ) ) {
		return false;
	}
	if ( (int) $m[1] !== (int) $s['sso_ver'] || (int) $m[2] < time() ) {
		return false;
	}
	$name = dgn_b64u_dec( $m[4] );
	if ( false === $name ) {
		return false;
	}
	return array( 'uidh' => $m[3], 'name' => $name );
}

function dgn_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Κανόνες του κοινού κωδικού καθηγητών: τουλάχιστον 8 λατινικοί χαρακτήρες (χωρίς κενά),
 * με κεφαλαίο, μικρό, αριθμό και ένα σύμβολο από τα # $ ! %.
 * Επιστρέφει τι λείπει (κενός πίνακας = ισχυρός κωδικός). Ίδιοι κανόνες και στο JS των Ρυθμίσεων.
 */
function dgn_code_problems( $code ) {
	$code = (string) $code;
	$p    = array();
	if ( '' !== $code && ! preg_match( '/^[\x21-\x7E]+$/', $code ) ) {
		$p[] = 'μόνο λατινικοί χαρακτήρες, αριθμοί και σύμβολα, χωρίς κενά';
	}
	if ( strlen( $code ) < 8 ) {
		$p[] = 'τουλάχιστον 8 χαρακτήρες';
	}
	if ( ! preg_match( '/[A-Z]/', $code ) ) {
		$p[] = 'ένα κεφαλαίο γράμμα';
	}
	if ( ! preg_match( '/[a-z]/', $code ) ) {
		$p[] = 'ένα μικρό γράμμα';
	}
	if ( ! preg_match( '/[0-9]/', $code ) ) {
		$p[] = 'έναν αριθμό';
	}
	if ( ! preg_match( '/[#$!%]/', $code ) ) {
		$p[] = 'ένα σύμβολο (# $ ! %)';
	}
	return $p;
}

/** Τυχαίος ισχυρός κωδικός 8 χαρακτήρων (χωρίς χαρακτήρες που μπερδεύονται: 0/O, 1/l/I). */
function dgn_generate_code() {
	$sets = array( 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '#$!%' );
	$all  = implode( '', $sets );
	$c    = array();
	foreach ( $sets as $set ) {
		$c[] = $set[ random_int( 0, strlen( $set ) - 1 ) ];
	}
	while ( count( $c ) < 8 ) {
		$c[] = $all[ random_int( 0, strlen( $all ) - 1 ) ];
	}
	for ( $i = count( $c ) - 1; $i > 0; $i-- ) { // ανακάτεμα
		$j        = random_int( 0, $i );
		$t        = $c[ $i ];
		$c[ $i ]  = $c[ $j ];
		$c[ $j ]  = $t;
	}
	return implode( '', $c );
}

/**
 * Προσθέτει στο κείμενο των πανελλαδικών αργιών τις σχολικές εορτές της χρονιάς $sy που λείπουν
 * (ελέγχεται η ημερομηνία). Οι γραμμές ταξινομούνται κατά ημερομηνία· οι υπόλοιπες μένουν όπως είναι.
 * Επιστρέφει το νέο κείμενο, ή null αν δεν χρειάστηκε αλλαγή.
 */
function dgn_add_missing_eves( $text, $sy ) {
	list( $have ) = dgn_parse_holidays( $text, $sy );
	$lines        = '' === trim( (string) $text ) ? array() : preg_split( '/\R/u', trim( (string) $text ) );
	$added        = false;
	foreach ( preg_split( '/\R/u', dgn_national_holidays_text( $sy ) ) as $line ) {
		if ( false !== mb_strpos( $line, 'Σχολική εορτή' ) && ! isset( $have[ substr( $line, 0, 10 ) ] ) ) {
			$lines[] = $line;
			$added   = true;
		}
	}
	if ( ! $added ) {
		return null;
	}
	usort(
		$lines,
		function ( $a, $b ) {
			$da = preg_match( '/^\d{4}-\d{2}-\d{2}/', trim( $a ) ) ? substr( trim( $a ), 0, 10 ) : '9999';
			$db = preg_match( '/^\d{4}-\d{2}-\d{2}/', trim( $b ) ) ? substr( trim( $b ), 0, 10 ) : '9999';
			return strcmp( $da, $db );
		}
	);
	return implode( "\n", $lines );
}
