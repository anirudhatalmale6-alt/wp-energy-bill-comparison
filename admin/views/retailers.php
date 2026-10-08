<?php
/**
 * Retailer list.
 */

defined( 'ABSPATH' ) || exit;

$aec_search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$aec_paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$aec_per_page = 20;

$aec_total = AEC_Retailers::count( array( 'search' => $aec_search ) );
$aec_rows  = AEC_Retailers::all(
	array(
		'search' => $aec_search,
		'limit'  => $aec_per_page,
		'offset' => ( $aec_paged - 1 ) * $aec_per_page,
	)
);
?>
<div class="wrap aec-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Retailers', 'aec-energy-compare' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=aec-retailers&action=new' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Add retailer', 'aec-energy-compare' ); ?>
	</a>
	<hr class="wp-header-end">

	<form method="get" class="aec-search">
		<input type="hidden" name="page" value="aec-retailers">
		<p class="search-box">
			<label class="screen-reader-text" for="aec-retailer-search"><?php esc_html_e( 'Search retailers', 'aec-energy-compare' ); ?></label>
			<input type="search" id="aec-retailer-search" name="s" value="<?php echo esc_attr( $aec_search ); ?>">
			<?php submit_button( __( 'Search', 'aec-energy-compare' ), '', '', false ); ?>
		</p>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col" class="column-primary"><?php esc_html_e( 'Retailer', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Plans', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Contact', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Bill keywords', 'aec-energy-compare' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'aec-energy-compare' ); ?></th>
				<th scope="col"></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $aec_rows ) : ?>
			<tr>
				<td colspan="6">
					<?php esc_html_e( 'No retailers yet. Add the ones whose plans you want to compare against.', 'aec-energy-compare' ); ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $aec_rows as $aec_row ) : ?>
				<?php
				$aec_id        = (int) $aec_row['id'];
				$aec_edit_url  = admin_url( 'admin.php?page=aec-retailers&action=edit&id=' . $aec_id );
				$aec_plans_url = admin_url( 'admin.php?page=aec-plans&retailer_id=' . $aec_id );
				$aec_count     = AEC_Plans::count( array( 'retailer_id' => $aec_id ) );
				?>
				<tr>
					<td class="column-primary">
						<strong><a href="<?php echo esc_url( $aec_edit_url ); ?>"><?php echo esc_html( $aec_row['name'] ); ?></a></strong>
						<?php if ( $aec_row['logo_url'] ) : ?>
							<img src="<?php echo esc_url( $aec_row['logo_url'] ); ?>" alt="" class="aec-logo-thumb">
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( $aec_plans_url ); ?>"><?php echo esc_html( number_format_i18n( $aec_count ) ); ?></a>
					</td>
					<td>
						<?php echo esc_html( $aec_row['phone'] ); ?>
						<?php if ( $aec_row['website'] ) : ?>
							<br><a href="<?php echo esc_url( $aec_row['website'] ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( wp_parse_url( $aec_row['website'], PHP_URL_HOST ) ); ?>
							</a>
						<?php endif; ?>
					</td>
					<td class="aec-truncate"><?php echo esc_html( $aec_row['bill_keywords'] ); ?></td>
					<td>
						<?php if ( $aec_row['is_active'] ) : ?>
							<span class="aec-pill aec-pill--good"><?php esc_html_e( 'Active', 'aec-energy-compare' ); ?></span>
						<?php else : ?>
							<span class="aec-pill"><?php esc_html_e( 'Off', 'aec-energy-compare' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="aec-row-actions">
						<a href="<?php echo esc_url( $aec_edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'aec-energy-compare' ); ?></a>
						<?php
						AEC_Admin::button_form(
							'aec_delete_retailer',
							array( 'id' => $aec_id ),
							__( 'Delete', 'aec-energy-compare' ),
							'button button-small button-link-delete',
							__( 'Delete this retailer? Its plans will be switched off, not deleted.', 'aec-energy-compare' )
						);
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php AEC_Admin::pagination( $aec_total, $aec_per_page, $aec_paged, array( 'page' => 'aec-retailers', 's' => $aec_search ) ); ?>
</div>
