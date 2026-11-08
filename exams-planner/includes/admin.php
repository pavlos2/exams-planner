<?php
/**
 * Σελίδα διαχείρισης: Ρυθμίσεις, Δηλώσεις, Ιστορικό.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_menu_page(
		'Exams Planner',
		'Exams Planner',
		'manage_options',
		'exams-planner',
		'dgn_admin_page',
		'dashicons-calendar-alt',
		30
	);
} );

add_filter( 'plugin_action_links_' . plugin_basename( DGN_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=exams-planner' ) ) . '">Ρυθμίσεις</a>' );
	return $links;
} );

add_action( 'admin_post_dgn_save', 'dgn_admin_save' );
add_action( 'admin_post_dgn_delete', 'dgn_admin_delete' );
add_action( 'admin_post_dgn_purge', 'dgn_admin_purge' );
add_action( 'admin_post_dgn_clear_log', 'dgn_admin_clear_log' );
add_action( 'admin_post_dgn_create_page', 'dgn_admin_create_page' );
add_action( 'admin_post_dgn_fill_holidays', 'dgn_admin_fill_holidays' );

/** «Συμπλήρωση πανελλαδικών αργιών»: ξαναγράφει το πεδίο με τις αργίες της τρέχουσας σχολικής χρονιάς. */
function dgn_admin_fill_holidays() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_fill_holidays' );
	$s                  = get_option( DGN_OPTION );
	$sy                 = (int) substr( dgn_school_year()[0], 0, 4 );
	$s['holidays']      = dgn_national_holidays_text( $sy );
	$s['holidays_year'] = $sy;
	update_option( DGN_OPTION, $s, false );
	wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => 'holfilled' ) ) );
	exit;
}

/**
 * «Δημιουργία σελίδας»: φτιάχνει πρόχειρη σελίδα «Προγραμματισμός Διαγωνισμάτων» με το [examsplanner]
 * και διεύθυνση /exams-planner/, και ανοίγει τον επεξεργαστή για «Δημοσίευση».
 * Αν υπάρχει ήδη πρόχειρη σελίδα με το shortcode, ανοίγει αυτήν (δεν φτιάχνει δεύτερη).
 */
function dgn_admin_create_page() {
	global $wpdb;
	if ( ! current_user_can( 'publish_pages' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_create_page' );
	$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('draft','pending','future','private') AND post_content LIKE '%[examsplanner%' ORDER BY ID DESC LIMIT 1" );
	if ( ! $id ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Προγραμματισμός Διαγωνισμάτων',
				'post_name'    => 'exams-planner',
				'post_content' => '[examsplanner]',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( 'Η σελίδα δεν δημιουργήθηκε: ' . $id->get_error_message() ) );
		}
	}
	wp_safe_redirect( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) );
	exit;
}

function dgn_admin_url( $tab = 'settings', $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'exams-planner', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
}

