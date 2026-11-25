<?php
/**
 * Plugin Name: NG Advanced Media Tools
 * Description: Import images from a URL into the Media Library, and find unused (orphan) media with a safe quarantine and restore instead of instant deletion. Part of the NG WordPress Suite.
 * Version: 0.1.0
 * Author: Nada Gamal
 * License: GPL-2.0-or-later
 * Text Domain: ng-amt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class NGAMT {
	const MAX = 10485760; // 10 MB
	static function init() {
		add_action( 'admin_menu', function () { add_media_page( 'NG Media Tools', __( 'Media Tools', 'ng-amt' ), 'upload_files', 'ngamt', array( __CLASS__, 'page' ) ); } );
		foreach ( array( 'import', 'scan', 'quarantine', 'restore', 'purge' ) as $a ) { add_action( 'admin_post_ngamt_' . $a, array( __CLASS__, 'act_' . $a ) ); }
		add_filter( 'gettext_ng-amt', function ( $t ) { $m = self::T(); $l = substr( apply_filters( 'ngamt_locale', determine_locale() ), 0, 2 ); return isset( $m[ $l ][ $t ] ) ? $m[ $l ][ $t ] : $t; } );
	}
	static function T() { return array(
		'ar' => array( 'Media Tools' => 'أدوات الوسائط', 'Import from URL' => 'استيراد من رابط', 'Find unused media' => 'البحث عن الوسائط غير المستخدمة', 'Move to quarantine' => 'نقل إلى الحجر', 'Restore' => 'استعادة' ),
		'fr' => array( 'Media Tools' => 'Outils média', 'Import from URL' => 'Importer depuis une URL', 'Find unused media' => 'Trouver les médias inutilisés', 'Move to quarantine' => 'Mettre en quarantaine', 'Restore' => 'Restaurer' ),
		'de' => array( 'Media Tools' => 'Medien-Werkzeuge', 'Import from URL' => 'Von URL importieren', 'Find unused media' => 'Ungenutzte Medien finden', 'Move to quarantine' => 'In Quarantäne', 'Restore' => 'Wiederherstellen' ),
	); }

	/* ---- import ---- */
	static function public_host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST ); if ( ! $host ) { return false; }
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : (array) gethostbynamel( $host );
		if ( ! $ips ) { return false; }
		foreach ( $ips as $ip ) { if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; } }
		return true;
	}
	static function import( $url, $alt = '' ) {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( ! $url ) { return new WP_Error( 'url', 'Invalid URL' ); }
		if ( ! apply_filters( 'ngamt_allow_unsafe_url', false ) && ( ! wp_http_validate_url( $url ) || ! self::public_host( $url ) ) ) { return new WP_Error( 'unsafe', 'URL not allowed (private or invalid host)' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
		$unsafe = apply_filters( 'ngamt_allow_unsafe_url', false ); // opt-in for trusted intranet/test sources only
		$relax = function ( $a ) { $a['reject_unsafe_urls'] = false; return $a; };
		if ( $unsafe ) { add_filter( 'http_request_args', $relax, 99 ); }
		$tmp = download_url( $url, 30 );
		if ( $unsafe ) { remove_filter( 'http_request_args', $relax, 99 ); } if ( is_wp_error( $tmp ) ) { return new WP_Error( 'dl', 'Download failed' ); }
		if ( filesize( $tmp ) > self::MAX ) { @unlink( $tmp ); return new WP_Error( 'big', 'File over 10 MB' ); }
		$info = @getimagesize( $tmp ); $ok = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' );
		if ( ! $info || ! isset( $ok[ $info['mime'] ] ) ) { @unlink( $tmp ); return new WP_Error( 'type', 'Not a supported image (JPG, PNG, GIF, WebP)' ); }
		$name = sanitize_file_name( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) ?: 'image' ) . '.' . $ok[ $info['mime'] ];
		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }
		if ( $alt ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) ); }
		update_post_meta( $id, '_ngamt_source', esc_url_raw( $url ) );
		return $id;
	}

	/* ---- orphan scan ---- */
	static function used_ids() {
		global $wpdb; $used = array();
		foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id'" ) as $v ) { $used[ (int) $v ] = 1; }
		foreach ( $wpdb->get_col( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent>0" ) as $v ) { } // parent alone does not prove use
		foreach ( array( 'site_icon', 'custom_logo' ) as $o ) { if ( $v = (int) get_option( $o ) ) { $used[ $v ] = 1; } }
		$mods = get_theme_mod( 'custom_logo' ); if ( $mods ) { $used[ (int) $mods ] = 1; }
		return $used;
	}
	static function is_used( $id, $used, $hay ) {
		if ( isset( $used[ $id ] ) ) { return true; }
		$file = get_post_meta( $id, '_wp_attached_file', true ); if ( ! $file ) { return false; }
		$base = pathinfo( $file, PATHINFO_FILENAME );
		if ( false !== strpos( $hay, 'wp-image-' . $id . ' ' ) || false !== strpos( $hay, 'wp-image-' . $id . '"' ) || false !== strpos( $hay, '"id":' . $id . ',' ) || false !== strpos( $hay, '"id":' . $id . '}' ) ) { return true; }
		return false !== strpos( $hay, $base );   // covers file name and its resized variants in content, builders, widgets, options
	}
	static function haystack() {
		global $wpdb;
		$parts = $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_status NOT IN ('trash','auto-draft')" );
		$parts = array_merge( $parts, $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_ngamt_source','_ngamt_q','_nglio','_ngco','_ngama','_wp_attachment_backup_sizes','_wp_old_slug') AND LENGTH(meta_value)>8" ) );
		$parts = array_merge( $parts, $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\_transient%' AND option_name NOT LIKE '\_site\_transient%' AND LENGTH(option_value)>8" ) );
		return implode( "\n", $parts );
	}
	static function scan() {
		$used = self::used_ids(); $hay = self::haystack(); $out = array();
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_ngamt_q', 'compare' => 'NOT EXISTS' ) ) ) );
		foreach ( $ids as $id ) { if ( ! self::is_used( $id, $used, $hay ) ) { $out[] = $id; } }
		return $out;
	}
	static function qdir() { $u = wp_get_upload_dir(); return $u['basedir'] . '/ng-quarantine'; }
	static function files_for( $id ) {
		$f = get_attached_file( $id ); if ( ! $f ) { return array(); }
		$list = array( $f ); $m = wp_get_attachment_metadata( $id );
		if ( ! empty( $m['sizes'] ) ) { foreach ( $m['sizes'] as $z ) { $list[] = dirname( $f ) . '/' . $z['file']; } }
		foreach ( $list as $x ) { foreach ( array( 'webp', 'avif' ) as $e ) { if ( file_exists( "$x.$e" ) ) { $list[] = "$x.$e"; } } }
		return array_values( array_filter( array_unique( $list ), 'file_exists' ) );
	}
	static function quarantine( $id ) {
		$base = trailingslashit( wp_get_upload_dir()['basedir'] ); $moved = array(); $d = self::qdir() . '/' . $id; wp_mkdir_p( $d );
		foreach ( self::files_for( $id ) as $f ) {
			if ( 0 !== strpos( $f, $base ) ) { continue; }
			$rel = substr( $f, strlen( $base ) ); $dst = $d . '/' . str_replace( '/', '__', $rel );
			if ( rename( $f, $dst ) ) { $moved[ $rel ] = basename( $dst ); }
		}
		if ( ! $moved ) { return false; }
		update_post_meta( $id, '_ngamt_q', array( 'files' => $moved, 'time' => time() ) ); return true;
	}
	static function restore( $id ) {
		$q = get_post_meta( $id, '_ngamt_q', true ); if ( empty( $q['files'] ) ) { return false; }
		$base = trailingslashit( wp_get_upload_dir()['basedir'] ); $d = self::qdir() . '/' . $id;
		foreach ( $q['files'] as $rel => $name ) { wp_mkdir_p( dirname( $base . $rel ) ); if ( file_exists( "$d/$name" ) ) { rename( "$d/$name", $base . $rel ); } }
		@rmdir( $d ); delete_post_meta( $id, '_ngamt_q' ); return true;
	}
	static function purge( $id ) {
		$q = get_post_meta( $id, '_ngamt_q', true ); if ( empty( $q ) ) { return false; }   // only quarantined items can be purged
		$d = self::qdir() . '/' . $id; foreach ( (array) glob( "$d/*" ) as $f ) { @unlink( $f ); } @rmdir( $d );
		wp_delete_attachment( $id, true ); return true;
	}

	/* ---- admin ---- */
	static function guard( $cap = 'upload_files' ) { if ( ! current_user_can( $cap ) ) { wp_die( 'Forbidden', 403 ); } check_admin_referer( 'ngamt' ); }
	static function back( $m, $extra = array() ) { wp_safe_redirect( add_query_arg( array_merge( array( 'msg' => rawurlencode( $m ) ), $extra ), admin_url( 'upload.php?page=ngamt' ) ) ); exit; }
	static function act_import() {
		self::guard(); $in = wp_unslash( $_POST ); $ok = 0; $bad = array();
		foreach ( array_slice( array_filter( array_map( 'trim', preg_split( '/\R/', $in['urls'] ?? '' ) ) ), 0, 20 ) as $u ) { $r = self::import( $u, $in['alt'] ?? '' ); is_wp_error( $r ) ? $bad[] = $r->get_error_message() : $ok++; }
		self::back( "Imported: $ok" . ( $bad ? '. Errors: ' . implode( '; ', array_unique( $bad ) ) : '' ) );
	}
	static function act_scan() { self::guard(); self::back( 'Scan done', array( 'scan' => 1 ) ); }
	static function act_quarantine() { self::guard( 'manage_options' ); $n = 0; foreach ( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) as $id ) { if ( in_array( $id, self::scan(), true ) && self::quarantine( $id ) ) { $n++; } } self::back( "Quarantined: $n" ); }
	static function act_restore() { self::guard( 'manage_options' ); $n = 0; foreach ( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) as $id ) { if ( self::restore( $id ) ) { $n++; } } self::back( "Restored: $n" ); }
	static function act_purge() { self::guard( 'manage_options' ); $n = 0; foreach ( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) as $id ) { if ( self::purge( $id ) ) { $n++; } } self::back( "Deleted permanently: $n" ); }
	static function page() {
		if ( ! current_user_can( 'upload_files' ) ) { return; }
		$act = admin_url( 'admin-post.php' ); $rtl = is_rtl() ? ' dir="rtl"' : '';
		echo '<div class="wrap"' . $rtl . '><h1>NG ' . esc_html__( 'Media Tools', 'ng-amt' ) . '</h1>';
		if ( isset( $_GET['msg'] ) ) { echo '<div class="notice notice-info"><p>' . esc_html( wp_unslash( $_GET['msg'] ) ) . '</p></div>'; }
		echo '<h2>' . esc_html__( 'Import from URL', 'ng-amt' ) . '</h2><form method="post" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngamt' );
		echo '<input type="hidden" name="action" value="ngamt_import"><p><textarea name="urls" rows="4" class="large-text" placeholder="https://example.com/photo.jpg"></textarea></p><p><input type="text" name="alt" class="regular-text" placeholder="Alt text (optional)"></p>'; submit_button( __( 'Import from URL', 'ng-amt' ) ); echo '</form>';
		echo '<h2>' . esc_html__( 'Find unused media', 'ng-amt' ) . '</h2><form method="post" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngamt' ); echo '<input type="hidden" name="action" value="ngamt_scan">'; submit_button( __( 'Find unused media', 'ng-amt' ), 'secondary' ); echo '</form>';
		if ( ! empty( $_GET['scan'] ) && current_user_can( 'manage_options' ) ) {
			$ids = self::scan(); echo '<form method="post" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngamt' ); echo '<input type="hidden" name="action" value="ngamt_quarantine"><p>' . (int) count( $ids ) . ' unused. Check before moving; nothing is deleted.</p><ul>';
			foreach ( $ids as $id ) { printf( '<li><label><input type="checkbox" name="ids[]" value="%d"> %s</label></li>', $id, esc_html( basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ) ) ); }
			echo '</ul>'; submit_button( __( 'Move to quarantine', 'ng-amt' ) ); echo '</form>';
		}
		$q = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_ngamt_q' ) );
		if ( $q && current_user_can( 'manage_options' ) ) {
			echo '<h2>Quarantine</h2><form method="post" action="' . esc_url( $act ) . '">'; wp_nonce_field( 'ngamt' ); echo '<ul>';
			foreach ( $q as $id ) { printf( '<li><label><input type="checkbox" name="ids[]" value="%d"> %s</label></li>', $id, esc_html( basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ) ) ); }
			echo '</ul><button class="button button-primary" name="action" value="ngamt_restore">' . esc_html__( 'Restore', 'ng-amt' ) . '</button> <button class="button" name="action" value="ngamt_purge" onclick="return confirm(\'Delete permanently?\')">Delete permanently</button></form>';
		}
		echo '</div>';
	}
}
NGAMT::init();
