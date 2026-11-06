<?php
/**
 * Shortcode [examsplanner]: η σελίδα δηλώσεων.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'examsplanner', 'dgn_shortcode' );

/** Η τρέχουσα σελίδα περιέχει το shortcode; */
function dgn_is_app_page() {
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_post();
	return $post && has_shortcode( $post->post_content, 'examsplanner' );
}

// Φόρτωση του CSS στο <head>, ώστε ο τίτλος να στοιχίζεται στο κέντρο από την αρχή.
add_action( 'wp_enqueue_scripts', function () {
	if ( dgn_is_app_page() ) {
		wp_enqueue_style( 'dgn-app', DGN_URL . 'assets/app.css', array(), DGN_VERSION );
	}
} );

add_filter( 'body_class', function ( $classes ) {
	if ( dgn_is_app_page() ) {
		$classes[] = 'dgn-page';
	}
	return $classes;
} );

/**
 * Οι δηλώσεις του μήνα που ανοίγει πρώτος, μέσα στη σελίδα, ώστε ο πίνακας να εμφανίζεται αμέσως γεμάτος
 * (χωρίς να περιμένει το αίτημα REST). Μόνο ό,τι βλέπει και ο επισκέπτης: χωρίς ονόματα καθηγητών.
 * Το ίδιο εύρος με το monthRange() του app.js: από τη Δευτέρα της 1ης έως την Παρασκευή της τελευταίας εβδομάδας.
 * Ονόματα, «δικές μου» και τυχόν νεότερες αλλαγές έρχονται αμέσως μετά από το κανονικό αίτημα REST.
 */
function dgn_preload_month( $s ) {
	if ( ! $s['public_view'] ) {
		return null; // ο πίνακας είναι μόνο για καθηγητές: τίποτα μέσα στη σελίδα
	}
	list( $sy_start, $sy_end ) = dgn_school_year();
	$today = dgn_today();
	$view  = $today < $sy_start ? $sy_start : ( $today > $sy_end ? $sy_end : $today );
	$first = new DateTimeImmutable( substr( $view, 0, 8 ) . '01' );
	$last  = $first->modify( 'last day of this month' );
	$start = $first->modify( '-' . ( (int) $first->format( 'N' ) - 1 ) . ' days' );
	$end   = $last->modify( '-' . ( (int) $last->format( 'N' ) - 1 ) . ' days' )->modify( '+4 days' );
	$out   = array();
	foreach ( dgn_exams_between( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ) as $row ) {
		$out[] = array(
			'id'      => (int) $row['id'],
			'cls'     => $row['cls'],
			'date'    => $row['exam_date'],
			'subject' => $row['subject'],
		);
	}
	return array(
		'from'  => $start->format( 'Y-m-d' ),
		'to'    => $end->format( 'Y-m-d' ),
		'exams' => $out,
	);
}

function dgn_shortcode() {
	$s   = dgn_settings();
	$who = dgn_whoami();

	wp_enqueue_style( 'dgn-app', DGN_URL . 'assets/app.css', array(), DGN_VERSION );
	wp_enqueue_script( 'dgn-app', DGN_URL . 'assets/app.js', array(), DGN_VERSION, true );

	$config = array(
		'rest'       => esc_url_raw( rest_url( 'exams-planner/v1/' ) ),
		'nonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		'access'     => $s['access'],
		'level'      => $who['level'],
		'userName'   => $who['name'],
		'loginUrl'   => wp_login_url( get_permalink() ),
		'ssoLogin'   => 'sso' === $s['access'] ? dgn_sso_service_url( $s ) : '',
		'ssoLogout'  => 'sso' === $s['access'] ? dgn_sso_logout_url( $s ) : '',
		'school'     => '' !== trim( $s['school_name'] ) ? $s['school_name'] : get_bloginfo( 'name' ),
		'pageTitle'  => wp_strip_all_tags( html_entity_decode( get_the_title(), ENT_QUOTES, 'UTF-8' ) ),
		'classes'    => dgn_classes( $s ),
		'subjects'   => dgn_subjects( $s ),
		'holidays'   => (object) dgn_holidays( $s ),
		'weeklyMax'  => max( 1, (int) $s['weekly_max'] ),
		'publicView' => (bool) $s['public_view'],
		'allowPast'  => false, // καταργήθηκε (1.9.80): περασμένες ημερομηνίες μόνο για τη Διεύθυνση
		'minNotice'  => max( 0, (int) $s['min_notice'] ),
		'today'      => dgn_today(),
		'syStart'    => dgn_school_year()[0],
		'syEnd'      => dgn_school_year()[1],
		'preload'    => dgn_preload_month( $s ),
	);
	wp_add_inline_script( 'dgn-app', 'window.DGN_CONFIG = ' . wp_json_encode( $config ) . ';', 'before' );

	// Υποσέλιδο «Δημιουργία: … · vX»: έξω από το #dgn-app (το script ξαναγράφει το περιεχόμενό του).
	// Το script το μεταφέρει στην κάτω δεξιά γωνία του <article> της σελίδας (π.χ. Astra), χωρίς να προσθέτει κενό.
	return '<div id="dgn-app" class="dgn-app alignwide"><noscript>Για τη σελίδα δηλώσεων χρειάζεται JavaScript.</noscript><p class="dgn-loading">Φόρτωση…</p></div>'
		. '<p class="dgn-credit">Δημιουργία: 5ο Γυμνάσιο Χανίων · v' . esc_html( DGN_VERSION ) . '</p>';
}
