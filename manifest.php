<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Short Links', 'fw' );
$manifest['slug']        = 'unysonplus-short-links';
$manifest['description'] = __(
	'Short, branded links on your own domain: yoursite.com/deal sends visitors to any URL with a 301, 302, 307 or 308 redirect. Counts clicks and unique visitors with bot filtering, IP exclusion and privacy-first defaults, and adds nofollow / sponsored handling, query-parameter forwarding, a links screen with categories and bulk actions, reports, CSV / JSON import and export (including straight from another link plugin), Quick Add, a [short_link] shortcode, and a REST API with API keys and signed webhooks for other apps.',
	'fw'
);

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.0.1 - Import / export, an API for other apps, webhooks, reports and
 *         categories. Export streams CSV or lossless JSON. Import is a job:
 *         a dry run lists every row as new, overwrite, rename, skip or
 *         failed before anything is written, then the browser drives it in
 *         chunks. The same flow reads another link plugin's tables directly
 *         (links, totals, categories, optionally click history) and leaves
 *         them untouched. API keys (Bearer) act as the user who made them,
 *         narrowed to read, write or stats scopes, and are rate limited.
 *         They work only on this extension's namespace and are stored as a
 *         hash. New routes: /links/batch, /links/{id}/stats, /stats and
 *         /categories, plus modified_since and view=any for syncing, ETag
 *         and If-Match versions, and an OpenAPI document generated from the
 *         routes. Webhooks are signed (HMAC-SHA256 over timestamp and body),
 *         queued and retried with backoff, cover link events and five-minute
 *         click batches, and send one summary per import. Reports read a new
 *         daily rollup (seeded from existing history on upgrade): totals, a
 *         clicks-per-day chart, top links and breakdowns. Link categories are
 *         a WordPress taxonomy, with a list column, filter and form picker.
 *         Click data is wired into the WordPress personal-data export and
 *         erase tools. The Extensions card's Settings link now opens this
 *         extension's own Settings tab, so there is one settings screen.
 *
 * 1.0.0 - First release. Links live in their own tables with a UNIQUE slug,
 *         so two links can never share an address. Redirects run on `init`
 *         before WordPress routes the request; ordinary page views are ruled
 *         out against an in-memory slug index, so they cost no query.
 *         Handles GET and HEAD, the four redirect codes, enabled / disabled
 *         with a per-link fallback URL, and query forwarding that keeps the
 *         incoming percent-encoding intact. Clicks are written after the
 *         response is flushed. They carry a referrer, a CDN-supplied country,
 *         device / browser / OS families and an anonymised IP. Uniques are
 *         counted by cookie, with a salted hash fallback. Forwarded-IP headers
 *         are honoured only from configured trusted proxies. Slugs are checked
 *         against reserved WordPress paths and existing posts, pages and
 *         terms. Includes a links screen (views, search, sort, bulk actions,
 *         trash, duplicate, reset), per-link click history, Quick Add from the
 *         admin bar and dashboard, the [short_link] shortcode, a REST API
 *         (`fw-short-links/v1`) with optimistic version checks, and the
 *         `edit_short_links` / `manage_short_links` capabilities.
 */

$manifest['version']    = '1.0.2';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Short-Links-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Short-Links-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
