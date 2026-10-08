<?php
/**
 * Enquiry list.
 */

defined( 'ABSPATH' ) || exit;

$aec_search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$aec_status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$aec_paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$aec_per_page = 20;

$aec_filters = array(
	'search' => $aec_search,
	'status' => in_array( $aec_status, array( 'new', 'contacted', 'switched', 'closed' ), true ) ? $aec_status : '',
);

$aec_total = AEC_Leads::count( $aec_filters );
$aec_rows  = AEC_Leads::all(
	array_merge(
		$aec_filters,
		array(
			'limit'  => $aec_per_page,
			'offset' => ( $aec_paged - 1 ) * $aec_per_page,
		)
	)
);

$aec_symbol   = AEC_Settings::get( 'currency_symbol' );
$aec_statuses = array(
	'new'       => __( 'New', 'aec-energy-compare' ),
	'contacted' => __( 'Contacted', 'aec-energy-compare' ),
	'switched'  => __( 'Switched', 'aec-energy-compare' ),
	'closed'    => __( 'Closed', 'aec-energy-compare' ),
);
?>
<div class="wrap aec-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Enquiries', 'aec-energy-compare' ); ?></h1>
	<?php AEC_Admin::button_form( 'aec_export_leads', array(), __( 'Export CSV', 'aec-energy-compare' ), 'page-title-action' ); ?>
	<hr class="wp-header-end">

	<form method="get" class="aec-filters">
		<input type="hidden" name="page" value="aec-leads">
		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'aec-energy-compare' ); ?></option>
			<?php foreach ( $aec_statuses as $aec_key => $aec_label ) : ?>
				<option value="<?php echo esc_attr( $aec_key ); ?>" <?php selected( $aec_status, $aec_key ); ?>>
					<?php echo esc_html( $aec_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $aec_search ); ?>"
			placeholder="<?php esc_attr_e( 'Name, email, phone', 'aec-energy-compare' ); ?>">
		<?php submit_button( __( 'Filter', 'aec-energy-compare' ), '', '', false ); ?>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'When', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Customer', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Chosen plan', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Saving', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'aec-energy-compare' ); ?></th>
				<th scope="col"></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $aec_rows ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No enquiries yet.', 'aec-energy-compare' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $aec_rows as $aec_lead ) : ?>
				<?php $aec_id = (int) $aec_lead['id']; ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'j M Y H:i', $aec_lead['created_at'] ) ); ?></td>
					<td>
						<strong><?php echo esc_html( $aec_lead['name'] ); ?></strong><br>
						<a href="mailto:<?php echo esc_attr( $aec_lead['email'] ); ?>"><?php echo esc_html( $aec_lead['email'] ); ?></a>
						<?php if ( $aec_lead['phone'] ) : ?>
							<br><?php echo esc_html( $aec_lead['phone'] ); ?>
						<?php endif; ?>
						<?php if ( $aec_lead['preferred_contact'] ) : ?>
							<div class="aec-sub">
								<?php
								printf(
									/* translators: %s: email, phone or either */
									esc_html__( 'Prefers: %s', 'aec-energy-compare' ),
									esc_html( $aec_lead['preferred_contact'] )
								);
								?>
							</div>
						<?php endif; ?>
						<?php if ( $aec_lead['message'] ) : ?>
							<div class="aec-sub aec-truncate"><?php echo esc_html( $aec_lead['message'] ); ?></div>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $aec_lead['plan_name'] ) : ?>
							<strong><?php echo esc_html( $aec_lead['retailer_name'] ); ?></strong><br>
							<?php echo esc_html( $aec_lead['plan_name'] ); ?>
						<?php else : ?>
							-
						<?php endif; ?>
						<?php if ( $aec_lead['fuel'] ) : ?>
							<div class="aec-sub">
								<?php echo esc_html( ucfirst( $aec_lead['fuel'] ) ); ?>
								<?php echo esc_html( $aec_lead['postcode'] ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $aec_lead['bill_id'] ) : ?>
							<div class="aec-sub">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-bills&bill=' . (int) $aec_lead['bill_id'] ) ); ?>">
									<?php esc_html_e( 'View their bill', 'aec-energy-compare' ); ?>
								</a>
							</div>
						<?php endif; ?>
					</td>
					<td>
						<?php
						echo null === $aec_lead['estimated_saving']
							? '-'
							: esc_html( $aec_symbol . number_format( (float) $aec_lead['estimated_saving'], 2 ) );
						?>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aec-inline-form">
							<?php wp_nonce_field( 'aec_lead_status' ); ?>
							<input type="hidden" name="action" value="aec_lead_status">
							<input type="hidden" name="id" value="<?php echo esc_attr( $aec_id ); ?>">
							<select name="status" onchange="this.form.submit()">
								<?php foreach ( $aec_statuses as $aec_key => $aec_label ) : ?>
									<option value="<?php echo esc_attr( $aec_key ); ?>" <?php selected( $aec_lead['status'], $aec_key ); ?>>
										<?php echo esc_html( $aec_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<noscript><button type="submit" class="button button-small"><?php esc_html_e( 'Set', 'aec-energy-compare' ); ?></button></noscript>
						</form>
					</td>
					<td class="aec-row-actions">
						<?php
						AEC_Admin::button_form(
							'aec_delete_lead',
							array( 'id' => $aec_id ),
							__( 'Delete', 'aec-energy-compare' ),
							'button button-small button-link-delete',
							__( 'Delete this enquiry?', 'aec-energy-compare' )
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
		array( 'page' => 'aec-leads', 's' => $aec_search, 'status' => $aec_status )
	);
	?>
</div>
