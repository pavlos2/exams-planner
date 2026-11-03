<?php
/**
 * Εξαγωγή δηλώσεων σχολικού έτους σε Excel (.xlsx), μόνο για διαχειριστές.
 * Το αρχείο φτιάχνεται χωρίς εξωτερικές βιβλιοθήκες (ZipArchive της PHP).
 * Αν ο server δεν έχει ZipArchive, δίνεται CSV που ανοίγει σωστά στο Excel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_dgn_export', 'dgn_admin_export' );
add_action( 'admin_post_dgn_export_log', 'dgn_admin_export_log' );

/** Σχολικά έτη (έτος έναρξης) για τα οποία υπάρχουν δηλώσεις, μαζί με το τρέχον. */
function dgn_export_years() {
	global $wpdb;
	$t     = dgn_table( 'exams' );
	$dates = $wpdb->get_col( "SELECT DISTINCT DATE_FORMAT(exam_date, '%Y-%m') FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	list( $cur ) = dgn_school_year();
	$years = array( (int) substr( $cur, 0, 4 ) );
	foreach ( $dates as $ym ) {
		$y       = (int) substr( $ym, 0, 4 );
		$m       = (int) substr( $ym, 5, 2 );
		$years[] = $m >= 7 ? $y : $y - 1;
	}
	$years = array_unique( $years );
	rsort( $years );
	return $years;
}

function dgn_admin_export() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'dgn_export' );

	$start_year = isset( $_POST['year'] ) ? (int) $_POST['year'] : 0;
	if ( $start_year < 2000 || $start_year > 2100 ) {
		list( $cur ) = dgn_school_year();
		$start_year  = (int) substr( $cur, 0, 4 );
	}
	$from = $start_year . '-09-01';
	$to   = ( $start_year + 1 ) . '-06-30';

	$t    = dgn_table( 'exams' );
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT exam_date, cls, subject, teacher, created_at FROM $t WHERE exam_date BETWEEN %s AND %s ORDER BY exam_date, cls", $from, $to ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	// Ταξινόμηση τμημάτων με τη σειρά των ρυθμίσεων (Α1, Α2 … Γ5), όχι αλφαβητικά.
	$order = array_flip( dgn_classes() );
	usort( $rows, function ( $a, $b ) use ( $order ) {
		if ( $a['exam_date'] !== $b['exam_date'] ) {
			return strcmp( $a['exam_date'], $b['exam_date'] );
		}
		$oa = isset( $order[ $a['cls'] ] ) ? $order[ $a['cls'] ] : 999;
		$ob = isset( $order[ $b['cls'] ] ) ? $order[ $b['cls'] ] : 999;
		return $oa <=> $ob;
	} );

	$label    = $start_year . '-' . substr( (string) ( $start_year + 1 ), 2 );
	$filename = 'diagonismata-' . $label;
	$days     = array( 1 => 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο', 'Κυριακή' );

	$data = array();
	foreach ( $rows as $r ) {
		$d      = dgn_parse_date( $r['exam_date'] );
		$data[] = array(
			'date'    => $r['exam_date'],
			'day'     => $days[ (int) $d->format( 'N' ) ],
			'cls'     => $r['cls'],
			'subject' => $r['subject'],
			'teacher' => $r['teacher'],
			'created' => $r['created_at'],
		);
	}

	if ( class_exists( 'ZipArchive' ) ) {
		$file = dgn_build_xlsx( $data, 'Σχολικό έτος ' . $start_year . '–' . ( $start_year + 1 ) );
		if ( $file ) {
			nocache_headers();
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '.xlsx"' );
			header( 'Content-Length: ' . filesize( $file ) );
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			wp_delete_file( $file );
			exit;
		}
	}

	// Εναλλακτικά: CSV με BOM και ελληνικό διαχωριστικό «;», ώστε το Excel να το ανοίγει σωστά.
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fputcsv( $out, array( 'Ημερομηνία', 'Ημέρα', 'Τμήμα', 'Μάθημα', 'Εκπαιδευτικός', 'Δηλώθηκε' ), ';' );
	foreach ( $data as $r ) {
		fputcsv( $out, array( dgn_parse_date( $r['date'] )->format( 'd/m/Y' ), $r['day'], $r['cls'], $r['subject'], $r['teacher'], mysql2date( 'd/m/Y H:i', $r['created'] ) ), ';' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}

/** Ημερομηνία Excel (ημέρες από 30/12/1899). */
function dgn_excel_serial( $ymd, $hm = null ) {
	$base = new DateTimeImmutable( '1899-12-30 00:00:00', new DateTimeZone( 'UTC' ) );
	$d    = new DateTimeImmutable( substr( $ymd, 0, 10 ) . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	$n    = (int) $base->diff( $d )->days;
	if ( $hm ) {
		list( $h, $i ) = array_map( 'intval', explode( ':', $hm ) );
		$n += ( $h * 60 + $i ) / 1440;
	}
	return $n;
}

function dgn_xml( $s ) {
	// Αφαίρεση χαρακτήρων ελέγχου που δεν επιτρέπονται σε XML.
	$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $s );
	return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

/**
 * Δημιουργεί .xlsx σε προσωρινό αρχείο και επιστρέφει τη διαδρομή του (ή false).
 * Δηλώσεις σχολικού έτους.
 */
function dgn_build_xlsx( array $data, $sheet_title ) {
	$columns = array(
		array( 'Ημερομηνία', 13, 'date' ),
		array( 'Ημέρα', 13, 'text' ),
		array( 'Τμήμα', 9, 'text' ),
		array( 'Μάθημα', 32, 'text' ),
		array( 'Εκπαιδευτικός', 22, 'text' ),
		array( 'Δηλώθηκε', 17, 'datetime' ),
	);
	$rows = array();
	foreach ( $data as $row ) {
		$rows[] = array( $row['date'], $row['day'], $row['cls'], $row['subject'], $row['teacher'], $row['created'] );
	}
	return dgn_build_xlsx_table( $columns, $rows, $sheet_title );
}

/**
 * Γενικό .xlsx: $columns = [ [επικεφαλίδα, πλάτος, 'text'|'date'|'datetime'], … ], $rows = [ [τιμές με την ίδια σειρά], … ].
 */
function dgn_build_xlsx_table( array $columns, array $rows, $sheet_title ) {
	$tmp = wp_tempnam( 'dgn-export' );
	if ( ! $tmp ) {
		return false;
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
		return false;
	}

	$n      = count( $columns );
	$letter = function ( $i ) {
		return chr( 65 + $i ); // έως 26 στήλες (A–Z)
	};
	$lastc  = $letter( $n - 1 );
	$last   = count( $rows ) + 1;
	$widths = array();

	$rows_xml = '<row r="1">';
	foreach ( $columns as $i => $c ) {
		$widths[]  = $c[1];
		$rows_xml .= '<c r="' . $letter( $i ) . '1" t="inlineStr" s="1"><is><t>' . dgn_xml( $c[0] ) . '</t></is></c>';
	}
	$rows_xml .= '</row>';
	$r = 1;
	foreach ( $rows as $row ) {
		$r++;
		$rows_xml .= '<row r="' . $r . '">';
		foreach ( $columns as $i => $c ) {
			$v   = isset( $row[ $i ] ) ? (string) $row[ $i ] : '';
			$ref = $letter( $i ) . $r;
			if ( 'date' === $c[2] && preg_match( '/^\d{4}-\d{2}-\d{2}/', $v ) && '0000-00-00' !== substr( $v, 0, 10 ) ) {
				$rows_xml .= '<c r="' . $ref . '" s="2"><v>' . dgn_excel_serial( $v ) . '</v></c>';
			} elseif ( 'datetime' === $c[2] && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $v ) ) {
				$rows_xml .= '<c r="' . $ref . '" s="3"><v>' . dgn_excel_serial( $v, substr( $v, 11, 5 ) ) . '</v></c>';
			} else {
				$rows_xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . dgn_xml( $v ) . '</t></is></c>';
			}
		}
		$rows_xml .= '</row>';
	}

	$cols_xml = '<cols>';
	foreach ( $widths as $i => $w ) {
		$cols_xml .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $w . '" customWidth="1"/>';
	}
	$cols_xml .= '</cols>';

	$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
		. '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
		. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
		. $cols_xml
		. '<sheetData>' . $rows_xml . '</sheetData>'
		. '<autoFilter ref="A1:' . $lastc . $last . '"/>'
		. '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
		. '<pageSetup orientation="portrait" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
		. '</worksheet>';

	// Ονόματα φύλλων: έως 31 χαρακτήρες, χωρίς : \ / ? * [ ]
	$title = mb_substr( preg_replace( '/[:\\\\\/\?\*\[\]]/u', '', $sheet_title ), 0, 31 );

	$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
		. '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm"/></numFmts>'
		. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
		. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
		. '<fill><patternFill patternType="solid"><fgColor rgb="FFE1EFF3"/><bgColor indexed="64"/></patternFill></fill></fills>'
		. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
		. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
		. '<cellXfs count="4">'
		. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
		. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
		. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
		. '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
		. '</cellXfs>'
		. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
		. '</styleSheet>';

	$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
		. '<Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
		. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
		. '</Types>' );
	$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
		. '</Relationships>' );
	$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
		. '<sheets><sheet name="' . dgn_xml( $title ) . '" sheetId="1" r:id="rId1"/></sheets>'
		. '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . dgn_xml( str_replace( "'", "''", $title ) ) . '\'!$A$1:$' . $lastc . '$' . $last . '</definedName></definedNames>'
		. '</workbook>' );
	$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
		. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
		. '</Relationships>' );
	$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet );
	$zip->addFromString( 'xl/styles.xml', $styles );
	$zip->close();
	return $tmp;
}

