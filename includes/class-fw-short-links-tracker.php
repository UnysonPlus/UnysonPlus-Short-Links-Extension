<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Click tracking: who the visitor is (IP, unique or not, bot or not), what is
 * stored, and the retention job. Also the click-row reads the admin uses.
 *
 * Privacy by default: IPs are anonymised before they are stored, uniques fall
 * back to a hash salted per day (not reversible to an IP), and nothing is
 * sent to a third party. Country comes only from headers a CDN already set.
 */
class FW_Short_Links_Tracker {

	const CRON_TRIM      = 'fw_ext_short_links_trim';
	const VISITOR_COOKIE = 'fwsl_v';
	const LINK_COOKIE    = 'fwsl_';
	const UNIQUE_WINDOW  = 30; // days

	/** Crawlers, link unfurlers, monitors, HTTP libraries, headless browsers, AI fetchers. */
	const BOT_REGEX = '~bot\b|bot/|crawl|spider|slurp|mediapartners|facebookexternalhit|facebookcatalog|embedly|quora link preview|outbrain|pinterest|vkshare|w3c_validator|whatsapp|telegrambot|discordbot|slackbot|skypeuripreview|bitlybot|tumblr|nuzzel|redditbot|applebot|yandex|baiduspider|bingpreview|duckduckgo|petalbot|semrush|ahrefs|mj12|dotbot|rogerbot|screaming frog|uptimerobot|pingdom|statuscake|site24x7|newrelic|datadog|headlesschrome|phantomjs|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|curl/|wget/|python-requests|python-urllib|aiohttp|httpx|go-http-client|okhttp|java/|libwww|httpclient|axios/|node-fetch|undici|guzzle|wordpress/|gptbot|chatgpt|oai-searchbot|claudebot|claude-web|anthropic|perplexity|ccbot|bytespider|amazonbot|google-extended|cohere|diffbot|scrapy~i';

	/* ---------------------------------------------------------------------- *
	 * Visitor
	 * ---------------------------------------------------------------------- */

	/**
	 * The client IP. Forwarded headers are believed ONLY when the direct peer
	 * (REMOTE_ADDR) is one of the configured trusted proxies — otherwise anyone
	 * could send X-Forwarded-For and dodge exclusions or poison the data.
	 *
	 * @return string '' when unknown
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$ip     = $remote;
		$proxies = FW_Short_Links_Service::lines( FW_Short_Links_Service::settings()['trusted_proxies'] );

		if ( $proxies && self::ip_in_list( $remote, $proxies ) ) {
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$ip = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				// Walk right to left: the first address that is not one of our own
				// proxies is the client.
				$chain = array_reverse( array_map( 'trim', explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );

				foreach ( $chain as $hop ) {
					if ( filter_var( $hop, FILTER_VALIDATE_IP ) && ! self::ip_in_list( $hop, $proxies ) ) {
						$ip = $hop;
						break;
					}
				}
			} elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$ip = trim( (string) $_SERVER['HTTP_X_REAL_IP'] );
			}
		}

		// Unwrap IPv4-mapped IPv6 (::ffff:1.2.3.4).
		if ( 0 === stripos( $ip, '::ffff:' ) && filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$ip = substr( $ip, 7 );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Exact addresses and CIDR ranges, IPv4 and IPv6.
	 *
	 * @param string   $ip
	 * @param string[] $list
	 *
	 * @return bool
	 */
	public static function ip_in_list( $ip, array $list ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $bin ) {
			return false;
		}

