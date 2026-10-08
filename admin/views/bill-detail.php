<?php
/**
 * One uploaded bill: what was read, and what the comparison gives today.
 *
 * @var array|null $bill
 */

defined( 'ABSPATH' ) || exit;

if ( ! $bill ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Bill not found', 'aec-energy-compare' ) . '</h1></div>';
	return;
}

$aec_symbol = AEC_Settings::get( 'currency_symbol' );
$aec_compare = AEC_Calculator::compare( $bill['profile'], array( 'limit' => 5 ) );

$aec_labels = array(
	'peak_kwh'            => __( 'Peak (kWh)', 'aec-energy-compare' ),
	'shoulder_kwh'        => __( 'Shoulder (kWh)', 'aec-energy-compare' ),
	'offpeak_kwh'         => __( 'Off-peak (kWh)', 'aec-energy-compare' ),
	'total_kwh'           => __( 'Total (kWh)', 'aec-energy-compare' ),
	'controlled_load_kwh' => __( 'Controlled load (kWh)', 'aec-energy-compare' ),
	'solar_export_kwh'    => __( 'Solar export (kWh)', 'aec-energy-compare' ),
	'gas_mj'              => __( 'Gas (MJ)', 'aec-energy-compare' ),
	'demand_kw'           => __( 'Demand (kW)', 'aec-energy-compare' ),
);
?>
<div class="wrap aec-wrap">
	<h1 class="wp-heading-inline">
		<?php
		printf(
			/* translators: %d: bill id */
			esc_html__( 'Bill #%d', 'aec-energy-compare' ),
			(int) $bill['id']
		);
		?>
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-bills' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Back to list', 'aec-energy-compare' ); ?>
	</a>
	<hr class="wp-header-end">

	<div class="aec-columns">
		<div class="aec-col">
			<h2><?php esc_html_e( 'What was read', 'aec-energy-compare' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr><th><?php esc_html_e( 'Uploaded', 'aec-energy-compare' ); ?></th><td><?php echo esc_html( mysql2date( 'j M Y H:i', $bill['created_at'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Original file', 'aec-energy-compare' ); ?></th><td><?php echo esc_html( $bill['original_name'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Read by', 'aec-energy-compare' ); ?></th><td><?php echo esc_html( $bill['extractor'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Fuel', 'aec-energy-compare' ); ?></th><td><?php echo esc_html( ucfirst( $bill['fuel'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Retailer', 'aec-energy-compare' ); ?></th><td><?php echo esc_html( $bill['retailer_name'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Plan', 'aec-energy-compare' ); ?></th><td><?php echo esc_html( $bill['plan_name'] ); ?></td></tr>
					<tr><th>NMI / MIRN</th><td><?php echo esc_html( $bill['nmi'] . $bill['mirn'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Postcode / state', 'aec-energy-compare' ); ?></th><td><?php echo esc_html( trim( $bill['postcode'] . ' ' . $bill['state'] ) ); ?></td></tr>
					<tr>
						<th><?php esc_html_e( 'Period', 'aec-energy-compare' ); ?></th>
						<td>
							<?php
							echo esc_html(
								trim(
									( $bill['period_start'] ? mysql2date( 'j M Y', $bill['period_start'] ) : '?' )
									. ' - '
									. ( $bill['period_end'] ? mysql2date( 'j M Y', $bill['period_end'] ) : '?' )
								)
							);
							?>
							<?php if ( $bill['billing_days'] ) : ?>
								(<?php echo esc_html( $bill['billing_days'] ); ?> <?php esc_html_e( 'days', 'aec-energy-compare' ); ?>)
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bill total', 'aec-energy-compare' ); ?></th>
						<td>
							<?php
							echo null === $bill['total_amount']
								? '-'
								: esc_html( $aec_symbol . number_format( (float) $bill['total_amount'], 2 ) );
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Usage', 'aec-energy-compare' ); ?></h3>
			<table class="widefat striped">
				<tbody>
				<?php
				$aec_any = false;
				foreach ( $aec_labels as $aec_key => $aec_label ) :
					if ( ! isset( $bill['usage'][ $aec_key ] ) ) {
						continue;
					}
					$aec_any = true;
					$aec_conf = $bill['confidence'][ 'usage.' . $aec_key ] ?? '';
					?>
					<tr>
						<th><?php echo esc_html( $aec_label ); ?></th>
						<td>
							<?php echo esc_html( AEC_Calculator::trim_number( $bill['usage'][ $aec_key ] ) ); ?>
							<?php if ( 'high' !== $aec_conf ) : ?>
								<span class="aec-pill aec-pill--warn"><?php echo esc_html( $aec_conf ? $aec_conf : 'unknown' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $aec_any ) : ?>
					<tr><td><?php esc_html_e( 'No usage figures were recorded.', 'aec-energy-compare' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $bill['rates'] ) : ?>
				<h3><?php esc_html_e( 'Rates read off the bill', 'aec-energy-compare' ); ?></h3>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( $bill['rates'] as $aec_key => $aec_value ) : ?>
						<tr>
							<th><?php echo esc_html( str_replace( '_', ' ', $aec_key ) ); ?></th>
							<td><?php echo esc_html( AEC_Calculator::trim_number( $aec_value ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="aec-col">
			<h2><?php esc_html_e( 'Comparison as it stands now', 'aec-energy-compare' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of plans */
					esc_html__( 'Run against %d matching plans using today\'s rates.', 'aec-energy-compare' ),
					(int) $aec_compare['plans_tested']
				);
				?>
			</p>

			<?php if ( ! $aec_compare['results'] ) : ?>
				<p><?php esc_html_e( 'No plans matched this bill. Check that you have active plans for its fuel, state and postcode.', 'aec-energy-compare' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plan', 'aec-energy-compare' ); ?></th>
							<th><?php esc_html_e( 'Annual', 'aec-energy-compare' ); ?></th>
							<th><?php esc_html_e( 'Saving', 'aec-energy-compare' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $aec_compare['results'] as $aec_row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $aec_row['retailer_name'] ); ?></strong><br>
								<?php echo esc_html( $aec_row['plan_name'] ); ?>
								<?php if ( $aec_row['estimated'] ) : ?>
									<span class="aec-pill aec-pill--warn"><?php esc_html_e( 'estimate', 'aec-energy-compare' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $aec_symbol . number_format( $aec_row['annual_cost'], 2 ) ); ?></td>
							<td>
								<?php
								echo null === $aec_row['annual_saving']
									? '-'
									: esc_html( $aec_symbol . number_format( $aec_row['annual_saving'], 2 ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $aec_compare['skipped'] ) : ?>
				<h3><?php esc_html_e( 'Plans that could not be costed', 'aec-energy-compare' ); ?></h3>
				<ul class="aec-skipped">
					<?php foreach ( $aec_compare['skipped'] as $aec_skip ) : ?>
						<li>
							<strong><?php echo esc_html( $aec_skip['retailer'] . ' ' . $aec_skip['plan'] ); ?></strong>
							<span><?php echo esc_html( $aec_skip['reason'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $bill['raw_text'] ) ) : ?>
				<h3><?php esc_html_e( 'Text read from the PDF', 'aec-energy-compare' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Kept so the extraction rules can be tuned against real layouts. Switch this off in settings if you would rather not store it.', 'aec-energy-compare' ); ?></p>
				<details>
					<summary><?php esc_html_e( 'Show text', 'aec-energy-compare' ); ?></summary>
					<pre class="aec-raw"><?php echo esc_html( $bill['raw_text'] ); ?></pre>
				</details>
			<?php endif; ?>
		</div>
	</div>
</div>
