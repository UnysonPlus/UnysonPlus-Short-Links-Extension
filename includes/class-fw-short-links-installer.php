<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The only DDL. Tables, schema version, capabilities, removal.
 *
 * Same `dbDelta()` contract as the Forms / Newsletter installers: two spaces
 * after PRIMARY KEY, one field per line, `KEY` not `INDEX`, and `$wpdb->prefix`
 * (per-site on multisite).
 *
 * Custom tables rather than a post type on purpose. The redirect needs one
 * indexed lookup by slug on the hot path, and the clicks table grows without
 * bound — neither belongs in wp_posts / wp_postmeta.
 *
 * `slug` is UNIQUE. The default collation is case-insensitive, so /Deal and
 * /deal are the same link, and the index enforces that as well.
 */
class FW_Short_Links_Installer {

	const DB_VERSION        = '1.1.0';
	const DB_VERSION_OPTION = 'fw_ext_short_links_db_version';

	/**
	 * @param string $which links|meta|clicks|daily|keys|webhooks|queue
	 *
	 * @return string
	 */
	public static function table( $which = 'links' ) {
		global $wpdb;

		$map = array(
			'links'    => 'fw_short_links',
			'meta'     => 'fw_short_link_meta',
			'clicks'   => 'fw_short_link_clicks',
			'daily'    => 'fw_short_link_daily',
			'keys'     => 'fw_short_link_keys',
			'webhooks' => 'fw_short_link_webhooks',
			'queue'    => 'fw_short_link_webhook_queue',
		);

		return $wpdb->prefix . $map[ $which ];
	}

	public static function maybe_install() {
		$installed = get_option( self::DB_VERSION_OPTION );

		if ( $installed === self::DB_VERSION ) {
			return;
		}

		self::install();

		// 1.1.0 added the daily rollup: seed it from the click history a 1.0.x
		// site already has, so its reports do not start empty.
		if ( $installed && version_compare( $installed, '1.1.0', '<' ) ) {
			FW_Short_Links_Stats::backfill();
		}
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$links   = self::table( 'links' );
		$meta    = self::table( 'meta' );
		$clicks  = self::table( 'clicks' );
		$collate = $wpdb->get_charset_collate();

		// `status` is varchar, not ENUM, so a new state is a whitelist change and
		// not a migration. `version` is bumped on every write and is what the REST
		// API compares for optimistic locking.
		dbDelta( "CREATE TABLE {$links} (
	id bigint(20) unsigned NOT NULL auto_increment,
	slug varchar(191) NOT NULL default '',
	target_url text NOT NULL,
	title varchar(255) NOT NULL default '',
	notes text NULL,
	redirect_type smallint(3) unsigned NOT NULL default 307,
	status varchar(20) NOT NULL default 'enabled',
	fallback_url text NULL,
	nofollow tinyint(1) unsigned NOT NULL default 1,
	sponsored tinyint(1) unsigned NOT NULL default 0,
	new_window tinyint(1) unsigned NOT NULL default 0,
	param_forwarding tinyint(1) unsigned NOT NULL default 0,
	track tinyint(1) unsigned NOT NULL default 1,
	owner_id bigint(20) unsigned NOT NULL default 0,
	clicks bigint(20) unsigned NOT NULL default 0,
	uniques bigint(20) unsigned NOT NULL default 0,
	version int(10) unsigned NOT NULL default 1,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	deleted_at datetime NULL default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY slug (slug),
	KEY status (status),
	KEY owner_id (owner_id),
	KEY deleted_at (deleted_at),
	KEY created_at (created_at)
) {$collate};" );

		dbDelta( "CREATE TABLE {$meta} (
	id bigint(20) unsigned NOT NULL auto_increment,
	link_id bigint(20) unsigned NOT NULL default 0,
	meta_key varchar(191) NOT NULL default '',
	meta_value longtext NULL,
	PRIMARY KEY  (id),
	KEY link_meta (link_id,meta_key)
) {$collate};" );

		// `ip` is varbinary(16) from inet_pton(), so v4 and v6 share one column
		// and an anonymised address is just the same bytes with the tail zeroed.
		dbDelta( "CREATE TABLE {$clicks} (
	id bigint(20) unsigned NOT NULL auto_increment,
	link_id bigint(20) unsigned NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	ip varbinary(16) NULL default NULL,
	visitor varchar(41) NOT NULL default '',
	is_unique tinyint(1) unsigned NOT NULL default 0,
	referrer varchar(255) NOT NULL default '',
	country char(2) NOT NULL default '',
	device varchar(16) NOT NULL default '',
	browser varchar(32) NOT NULL default '',
	os varchar(32) NOT NULL default '',
	query varchar(255) NOT NULL default '',
	user_id bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (id),
	KEY link_created (link_id,created_at),
	KEY link_visitor (link_id,visitor),
	KEY created_at (created_at)
) {$collate};" );

		$daily    = self::table( 'daily' );
		$keys     = self::table( 'keys' );
		$webhooks = self::table( 'webhooks' );
		$queue    = self::table( 'queue' );

		// One row per link per site-local day; what charts and the stats API read.
		dbDelta( "CREATE TABLE {$daily} (
	link_id bigint(20) unsigned NOT NULL default 0,
	day date NOT NULL default '0000-00-00',
	clicks int(10) unsigned NOT NULL default 0,
	uniques int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (link_id,day),
	KEY day (day)
) {$collate};" );

		// API keys: only a hash and a short lookup prefix are kept.
		dbDelta( "CREATE TABLE {$keys} (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(100) NOT NULL default '',
	prefix varchar(12) NOT NULL default '',
	key_hash char(64) NOT NULL default '',
	scopes varchar(64) NOT NULL default '',
	user_id bigint(20) unsigned NOT NULL default 0,
	last_used_at datetime NULL default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	revoked_at datetime NULL default NULL,
	PRIMARY KEY  (id),
	KEY prefix (prefix)
) {$collate};" );

		dbDelta( "CREATE TABLE {$webhooks} (
	id bigint(20) unsigned NOT NULL auto_increment,
	url text NOT NULL,
	secret varchar(64) NOT NULL default '',
	events varchar(255) NOT NULL default '',
	active tinyint(1) unsigned NOT NULL default 1,
	failures int(10) unsigned NOT NULL default 0,
	last_status varchar(190) NOT NULL default '',
	last_sent_at datetime NULL default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id)
) {$collate};" );

