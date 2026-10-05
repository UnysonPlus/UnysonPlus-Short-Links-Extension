<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Short Links → Import / Export.
 *
 * Export streams a CSV or JSON file. Import goes upload → review (the dry
 * run's counts and every problem it found, nothing written yet) → run, with
 * the browser driving one chunk per AJAX call so a big file never hits a
 * request time limit. The same flow imports from another link plugin's tables
 * when they exist on this site.
 */
class FW_Short_Links_Admin_Transfer {

	const AJAX_STEP = 'fw_short_links_import_step';

	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_STEP, array( $this, '_ajax_step' ) );
	}

	/**
	 * Handle a POST/GET action from the admin page's load hook. Export and
	 * the sample stream and exit; everything else returns where to go next.
	 *
	 * @param string $action
	 *
	 * @return array|null [ type, message, url ] or null when not ours
	 */
	public static function handle( $action ) {
		$url = FW_Short_Links_Admin_Page::page_url( 'transfer' );

		switch ( $action ) {
			case 'export':
				$format   = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv'; // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked by the caller
				$category = isset( $_GET['category'] ) ? (int) $_GET['category'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
				FW_Short_Links_Transfer::export( $format, FW_Short_Links_Service::can_manage() ? 0 : get_current_user_id(), $category );
				return null; // exits

			case 'sample':
				FW_Short_Links_Transfer::sample();
				return null; // exits

			case 'import_upload':
				if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					return array( 'error', __( 'Choose a CSV or JSON file to import.', 'fw' ), $url );
				}

				if ( (int) $_FILES['file']['size'] > 20 * MB_IN_BYTES ) {
					return array( 'error', __( 'That file is larger than 20 MB. Split it into smaller files.', 'fw' ), $url );
				}

				$rows = FW_Short_Links_Transfer::read_file( $_FILES['file']['tmp_name'], sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

				if ( is_wp_error( $rows ) ) {
					return array( 'error', $rows->get_error_message(), $url );
				}

				if ( ! $rows ) {
					return array( 'error', __( 'The file has no links in it.', 'fw' ), $url );
				}

				$job = FW_Short_Links_Transfer::create_job( $rows, array(
					'policy'        => isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : 'skip',
					'import_counts' => ! empty( $_POST['import_counts'] ),
					'source'        => 'file',
					'label'         => sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				) );

				return array( '', '', add_query_arg( 'job', $job['id'], $url ) );

			case 'import_migrate':
				$rows = FW_Short_Links_Transfer::read_source( ! empty( $_POST['include_trashed'] ) );

				if ( ! $rows ) {
					return array( 'error', __( 'No links were found to import.', 'fw' ), $url );
				}

				$job = FW_Short_Links_Transfer::create_job( $rows, array(
					'policy'         => isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : 'skip',
					'import_counts'  => ! empty( $_POST['import_counts'] ),
					'include_clicks' => ! empty( $_POST['include_clicks'] ),
					'source'         => 'plugin',
					'label'          => __( 'another link plugin', 'fw' ),
				) );

				return array( '', '', add_query_arg( 'job', $job['id'], $url ) );

			case 'import_cancel':
				FW_Short_Links_Transfer::cancel( isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '' );

				return array( 'warning', __( 'Import cancelled. Rows already imported were kept.', 'fw' ), $url );
		}

		return null;
	}

	/**
	 * @internal
	 */
	public function _ajax_step() {
		check_ajax_referer( FW_Short_Links_Admin_Page::NONCE, 'nonce' );

		if ( ! FW_Short_Links_Service::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'fw' ) ), 403 );
		}

		$job = FW_Short_Links_Transfer::step( isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '' );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}

		wp_send_json_success( array(
			'phase'  => $job['phase'],
			'done'   => $job['done'],
			'total'  => $job['total'],
			'result' => $job['result'],
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	public static function render() {
		$job_id = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job    = '' !== $job_id ? FW_Short_Links_Transfer::job( $job_id ) : null;

		if ( $job ) {
			self::render_job( $job );

			return;
		}

		if ( '' !== $job_id ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'That import has expired. Start it again.', 'fw' ) . '</p></div>';
		}

		$source = FW_Short_Links_Transfer::detect_source();
		?>
		<div class="fw-sl-transfer">
			<div class="fw-sl-panel">
				<h2><?php esc_html_e( 'Export', 'fw' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Every link that is not in the trash. CSV opens in any spreadsheet; JSON keeps everything (categories and extra data) for moving links to another site.', 'fw' ); ?></p>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fw-sl-inline-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( FW_Short_Links_Admin_Page::PAGE_SLUG ); ?>" />
					<input type="hidden" name="fw_sl_action" value="export" />
					<?php wp_nonce_field( FW_Short_Links_Admin_Page::NONCE, '_wpnonce', false ); ?>
					<select name="format">
						<option value="csv"><?php esc_html_e( 'CSV', 'fw' ); ?></option>
						<option value="json"><?php esc_html_e( 'JSON', 'fw' ); ?></option>
					</select>
					<?php self::category_select( 'category', __( 'All categories', 'fw' ) ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Download', 'fw' ); ?></button>
				</form>
			</div>

			<div class="fw-sl-panel">
				<h2><?php esc_html_e( 'Import a file', 'fw' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'A CSV or JSON file from this extension, or any CSV with at least a target_url (or url) column. Nothing is written until you have reviewed what will happen.', 'fw' ); ?>
					<a href="<?php echo esc_url( FW_Short_Links_Admin_Page::action_url( 'sample', 0 ) ); ?>"><?php esc_html_e( 'Download a sample CSV', 'fw' ); ?></a>
				</p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( FW_Short_Links_Admin_Page::page_url( 'transfer' ) ); ?>">
					<?php wp_nonce_field( FW_Short_Links_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_sl_action" value="import_upload" />
					<p><input type="file" name="file" accept=".csv,.json,text/csv,application/json" required /></p>
					<?php self::policy_fields(); ?>
					<p><label><input type="checkbox" name="import_counts" value="1" /> <?php esc_html_e( 'Keep the click and unique totals from the file', 'fw' ); ?></label></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and review', 'fw' ); ?></button></p>
				</form>
			</div>

			<?php if ( $source ) : ?>
				<div class="fw-sl-panel fw-sl-panel--accent">
					<h2><?php esc_html_e( 'Import from another link plugin', 'fw' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: 1: number of links, 2: number of clicks */
							esc_html__( 'Link tables from another link-shortener plugin were found on this site: %1$s links and %2$s recorded clicks. They are read directly — nothing to export first — and are left untouched, so both can run side by side until you switch over.', 'fw' ),
							'<strong>' . esc_html( number_format_i18n( $source['links'] ) ) . '</strong>',
							'<strong>' . esc_html( number_format_i18n( $source['clicks'] ) ) . '</strong>'
						);
						?>
					</p>
					<p class="description"><?php esc_html_e( 'Kept: slug, destination, title, notes, 301/302/307, nofollow, sponsored, query forwarding, tracking, categories and totals. Framed, meta-refresh, JavaScript and similar redirect kinds become 307 (each is listed in the review). Payment links and links with no destination are left out. Deactivate the other plugin after importing so the two do not answer the same addresses.', 'fw' ); ?></p>
					<form method="post" action="<?php echo esc_url( FW_Short_Links_Admin_Page::page_url( 'transfer' ) ); ?>">
						<?php wp_nonce_field( FW_Short_Links_Admin_Page::NONCE ); ?>
						<input type="hidden" name="fw_sl_action" value="import_migrate" />
						<?php self::policy_fields(); ?>
						<p><label><input type="checkbox" name="import_counts" value="1" checked /> <?php esc_html_e( 'Keep each link\'s click and unique totals', 'fw' ); ?></label></p>
						<?php if ( $source['clicks'] ) : ?>
							<p><label><input type="checkbox" name="include_clicks" value="1" /> <?php printf( esc_html__( 'Also copy the click history (%s rows) so reports show past days', 'fw' ), esc_html( number_format_i18n( $source['clicks'] ) ) ); ?></label></p>
						<?php endif; ?>
						<?php if ( $source['has_deleted'] ) : ?>
							<p><label><input type="checkbox" name="include_trashed" value="1" /> <?php esc_html_e( 'Include links that are in its trash (imported into the trash here)', 'fw' ); ?></label></p>
						<?php endif; ?>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Review import', 'fw' ); ?></button></p>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function policy_fields() {
		?>
		<fieldset class="fw-sl-policy">
			<legend><?php esc_html_e( 'When a slug already exists here:', 'fw' ); ?></legend>
			<label><input type="radio" name="policy" value="skip" checked /> <?php esc_html_e( 'Skip it — keep the existing link', 'fw' ); ?></label>
			<label><input type="radio" name="policy" value="overwrite" /> <?php esc_html_e( 'Overwrite it with the imported values', 'fw' ); ?></label>
			<label><input type="radio" name="policy" value="rename" /> <?php esc_html_e( 'Import it under a new slug (slug-2, slug-3 …)', 'fw' ); ?></label>
		</fieldset>
		<?php
	}

	/**
	 * @param string $name
	 * @param string $all_label
	 */
	public static function category_select( $name, $all_label ) {
		$terms = FW_Short_Links_Categories::all();

		if ( ! $terms ) {
			return;
		}

		echo '<select name="' . esc_attr( $name ) . '"><option value="0">' . esc_html( $all_label ) . '</option>';

		foreach ( $terms as $t ) {
			echo '<option value="' . (int) $t->term_id . '">' . esc_html( $t->name ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Review (before anything is written), progress, or the final result.
	 *
	 * @param array $job
	 */
	private static function render_job( array $job ) {
		$plan     = $job['plan'];
		$policies = array(
			'skip'      => __( 'skip existing slugs', 'fw' ),
			'overwrite' => __( 'overwrite existing slugs', 'fw' ),
			'rename'    => __( 'rename on conflict', 'fw' ),
		);
		?>
		<div class="fw-sl-panel fw-sl-job" data-job="<?php echo esc_attr( $job['id'] ); ?>" data-phase="<?php echo esc_attr( $job['phase'] ); ?>">
			<h2>
				<?php
				/* translators: %s: file name or source */
				printf( esc_html__( 'Import from %s', 'fw' ), esc_html( $job['label'] ) );
				?>
			</h2>

			<?php if ( 'review' === $job['phase'] ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: rows, 2: policy */
						esc_html__( '%1$s links read; %2$s. Nothing has been written yet.', 'fw' ),
						'<strong>' . esc_html( number_format_i18n( $job['total'] ) ) . '</strong>',
						esc_html( $policies[ $job['policy'] ] )
					);
					?>
				</p>
				<div class="fw-sl-kpis fw-sl-kpis--small">
					<?php
					$cells = array(
						'create' => __( 'New', 'fw' ),
						'update' => __( 'Overwrite', 'fw' ),
						'rename' => __( 'Renamed', 'fw' ),
						'skip'   => __( 'Skipped', 'fw' ),
						'error'  => __( 'Can\'t import', 'fw' ),
					);
					foreach ( $cells as $k => $label ) :
						if ( ! $plan[ $k ] && in_array( $k, array( 'update', 'rename' ), true ) ) {
							continue;
						}
						?>
						<div class="fw-sl-kpi<?php echo 'error' === $k && $plan[ $k ] ? ' fw-sl-kpi--bad' : ''; ?>"><span class="fw-sl-kpi__n"><?php echo esc_html( number_format_i18n( $plan[ $k ] ) ); ?></span><span class="fw-sl-kpi__l"><?php echo esc_html( $label ); ?></span></div>
					<?php endforeach; ?>
				</div>

				<?php self::problems_table( $plan['problems'], __( 'Notes from the review', 'fw' ) ); ?>

				<div class="fw-sl-job__actions">
					<?php if ( $plan['create'] + $plan['update'] + $plan['rename'] ) : ?>
						<button type="button" class="button button-primary fw-sl-job__start"><?php esc_html_e( 'Import now', 'fw' ); ?></button>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( FW_Short_Links_Admin_Page::page_url( 'transfer' ) ); ?>" style="display:inline">
						<?php wp_nonce_field( FW_Short_Links_Admin_Page::NONCE ); ?>
						<input type="hidden" name="fw_sl_action" value="import_cancel" />
						<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>" />
						<button type="submit" class="button-link"><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<div class="fw-sl-job__progress"<?php echo in_array( $job['phase'], array( 'review', 'done' ), true ) ? ' hidden' : ''; ?>>
				<progress max="<?php echo (int) max( 1, $job['total'] ); ?>" value="<?php echo (int) $job['done']; ?>"></progress>
				<p class="fw-sl-job__status" aria-live="polite"></p>
			</div>

			<div class="fw-sl-job__result"<?php echo 'done' === $job['phase'] ? '' : ' hidden'; ?>>
				<?php self::result( $job ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array $job
	 */
	private static function result( array $job ) {
		$r = $job['result'];
		?>
		<p class="fw-sl-job__summary">
			<?php
			$parts = array_filter( array(
				$r['created'] ? sprintf( _n( '%s link created', '%s links created', $r['created'], 'fw' ), number_format_i18n( $r['created'] ) ) : '',
				$r['updated'] ? sprintf( _n( '%s updated', '%s updated', $r['updated'], 'fw' ), number_format_i18n( $r['updated'] ) ) : '',
				$r['renamed'] ? sprintf( _n( '%s imported under a new slug', '%s imported under new slugs', $r['renamed'], 'fw' ), number_format_i18n( $r['renamed'] ) ) : '',
				$r['skipped'] ? sprintf( _n( '%s skipped', '%s skipped', $r['skipped'], 'fw' ), number_format_i18n( $r['skipped'] ) ) : '',
				$r['failed'] ? sprintf( _n( '%s failed', '%s failed', $r['failed'], 'fw' ), number_format_i18n( $r['failed'] ) ) : '',
				$r['clicks'] ? sprintf( _n( '%s click copied', '%s clicks copied', $r['clicks'], 'fw' ), number_format_i18n( $r['clicks'] ) ) : '',
			) );
			echo esc_html( $parts ? implode( ' · ', $parts ) . '.' : __( 'Nothing to import.', 'fw' ) );
			?>
		</p>
		<?php self::problems_table( $job['errors'], __( 'Rows that failed', 'fw' ) ); ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( FW_Short_Links_Admin_Page::page_url() ); ?>"><?php esc_html_e( 'View links', 'fw' ); ?></a>
			<a class="button" href="<?php echo esc_url( FW_Short_Links_Admin_Page::page_url( 'transfer' ) ); ?>"><?php esc_html_e( 'Import more', 'fw' ); ?></a>
		</p>
		<?php
	}

	/**
	 * @param array  $rows [ {row, slug, message} ]
	 * @param string $title
	 */
	private static function problems_table( array $rows, $title ) {
		if ( ! $rows ) {
			return;
		}
		?>
		<details class="fw-sl-problems" open>
			<summary><?php echo esc_html( $title ); ?> (<?php echo (int) count( $rows ); ?>)</summary>
			<table class="widefat striped">
				<thead><tr><th style="width:5em"><?php esc_html_e( 'Row', 'fw' ); ?></th><th style="width:30%"><?php esc_html_e( 'Slug', 'fw' ); ?></th><th><?php esc_html_e( 'Note', 'fw' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $p ) : ?>
						<tr><td><?php echo (int) $p['row']; ?></td><td><code><?php echo esc_html( '' !== $p['slug'] ? $p['slug'] : '—' ); ?></code></td><td><?php echo esc_html( $p['message'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}
}
