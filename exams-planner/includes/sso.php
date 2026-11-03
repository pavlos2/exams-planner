<?php
/**
 * Είσοδος καθηγητών μέσω της Κεντρικής Υπηρεσίας Πιστοποίησης (SSO) του ΠΣΔ.
 *
 * Πρωτόκολλο CAS 3.0, με τη βιβλιοθήκη phpCAS 1.6 όπως προτείνει το ΠΣΔ.
 * Ροή:
 *   1. «Είσοδος καθηγητών» → <διεύθυνση υπηρεσίας>?dgn=sso
 *   2. phpCAS → sso.sch.gr/login → επιστροφή με ticket → επικύρωση (serviceValidate)
 *   3. Έλεγχος: ρόλος εκπαιδευτικού ΚΑΙ κωδικός σχολικής μονάδας του σχολείου
 *   4. Δημιουργία διακριτικού 12 ωρών και επιστροφή στη σελίδα (#dgn-sso=…)
 *
 * Σύμφωνα με τη Δήλωση Προστασίας Δεδομένων του ΠΣΔ δεν αποθηκεύουμε το όνομα χρήστη
 * ούτε άλλα στοιχεία: κρατάμε μόνο ένα hash του ονόματος χρήστη (για να ξέρουμε ποιες
 * δηλώσεις μπορεί να διαγράψει κάποιος) και το επώνυμο στη δήλωση.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Διακομιστής CAS: κανονικός ή δοκιμαστικός (ρύθμιση). Η σταθερά DGN_CAS_HOST στο wp-config.php υπερισχύει. */
function dgn_cas_host() {
	if ( defined( 'DGN_CAS_HOST' ) ) {
		return DGN_CAS_HOST;
	}
	$s = dgn_settings();
	return ( isset( $s['sso_server'] ) && 'test' === $s['sso_server'] ) ? 'sso-01-test.sch.gr' : 'sso.sch.gr';
}
function dgn_cas_port() {
	return defined( 'DGN_CAS_PORT' ) ? (int) DGN_CAS_PORT : 443;
}

/** Η διεύθυνση της σελίδας της εφαρμογής (όπως δηλώθηκε στο ΠΣΔ, αλλιώς η σελίδα με το shortcode). */
function dgn_sso_service_base( $s = null ) {
	$s    = $s ? $s : dgn_settings();
	$base = trim( (string) $s['sso_service'] );
	if ( '' === $base ) {
		$pages = function_exists( 'dgn_app_pages' ) ? dgn_app_pages() : array();
		$base  = $pages ? get_permalink( $pages[0] ) : home_url( '/' );
	}
	return $base;
}

/** Η διεύθυνση (service URL) που στέλνεται στο ΠΣΔ: η διεύθυνση της υπηρεσίας + ?dgn=sso */
function dgn_sso_service_url( $s = null ) {
	return add_query_arg( 'dgn', 'sso', dgn_sso_service_base( $s ) );
}

function dgn_sso_logout_url( $s = null ) {
	$host = dgn_cas_host();
	$port = dgn_cas_port();
	// Επιστροφή στη διεύθυνση της υπηρεσίας (αυτή δέχεται το ΠΣΔ) με ?dgn=out· το plugin μετά στέλνει στην κανονική σελίδα.
	return 'https://' . $host . ( 443 === $port ? '' : ':' . $port ) . '/logout?service=' . rawurlencode( add_query_arg( 'dgn', 'out', dgn_sso_service_base( $s ) ) );
}

function dgn_sso_requirements_ok() {
	return function_exists( 'curl_init' ) && class_exists( 'DOMDocument' );
}

/** Τιμές attribute ως πίνακας συμβολοσειρών, ανεξάρτητα από κεφαλαία/πεζά στο όνομα. */
function dgn_sso_attr( array $attrs, $name ) {
	foreach ( $attrs as $k => $v ) {
		if ( 0 === strcasecmp( (string) $k, $name ) ) {
			$vals = is_array( $v ) ? $v : array( $v );
			return array_values( array_filter( array_map( 'strval', $vals ), 'strlen' ) );
		}
	}
	return array();
}