function dgn_admin_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_save' );
	$old = dgn_settings();
	$in  = wp_unslash( $_POST );

	$new                = $old;
	$new['classes']     = sanitize_textarea_field( isset( $in['classes'] ) ? $in['classes'] : '' );
	$new['subjects']    = sanitize_textarea_field( isset( $in['subjects'] ) ? $in['subjects'] : '' );
	$new['holidays']       = sanitize_textarea_field( isset( $in['holidays'] ) ? $in['holidays'] : '' );
	$new['holidays_local'] = sanitize_textarea_field( isset( $in['holidays_local'] ) ? $in['holidays_local'] : '' );
	$new['holidays_auto']  = empty( $in['holidays_auto'] ) ? 0 : 1;
	$new['holidays_year']  = isset( $old['holidays_year'] ) ? (int) $old['holidays_year'] : (int) substr( dgn_school_year()[0], 0, 4 );
	unset( $new['holidays_refreshed'], $new['holidays_eves_note'] );
	$new['holidays_eves_done'] = 1;
	$new['weekly_max']  = min( 5, max( 1, (int) ( isset( $in['weekly_max'] ) ? $in['weekly_max'] : 3 ) ) );
	$new['access']      = ( isset( $in['access'] ) && in_array( $in['access'], array( 'users', 'sso' ), true ) ) ? $in['access'] : 'code';
	$new['sso_server']  = ( isset( $in['sso_server'] ) && 'test' === $in['sso_server'] ) ? 'test' : 'prod';
	$new['sso_service'] = esc_url_raw( trim( isset( $in['sso_service'] ) ? $in['sso_service'] : '' ) );
	$new['sso_units']   = sanitize_text_field( isset( $in['sso_units'] ) ? $in['sso_units'] : '' );
	$new['sso_roles']   = sanitize_text_field( isset( $in['sso_roles'] ) ? $in['sso_roles'] : 'teacher, ateacher, pteacher' );
	if ( '' === trim( $new['sso_roles'] ) ) {
		$new['sso_roles'] = 'teacher, ateacher, pteacher';
	}
	if ( $new['access'] !== $old['access'] || $new['sso_server'] !== $old['sso_server'] || $new['sso_units'] !== $old['sso_units'] || $new['sso_roles'] !== $old['sso_roles'] ) {
		$new['sso_ver'] = (int) $old['sso_ver'] + 1; // αλλαγή κανόνων εισόδου: όλοι ξαναμπαίνουν
	}
	$new['school_name'] = sanitize_text_field( isset( $in['school_name'] ) ? $in['school_name'] : '' );
	$new['teachers']    = sanitize_textarea_field( isset( $in['teachers'] ) ? $in['teachers'] : '' );
	$new['public_view'] = empty( $in['public_view'] ) ? 0 : 1;
	$new['allow_past']  = 0; // καταργήθηκε (1.9.80): οι καθηγητές δεν δηλώνουν περασμένες ημερομηνίες
	$new['min_notice']  = min( 30, max( 0, (int) ( isset( $in['min_notice'] ) ? $in['min_notice'] : 0 ) ) );

	$code = trim( sanitize_text_field( isset( $in['code'] ) ? $in['code'] : '' ) );
	// Ο κωδικός ελέγχεται όταν αλλάζει, και όταν η είσοδος γίνεται «με κωδικό» ενώ πριν δεν ήταν.
	$check = ( '' !== $code && $code !== $old['code'] ) ? $code : ( ( 'code' === $new['access'] && 'code' !== $old['access'] ) ? $old['code'] : null );
	if ( null !== $check && dgn_code_problems( $check ) ) {
		wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => 'weakcode' ) ) );
		exit;
	}
	if ( '' !== $code && $code !== $old['code'] ) {
		$new['code']     = $code;
		$new['code_ver'] = (int) $old['code_ver'] + 1; // αποσυνδέει όσους μπήκαν με τον παλιό κωδικό
	}

	$errors = array();
	if ( ! dgn_classes( $new ) ) {
		$errors[] = 'noclasses';
	}
	if ( ! dgn_subjects( $new ) ) {
		$errors[] = 'nosubjects';
	}
	list( , $bad )  = dgn_parse_holidays( $new['holidays'] );
	list( , $bad2 ) = dgn_parse_holidays( $new['holidays_local'] );
	$bad            = array_merge( $bad, $bad2 );
	if ( $errors ) {
		wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => implode( ',', $errors ) ) ) );
		exit;
	}
	update_option( DGN_OPTION, $new, false );
	wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => $bad ? 'savedbad' : 'saved' ) ) );
	exit;
}

function dgn_admin_delete() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	check_admin_referer( 'dgn_delete_' . $id );
	$exam = dgn_exam_get( $id );
	if ( $exam ) {
		dgn_exam_delete( $exam, 'Διαχειριστής: ' . wp_get_current_user()->display_name );
	}
	wp_safe_redirect( dgn_admin_url( 'exams', array( 'dgn_msg' => 'deleted' ) ) );
	exit;
}

function dgn_admin_purge() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_purge' );
	$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';
	$before  = isset( $_POST['before'] ) ? dgn_parse_date( sanitize_text_field( wp_unslash( $_POST['before'] ) ) ) : null;
	if ( 'ΔΙΑΓΡΑΦΗ' !== $confirm || ! $before ) {
		wp_safe_redirect( dgn_admin_url( 'exams', array( 'dgn_msg' => 'purgefail' ) ) );
		exit;
	}
	$t = dgn_table( 'exams' );
	$n = $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE exam_date < %s", $before->format( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	dgn_log( 'εκκαθάριση', array( 'cls' => '—', 'exam_date' => $before->format( 'Y-m-d' ), 'subject' => (int) $n . ' δηλώσεις', 'teacher' => '' ), 'Διαχειριστής: ' . wp_get_current_user()->display_name );
	wp_safe_redirect( dgn_admin_url( 'exams', array( 'dgn_msg' => 'purged', 'n' => (int) $n ) ) );
	exit;
}

/** Διαγραφή όλου του ιστορικού (με επιβεβαίωση «ΔΙΑΓΡΑΦΗ»). Οι δηλώσεις δεν επηρεάζονται. */
function dgn_admin_clear_log() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_clear_log' );
	$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';
	if ( 'ΔΙΑΓΡΑΦΗ' !== $confirm ) {
		wp_safe_redirect( dgn_admin_url( 'log', array( 'dgn_msg' => 'clearfail' ) ) );
		exit;
	}
	$t = dgn_table( 'log' );
	$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// Η στήλη «ενέργεια» δέχεται έως 12 χαρακτήρες.
	dgn_log( 'καθαρισμός', array( 'cls' => '—', 'exam_date' => dgn_today(), 'subject' => 'ιστορικού: ' . $n . ' εγγραφές', 'teacher' => '' ), 'Διαχειριστής: ' . wp_get_current_user()->display_name );
	wp_safe_redirect( dgn_admin_url( 'log', array( 'dgn_msg' => 'cleared', 'n' => $n ) ) );
	exit;
}

