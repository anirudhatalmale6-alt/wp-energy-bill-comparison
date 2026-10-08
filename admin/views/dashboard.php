<?php
/**
 * Admin dashboard: where things stand, and what is still missing.
 */

defined( 'ABSPATH' ) || exit;

$aec_settings  = AEC_Settings::all();
$aec_retailers = AEC_Retailers::count( array( 'active_only' => true ) );
$aec_elec      = AEC_Plans::count( array( 'fuel' => 'electricity', 'active_only' => true ) );
$aec_gas       = AEC_Plans::count( array( 'fuel' => 'gas', 'active_only' => true ) );
$aec_bills     = AEC_Bills::count();
$aec_leads     = AEC_Leads::count();
$aec_leads_new = AEC_Leads::count( array( 'status' => 'new' ) );

$aec_pdftotext = AEC_PDF::pdftotext_binary();
$aec_ai        = AEC_Extractor_AI::is_configured();

$aec_checks = array(
	array(
		'done'  => $aec_retailers > 0,
		'label' => __( 'Add at least one retailer', 'aec-energy-compare' ),
		'help'  => __( 'Plans hang off retailers, so this comes first.', 'aec-energy-compare' ),
		'link'  => admin_url( 'admin.php?page=aec-retailers&action=new' ),
	),
	array(
		'done'  => ( $aec_elec + $aec_gas ) > 0,
		'label' => __( 'Add your energy plans and their rates', 'aec-energy-compare' ),
		'help'  => __( 'Without plans there is nothing to compare a bill against.', 'aec-energy-compare' ),
		'link'  => admin_url( 'admin.php?page=aec-plans&action=new' ),
	),
	array(
		'done'  => $aec_ai,
		'label' => __( 'Add a Claude API key for AI bill reading', 'aec-energy-compare' ),
		'help'  => __( 'Without it only the built-in text reader runs, which handles fewer bill layouts.', 'aec-energy-compare' ),
		'link'  => admin_url( 'admin.php?page=aec-settings' ),
	),
	array(
		'done'  => '' !== trim( (string) $aec_settings['lead_notify_email'] ),
		'label' => __( 'Set an address for enquiry notifications', 'aec-energy-compare' ),
		'help'  => __( 'Otherwise enquiries sit in the dashboard with nobody told about them.', 'aec-energy-compare' ),
		'link'  => admin_url( 'admin.php?page=aec-settings' ),
	),
);
?>
<div class="wrap aec-wrap">
	<h1><?php esc_html_e( 'Energy Comparison', 'aec-energy-compare' ); ?></h1>

	<div class="aec-cards">
		<div class="aec-card-stat">
			<span class="aec-card-stat__label"><?php esc_html_e( 'Active retailers', 'aec-energy-compare' ); ?></span>
			<span class="aec-card-stat__value"><?php echo esc_html( number_format_i18n( $aec_retailers ) ); ?></span>
		</div>
		<div class="aec-card-stat">
			<span class="aec-card-stat__label"><?php esc_html_e( 'Electricity plans', 'aec-energy-compare' ); ?></span>
			<span class="aec-card-stat__value"><?php echo esc_html( number_format_i18n( $aec_elec ) ); ?></span>
		</div>
		<div class="aec-card-stat">
			<span class="aec-card-stat__label"><?php esc_html_e( 'Gas plans', 'aec-energy-compare' ); ?></span>
			<span class="aec-card-stat__value"><?php echo esc_html( number_format_i18n( $aec_gas ) ); ?></span>
		</div>
		<div class="aec-card-stat">
			<span class="aec-card-stat__label"><?php esc_html_e( 'Bills uploaded', 'aec-energy-compare' ); ?></span>
			<span class="aec-card-stat__value"><?php echo esc_html( number_format_i18n( $aec_bills ) ); ?></span>
		</div>
		<div class="aec-card-stat">
			<span class="aec-card-stat__label"><?php esc_html_e( 'Enquiries', 'aec-energy-compare' ); ?></span>
			<span class="aec-card-stat__value">
				<?php echo esc_html( number_format_i18n( $aec_leads ) ); ?>
				<?php if ( $aec_leads_new > 0 ) : ?>
					<small><?php echo esc_html( sprintf( __( '%d new', 'aec-energy-compare' ), $aec_leads_new ) ); ?></small>
				<?php endif; ?>
			</span>
		</div>
	</div>

	<div class="aec-columns">
		<div class="aec-col">
			<h2><?php esc_html_e( 'Setup', 'aec-energy-compare' ); ?></h2>
			<ul class="aec-checklist">
				<?php foreach ( $aec_checks as $aec_check ) : ?>
					<li class="<?php echo $aec_check['done'] ? 'is-done' : 'is-todo'; ?>">
						<strong><?php echo esc_html( $aec_check['label'] ); ?></strong>
						<span><?php echo esc_html( $aec_check['help'] ); ?></span>
						<?php if ( ! $aec_check['done'] ) : ?>
							<a href="<?php echo esc_url( $aec_check['link'] ); ?>"><?php esc_html_e( 'Do this', 'aec-energy-compare' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2><?php esc_html_e( 'Put the form on a page', 'aec-energy-compare' ); ?></h2>
			<p><?php esc_html_e( 'Paste this shortcode into any page or post:', 'aec-energy-compare' ); ?></p>
			<p><code class="aec-code">[aec_compare]</code></p>
			<p class="description">
				<?php esc_html_e( 'Optional attributes: fuel="electricity" or fuel="gas" to pre-select the bill type, state="NSW" to pre-select the state, and title="..." to change the heading.', 'aec-energy-compare' ); ?>
			</p>
		</div>

		<div class="aec-col">
			<h2><?php esc_html_e( 'This server', 'aec-energy-compare' ); ?></h2>
			<table class="widefat striped aec-env">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'PDF text extraction', 'aec-energy-compare' ); ?></th>
						<td>
							<?php if ( $aec_pdftotext ) : ?>
								<span class="aec-pill aec-pill--good"><?php esc_html_e( 'pdftotext available', 'aec-energy-compare' ); ?></span>
							<?php else : ?>
								<span class="aec-pill aec-pill--warn"><?php esc_html_e( 'Built-in reader only', 'aec-energy-compare' ); ?></span>
								<p class="description"><?php esc_html_e( 'pdftotext is not available here, so the plugin falls back to its own parser. That is fine for most text PDFs.', 'aec-energy-compare' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'AI reading', 'aec-energy-compare' ); ?></th>
						<td>
							<?php if ( $aec_ai ) : ?>
								<span class="aec-pill aec-pill--good"><?php esc_html_e( 'Configured', 'aec-energy-compare' ); ?></span>
								<p class="description">
									<?php
									printf(
										/* translators: 1: model, 2: effort */
										esc_html__( 'Model %1$s, effort %2$s.', 'aec-energy-compare' ),
										esc_html( $aec_settings['anthropic_model'] ),
										esc_html( $aec_settings['anthropic_effort'] )
									);
									?>
								</p>
							<?php else : ?>
								<span class="aec-pill aec-pill--warn"><?php esc_html_e( 'Not configured', 'aec-energy-compare' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Upload limit', 'aec-energy-compare' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: plugin limit, 2: PHP limit */
								esc_html__( '%1$s MB (plugin), %2$s (PHP upload_max_filesize)', 'aec-energy-compare' ),
								esc_html( $aec_settings['max_upload_mb'] ),
								esc_html( ini_get( 'upload_max_filesize' ) )
							);
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bill storage', 'aec-energy-compare' ); ?></th>
						<td>
							<?php if ( empty( $aec_settings['retain_pdf'] ) ) : ?>
								<?php esc_html_e( 'PDFs are deleted straight after reading.', 'aec-energy-compare' ); ?>
							<?php else : ?>
								<span class="aec-pill aec-pill--warn"><?php esc_html_e( 'PDFs are kept', 'aec-energy-compare' ); ?></span>
								<p class="description"><?php echo esc_html( AEC_Install::upload_dir() ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Max execution time', 'aec-energy-compare' ); ?></th>
						<td>
							<?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s
							<?php if ( (int) ini_get( 'max_execution_time' ) > 0 && (int) ini_get( 'max_execution_time' ) < 60 ) : ?>
								<p class="description aec-warn-text">
									<?php esc_html_e( 'AI reading can take longer than this. Ask your host to raise it to at least 120 seconds.', 'aec-energy-compare' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</div>
