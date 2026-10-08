<?php
/**
 * Plan add/edit form, including the tariff rate rows.
 *
 * @var array|null $plan
 */

defined( 'ABSPATH' ) || exit;

$aec_p = wp_parse_args(
	is_array( $plan ) ? $plan : array(),
	array(
		'id'                         => 0,
		'retailer_id'                => 0,
		'name'                       => '',
		'fuel'                       => 'electricity',
		'state'                      => '',
		'distributor'                => '',
		'postcodes'                  => '',
		'tariff_type'                => 'single',
		'daily_supply_cents'         => '',
		'demand_cents'               => '',
		'demand_window'              => '',
		'guaranteed_discount'        => '',
		'conditional_discount'       => '',
		'conditional_discount_label' => '',
		'discount_applies_to'        => 'usage',
		'signup_credit'              => '',
		'contract_months'            => '',
		'exit_fee'                   => '',
		'green_percent'              => '',
		'reference_price_pct'        => '',
		'plan_url'                   => '',
		'notes'                      => '',
		'is_active'                  => 1,
		'valid_from'                 => '',
		'valid_to'                   => '',
		'rates'                      => array(),
	)
);

$aec_flat_rates = array();
foreach ( (array) $aec_p['rates'] as $aec_group => $aec_blocks ) {
	foreach ( $aec_blocks as $aec_block ) {
		$aec_flat_rates[] = array(
			'rate_group'   => $aec_group,
			'block_limit'  => ( null === $aec_block['block_limit'] ) ? '' : AEC_Calculator::trim_number( $aec_block['block_limit'] ),
			'block_period' => $aec_block['block_period'],
			'unit'         => $aec_block['unit'],
			'rate_cents'   => AEC_Calculator::trim_number( $aec_block['rate_cents'] ),
		);
	}
}