		dbDelta( "CREATE TABLE {$queue} (
	id bigint(20) unsigned NOT NULL auto_increment,
	webhook_id bigint(20) unsigned NOT NULL default 0,
	event varchar(32) NOT NULL default '',
	payload longtext NOT NULL,
	attempts tinyint(3) unsigned NOT NULL default 0,
	next_attempt_at datetime NOT NULL default '0000-00-00 00:00:00',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY due (next_attempt_at)
) {$collate};" );

		self::add_caps();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * `edit_short_links` = make and edit your own links.
	 * `manage_short_links` = edit anyone's. Settings stay behind manage_options.
	 */
	public static function add_caps() {
		$map = array(
			'administrator' => array( 'edit_short_links', 'manage_short_links' ),
			'editor'        => array( 'edit_short_links', 'manage_short_links' ),
			'author'        => array( 'edit_short_links' ),
		);

		foreach ( $map as $role_name => $caps ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * The explicit, opt-in data removal. Never runs on deactivation. The tables
	 * are recreated empty on the next load while the extension stays active.
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( array( 'links', 'meta', 'clicks', 'daily', 'keys', 'webhooks', 'queue' ) as $which ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $which ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		delete_option( self::DB_VERSION_OPTION );
		delete_option( FW_Short_Links_Repository::INDEX_OPTION );
		wp_clear_scheduled_hook( FW_Short_Links_Tracker::CRON_TRIM );
		wp_clear_scheduled_hook( FW_Short_Links_Webhooks::CRON_DELIVER );
		wp_clear_scheduled_hook( FW_Short_Links_Webhooks::CRON_CLICKS );
		delete_option( FW_Short_Links_Webhooks::CURSOR );

		// Link categories live in WordPress's own term tables.
		$terms = get_terms( array( 'taxonomy' => FW_Short_Links_Categories::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) );

		foreach ( is_wp_error( $terms ) ? array() : $terms as $term_id ) {
			wp_delete_term( $term_id, FW_Short_Links_Categories::TAXONOMY );
		}

		foreach ( array( 'administrator', 'editor', 'author' ) as $role_name ) {
			$role = get_role( $role_name );

			if ( $role ) {
				$role->remove_cap( 'edit_short_links' );
				$role->remove_cap( 'manage_short_links' );
			}
		}
	}
}