/** Επώνυμο σε κεφαλαία χωρίς τόνους, όπως στη λίστα καθηγητών (π.χ. «Παπαδάκης» → «ΠΑΠΑΔΑΚΗΣ»). */
function dgn_greek_upper( $s ) {
	$s = mb_strtoupper( trim( (string) $s ), 'UTF-8' );
	return strtr( $s, array(
		'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
		'ΐ' => 'Ϊ', 'ΰ' => 'Ϋ',
	) );
}

/**
 * Έλεγχος εξουσιοδότησης από τα attributes του ΠΣΔ.
 *
 * @return true|string true αν επιτρέπεται, αλλιώς κωδικός λόγου απόρριψης.
 */
function dgn_sso_authorize( array $attrs, $s ) {
	$roles_ok = array_filter( array_map( 'strtolower', array_map( 'trim', explode( ',', (string) $s['sso_roles'] ) ) ) );
	$roles    = array_map( 'strtolower', dgn_sso_attr( $attrs, 'umdobject' ) );
	if ( ! array_intersect( $roles, $roles_ok ) ) {
		return 'role';
	}
	$units = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $s['sso_units'] ) ) );
	if ( ! $units ) {
		return 'nounit';
	}
	// Η ακριβής μορφή του attribute μονάδας στο XML του ΠΣΔ δεν είναι γνωστή εκ των προτέρων
	// (π.χ. «edupersonorgunitdn:gsnunitcode:extended» δεν είναι έγκυρο όνομα στοιχείου XML).
	// Γι' αυτό ψάχνουμε τον κωδικό μονάδας ως ολόκληρη λέξη σε ΟΛΕΣ τις τιμές εκτός από umdobject.
	// Ένας 7ψήφιος κωδικός μονάδας δεν μπορεί να ταιριάξει κατά λάθος μέσα σε μεγαλύτερο αριθμό.
	$haystack = array();
	foreach ( $attrs as $k => $v ) {
		if ( 0 === strcasecmp( (string) $k, 'umdobject' ) ) {
			continue;
		}
		foreach ( ( is_array( $v ) ? $v : array( $v ) ) as $one ) {
			if ( is_scalar( $one ) ) {
				$haystack[] = (string) $one;
			}
		}
	}
	foreach ( $units as $u ) {
		foreach ( $haystack as $v ) {
			if ( preg_match( '/(?<![0-9A-Za-z])' . preg_quote( $u, '/' ) . '(?![0-9A-Za-z])/u', $v ) ) {
				return true;
			}
		}
	}
	return 'unit';
}

add_action( 'template_redirect', 'dgn_sso_handle', 1 );