$aec_groups    = AEC_DB::rate_groups();
$aec_periods   = AEC_DB::block_periods();
$aec_retailers = AEC_Retailers::options();
?>
<div class="wrap aec-wrap">
	<h1><?php echo $aec_p['id'] ? esc_html__( 'Edit plan', 'aec-energy-compare' ) : esc_html__( 'Add plan', 'aec-energy-compare' ); ?></h1>

	<?php if ( ! $aec_retailers ) : ?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'You need a retailer first.', 'aec-energy-compare' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-retailers&action=new' ) ); ?>">
					<?php esc_html_e( 'Add one', 'aec-energy-compare' ); ?>
				</a>
			</p>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="aec-plan-form">
		<?php wp_nonce_field( 'aec_save_plan' ); ?>
		<input type="hidden" name="action" value="aec_save_plan">
		<input type="hidden" name="id" value="<?php echo esc_attr( $aec_p['id'] ); ?>">

		<h2><?php esc_html_e( 'The plan', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-plan-name"><?php esc_html_e( 'Plan name', 'aec-energy-compare' ); ?></label></th>
					<td><input type="text" id="aec-plan-name" name="name" class="regular-text" required value="<?php echo esc_attr( $aec_p['name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-plan-retailer"><?php esc_html_e( 'Retailer', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-plan-retailer" name="retailer_id" required>
							<option value=""><?php esc_html_e( 'Select', 'aec-energy-compare' ); ?></option>
							<?php foreach ( $aec_retailers as $aec_rid => $aec_rname ) : ?>
								<option value="<?php echo esc_attr( $aec_rid ); ?>" <?php selected( (int) $aec_p['retailer_id'], $aec_rid ); ?>>
									<?php echo esc_html( $aec_rname ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-plan-fuel"><?php esc_html_e( 'Fuel', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-plan-fuel" name="fuel">
							<option value="electricity" <?php selected( $aec_p['fuel'], 'electricity' ); ?>><?php esc_html_e( 'Electricity', 'aec-energy-compare' ); ?></option>
							<option value="gas" <?php selected( $aec_p['fuel'], 'gas' ); ?>><?php esc_html_e( 'Gas', 'aec-energy-compare' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-plan-state"><?php esc_html_e( 'State', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-plan-state" name="state" required>
							<option value=""><?php esc_html_e( 'Select', 'aec-energy-compare' ); ?></option>
							<?php foreach ( AEC_DB::states() as $aec_code => $aec_label ) : ?>
								<option value="<?php echo esc_attr( $aec_code ); ?>" <?php selected( $aec_p['state'], $aec_code ); ?>>
									<?php echo esc_html( $aec_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-plan-distributor"><?php esc_html_e( 'Distributor', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="text" id="aec-plan-distributor" name="distributor" class="regular-text" value="<?php echo esc_attr( $aec_p['distributor'] ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. For your own reference, for example Ausgrid or Powercor.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-plan-postcodes"><?php esc_html_e( 'Postcodes', 'aec-energy-compare' ); ?></label></th>
					<td>
						<textarea id="aec-plan-postcodes" name="postcodes" rows="2" class="large-text"><?php echo esc_textarea( $aec_p['postcodes'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Leave blank for the whole state. Otherwise single postcodes and ranges, comma separated: 2000-2234, 2250, 2300.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-plan-tariff"><?php esc_html_e( 'Tariff structure', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-plan-tariff" name="tariff_type">
							<?php foreach ( AEC_DB::tariff_types() as $aec_key => $aec_label ) : ?>
								<option value="<?php echo esc_attr( $aec_key ); ?>" <?php selected( $aec_p['tariff_type'], $aec_key ); ?>>
									<?php echo esc_html( $aec_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'This is a label for the results page. What the calculator actually uses is which rate rows you add below.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Active', 'aec-energy-compare' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="is_active" value="1" <?php checked( $aec_p['is_active'], 1 ); ?>>
							<?php esc_html_e( 'Include this plan in comparisons', 'aec-energy-compare' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Charges', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-supply"><?php esc_html_e( 'Daily supply charge (c/day)', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="number" id="aec-supply" name="daily_supply_cents" step="0.0001" min="0" value="<?php echo esc_attr( $aec_p['daily_supply_cents'] ); ?>">
						<p class="description"><?php esc_html_e( 'In cents. A fact sheet showing $1.0890 per day is 108.90 here.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-demand-cents"><?php esc_html_e( 'Demand charge (c/kW/day)', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="number" id="aec-demand-cents" name="demand_cents" step="0.0001" min="0" value="<?php echo esc_attr( $aec_p['demand_cents'] ); ?>">
						<input type="text" name="demand_window" class="regular-text" placeholder="<?php esc_attr_e( 'When demand is measured, e.g. 3pm-9pm weekdays', 'aec-energy-compare' ); ?>"
							value="<?php echo esc_attr( $aec_p['demand_window'] ); ?>">
						<p class="description"><?php esc_html_e( 'Leave blank unless this plan really has a demand charge. A plan with one is shown as an estimate when the customer\'s bill does not state their demand.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Usage rates', 'aec-energy-compare' ); ?></h2>
		<p class="description aec-rates-help">
			<?php esc_html_e( 'Add one row per rate. For a stepped tariff, add a row per block in order and leave the last block\'s size blank to mean "everything after that". Block size is the size of that band for the period you pick, which is how fact sheets word it: "first 1000 kWh per quarter, then...".', 'aec-energy-compare' ); ?>
		</p>

		<table class="widefat aec-rates" id="aec-rates">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Charge', 'aec-energy-compare' ); ?></th>
					<th><?php esc_html_e( 'Block size', 'aec-energy-compare' ); ?></th>
					<th><?php esc_html_e( 'Per', 'aec-energy-compare' ); ?></th>
					<th><?php esc_html_e( 'Unit', 'aec-energy-compare' ); ?></th>
					<th><?php esc_html_e( 'Rate (cents)', 'aec-energy-compare' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="aec-rates-body">
				<?php foreach ( $aec_flat_rates as $aec_i => $aec_rate ) : ?>
					<tr class="aec-rate-row">
						<td>
							<select name="rates[<?php echo (int) $aec_i; ?>][rate_group]">
								<?php foreach ( $aec_groups as $aec_key => $aec_label ) : ?>
									<option value="<?php echo esc_attr( $aec_key ); ?>" <?php selected( $aec_rate['rate_group'], $aec_key ); ?>>
										<?php echo esc_html( $aec_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<input type="number" step="0.001" min="0" name="rates[<?php echo (int) $aec_i; ?>][block_limit]"
								value="<?php echo esc_attr( $aec_rate['block_limit'] ); ?>"
								placeholder="<?php esc_attr_e( 'all', 'aec-energy-compare' ); ?>">
						</td>
						<td>
							<select name="rates[<?php echo (int) $aec_i; ?>][block_period]">
								<?php foreach ( $aec_periods as $aec_key => $aec_label ) : ?>
									<option value="<?php echo esc_attr( $aec_key ); ?>" <?php selected( $aec_rate['block_period'], $aec_key ); ?>>
										<?php echo esc_html( $aec_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<input type="text" name="rates[<?php echo (int) $aec_i; ?>][unit]" size="5"
								value="<?php echo esc_attr( $aec_rate['unit'] ); ?>">
						</td>
						<td>
							<input type="number" step="0.0001" min="0" name="rates[<?php echo (int) $aec_i; ?>][rate_cents]"
								value="<?php echo esc_attr( $aec_rate['rate_cents'] ); ?>" required>
						</td>
						<td>
							<button type="button" class="button button-small aec-remove-rate"><?php esc_html_e( 'Remove', 'aec-energy-compare' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button" id="aec-add-rate"><?php esc_html_e( 'Add rate row', 'aec-energy-compare' ); ?></button>
		</p>

		<template id="aec-rate-template">
			<tr class="aec-rate-row">
				<td>
					<select name="rates[__INDEX__][rate_group]">
						<?php foreach ( $aec_groups as $aec_key => $aec_label ) : ?>
							<option value="<?php echo esc_attr( $aec_key ); ?>"><?php echo esc_html( $aec_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td>
					<input type="number" step="0.001" min="0" name="rates[__INDEX__][block_limit]"
						placeholder="<?php esc_attr_e( 'all', 'aec-energy-compare' ); ?>">
				</td>
				<td>
					<select name="rates[__INDEX__][block_period]">
						<?php foreach ( $aec_periods as $aec_key => $aec_label ) : ?>
							<option value="<?php echo esc_attr( $aec_key ); ?>"><?php echo esc_html( $aec_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td><input type="text" name="rates[__INDEX__][unit]" size="5" value="kWh"></td>
				<td><input type="number" step="0.0001" min="0" name="rates[__INDEX__][rate_cents]" required></td>
				<td><button type="button" class="button button-small aec-remove-rate"><?php esc_html_e( 'Remove', 'aec-energy-compare' ); ?></button></td>
			</tr>
		</template>

		<h2><?php esc_html_e( 'Discounts and incentives', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-guaranteed"><?php esc_html_e( 'Guaranteed discount (%)', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="number" id="aec-guaranteed" name="guaranteed_discount" step="0.001" min="0" max="100" value="<?php echo esc_attr( $aec_p['guaranteed_discount'] ); ?>">
						<p class="description"><?php esc_html_e( 'Applied in the headline figure, because the customer gets it whatever they do.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-conditional"><?php esc_html_e( 'Conditional discount (%)', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="number" id="aec-conditional" name="conditional_discount" step="0.001" min="0" max="100" value="<?php echo esc_attr( $aec_p['conditional_discount'] ); ?>">
						<input type="text" name="conditional_discount_label" class="regular-text"
							placeholder="<?php esc_attr_e( 'Condition, e.g. pay on time', 'aec-energy-compare' ); ?>"
							value="<?php echo esc_attr( $aec_p['conditional_discount_label'] ); ?>">
						<p class="description"><?php esc_html_e( 'Shown separately rather than in the headline price, so the comparison stays honest for a customer who might miss the condition.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-applies"><?php esc_html_e( 'Discounts apply to', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-applies" name="discount_applies_to">
							<option value="usage" <?php selected( $aec_p['discount_applies_to'], 'usage' ); ?>><?php esc_html_e( 'Usage charges only', 'aec-energy-compare' ); ?></option>
							<option value="total" <?php selected( $aec_p['discount_applies_to'], 'total' ); ?>><?php esc_html_e( 'The whole bill', 'aec-energy-compare' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-credit"><?php esc_html_e( 'Sign-up credit', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="number" id="aec-credit" name="signup_credit" step="0.01" min="0" value="<?php echo esc_attr( $aec_p['signup_credit'] ); ?>">
						<p class="description"><?php esc_html_e( 'One-off credit. Counted in the first-year figure only, not the ongoing annual cost.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-contract"><?php esc_html_e( 'Contract length (months)', 'aec-energy-compare' ); ?></label></th>
					<td><input type="number" id="aec-contract" name="contract_months" step="1" min="0" value="<?php echo esc_attr( $aec_p['contract_months'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-exit"><?php esc_html_e( 'Exit fee', 'aec-energy-compare' ); ?></label></th>
					<td><input type="number" id="aec-exit" name="exit_fee" step="0.01" min="0" value="<?php echo esc_attr( $aec_p['exit_fee'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-green"><?php esc_html_e( 'GreenPower (%)', 'aec-energy-compare' ); ?></label></th>
					<td><input type="number" id="aec-green" name="green_percent" step="1" min="0" max="100" value="<?php echo esc_attr( $aec_p['green_percent'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-refprice"><?php esc_html_e( 'Reference price comparison (%)', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="number" id="aec-refprice" name="reference_price_pct" step="0.01" value="<?php echo esc_attr( $aec_p['reference_price_pct'] ); ?>">
						<p class="description"><?php esc_html_e( 'From the retailer\'s own energy fact sheet, for your records. Negative means below the reference price.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Other', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-plan-url"><?php esc_html_e( 'Plan page / fact sheet URL', 'aec-energy-compare' ); ?></label></th>
					<td><input type="url" id="aec-plan-url" name="plan_url" class="regular-text" value="<?php echo esc_attr( $aec_p['plan_url'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-plan-notes"><?php esc_html_e( 'Notes shown to the customer', 'aec-energy-compare' ); ?></label></th>
					<td><textarea id="aec-plan-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $aec_p['notes'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Valid between', 'aec-energy-compare' ); ?></th>
					<td>
						<input type="date" name="valid_from" value="<?php echo esc_attr( $aec_p['valid_from'] ); ?>">
						<input type="date" name="valid_to" value="<?php echo esc_attr( $aec_p['valid_to'] ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. A plan outside its dates is skipped in comparisons without you having to switch it off.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="submit">
			<?php submit_button( __( 'Save plan', 'aec-energy-compare' ), 'primary', 'submit', false ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-plans' ) ); ?>" class="button">
				<?php esc_html_e( 'Back to plans', 'aec-energy-compare' ); ?>
			</a>
		</p>
	</form>
</div>