function dgn_admin_notice( $msg ) {
	$map = array(
		'saved'      => array( 'success', 'Οι ρυθμίσεις αποθηκεύτηκαν.' ),
		'holfilled'  => array( 'success', 'Οι πανελλαδικές αργίες συμπληρώθηκαν για την τρέχουσα σχολική χρονιά.' ),
		'savedbad'   => array( 'warning', 'Οι ρυθμίσεις αποθηκεύτηκαν, αλλά κάποιες γραμμές στις αργίες δεν αναγνωρίστηκαν και αγνοούνται (δείτε παρακάτω).' ),
		'noclasses'  => array( 'error', 'Δεν αποθηκεύτηκε: γράψτε τουλάχιστον ένα τμήμα.' ),
		'nosubjects' => array( 'error', 'Δεν αποθηκεύτηκε: γράψτε τουλάχιστον ένα μάθημα.' ),
		'weakcode'   => array( 'error', 'Δεν αποθηκεύτηκε: ο κωδικός καθηγητών πρέπει να έχει τουλάχιστον 8 λατινικούς χαρακτήρες, με κεφαλαίο, μικρό, αριθμό και ένα σύμβολο (# $ ! %).' ),
		'deleted'    => array( 'success', 'Η δήλωση διαγράφηκε.' ),
		'purged'     => array( 'success', sprintf( 'Διαγράφηκαν %d δηλώσεις.', isset( $_GET['n'] ) ? (int) $_GET['n'] : 0 ) ),
		'purgefail'  => array( 'error', 'Η εκκαθάριση δεν έγινε: γράψτε ΔΙΑΓΡΑΦΗ με κεφαλαία και επιλέξτε ημερομηνία.' ),
		'cleared'    => array( 'success', sprintf( 'Το ιστορικό διαγράφηκε (%d εγγραφές).', isset( $_GET['n'] ) ? (int) $_GET['n'] : 0 ) ),
		'clearfail'  => array( 'error', 'Η διαγραφή ιστορικού δεν έγινε: γράψτε ΔΙΑΓΡΑΦΗ με κεφαλαία.' ),
	);
	foreach ( explode( ',', $msg ) as $m ) {
		if ( isset( $map[ $m ] ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $map[ $m ][0] ), esc_html( $map[ $m ][1] ) );
		}
	}
}

function dgn_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
	$tabs = array(
		'settings' => 'Ρυθμίσεις',
		'exams'    => 'Δηλώσεις',
		'log'      => 'Ιστορικό',
	);
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'settings';
	}
	echo '<div class="wrap"><h1>Exams Planner</h1>';
	if ( isset( $_GET['dgn_msg'] ) ) {
		dgn_admin_notice( sanitize_text_field( wp_unslash( $_GET['dgn_msg'] ) ) );
	}
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $label ) {
		printf( '<a class="nav-tab%s" href="%s">%s</a>', $k === $tab ? ' nav-tab-active' : '', esc_url( dgn_admin_url( $k ) ), esc_html( $label ) );
	}
	echo '</nav>';
	call_user_func( 'dgn_admin_tab_' . $tab );
	echo '</div>';
}

/** Δημοσιευμένες σελίδες που περιέχουν το shortcode. */
function dgn_app_pages() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE '%[examsplanner%' LIMIT 5" );
	return array_map( 'intval', $ids );
}

