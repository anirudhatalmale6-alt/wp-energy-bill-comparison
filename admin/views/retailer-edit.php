<?php
/**
 * Retailer add/edit form.
 *
 * @var array|null $retailer
 */

defined( 'ABSPATH' ) || exit;

$aec_r = wp_parse_args(
	is_array( $retailer ) ? $retailer : array(),
	array(
		'id'            => 0,
		'name'          => '',
		'slug'          => '',
		'phone'         => '',
		'website'       => '',
		'logo_url'      => '',
		'bill_keywords' => '',
		'is_active'     => 1,
	)
);
?>
<div class="wrap aec-wrap">
	<h1>
		<?php
		echo $aec_r['id']
			? esc_html__( 'Edit retailer', 'aec-energy-compare' )
			: esc_html__( 'Add retailer', 'aec-energy-compare' );
		?>
	</h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'aec_save_retailer' ); ?>
		<input type="hidden" name="action" value="aec_save_retailer">
		<input type="hidden" name="id" value="<?php echo esc_attr( $aec_r['id'] ); ?>">

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-name"><?php esc_html_e( 'Name', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="text" id="aec-name" name="name" class="regular-text" required
							value="<?php echo esc_attr( $aec_r['name'] ); ?>">
						<p class="description"><?php esc_html_e( 'As customers would recognise it, for example "Origin Energy".', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-slug"><?php esc_html_e( 'Slug', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="text" id="aec-slug" name="slug" class="regular-text"
							value="<?php echo esc_attr( $aec_r['slug'] ); ?>">
						<p class="description"><?php esc_html_e( 'Leave blank to generate from the name.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-phone"><?php esc_html_e( 'Phone', 'aec-energy-compare' ); ?></label></th>
					<td><input type="text" id="aec-phone" name="phone" class="regular-text" value="<?php echo esc_attr( $aec_r['phone'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-website"><?php esc_html_e( 'Website', 'aec-energy-compare' ); ?></label></th>
					<td><input type="url" id="aec-website" name="website" class="regular-text" value="<?php echo esc_attr( $aec_r['website'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-logo"><?php esc_html_e( 'Logo URL', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="url" id="aec-logo" name="logo_url" class="regular-text" value="<?php echo esc_attr( $aec_r['logo_url'] ); ?>">
						<?php if ( $aec_r['logo_url'] ) : ?>
							<p><img src="<?php echo esc_url( $aec_r['logo_url'] ); ?>" alt="" class="aec-logo-preview"></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Upload the logo in Media, then paste its URL here. A square image around 160px works best.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-keywords"><?php esc_html_e( 'Bill keywords', 'aec-energy-compare' ); ?></label></th>
					<td>
						<textarea id="aec-keywords" name="bill_keywords" rows="3" class="large-text"><?php echo esc_textarea( $aec_r['bill_keywords'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One per line, or comma separated. Used to recognise this retailer in an uploaded bill. Add trading names, the billing entity name, and the ABN if it appears on the bill.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Active', 'aec-energy-compare' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="is_active" value="1" <?php checked( $aec_r['is_active'], 1 ); ?>>
							<?php esc_html_e( 'Include this retailer\'s plans in comparisons', 'aec-energy-compare' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="submit">
			<?php submit_button( __( 'Save retailer', 'aec-energy-compare' ), 'primary', 'submit', false ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-retailers' ) ); ?>" class="button">
				<?php esc_html_e( 'Cancel', 'aec-energy-compare' ); ?>
			</a>
		</p>
	</form>
</div>