function dgn_sso_handle() {
	if ( ! isset( $_GET['dgn'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	// Επιστροφή από την έξοδο του ΠΣΔ (ίσως μέσω της διεύθυνσης sch.gr): στην κανονική σελίδα της εφαρμογής.
	if ( 'out' === $_GET['dgn'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = get_permalink( get_queried_object_id() );
		if ( ! $page ) {
			$pages = function_exists( 'dgn_app_pages' ) ? dgn_app_pages() : array();
			$page  = $pages ? get_permalink( $pages[0] ) : home_url( '/' );
		}
		nocache_headers();
		wp_safe_redirect( remove_query_arg( array( 'dgn', 'ticket' ), $page ) );
		exit;
	}
	if ( 'sso' !== $_GET['dgn'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$s = dgn_settings();
	if ( 'sso' !== $s['access'] ) {
		return;
	}
	nocache_headers();
	$back = get_permalink( get_queried_object_id() );
	if ( ! $back ) {
		$back = dgn_sso_service_base( $s );
	}
	$back = remove_query_arg( array( 'dgn', 'ticket' ), $back );

	if ( ! dgn_sso_requirements_ok() ) {
		wp_safe_redirect( $back . '#dgn-sso-error=server' );
		exit;
	}

	require_once DGN_DIR . 'vendor/phpCAS/source/CAS.php';

	$service = dgn_sso_service_url( $s );
	$parts   = wp_parse_url( $service );
	$origin  = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	try {
		phpCAS::client( CAS_VERSION_3_0, dgn_cas_host(), dgn_cas_port(), '', $origin );
		// Πάντα η διεύθυνση που δηλώθηκε στο ΠΣΔ, ακόμη κι αν ο χρήστης ήρθε από άλλο domain (ανακατεύθυνση).
		phpCAS::setFixedServiceURL( $service );
		phpCAS::setCasServerCACert( apply_filters( 'dgn_cas_ca_cert', ABSPATH . WPINC . '/certificates/ca-bundle.crt' ) );
		phpCAS::setNoClearTicketsFromUrl();
		phpCAS::forceAuthentication(); // ανακατευθύνει στο ΠΣΔ αν χρειάζεται, αλλιώς επικυρώνει το ticket

		$uid   = (string) phpCAS::getUser();
		$attrs = (array) phpCAS::getAttributes();
	} catch ( Exception $e ) {
		dgn_sso_clear_session();
		dgn_log( 'σφάλμα ΠΣΔ', array( 'cls' => '—', 'exam_date' => dgn_today(), 'subject' => mb_substr( $e->getMessage(), 0, 100 ), 'teacher' => '' ), 'ΠΣΔ' );
		wp_safe_redirect( $back . '#dgn-sso-error=server' );
		exit;
	}

	// Δεν κρατάμε τη συνεδρία του phpCAS: η επόμενη είσοδος πρέπει να περάσει ξανά από το ΠΣΔ.
	dgn_sso_clear_session();

	$auth = dgn_sso_authorize( $attrs, $s );
	if ( true !== $auth ) {
		// Για διάγνωση καταγράφουμε μόνο ρόλο και κωδικούς μονάδας, όχι ονόματα.
		$roles = implode( ',', dgn_sso_attr( $attrs, 'umdobject' ) );
		// Κωδικοί που μοιάζουν με κωδικούς μονάδας (5–8 ψηφία), για να βρείτε τον σωστό στις ρυθμίσεις.
		preg_match_all( '/(?<![0-9])[0-9]{5,8}(?![0-9])/', implode( ' ', array_map( function ( $v ) { return is_array( $v ) ? implode( ' ', array_filter( $v, 'is_scalar' ) ) : (string) $v; }, $attrs ) ), $mm );
		$units = implode( ',', array_slice( array_unique( $mm[0] ), 0, 6 ) );
		dgn_log( 'άρνηση ΠΣΔ', array( 'cls' => '—', 'exam_date' => dgn_today(), 'subject' => mb_substr( 'ρόλος: ' . $roles . ' · κωδικοί: ' . $units, 0, 100 ), 'teacher' => '' ), mb_substr( 'ΠΣΔ (' . $auth . ') · πεδία: ' . implode( ',', array_keys( $attrs ) ), 0, 100 ) );
		wp_safe_redirect( $back . '#dgn-sso-error=' . $auth );
		exit;
	}

	$sn = dgn_sso_attr( $attrs, 'sn' );
	if ( $sn ) {
		$name = $sn[0];
	} else {
		$cn   = dgn_sso_attr( $attrs, 'cn' );
		$name = $cn ? strtok( $cn[0], ' ' ) : $uid;
	}
	$name  = mb_substr( dgn_greek_upper( $name ), 0, 40 );
	$token = dgn_make_sso_token( dgn_uid_hash( $uid ), $name, $s );
	wp_safe_redirect( $back . '#dgn-sso=' . $token );
	exit;
}

function dgn_sso_clear_session() {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		$_SESSION = array();
		session_destroy();
	}
}

/** Το wp_safe_redirect επιτρέπει μόνο τον δικό μας host· προσθέτουμε και τον host της υπηρεσίας ΠΣΔ. */
add_filter( 'allowed_redirect_hosts', function ( $hosts ) {
	$s = dgn_settings();
	if ( 'sso' === $s['access'] && '' !== trim( (string) $s['sso_service'] ) ) {
		$h = wp_parse_url( $s['sso_service'], PHP_URL_HOST );
		if ( $h ) {
			$hosts[] = $h;
		}
	}
	return $hosts;
} );