/** Εξαγωγή όλου του ιστορικού (όσες εγγραφές υπάρχουν, έως DGN_LOG_KEEP) σε Excel. */
function dgn_admin_export_log() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'dgn_export_log' );
	$t    = dgn_table( 'log' );
	$list = $wpdb->get_results( "SELECT created_at, action, cls, exam_date, subject, teacher, actor, ip FROM $t ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = array();
	foreach ( $list as $l ) {
		$rows[] = array( $l['created_at'], $l['action'], $l['cls'], $l['exam_date'], $l['subject'], $l['teacher'], $l['actor'], $l['ip'] );
	}
	$filename = 'istoriko-' . current_time( 'Y-m-d' );
	if ( class_exists( 'ZipArchive' ) ) {
		$columns = array(
			array( 'Πότε', 17, 'datetime' ),
			array( 'Ενέργεια', 14, 'text' ),
			array( 'Τμήμα', 9, 'text' ),
			array( 'Ημ. διαγωνίσματος', 17, 'date' ),
			array( 'Μάθημα', 30, 'text' ),
			array( 'Εκπαιδευτικός', 22, 'text' ),
			array( 'Από', 30, 'text' ),
			array( 'IP', 16, 'text' ),
		);
		$file = dgn_build_xlsx_table( $columns, $rows, 'Ιστορικό' );
		if ( $file ) {
			nocache_headers();
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '.xlsx"' );
			header( 'Content-Length: ' . filesize( $file ) );
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			wp_delete_file( $file );
			exit;
		}
	}
	// Εναλλακτικά: CSV με BOM και «;», ώστε το Excel να το ανοίγει σωστά.
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fputcsv( $out, array( 'Πότε', 'Ενέργεια', 'Τμήμα', 'Ημ. διαγωνίσματος', 'Μάθημα', 'Εκπαιδευτικός', 'Από', 'IP' ), ';' );
	foreach ( $rows as $r ) {
		$r[0] = mysql2date( 'd/m/Y H:i', $r[0] );
		$r[3] = mysql2date( 'd/m/Y', $r[3] );
		fputcsv( $out, $r, ';' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}