		foreach ( $list as $entry ) {
			if ( false === strpos( $entry, '/' ) ) {
				if ( @inet_pton( $entry ) === $bin ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return true;
				}
				continue;
			}

			list( $net, $bits ) = explode( '/', $entry, 2 );
			$net_bin = @inet_pton( trim( $net ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$bits    = (int) $bits;

			if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) ) {
				continue;
			}

			$bytes = intdiv( $bits, 8 );
			$rem   = $bits % 8;

			if ( 0 !== strncmp( $bin, $net_bin, $bytes ) ) {
				continue;
			}

			if ( 0 === $rem ) {
				return true;
			}

			$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;

			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Packed bytes for storage, anonymised when the setting is on (last IPv4
	 * octet / last 80 bits of IPv6 zeroed — the usual analytics convention).
	 *
	 * @param string $ip
	 *
	 * @return string|null
	 */
	public static function pack_ip( $ip ) {
		$bin = '' !== $ip ? @inet_pton( $ip ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $bin ) {
			return null;
		}

		if ( FW_Short_Links_Service::settings()['anonymize_ip'] ) {
			$bin = 4 === strlen( $bin )
				? substr( $bin, 0, 3 ) . "\0"
				: substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
		}

		return $bin;
	}

	/**
	 * @param string|null $bin
	 *
	 * @return string
	 */
	public static function unpack_ip( $bin ) {
		if ( null === $bin || '' === $bin ) {
			return '';
		}

		$ip = @inet_ntop( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return false === $ip ? '' : $ip;
	}

	/**
	 * @return bool
	 */
	public static function is_bot() {
		if ( ! FW_Short_Links_Service::settings()['bot_filter'] ) {
			return false;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		// Real browsers always send a user agent and an Accept header.
		if ( '' === trim( $ua ) || empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return true;
		}

		/** Filters the user-agent regex that marks a request as a bot. */
		if ( preg_match( (string) apply_filters( 'fw_ext_short_links_bot_regex', self::BOT_REGEX ), $ua ) ) {
			return true;
		}

		foreach ( FW_Short_Links_Service::lines( FW_Short_Links_Service::settings()['bot_patterns'] ) as $needle ) {
			if ( strlen( $needle ) >= 3 && false !== stripos( $ua, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Device / browser / OS families from the user agent. Families only — the
	 * raw user agent is never stored.
	 *
	 * @param string $ua
	 *
	 * @return array { device, browser, os }
	 */
	public static function parse_ua( $ua ) {
		$device = 'desktop';

		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle|(Android(?!.*Mobile))/i', $ua ) ) {
			$device = 'tablet';
		} elseif ( preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua ) ) {
			$device = 'mobile';
		}

		$os = 'Other';
		foreach ( array(
			'iOS'       => '/iPhone|iPad|iPod/i',
			'Android'   => '/Android/i',
			'Windows'   => '/Windows/i',
			'macOS'     => '/Mac OS X|Macintosh/i',
			'ChromeOS'  => '/CrOS/i',
			'Linux'     => '/Linux/i',
		) as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$os = $name;
				break;
			}
		}

		$browser = 'Other';
		foreach ( array(
			'Edge'             => '/Edg(e|A|iOS)?\//',
			'Opera'            => '/OPR\/|Opera/',
			'Samsung Internet' => '/SamsungBrowser/',
			'Firefox'          => '/Firefox\/|FxiOS/',
			'Chrome'           => '/Chrome\/|CriOS/',
			'Safari'           => '/Safari\//',
		) as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$browser = $name;
				break;
			}
		}

		return array( 'device' => $device, 'browser' => $browser, 'os' => $os );
	}

	/**
	 * Country from headers a CDN / host already set. Never a remote lookup.
	 *
	 * @return string ISO-3166 alpha-2 or ''
	 */
	public static function country() {
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'GEOIP_COUNTRY_CODE' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$c = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER[ $h ] ), 0, 2 ) );

				if ( 2 === strlen( $c ) && ! in_array( $c, array( 'XX', 'T1', 'ZZ' ), true ) ) {
					return $c;
				}
			}
		}

		return '';
	}

	/* ---------------------------------------------------------------------- *
	 * The click
	 * ---------------------------------------------------------------------- */

	/**
	 * Decide whether this request is counted, set the cookies (they must go out
	 * with the redirect headers), and queue the write for after the response.
	 *
	 * @param object $link
	 */
	public static function track( $link ) {
		$s    = FW_Short_Links_Service::settings();
		$mode = $s['tracking_mode'];

		$should = 'off' !== $mode && $link->track && 'GET' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		/** Filters whether this click is recorded. */
		$should = (bool) apply_filters( 'fw_ext_short_links_should_track', $should, $link );

		if ( ! $should || self::is_bot() ) {
			return;
		}

		// People who make links test them; those clicks are not traffic.
		if ( ! $s['track_editors'] && is_user_logged_in() && FW_Short_Links_Service::can_edit() ) {
			return;
		}

		$ip = self::client_ip();

		if ( '' !== $ip && self::ip_in_list( $ip, FW_Short_Links_Service::lines( $s['excluded_ips'] ) ) ) {
			return;
		}

		$ua         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$cookie_hit = false;

		// The visitor id is a hash that is stable for a day and cannot be turned
		// back into an IP (the salt rotates daily and is never stored with it).
		// When cookies are on it is also saved in a cookie, so a returning
		// visitor keeps the same id after the day rolls over. Using the hash
		// rather than a random id for a visitor who sent no cookie means a
		// browser that refuses cookies is not a new unique on every click.
		$visitor = 'h' . substr( hash_hmac( 'sha256', $ip . '|' . $ua, wp_salt( 'nonce' ) . gmdate( 'Y-m-d' ) ), 0, 40 );

		if ( $s['use_cookies'] ) {
			$sent = isset( $_COOKIE[ self::VISITOR_COOKIE ] ) ? (string) $_COOKIE[ self::VISITOR_COOKIE ] : '';

			if ( preg_match( '/^h[a-f0-9]{40}$/', $sent ) ) {
				$visitor = $sent;
			}

			$cookie_hit = isset( $_COOKIE[ self::LINK_COOKIE . $link->id ] );

			if ( ! headers_sent() ) {
				$secure = is_ssl();
				setcookie( self::VISITOR_COOKIE, $visitor, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, true );
				setcookie( self::LINK_COOKIE . $link->id, '1', time() + self::UNIQUE_WINDOW * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, true );
			}
		}

		$click = array(
			'link_id'    => $link->id,
			'mode'       => $mode,
			'cookie_hit' => $cookie_hit,
			'visitor'    => $visitor,
			'ip'         => self::pack_ip( $ip ),
			'referrer'   => isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 255 ) : '',
			'country'    => self::country(),
			'query'      => isset( $_SERVER['QUERY_STRING'] ) ? substr( (string) $_SERVER['QUERY_STRING'], 0, 255 ) : '',
			'user_id'    => get_current_user_id(),
		) + self::parse_ua( $ua );

		// Write after the visitor has their redirect: flush first, then insert.
		register_shutdown_function( function () use ( $click ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}

			FW_Short_Links_Tracker::write( $click );
		} );
	}

	/**
	 * @param array $click
	 */
	public static function write( array $click ) {
		global $wpdb;

		$table  = FW_Short_Links_Installer::table( 'clicks' );
		$unique = ! $click['cookie_hit'];

		// Full mode also checks the stored history, which catches a cleared
		// cookie (and is the only check for cookie-less visitors).
		if ( $unique && 'full' === $click['mode'] ) {
			$seen = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT 1 FROM {$table} WHERE link_id = %d AND visitor = %s AND created_at > %s LIMIT 1",
				$click['link_id'],
				$click['visitor'],
				gmdate( 'Y-m-d H:i:s', time() - self::UNIQUE_WINDOW * DAY_IN_SECONDS )
			) );

			$unique = ! $seen;
		}

		if ( 'full' === $click['mode'] ) {
			$row = array(
				'link_id'    => $click['link_id'],
				'created_at' => current_time( 'mysql', true ),
				'ip'         => $click['ip'],
				'visitor'    => $click['visitor'],
				'is_unique'  => $unique ? 1 : 0,
				'referrer'   => $click['referrer'],
				'country'    => $click['country'],
				'device'     => $click['device'],
				'browser'    => $click['browser'],
				'os'         => $click['os'],
				'query'      => $click['query'],
				'user_id'    => $click['user_id'],
			);

			$wpdb->insert( $table, $row );
			$click['id'] = (int) $wpdb->insert_id;
		}

		FW_Short_Links_Repository::increment( $click['link_id'], $unique );
		FW_Short_Links_Stats::bump( $click['link_id'], $unique );

		/** Fires after a click is recorded (counts-only mode passes no row id). */
		do_action( 'fw_ext_short_links_click', $click['link_id'], $click, $unique );
	}

	/* ---------------------------------------------------------------------- *
	 * Reads for the admin
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $link_id
	 * @param int $per_page
	 * @param int $page
	 *
	 * @return array { rows, total }
	 */
	public static function clicks_for( $link_id, $per_page = 50, $page = 1 ) {
		global $wpdb;

		$table = FW_Short_Links_Installer::table( 'clicks' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE link_id = %d", $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE link_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
			$link_id,
			$per_page,
			( max( 1, $page ) - 1 ) * $per_page
		) );

		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	/**
	 * Top values of one column for a link (referrer / country / device / browser / os).
	 *
	 * @param int    $link_id
	 * @param string $column
	 * @param int    $limit
	 *
	 * @return array value => count
	 */
	public static function breakdown( $link_id, $column, $limit = 5 ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'referrer', 'country', 'device', 'browser', 'os' ), true ) ) {
			return array();
		}

		$table = FW_Short_Links_Installer::table( 'clicks' );
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT {$column} AS v, COUNT(*) AS n FROM {$table} WHERE link_id = %d GROUP BY {$column} ORDER BY n DESC LIMIT %d",
			$link_id,
			$limit
		) );

		$out = array();

		foreach ( (array) $rows as $r ) {
			$out[ (string) $r->v ] = (int) $r->n;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Retention
	 * ---------------------------------------------------------------------- */

	/**
	 * @param bool $reschedule drop any existing event first (settings changed)
	 */
	public static function schedule_trim( $reschedule = false ) {
		if ( $reschedule ) {
			wp_clear_scheduled_hook( self::CRON_TRIM );
		}

		$days = (int) FW_Short_Links_Service::settings()['retention_days'];

		if ( $days > 0 && ! wp_next_scheduled( self::CRON_TRIM ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_TRIM );
		} elseif ( $days <= 0 && wp_next_scheduled( self::CRON_TRIM ) ) {
			wp_clear_scheduled_hook( self::CRON_TRIM );
		}
	}

	/**
	 * Delete click rows past the retention window, in batches. Link counters are
	 * lifetime totals and are left alone.
	 */
	public static function trim() {
		global $wpdb;

		$days = (int) FW_Short_Links_Service::settings()['retention_days'];

		if ( $days <= 0 ) {
			return;
		}

		$table  = FW_Short_Links_Installer::table( 'clicks' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		for ( $i = 0; $i < 20; $i++ ) {
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $n < 5000 ) {
				break;
			}
		}
	}
}