function dgn_admin_tab_settings() {
	$s = dgn_settings();
	list( , $bad )      = dgn_parse_holidays( $s['holidays'] );
	list( , $bad_local ) = dgn_parse_holidays( $s['holidays_local'] );
	$sy                 = (int) substr( dgn_school_year()[0], 0, 4 );
	if ( ! empty( $s['holidays_eves_note'] ) ) {
		$opt = get_option( DGN_OPTION );
		unset( $opt['holidays_eves_note'] );
		update_option( DGN_OPTION, $opt, false );
		echo '<div class="notice notice-info inline" style="max-width:780px"><p>Προστέθηκαν οι σχολικές εορτές της 28ης Οκτωβρίου και της 25ης Μαρτίου στις πανελλαδικές αργίες.</p></div>';
	}
	if ( ! empty( $s['holidays_refreshed'] ) ) {
		// Μήνυμα μία φορά, μετά την αυτόματη ενημέρωση της νέας χρονιάς.
		$opt = get_option( DGN_OPTION );
		unset( $opt['holidays_refreshed'] );
		update_option( DGN_OPTION, $opt, false );
		echo '<div class="notice notice-info inline" style="max-width:780px"><p>Οι πανελλαδικές αργίες ενημερώθηκαν αυτόματα για το σχολικό έτος ' . (int) $s['holidays_refreshed'] . '–' . ( (int) $s['holidays_refreshed'] + 1 ) . '. Ελέγξτε αν χρειάζεται κάποια διόρθωση.</p></div>';
	}
	?>
	<?php $pages = dgn_app_pages(); ?>
	<?php if ( ! $pages ) : ?>
		<div class="notice notice-warning inline" style="margin:16px 0;max-width:780px;padding:10px 14px">
			<p style="margin:.3em 0 .6em"><strong>Το πρόγραμμα δεν εμφανίζεται ακόμα στην ιστοσελίδα.</strong> Για να το δουν καθηγητές, μαθητές και γονείς:</p>
			<ol style="margin:0 0 .6em 1.6em;line-height:1.9">
				<li>Πατήστε <strong>Δημιουργία σελίδας</strong>: ανοίγει έτοιμη η σελίδα «Προγραμματισμός Διαγωνισμάτων».</li>
				<li>Στη σελίδα που ανοίγει, πατήστε <strong>Δημοσίευση</strong>.</li>
			</ol>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:.4em 0 .5em">
				<input type="hidden" name="action" value="dgn_create_page">
				<?php wp_nonce_field( 'dgn_create_page' ); ?>
				<button type="submit" class="button button-primary">Δημιουργία σελίδας</button>
			</form>
			<p style="margin:.2em 0 .4em;color:#646970">Εναλλακτικά: Σελίδες → Προσθήκη νέας, γράψτε μέσα στη σελίδα <code>[examsplanner]</code> και πατήστε Δημοσίευση.</p>
		</div>
	<?php endif; ?>
	<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:780px;line-height:1.7">
		<?php if ( $pages ) : ?>
			<?php foreach ( $pages as $p ) : ?>
				<strong>Σελίδα προγράμματος:</strong> <a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank"><?php echo esc_html( get_permalink( $p ) ); ?></a><br>
				<?php if ( 'code' === $s['access'] ) : ?>
					<strong>Σελίδα εισόδου καθηγητών:</strong> <a href="<?php echo esc_url( add_query_arg( 'dgn', 'login', get_permalink( $p ) ) ); ?>" target="_blank"><?php echo esc_html( add_query_arg( 'dgn', 'login', get_permalink( $p ) ) ); ?></a><br>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( 'code' === $s['access'] ) : ?>
			<strong>Κωδικός καθηγητών:</strong> <code style="font-size:15px"><?php echo esc_html( $s['code'] ); ?></code><br>
		<?php endif; ?>
		<span style="color:#646970">Αν χρησιμοποιείτε plugin cache (π.χ. WP Fastest Cache, LiteSpeed, WP Rocket), εξαιρέστε <?php
		// Το όνομα (slug) της σελίδας όπως είναι στη διεύθυνσή της, π.χ. «exams-planner».
		$slugs = array_filter( array_map( function ( $p ) { return get_post_field( 'post_name', $p ); }, $pages ) );
		if ( $slugs ) {
			echo 'τη σελίδα <code>' . implode( '</code>, <code>', array_map( 'esc_html', $slugs ) ) . '</code>';
		} else {
			echo 'τη σελίδα του προγράμματος';
		}
		?> από την cache, ώστε να εμφανίζεται πάντα η σωστή ημερομηνία και η τρέχουσα έκδοση.</span>
	</div>
	<style>
		.dgn-settings input[type=number].small-text { width: 4.2em; height: 30px; min-height: 30px; padding: 0 4px 0 8px; line-height: 28px; }
		.dgn-settings td > label { display: inline-block; line-height: 2; }
		/* Ο κωδικός καθηγητών «ανήκει» στην τελευταία επιλογή από πάνω: μικρότερο κενό ανάμεσά τους. */
		.dgn-settings tr.dgn-access-row > th, .dgn-settings tr.dgn-access-row > td { padding-bottom: 6px; }
		.dgn-settings tr.dgn-code-row > th, .dgn-settings tr.dgn-code-row > td { padding-top: 6px; }
		/* Ενότητα που δεν αφορά την τρέχουσα επιλογή «Ποιος κάνει δηλώσεις»: γκρι και ανενεργή. */
		.dgn-settings tr.dgn-off > th, .dgn-settings tr.dgn-off > td { opacity: .5; }
		/* Επιλογές μέσα στην ίδια γραμμή: η καθεμία χωριστά, με κενό ανάμεσα. */
		.dgn-settings .dgn-opt { margin: 0 0 14px; }
		.dgn-settings .dgn-opt:last-of-type { margin-bottom: 8px; }
		/* Σύνδεση με ΠΣΔ: κενό ανάμεσα στις ομάδες πεδίων· οι σημειώσεις χωριστά, κάτω από μια λεπτή γραμμή. */
		.dgn-settings .dgn-group { margin: 0 0 16px; }
		.dgn-settings .dgn-group label { line-height: 2; }
		.dgn-settings .dgn-notes { border-top: 1px solid #dcdcde; padding-top: 10px; margin-top: 4px; }
		.dgn-settings .dgn-notes p.description { margin: 0 0 8px; }
		.dgn-settings .dgn-strength { margin: 6px 0 4px; font-weight: 600; }
		.dgn-settings .dgn-strength.ok { color: #00a32a; }
		.dgn-settings .dgn-strength.bad { color: #d63638; }
	</style>
	<form class="dgn-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dgn_save">
		<?php wp_nonce_field( 'dgn_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dgn-school">Όνομα σχολείου</label></th>
				<td>
					<input id="dgn-school" name="school_name" class="regular-text" value="<?php echo esc_attr( $s['school_name'] ); ?>">
					<p class="description">Δεν εμφανίζεται στη σελίδα. Με αυτό το όνομα αποθηκεύει ο browser τον κωδικό των καθηγητών.</p>
				</td>
			</tr>
			<tr class="dgn-access-row">
				<th scope="row">Ποιος κάνει δηλώσεις</th>
				<td>
					<fieldset>
						<label><input type="radio" name="access" value="sso" <?php checked( $s['access'], 'sso' ); ?>> Με τον <strong>λογαριασμό ΠΣΔ (sch.gr)</strong>, μόνο εκπαιδευτικοί της σχολικής μονάδας με εγγραφή στο ΠΣΔ</label><br>
						<label><input type="radio" name="access" value="users" <?php checked( $s['access'], 'users' ); ?>> Μόνο όσοι έχουν <strong>λογαριασμό στην ιστοσελίδα</strong> και έχουν συνδεθεί</label><br>
						<label><input type="radio" name="access" value="code" <?php checked( $s['access'], 'code' ); ?>> Όποιος ξέρει τον <strong>κοινό κωδικό καθηγητών</strong></label>
					</fieldset>
				</td>
			</tr>
			<tr class="dgn-code-row">
				<th scope="row"><label for="dgn-code">Κωδικός καθηγητών</label></th>
				<td>
					<input id="dgn-code" name="code" class="regular-text" value="<?php echo esc_attr( $s['code'] ); ?>" autocomplete="off" autocapitalize="none" spellcheck="false">
					<p id="dgn-code-strength" class="dgn-strength" aria-live="polite"></p>
					<p class="description">Τουλάχιστον 8 λατινικοί χαρακτήρες, με κεφαλαίο, μικρό, αριθμό και ένα σύμβολο από τα <code>#</code> <code>$</code> <code>!</code> <code>%</code>. Αν τον αλλάξετε, όσοι είχαν μπει με τον παλιό θα πρέπει να γράψουν τον νέο. Οι δηλώσεις τους μένουν.</p>
				</td>
			</tr>
			<tr class="dgn-sso-row">
				<th scope="row">Σύνδεση με ΠΣΔ</th>
				<td>
					<?php if ( ! dgn_sso_requirements_ok() ) : ?>
						<p style="color:#b32d2e"><strong>Ο server δεν έχει τις επεκτάσεις PHP curl/dom που χρειάζεται η σύνδεση με ΠΣΔ.</strong> Ζητήστε τις από τη φιλοξενία.</p>
					<?php endif; ?>
					<fieldset class="dgn-group"><strong>Διακομιστής ΠΣΔ</strong><br>
						<label><input type="radio" name="sso_server" value="prod" <?php checked( $s['sso_server'], 'prod' ); ?>> Κανονικός (<code>sso.sch.gr</code>)</label><br>
						<label><input type="radio" name="sso_server" value="test" <?php checked( $s['sso_server'], 'test' ); ?>> Δοκιμαστικός (<code>sso-01-test.sch.gr</code>) — μόνο όσο το ΠΣΔ έχει εγγράψει την υπηρεσία εκεί</label>
						<?php if ( defined( 'DGN_CAS_HOST' ) ) : ?><br><em>Προσοχή: το wp-config.php ορίζει <code>DGN_CAS_HOST</code> = <code><?php echo esc_html( DGN_CAS_HOST ); ?></code>, που υπερισχύει αυτής της επιλογής.</em><?php endif; ?>
					</fieldset>
					<p class="dgn-group"><label for="dgn-sso-service"><strong>Διεύθυνση υπηρεσίας</strong> (ακριβώς όπως δηλώθηκε στο ΠΣΔ)</label><br>
					<input id="dgn-sso-service" name="sso_service" class="regular-text" value="<?php echo esc_attr( $s['sso_service'] ); ?>" placeholder="https://&lt;σχολείο&gt;.&lt;νομός&gt;.sch.gr/exams-planner/"></p>
					<p class="dgn-group"><label for="dgn-sso-units"><strong>Κωδικός σχολικής μονάδας</strong> (όπως στο myschool· περισσότεροι με κόμμα)</label><br>
					<input id="dgn-sso-units" name="sso_units" class="regular-text" value="<?php echo esc_attr( $s['sso_units'] ); ?>"></p>
					<p class="dgn-group"><label for="dgn-sso-roles"><strong>Ρόλοι που επιτρέπονται</strong> (τιμές του umdobject)</label><br>
					<input id="dgn-sso-roles" name="sso_roles" class="regular-text" value="<?php echo esc_attr( $s['sso_roles'] ); ?>"></p>
					<div class="dgn-notes">
						<p class="description">Στο ΠΣΔ ζητήστε τα attributes: <code>uid, sn, umdobject, edupersonorgunitdn:gsnunitcode:extended</code>.</p>
						<p class="description">Οι απορρίψεις εισόδου (π.χ. μαθητής ή εκπαιδευτικός άλλου σχολείου) φαίνονται στο «Ιστορικό» με τον ρόλο και τους κωδικούς μονάδας, χωρίς ονόματα.</p>
						<p class="description">Διεύθυνση που θα στέλνεται στο ΠΣΔ: <code><?php echo esc_html( dgn_sso_service_url( $s ) ); ?></code></p>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dgn-max">Μέγιστα διαγωνίσματα την εβδομάδα</label></th>
				<td><input id="dgn-max" name="weekly_max" type="number" min="1" max="5" value="<?php echo (int) $s['weekly_max']; ?>" class="small-text"> ανά τμήμα (πάντα έως 1 την ημέρα)</td>
			</tr>
			<tr>
				<th scope="row">Προβολή</th>
				<td>
					<label><input type="checkbox" name="public_view" value="1" <?php checked( $s['public_view'], 1 ); ?>> Μαθητές και γονείς βλέπουν τον πίνακα (χωρίς ονόματα καθηγητών, χωρίς δυνατότητα αλλαγών)</label>
				</td>
			</tr>
			<tr>
				<th scope="row">Προθεσμίες δήλωσης</th>
				<td>
					<div class="dgn-opt"><label>Ελάχιστη προειδοποίηση: <input type="number" name="min_notice" min="0" max="30" class="small-text" value="<?php echo (int) $s['min_notice']; ?>"> εργάσιμες ημέρες πριν το διαγώνισμα, χωρίς Σαββατοκύριακα και αργίες. 0 = χωρίς όριο.</label></div>
					<p class="description">Οι καθηγητές δεν μπορούν να δηλώνουν περασμένες ημερομηνίες ούτε ημερομηνίες που εμπίπτουν στο διάστημα της ελάχιστης προειδοποίησης. Η Διεύθυνση (διαχειριστής) δεν έχει αυτούς τους περιορισμούς. Σήμερα, η νωρίτερη ημερομηνία που μπορεί να δηλώσει ένας καθηγητής είναι η <strong><?php echo esc_html( dgn_parse_date( (int) $s['min_notice'] > 0 ? dgn_earliest_allowed( (int) $s['min_notice'], $s ) : dgn_today() )->format( 'd/m/Y' ) ); ?></strong>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dgn-classes">Τμήματα</label></th>
				<td><textarea id="dgn-classes" name="classes" rows="8" cols="20"><?php echo esc_textarea( $s['classes'] ); ?></textarea>
				<p class="description">Ένα ανά γραμμή, με τη σειρά που θέλετε να εμφανίζονται.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="dgn-teachers">Εκπαιδευτικοί</label></th>
				<td><textarea id="dgn-teachers" name="teachers" rows="10" cols="30"><?php echo esc_textarea( $s['teachers'] ); ?></textarea>
				<p class="description">Ένας ανά γραμμή, όπως θέλετε να φαίνεται (π.χ. <code>ΠΑΠΑΔΑΚΗΣ</code> ή <code>ΠΑΠΑΔΑΚΗΣ Γ.</code> αν υπάρχουν ίδια επώνυμα). Εμφανίζονται με αλφαβητική σειρά στη φόρμα δήλωσης, μόνο σε όσους έχουν μπει με τον κωδικό. Αν μείνει κενό, οι εκπαιδευτικοί γράφουν ελεύθερα το επώνυμό τους.<br><?php echo count( dgn_teachers( $s ) ); ?> εκπαιδευτικοί στη λίστα.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="dgn-subjects">Μαθήματα</label></th>
				<td><textarea id="dgn-subjects" name="subjects" rows="10" cols="50"><?php echo esc_textarea( $s['subjects'] ); ?></textarea>
				<p class="description">Ένα ανά γραμμή: <code>Όνομα | ΣΥΝΤΟΜΟΓΡΑΦΙΑ</code>, π.χ. <code>Θρησκευτικά | ΘΡΗ</code>. Η συντομογραφία (έως 5 γράμματα) φαίνεται στον πίνακα.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="dgn-holidays">Αργίες</label></th>
				<td>
					<div style="margin:0 0 6px"><strong>Πανελλαδικές αργίες <?php echo (int) $sy . '–' . ( (int) $sy + 1 ); ?></strong></div>
					<textarea id="dgn-holidays" name="holidays" rows="9" cols="50" class="code"><?php echo esc_textarea( $s['holidays'] ); ?></textarea>
					<?php if ( $bad ) : ?>
						<p style="color:#b32d2e"><strong>Δεν αναγνωρίστηκαν:</strong> <?php echo esc_html( implode( ' · ', $bad ) ); ?></p>
					<?php endif; ?>
					<label style="display:block;margin:8px 0 4px"><input type="checkbox" name="holidays_auto" value="1" <?php checked( ! empty( $s['holidays_auto'] ) ); ?>> Αυτόματη ενημέρωση των πανελλαδικών αργιών κάθε σχολική χρονιά</label>
					<p class="description" style="margin:0 0 8px">Κάθε νέα σχολική χρονιά το πεδίο ξαναγεμίζει μόνο του με τις αργίες της. Μέσα στη χρονιά μπορείτε να το διορθώσετε, π.χ. αν μετατεθεί η Πρωτομαγιά. Βγάλτε το τικ αν θέλετε να το συμπληρώνετε μόνοι σας.</p>
					<p style="margin:0 0 18px"><button type="submit" form="dgn-fill-holidays" class="button" onclick="return confirm('Το πεδίο «Πανελλαδικές αργίες» θα αντικατασταθεί με τις αργίες του <?php echo (int) $sy . '–' . ( (int) $sy + 1 ); ?>. Συνέχεια;');">Συμπλήρωση πανελλαδικών αργιών</button> <span style="color:#646970">αντικαθιστά το πεδίο με τις αργίες της τρέχουσας χρονιάς</span></p>
					<div style="margin:0 0 6px"><label for="dgn-holidays-local"><strong>Τοπικές αργίες</strong></label> <span style="color:#646970">(του σχολείου ή της πόλης σας· δεν αλλάζουν αυτόματα)</span></div>
					<textarea id="dgn-holidays-local" name="holidays_local" rows="3" cols="50" class="code"><?php echo esc_textarea( $s['holidays_local'] ); ?></textarea>
					<?php if ( $bad_local ) : ?>
						<p style="color:#b32d2e"><strong>Δεν αναγνωρίστηκαν:</strong> <?php echo esc_html( implode( ' · ', $bad_local ) ); ?></p>
					<?php endif; ?>
					<p class="description">Μία ανά γραμμή. Χωρίς έτος ισχύει κάθε χρόνο: <code>11-08 | Πολιούχος</code>. Με έτος ισχύει μόνο τότε: <code>2027-05-20 έως 2027-05-21 | Επέτειος</code>. Σε όλες τις αργίες δεν γίνονται δηλώσεις.</p>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Αποθήκευση' ); ?>
	</form>
	<form id="dgn-fill-holidays" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dgn_fill_holidays">
		<?php wp_nonce_field( 'dgn_fill_holidays' ); ?>
	</form>
	<script>
	( function () {
		var form = document.querySelector( 'form.dgn-settings' );
		if ( ! form ) return;
		var rows = { code: form.querySelector( 'tr.dgn-code-row' ), sso: form.querySelector( 'tr.dgn-sso-row' ) };
		function sync() {
			var cur = ( form.querySelector( 'input[name=access]:checked' ) || {} ).value;
			Object.keys( rows ).forEach( function ( k ) {
				var row = rows[ k ];
				if ( ! row ) return;
				var off = cur !== k;
				row.classList.toggle( 'dgn-off', off );
				row.querySelectorAll( 'input, textarea, select' ).forEach( function ( el ) { el.disabled = off; } );
			} );
		}
		form.querySelectorAll( 'input[name=access]' ).forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
		// Τα ανενεργά πεδία δεν στέλνονται: τα ενεργοποιούμε πριν την αποθήκευση, ώστε να μη χαθούν οι τιμές τους.
		form.addEventListener( 'submit', function () {
			form.querySelectorAll( 'tr.dgn-off input, tr.dgn-off textarea, tr.dgn-off select' ).forEach( function ( el ) { el.disabled = false; } );
		} );
		sync();
		// Ζωντανή ένδειξη ισχύος του κωδικού (ίδιοι κανόνες με το dgn_code_problems() της PHP).
		var codeIn = form.querySelector( '#dgn-code' ), codeOut = form.querySelector( '#dgn-code-strength' );
		function strength() {
			if ( ! codeIn || ! codeOut ) return;
			var v = codeIn.value.trim(), p = [];
			if ( v && ! /^[\x21-\x7E]+$/.test( v ) ) p.push( 'μόνο λατινικοί χαρακτήρες, αριθμοί και σύμβολα, χωρίς κενά' );
			if ( v.length < 8 ) p.push( 'τουλάχιστον 8 χαρακτήρες' );
			if ( ! /[A-Z]/.test( v ) ) p.push( 'ένα κεφαλαίο γράμμα' );
			if ( ! /[a-z]/.test( v ) ) p.push( 'ένα μικρό γράμμα' );
			if ( ! /[0-9]/.test( v ) ) p.push( 'έναν αριθμό' );
			if ( ! /[#$!%]/.test( v ) ) p.push( 'ένα σύμβολο (# $ ! %)' );
			codeOut.textContent = p.length ? 'Αδύναμος κωδικός: χρειάζεται ' + p.join( ', ' ) + '.' : '✓ Ισχυρός κωδικός';
			codeOut.className = 'dgn-strength ' + ( p.length ? 'bad' : 'ok' );
		}
		if ( codeIn ) { codeIn.addEventListener( 'input', strength ); strength(); }
	} )();
	</script>
	<?php
}

function dgn_admin_tab_exams() {
	global $wpdb;
	$t    = dgn_table( 'exams' );
	$from = dgn_today();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE exam_date >= %s ORDER BY exam_date, cls LIMIT 500", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$days = array( 1 => 'Δευ', 'Τρι', 'Τετ', 'Πεμ', 'Παρ', 'Σαβ', 'Κυρ' );
	$years = dgn_export_years();
	?>
	<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:900px">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;margin:0">
			<input type="hidden" name="action" value="dgn_export">
			<?php wp_nonce_field( 'dgn_export' ); ?>
			<strong>Εξαγωγή σε Excel:</strong>
			<label>Σχολικό έτος
				<select name="year">
					<?php foreach ( $years as $y ) : ?>
						<option value="<?php echo (int) $y; ?>"><?php echo (int) $y . '–' . ( (int) $y + 1 ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php submit_button( 'Λήψη αρχείου Excel', 'primary', 'submit', false ); ?>
			<span class="description">Όλα τα διαγωνίσματα της χρονιάς (1/9 – 30/6), ένα ανά γραμμή.</span>
		</form>
	</div>
	<?php
	echo '<h2>Επόμενα διαγωνίσματα</h2>';
	if ( ! $rows ) {
		echo '<p>Δεν υπάρχουν δηλώσεις από σήμερα και μετά.</p>';
	} else {
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Ημερομηνία</th><th>Τμήμα</th><th>Μάθημα</th><th>Εκπαιδευτικός</th><th>Δηλώθηκε</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$d   = dgn_parse_date( $r['exam_date'] );
			$del = wp_nonce_url( admin_url( 'admin-post.php?action=dgn_delete&id=' . (int) $r['id'] ), 'dgn_delete_' . (int) $r['id'] );
			printf(
				'<tr><td>%s %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" class="button-link-delete" onclick="return window.confirm(\'Να διαγραφεί η δήλωση;\')">Διαγραφή</a></td></tr>',
				esc_html( $days[ (int) $d->format( 'N' ) ] ),
				esc_html( $d->format( 'd/m/Y' ) ),
				esc_html( $r['cls'] ),
				esc_html( $r['subject'] ),
				esc_html( $r['teacher'] ),
				esc_html( mysql2date( 'd/m H:i', $r['created_at'] ) ),
				esc_url( $del )
			);
		}
		echo '</tbody></table>';
	}
	?>
	<h2 style="margin-top:32px">Νέα σχολική χρονιά</h2>
	<p>Διαγράφει όλες τις δηλώσεις πριν από την ημερομηνία που θα επιλέξετε. Το ιστορικό μένει.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dgn_purge">
		<?php wp_nonce_field( 'dgn_purge' ); ?>
		<label>Δηλώσεις πριν από <input type="date" name="before" required></label>
		<label style="margin-left:12px">Γράψτε <strong>ΔΙΑΓΡΑΦΗ</strong> για επιβεβαίωση <input name="confirm" required></label>
		<?php submit_button( 'Διαγραφή παλιών δηλώσεων', 'delete', 'submit', false ); ?>
	</form>
	<?php
}

function dgn_admin_tab_log() {
	global $wpdb;
	$t    = dgn_table( 'log' );
	$rows  = $wpdb->get_results( "SELECT * FROM $t ORDER BY id DESC LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	?>
	<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:1100px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between">
		<span>Κρατούνται οι τελευταίες <?php echo esc_html( number_format_i18n( DGN_LOG_KEEP ) ); ?> ενέργειες· οι παλαιότερες διαγράφονται αυτόματα. Αυτή τη στιγμή: <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>.</span>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
			<input type="hidden" name="action" value="dgn_export_log">
			<?php wp_nonce_field( 'dgn_export_log' ); ?>
			<?php submit_button( 'Εξαγωγή ιστορικού (Excel)', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
	echo '<h2>Τελευταίες 300 ενέργειες</h2>';
	if ( ! $rows ) {
		echo '<p>Δεν υπάρχουν ακόμη ενέργειες.</p>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Πότε</th><th>Ενέργεια</th><th>Τμήμα</th><th>Ημ. διαγωνίσματος</th><th>Μάθημα</th><th>Εκπαιδευτικός</th><th>Από</th><th>IP</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( mysql2date( 'd/m/Y H:i', $r['created_at'] ) ),
			esc_html( $r['action'] ),
			esc_html( $r['cls'] ),
			esc_html( mysql2date( 'd/m/Y', $r['exam_date'] ) ),
			esc_html( $r['subject'] ),
			esc_html( $r['teacher'] ),
			esc_html( $r['actor'] ),
			esc_html( $r['ip'] )
		);
	}
	echo '</tbody></table>';
	?>
	<h2 style="margin-top:32px">Διαγραφή ιστορικού</h2>
	<p>Διαγράφει όλες τις εγγραφές του ιστορικού (π.χ. στην αρχή της σχολικής χρονιάς). Οι δηλώσεις δεν επηρεάζονται. Αν θέλετε αρχείο, κάντε πρώτα «Εξαγωγή ιστορικού».</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dgn_clear_log">
		<?php wp_nonce_field( 'dgn_clear_log' ); ?>
		<label>Γράψτε <strong>ΔΙΑΓΡΑΦΗ</strong> για επιβεβαίωση <input name="confirm" required></label>
		<?php submit_button( 'Διαγραφή ιστορικού', 'delete', 'submit', false ); ?>
	</form>
	<?php
}
