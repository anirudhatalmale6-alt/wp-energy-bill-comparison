<?php
/**
 * Plan list.
 */

defined( 'ABSPATH' ) || exit;

$aec_search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$aec_fuel     = isset( $_GET['fuel'] ) ? sanitize_key( $_GET['fuel'] ) : '';
$aec_state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
$aec_retailer = isset( $_GET['retailer_id'] ) ? (int) $_GET['retailer_id'] : 0;
$aec_paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$aec_per_page = 25;

$aec_filters = array(
	'search'      => $aec_search,
	'fuel'        => in_array( $aec_fuel, array( 'electricity', 'gas' ), true ) ? $aec_fuel : '',
	'state'       => $aec_state,
	'retailer_id' => $aec_retailer,
);

$aec_total = AEC_Plans::count( $aec_filters );
$aec_rows  = AEC_Plans::all(
	array_merge(
		$aec_filters,
		array(
			'with_rates' => true,
			'limit'      => $aec_per_page,
			'offset'     => ( $aec_paged - 1 ) * $aec_per_page,
		)
	)
);

$aec_tariffs   = AEC_DB::tariff_types();
$aec_retailers = AEC_Retailers::options();
?>
<div class="wrap aec-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Plans & Tariffs', 'aec-energy-compare' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-plans&action=new' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Add plan', 'aec-energy-compare' ); ?>
	</a>
	<?php if ( ! $aec_total && $aec_retailers ) : ?>
		<?php
		AEC_Admin::button_form(
			'aec_seed_demo',
			array(),
			__( 'Load sample plans', 'aec-energy-compare' ),
			'page-title-action',
			__( 'This adds a handful of placeholder plans so you can try the calculator. Edit or delete them before going live. Continue?', 'aec-energy-compare' )
		);
		?>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php if ( ! $aec_retailers ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'Add a retailer before adding plans.', 'aec-energy-compare' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-retailers&action=new' ) ); ?>">
					<?php esc_html_e( 'Add retailer', 'aec-energy-compare' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<form method="get" class="aec-filters">
		<input type="hidden" name="page" value="aec-plans">
		<select name="fuel">
			<option value=""><?php esc_html_e( 'All fuels', 'aec-energy-compare' ); ?></option>
			<option value="electricity" <?php selected( $aec_fuel, 'electricity' ); ?>><?php esc_html_e( 'Electricity', 'aec-energy-compare' ); ?></option>
			<option value="gas" <?php selected( $aec_fuel, 'gas' ); ?>><?php esc_html_e( 'Gas', 'aec-energy-compare' ); ?></option>
		</select>
		<select name="state">
			<option value=""><?php esc_html_e( 'All states', 'aec-energy-compare' ); ?></option>
			<?php foreach ( AEC_DB::states() as $aec_code => $aec_label ) : ?>
				<option value="<?php echo esc_attr( $aec_code ); ?>" <?php selected( $aec_state, $aec_code ); ?>>
					<?php echo esc_html( $aec_code ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<select name="retailer_id">
			<option value="0"><?php esc_html_e( 'All retailers', 'aec-energy-compare' ); ?></option>
			<?php foreach ( $aec_retailers as $aec_rid => $aec_rname ) : ?>
				<option value="<?php echo esc_attr( $aec_rid ); ?>" <?php selected( $aec_retailer, $aec_rid ); ?>>
					<?php echo esc_html( $aec_rname ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $aec_search ); ?>"
			placeholder="<?php esc_attr_e( 'Search plans', 'aec-energy-compare' ); ?>">
		<?php submit_button( __( 'Filter', 'aec-energy-compare' ), '', '', false ); ?>
	</form>

	<table class="wp-list-table widefat fixed striped aec-plans-table">
		<thead>
			<tr>
				<th scope="col" class="column-primary"><?php esc_html_e( 'Plan', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Fuel / State', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Structure', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Supply', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Usage rates', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'aec-energy-compare' ); ?></th>
				<th scope="col"></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $aec_rows ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No plans match.', 'aec-energy-compare' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $aec_rows as $aec_plan ) : ?>
				<?php
				$aec_id       = (int) $aec_plan['id'];
				$aec_edit_url = admin_url( 'admin.php?page=aec-plans&action=edit&id=' . $aec_id );
				$aec_rates    = isset( $aec_plan['rates'] ) ? $aec_plan['rates'] : array();
				$aec_has_rate = false;
				foreach ( array( 'single', 'peak', 'offpeak', 'gas_usage' ) as $aec_group ) {
					if ( ! empty( $aec_rates[ $aec_group ] ) ) {
						$aec_has_rate = true;
						break;
					}
				}
				?>
				<tr>
					<td class="column-primary">
						<strong><a href="<?php echo esc_url( $aec_edit_url ); ?>"><?php echo esc_html( $aec_plan['name'] ); ?></a></strong>
						<div class="aec-sub"><?php echo esc_html( $aec_plan['retailer_name'] ); ?></div>
					</td>
					<td>
						<?php echo esc_html( ucfirst( $aec_plan['fuel'] ) ); ?> / <?php echo esc_html( $aec_plan['state'] ); ?>
						<?php if ( $aec_plan['postcodes'] ) : ?>
							<div class="aec-sub"><?php esc_html_e( 'Postcode limited', 'aec-energy-compare' ); ?></div>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $aec_tariffs[ $aec_plan['tariff_type'] ] ?? $aec_plan['tariff_type'] ); ?>
					</td>
					<td><?php echo esc_html( AEC_Calculator::trim_number( $aec_plan['daily_supply_cents'] ) ); ?>c</td>
					<td>
						<?php if ( ! $aec_has_rate ) : ?>
							<span class="aec-pill aec-pill--bad"><?php esc_html_e( 'No usage rate', 'aec-energy-compare' ); ?></span>
						<?php else : ?>
							<?php
							$aec_bits = array();
							foreach ( $aec_rates as $aec_group => $aec_blocks ) {
								if ( in_array( $aec_group, array( 'feedin', 'demand' ), true ) ) {
									continue;
								}
								$aec_bits[] = sprintf(
									'%s %sc',
									esc_html( $aec_group ),
									esc_html( AEC_Calculator::trim_number( $aec_blocks[0]['rate_cents'] ) )
								);
							}
							echo esc_html( implode( ', ', $aec_bits ) );
							?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $aec_plan['is_active'] ) : ?>
							<span class="aec-pill aec-pill--good"><?php esc_html_e( 'Active', 'aec-energy-compare' ); ?></span>
						<?php else : ?>
							<span class="aec-pill"><?php esc_html_e( 'Off', 'aec-energy-compare' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="aec-row-actions">
						<a href="<?php echo esc_url( $aec_edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'aec-energy-compare' ); ?></a>
						<?php
						AEC_Admin::button_form( 'aec_duplicate_plan', array( 'id' => $aec_id ), __( 'Copy', 'aec-energy-compare' ), 'button button-small' );
						AEC_Admin::button_form(
							'aec_delete_plan',
							array( 'id' => $aec_id ),
							__( 'Delete', 'aec-energy-compare' ),
							'button button-small button-link-delete',
							__( 'Delete this plan and its rates?', 'aec-energy-compare' )
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
		array(
			'page'        => 'aec-plans',
			's'           => $aec_search,
			'fuel'        => $aec_fuel,
			'state'       => $aec_state,
			'retailer_id' => $aec_retailer,
		)
	);
	?>
</div>
