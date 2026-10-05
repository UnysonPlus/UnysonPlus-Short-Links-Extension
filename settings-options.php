<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Short Links settings. Rendered on the extension's own Settings tab
 * (Unyson+ → Short Links → Settings) and by the Extensions manager, so it stays a
 * plain options array with no side effects. Defaults here must match
 * FW_Short_Links_Service::settings().
 */

$options = array(
	'box_links' => array(
		'type'    => 'box',
		'title'   => __( 'Links', 'fw' ),
		'options' => array(
			'group_links' => array(
				'type'    => 'group',
				'options' => array(
					'default_redirect'  => array(
						'type'    => 'select',
						'label'   => __( 'Default redirect type', 'fw' ),
						'desc'    => __( 'Pre-selected for new links. 307 is temporary and keeps the request method, so you can repoint the link later.', 'fw' ),
						'value'   => '307',
						'choices' => array(
							'307' => __( '307 Temporary (recommended)', 'fw' ),
							'302' => __( '302 Found (temporary)', 'fw' ),
							'301' => __( '301 Moved Permanently', 'fw' ),
							'308' => __( '308 Permanent Redirect', 'fw' ),
						),
					),
					'link_prefix'       => array(
						'type'  => 'text',
						'label' => __( 'Link prefix', 'fw' ),
						'desc'  => __( 'Optional path every short link lives under, e.g. "go" gives yoursite.com/go/deal. Leave empty for yoursite.com/deal. Changing it moves every existing link to the new address.', 'fw' ),
						'value' => '',
					),
					'slug_length'       => array(
						'type'  => 'short-text',
						'label' => __( 'Generated slug length', 'fw' ),
						'desc'  => __( 'Characters in a generated slug (3–20). Generated slugs skip look-alike characters such as 0/o and 1/l.', 'fw' ),
						'value' => '5',
					),
					'default_nofollow'  => array(
						'type'  => 'switch',
						'label' => __( 'Nofollow new links', 'fw' ),
						'desc'  => __( 'Ask search engines not to follow or index new links.', 'fw' ),
						'value' => true,
					),
					'default_sponsored' => array(
						'type'  => 'switch',
						'label' => __( 'Mark new links as sponsored', 'fw' ),
						'desc'  => __( 'For affiliate and paid links.', 'fw' ),
						'value' => false,
					),
					'reserved_patterns' => array(
						'type'  => 'textarea',
						'label' => __( 'Extra reserved slugs', 'fw' ),
						'desc'  => __( 'One per line; * is a wildcard (e.g. shop/*). WordPress\'s own paths — wp-admin, wp-json, feeds, sitemaps and so on — are always reserved.', 'fw' ),
						'value' => '',
					),
				),
			),
		),
	),
	'box_tracking' => array(
		'type'    => 'box',
		'title'   => __( 'Click tracking', 'fw' ),
		'options' => array(
			'group_tracking' => array(
				'type'    => 'group',
				'options' => array(
					'tracking_mode'   => array(
						'type'    => 'select',
						'label'   => __( 'Tracking', 'fw' ),
						'desc'    => __( 'Full keeps one row per click (time, referrer, country, device). Totals only keeps just the click and unique counts. Switching never deletes data.', 'fw' ),
						'value'   => 'full',
						'choices' => array(
							'full'   => __( 'Full — click history', 'fw' ),
							'counts' => __( 'Totals only', 'fw' ),
							'off'    => __( 'Off', 'fw' ),
						),
					),
					'default_track'   => array(
						'type'  => 'switch',
						'label' => __( 'Track new links', 'fw' ),
						'desc'  => __( 'The default for the per-link "Track clicks" box.', 'fw' ),
						'value' => true,
					),
					'track_editors'   => array(
						'type'  => 'switch',
						'label' => __( 'Count clicks from link editors', 'fw' ),
						'desc'  => __( 'Off by default, so testing your own links does not inflate the numbers.', 'fw' ),
						'value' => false,
					),
					'use_cookies'     => array(
						'type'  => 'switch',
						'label' => __( 'Use cookies for unique visitors', 'fw' ),
						'desc'  => __( 'Sets two small first-party cookies. Off: uniques are estimated from a daily-salted hash that cannot be reversed to an IP.', 'fw' ),
						'value' => true,
					),
					'anonymize_ip'    => array(
						'type'  => 'switch',
						'label' => __( 'Anonymize IP addresses', 'fw' ),
						'desc'  => __( 'Store IPs with the last part zeroed (1.2.3.0). Recommended for privacy law compliance.', 'fw' ),
						'value' => true,
					),
					'retention_days'  => array(
						'type'    => 'select',
						'label'   => __( 'Keep click history for', 'fw' ),
						'desc'    => __( 'Older click rows are deleted nightly. Link totals are kept.', 'fw' ),
						'value'   => '0',
						'choices' => array(
							'0'   => __( 'Forever', 'fw' ),
							'30'  => __( '30 days', 'fw' ),
							'90'  => __( '90 days', 'fw' ),
							'180' => __( '180 days', 'fw' ),
							'365' => __( '1 year', 'fw' ),
							'730' => __( '2 years', 'fw' ),
						),
					),
				),
			),
		),
	),
	'box_filtering' => array(
		'type'    => 'box',
		'title'   => __( 'Bots, exclusions and proxies', 'fw' ),
		'options' => array(
			'group_filtering' => array(
				'type'    => 'group',
				'options' => array(
					'bot_filter'      => array(
						'type'  => 'switch',
						'label' => __( 'Ignore bots', 'fw' ),
						'desc'  => __( 'Don\'t count search crawlers, link previews, uptime monitors, AI fetchers and scripts. They are still redirected.', 'fw' ),
						'value' => true,
					),
					'bot_patterns'    => array(
						'type'  => 'textarea',
						'label' => __( 'Extra bot user agents', 'fw' ),
						'desc'  => __( 'One per line. Any user agent containing one of these (3+ characters) is treated as a bot.', 'fw' ),
						'value' => '',
					),
					'excluded_ips'    => array(
						'type'  => 'textarea',
						'label' => __( 'Excluded IPs', 'fw' ),
						'desc'  => __( 'Clicks from these are never counted. One per line: an address (203.0.113.7) or a range (203.0.113.0/24, 2001:db8::/32).', 'fw' ),
						'value' => '',
					),
					'trusted_proxies' => array(
						'type'  => 'textarea',
						'label' => __( 'Trusted proxies', 'fw' ),
						'desc'  => __( 'Only if your site sits behind a CDN or load balancer: list its addresses or ranges, and the visitor IP is read from the forwarded headers it adds. Leave empty otherwise — those headers are easy to fake.', 'fw' ),
						'value' => '',
					),
				),
			),
		),
	),
);
