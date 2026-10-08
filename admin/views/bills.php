<?php
/**
 * Uploaded bill list.
 */

defined( 'ABSPATH' ) || exit;

$aec_search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$aec_fuel     = isset( $_GET['fuel'] ) ? sanitize_key( $_GET['fuel'] ) : '';
$aec_paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$aec_per_page = 20;

$aec_filters = array(
	'search' => $aec_search,
	'fuel'   => in_array( $aec_fuel, array( 'electricity', 'gas' ), true ) ? $aec_fuel : '',
);

$aec_total = AEC_Bills::count( $aec_filters );
$aec_rows  = AEC_Bills::all(
	array_merge(
		$aec_filters,
		array(
			'limit'  => $aec_per_page,
			'offset' => ( $aec_paged - 1 ) * $aec_per_page,
		)
	)
);

$aec_retention = (int) AEC_Settings::get( 'bill_retention_days' );
?>
<div class="wrap aec-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Uploaded Bills', 'aec-energy-compare' ); ?></h1>
	<hr class="wp-header-end">

	<p class="description">
		<?php if ( $aec_retention > 0 ) : ?>
			<?php
			printf(
				/* translators: %d: days */
				esc_html__( 'Bills with no enquiry attached are removed after %d days.', 'aec-energy-compare' ),
				$aec_retention
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'Automatic clean-up is switched off, so bills are kept until you remove them.', 'aec-energy-compare' ); ?>
		<?php endif; ?>
		<?php AEC_Admin::button_form( 'aec_purge_bills', array(), __( 'Run clean-up now', 'aec-energy-compare' ), 'button button-small' ); ?>
	</p>

	<form method="get" class="aec-filters">
		<input type="hidden" name="page" value="aec-bills">
		<select name="fuel">
			<option value=""><?php esc_html_e( 'All fuels', 'aec-energy-compare' ); ?></option>
			<option value="electricity" <?php selected( $aec_fuel, 'electricity' ); ?>><?php esc_html_e( 'Electricity', 'aec-energy-compare' ); ?></option>
			<option value="gas" <?php selected( $aec_fuel, 'gas' ); ?>><?php esc_html_e( 'Gas', 'aec-energy-compare' ); ?></option>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $aec_search ); ?>"
			placeholder="<?php esc_attr_e( 'Retailer, postcode, NMI', 'aec-energy-compare' ); ?>">
		<?php submit_button( __( 'Filter', 'aec-energy-compare' ), '', '', false ); ?>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'When', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Fuel', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Retailer', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Postcode', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Days', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Usage', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Bill total', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Read by', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'aec-energy-compare' ); ?></th>
				<th scope="col"></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $aec_rows ) : ?>
			<tr><td colspan="10"><?php esc_html_e( 'No bills have been uploaded yet.', 'aec-energy-compare' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $aec_rows as $aec_bill ) : ?>
				<?php
				$aec_id    = (int) $aec_bill['id'];
				$aec_usage = 'gas' === $aec_bill['fuel']
					? ( isset( $aec_bill['usage']['gas_mj'] ) ? AEC_Calculator::trim_number( $aec_bill['usage']['gas_mj'] ) . ' MJ' : '-' )
					: ( isset( $aec_bill['usage']['total_kwh'] ) ? AEC_Calculator::trim_number( $aec_bill['usage']['total_kwh'] ) . ' kWh' : '-' );
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-bills&bill=' . $aec_id ) ); ?>">
							<?php echo esc_html( mysql2date( 'j M Y H:i', $aec_bill['created_at'] ) ); ?>
						</a>
					</td>
					<td><?php echo esc_html( ucfirst( $aec_bill['fuel'] ) ); ?></td>
					<td><?php echo esc_html( $aec_bill['retailer_name'] ? $aec_bill['retailer_name'] : '-' ); ?></td>
					<td><?php echo esc_html( $aec_bill['postcode'] ? $aec_bill['postcode'] : '-' ); ?></td>
					<td><?php echo esc_html( $aec_bill['billing_days'] ? $aec_bill['billing_days'] : '-' ); ?></td>
					<td><?php echo esc_html( $aec_usage ); ?></td>
					<td>
						<?php
						echo null === $aec_bill['total_amount']
							? '-'
							: esc_html( AEC_Settings::get( 'currency_symbol' ) . number_format( (float) $aec_bill['total_amount'], 2 ) );
						?>
					</td>
					<td><?php echo esc_html( $aec_bill['extractor'] ? $aec_bill['extractor'] : '-' ); ?></td>
					<td><?php echo esc_html( $aec_bill['status'] ); ?></td>
					<td class="aec-row-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-bills&bill=' . $aec_id ) ); ?>" class="button button-small">
							<?php esc_html_e( 'View', 'aec-energy-compare' ); ?>
						</a>
						<?php
						AEC_Admin::button_form(
							'aec_delete_bill',
							array( 'id' => $aec_id ),
							__( 'Delete', 'aec-energy-compare' ),
							'button button-small button-link-delete',
							__( 'Delete this bill record?', 'aec-energy-compare' )
						);
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php
	AEC_Admin::pagination(
		$aec_total,
		$aec_per_page,
		$aec_paged,
		array( 'page' => 'aec-bills', 's' => $aec_search, 'fuel' => $aec_fuel )
	);
	?>
</div>
