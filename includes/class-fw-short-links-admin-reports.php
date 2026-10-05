<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Short Links → Reports: totals, clicks per day, top links, and breakdowns
 * across every link for a chosen range. Reads the daily rollup, so it is fast
 * at any volume; breakdowns read raw clicks and so cover only what the
 * retention setting has kept.
 *
 * The chart is plain inline SVG — no chart library to load for one bar chart.
 */
class FW_Short_Links_Admin_Reports {

	const RANGES = array( 7, 30, 90, 365 );

	public static function render() {
		$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days = in_array( $days, self::RANGES, true ) ? $days : 30;
		$to   = FW_Short_Links_Stats::local_day();
		$from = wp_date( 'Y-m-d', strtotime( $to . ' 12:00:00 UTC' ) - ( $days - 1 ) * DAY_IN_SECONDS );
		$data = FW_Short_Links_Stats::series( null, $from, $to );
		$top  = FW_Short_Links_Stats::top_links( $from, $to, 10 );
		$avg  = $days ? $data['clicks'] / $days : 0;
		?>
		<div class="fw-sl-report">
			<div class="fw-sl-report__bar">
				<span class="fw-sl-report__range"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $from . ' 12:00:00' ) ) . ' – ' . date_i18n( get_option( 'date_format' ), strtotime( $to . ' 12:00:00' ) ) ); ?></span>
				<span class="subsubsub fw-sl-report__ranges">
					<?php
					$links = array();
					foreach ( self::RANGES as $r ) {
						$label   = 365 === $r ? __( '12 months', 'fw' ) : sprintf( _n( '%d day', '%d days', $r, 'fw' ), $r );
						$links[] = sprintf(
							'<a href="%s"%s>%s</a>',
							esc_url( add_query_arg( 'days', $r, FW_Short_Links_Admin_Page::page_url( 'reports' ) ) ),
							$r === $days ? ' class="current" aria-current="page"' : '',
							esc_html( $label )
						);
					}
					echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</span>
			</div>

			<div class="fw-sl-kpis">
				<div class="fw-sl-kpi"><span class="fw-sl-kpi__n"><?php echo esc_html( number_format_i18n( $data['clicks'] ) ); ?></span><span class="fw-sl-kpi__l"><?php esc_html_e( 'Clicks', 'fw' ); ?></span></div>
				<div class="fw-sl-kpi"><span class="fw-sl-kpi__n"><?php echo esc_html( number_format_i18n( $data['uniques'] ) ); ?></span><span class="fw-sl-kpi__l"><?php esc_html_e( 'Unique visitors', 'fw' ); ?></span></div>
				<div class="fw-sl-kpi"><span class="fw-sl-kpi__n"><?php echo esc_html( number_format_i18n( $avg, $avg < 10 ? 1 : 0 ) ); ?></span><span class="fw-sl-kpi__l"><?php esc_html_e( 'Clicks per day', 'fw' ); ?></span></div>
				<div class="fw-sl-kpi"><span class="fw-sl-kpi__n"><?php echo esc_html( number_format_i18n( $data['links'] ) ); ?></span><span class="fw-sl-kpi__l"><?php esc_html_e( 'Links clicked', 'fw' ); ?></span></div>
			</div>

			<div class="fw-sl-panel">
				<h2><?php esc_html_e( 'Clicks per day', 'fw' ); ?></h2>
				<?php self::chart( $data['series'] ); ?>
			</div>

			<div class="fw-sl-report__grid">
				<div class="fw-sl-panel">
					<h2><?php esc_html_e( 'Top links', 'fw' ); ?></h2>
					<?php if ( ! $top ) : ?>
						<p class="description"><?php esc_html_e( 'No clicks in this period.', 'fw' ); ?></p>
					<?php else : ?>
						<table class="widefat striped fw-sl-top">
							<thead><tr><th><?php esc_html_e( 'Link', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th><th class="num"><?php esc_html_e( 'Unique', 'fw' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $top as $t ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( FW_Short_Links_Admin_Page::edit_url( $t['id'] ) ); ?>"><?php echo esc_html( '' !== $t['title'] ? $t['title'] : '/' . $t['slug'] ); ?></a><br><code>/<?php echo esc_html( $t['slug'] ); ?></code></td>
									<td class="num"><?php echo esc_html( number_format_i18n( $t['clicks'] ) ); ?></td>
									<td class="num"><?php echo esc_html( number_format_i18n( $t['uniques'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="fw-sl-panel">
					<h2><?php esc_html_e( 'Where clicks came from', 'fw' ); ?></h2>
					<div class="fw-sl-breakdowns fw-sl-breakdowns--tight">
						<?php
						$groups = array(
							'referrer' => __( 'Referrers', 'fw' ),
							'country'  => __( 'Countries', 'fw' ),
							'device'   => __( 'Devices', 'fw' ),
							'browser'  => __( 'Browsers', 'fw' ),
						);
						foreach ( $groups as $col => $label ) :
							$rows = FW_Short_Links_Stats::breakdown( $col, null, $from, $to, 6 );
							?>
							<div class="fw-sl-breakdown">
								<h3><?php echo esc_html( $label ); ?></h3>
								<?php if ( ! $rows ) : ?>
									<p class="description"><?php esc_html_e( 'No data', 'fw' ); ?></p>
								<?php else : ?>
									<ul>
										<?php foreach ( $rows as $r ) : ?>
											<li><span><?php echo esc_html( '' !== $r['value'] ? $r['value'] : ( 'referrer' === $col ? __( '(direct)', 'fw' ) : __( '(unknown)', 'fw' ) ) ); ?></span><b><?php echo esc_html( number_format_i18n( $r['clicks'] ) ); ?></b></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<?php if ( 'full' !== FW_Short_Links_Service::settings()['tracking_mode'] ) : ?>
						<p class="description"><?php esc_html_e( 'Breakdowns need full tracking (Settings → Tracking). Totals and the chart work in every mode.', 'fw' ); ?></p>
					<?php elseif ( (int) FW_Short_Links_Service::settings()['retention_days'] > 0 ) : ?>
						<p class="description"><?php printf( esc_html__( 'Breakdowns cover the click history you keep (%d days). Totals and the chart cover everything.', 'fw' ), (int) FW_Short_Links_Service::settings()['retention_days'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * A bar chart of clicks per day, uniques as a darker inner bar. Each day
	 * has a <title> so hovering shows the exact numbers, and the whole chart
	 * has a text alternative for screen readers.
	 *
	 * @param array $series [ {date, clicks, uniques} ]
	 */
	private static function chart( array $series ) {
		$n     = count( $series );
		$max   = max( 1, max( wp_list_pluck( $series, 'clicks' ) ) );
		$scale = self::nice( $max );
		$w     = 1000;
		$h     = 220;
		$pad_l = 44;
		$pad_b = 24;
		$plot  = $w - $pad_l;
		$slot  = $plot / max( 1, $n );
		$bar   = max( 1, min( 28, $slot * 0.72 ) );
		$total = array_sum( wp_list_pluck( $series, 'clicks' ) );
		$label = sprintf( __( 'Clicks per day: %1$s clicks over %2$d days, highest %3$s in a day.', 'fw' ), number_format_i18n( $total ), $n, number_format_i18n( $max ) );
		$every = max( 1, (int) ceil( $n / 8 ) );
		?>
		<svg class="fw-sl-chart" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) ( $h + $pad_b ); ?>" role="img" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php for ( $g = 0; $g <= 4; $g++ ) : ?>
				<?php $y = $h - ( $h - 10 ) * $g / 4; ?>
				<line class="fw-sl-chart__grid" x1="<?php echo (int) $pad_l; ?>" x2="<?php echo (int) $w; ?>" y1="<?php echo esc_attr( $y ); ?>" y2="<?php echo esc_attr( $y ); ?>" />
				<text class="fw-sl-chart__axis" x="<?php echo (int) ( $pad_l - 8 ); ?>" y="<?php echo esc_attr( $y + 4 ); ?>" text-anchor="end"><?php echo esc_html( number_format_i18n( $scale * $g / 4 ) ); ?></text>
			<?php endfor; ?>
			<?php foreach ( $series as $i => $d ) : ?>
				<?php
				$x  = $pad_l + $slot * $i + ( $slot - $bar ) / 2;
				$ch = ( $h - 10 ) * $d['clicks'] / $scale;
				$uh = ( $h - 10 ) * $d['uniques'] / $scale;
				$ts = strtotime( $d['date'] . ' 12:00:00' );
				?>
				<g>
					<title><?php echo esc_html( date_i18n( get_option( 'date_format' ), $ts ) . ': ' . sprintf( __( '%1$s clicks, %2$s unique', 'fw' ), number_format_i18n( $d['clicks'] ), number_format_i18n( $d['uniques'] ) ) ); ?></title>
					<rect class="fw-sl-chart__hit" x="<?php echo esc_attr( $pad_l + $slot * $i ); ?>" y="0" width="<?php echo esc_attr( $slot ); ?>" height="<?php echo (int) $h; ?>" />
					<?php if ( $d['clicks'] ) : ?>
						<rect class="fw-sl-chart__bar" x="<?php echo esc_attr( $x ); ?>" y="<?php echo esc_attr( $h - $ch ); ?>" width="<?php echo esc_attr( $bar ); ?>" height="<?php echo esc_attr( $ch ); ?>" rx="2" />
						<rect class="fw-sl-chart__bar fw-sl-chart__bar--u" x="<?php echo esc_attr( $x ); ?>" y="<?php echo esc_attr( $h - $uh ); ?>" width="<?php echo esc_attr( $bar ); ?>" height="<?php echo esc_attr( $uh ); ?>" rx="2" />
					<?php endif; ?>
				</g>
				<?php if ( 0 === $i % $every ) : ?>
					<text class="fw-sl-chart__axis" x="<?php echo esc_attr( $pad_l + $slot * $i + $slot / 2 ); ?>" y="<?php echo (int) ( $h + 17 ); ?>" text-anchor="middle"><?php echo esc_html( date_i18n( 'M j', $ts ) ); ?></text>
				<?php endif; ?>
			<?php endforeach; ?>
		</svg>
		<p class="fw-sl-chart__legend">
			<span class="fw-sl-swatch"></span><?php esc_html_e( 'Clicks', 'fw' ); ?>
			<span class="fw-sl-swatch fw-sl-swatch--u"></span><?php esc_html_e( 'Unique visitors', 'fw' ); ?>
		</p>
		<?php
	}

	/**
	 * A round axis top that splits into four round steps: the step is the
	 * first of 1, 2, 2.5, 5 × 10^n (whole numbers only) that covers a quarter of $max, so the gridlines
	 * read 0, 5, 10, 15, 20 — never 1.25, 2.5, 3.75.
	 *
	 * @param int $max
	 *
	 * @return int
	 */
	private static function nice( $max ) {
		$quarter = max( 1, $max ) / 4;
		$p       = pow( 10, floor( log10( $quarter ) ) );

		foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) {
			$step = max( 1, $m * $p );

			// 2.5 only where it lands on a whole number (25, 250 …).
			if ( $step >= $quarter && floor( $step ) == $step ) { // phpcs:ignore Universal.Operators.StrictComparisons
				return (int) ( 4 * $step );
			}
		}

		return (int) ( 40 * $p );
	}
}
