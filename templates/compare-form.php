<?php
/**
 * The front-end comparison form.
 *
 * Override by copying to `aec-energy-compare/compare-form.php` in your theme.
 *
 * @var array $atts
 * @var array $settings
 * @var array $states
 */

defined( 'ABSPATH' ) || exit;

$aec_title = '' !== $atts['title'] ? $atts['title'] : __( 'Compare your energy bill', 'aec-energy-compare' );
?>
<div class="aec-app" id="aec-app"
	data-fuel="<?php echo esc_attr( $atts['fuel'] ); ?>"
	data-state="<?php echo esc_attr( $atts['state'] ); ?>"
	style="--aec-accent: <?php echo esc_attr( $settings['accent_colour'] ); ?>;">

	<ol class="aec-steps" aria-label="<?php esc_attr_e( 'Progress', 'aec-energy-compare' ); ?>">
		<li class="aec-step is-current" data-step="upload"><span>1</span><?php esc_html_e( 'Upload', 'aec-energy-compare' ); ?></li>
		<li class="aec-step" data-step="review"><span>2</span><?php esc_html_e( 'Check', 'aec-energy-compare' ); ?></li>
		<li class="aec-step" data-step="results"><span>3</span><?php esc_html_e( 'Results', 'aec-energy-compare' ); ?></li>
	</ol>

	<div class="aec-alert" id="aec-alert" role="alert" hidden></div>

	<!-- Step 1: upload ------------------------------------------------------>
	<section class="aec-panel" id="aec-panel-upload" aria-labelledby="aec-upload-title">
		<h2 class="aec-panel__title" id="aec-upload-title"><?php echo esc_html( $aec_title ); ?></h2>

		<?php if ( '' !== trim( (string) $settings['intro_text'] ) ) : ?>
			<p class="aec-lede"><?php echo esc_html( $settings['intro_text'] ); ?></p>
		<?php endif; ?>

		<form id="aec-upload-form" class="aec-form" novalidate>
			<div class="aec-drop" id="aec-drop">
				<input type="file" id="aec-file" name="bill" accept="application/pdf,.pdf" class="aec-drop__input">
				<label for="aec-file" class="aec-drop__label">
					<svg class="aec-drop__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path d="M12 16V4m0 0L8 8m4-4 4 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
					</svg>
					<span class="aec-drop__primary"><?php esc_html_e( 'Choose your bill PDF', 'aec-energy-compare' ); ?></span>
					<span class="aec-drop__secondary">
						<?php
						printf(
							/* translators: %d: megabytes */
							esc_html__( 'or drop it here. PDF, up to %d MB.', 'aec-energy-compare' ),
							(int) $settings['max_upload_mb']
						);
						?>
					</span>
				</label>
				<p class="aec-drop__chosen" id="aec-chosen" hidden></p>
			</div>

			<p class="aec-privacy">
				<?php if ( empty( $settings['retain_pdf'] ) ) : ?>
					<?php esc_html_e( 'Your bill is read and then deleted from the server. Only the usage figures are kept.', 'aec-energy-compare' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Your bill is stored securely and used only to work out your comparison.', 'aec-energy-compare' ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $settings['privacy_url'] ) ) : ?>
					<a href="<?php echo esc_url( $settings['privacy_url'] ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Privacy policy', 'aec-energy-compare' ); ?>
					</a>
				<?php endif; ?>
			</p>

			<div class="aec-actions">
				<button type="submit" class="aec-btn aec-btn--primary" id="aec-upload-submit" disabled>
					<?php esc_html_e( 'Read my bill', 'aec-energy-compare' ); ?>
				</button>
				<button type="button" class="aec-btn aec-btn--ghost" id="aec-manual">
					<?php esc_html_e( 'Enter my details by hand instead', 'aec-energy-compare' ); ?>
				</button>
			</div>
		</form>

		<div class="aec-progress" id="aec-progress" hidden>
			<div class="aec-progress__bar"><span></span></div>
			<p class="aec-progress__text" id="aec-progress-text"></p>
		</div>
	</section>

	<!-- Step 2: review ------------------------------------------------------>
	<section class="aec-panel" id="aec-panel-review" aria-labelledby="aec-review-title" hidden>
		<h2 class="aec-panel__title" id="aec-review-title"><?php esc_html_e( 'Check these details', 'aec-energy-compare' ); ?></h2>
		<p class="aec-lede"><?php esc_html_e( 'Anything we were unsure about is marked. Correct anything that looks wrong before we compare.', 'aec-energy-compare' ); ?></p>

		<div class="aec-notice aec-notice--warn" id="aec-review-warnings" hidden></div>

		<form id="aec-review-form" class="aec-form">
			<fieldset class="aec-fieldset">
				<legend><?php esc_html_e( 'Your bill', 'aec-energy-compare' ); ?></legend>

				<div class="aec-grid">
					<p class="aec-field" data-field="fuel">
						<label for="aec-fuel"><?php esc_html_e( 'Bill type', 'aec-energy-compare' ); ?></label>
						<select id="aec-fuel" name="fuel">
							<option value="electricity"><?php esc_html_e( 'Electricity', 'aec-energy-compare' ); ?></option>
							<option value="gas"><?php esc_html_e( 'Gas', 'aec-energy-compare' ); ?></option>
						</select>
					</p>

					<p class="aec-field" data-field="postcode">
						<label for="aec-postcode"><?php esc_html_e( 'Postcode', 'aec-energy-compare' ); ?></label>
						<input type="text" id="aec-postcode" name="postcode" inputmode="numeric" maxlength="4" autocomplete="postal-code">
					</p>

					<p class="aec-field" data-field="state">
						<label for="aec-state"><?php esc_html_e( 'State', 'aec-energy-compare' ); ?></label>
						<select id="aec-state" name="state">
							<option value=""><?php esc_html_e( 'Select', 'aec-energy-compare' ); ?></option>
							<?php foreach ( $states as $aec_code => $aec_label ) : ?>
								<option value="<?php echo esc_attr( $aec_code ); ?>"><?php echo esc_html( $aec_code ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>

					<p class="aec-field" data-field="billing_days">
						<label for="aec-days"><?php esc_html_e( 'Days on this bill', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-days" name="billing_days" min="1" max="400" step="1">
					</p>

					<p class="aec-field" data-field="total_amount">
						<label for="aec-total">
							<?php
							printf(
								/* translators: %s: currency symbol */
								esc_html__( 'Total on this bill (%s)', 'aec-energy-compare' ),
								esc_html( $settings['currency_symbol'] )
							);
							?>
						</label>
						<input type="number" id="aec-total" name="total_amount" min="0" step="0.01">
					</p>

					<p class="aec-field" data-field="retailer_name">
						<label for="aec-retailer"><?php esc_html_e( 'Current retailer', 'aec-energy-compare' ); ?></label>
						<input type="text" id="aec-retailer" name="retailer_name" autocomplete="off">
					</p>
				</div>
			</fieldset>

			<fieldset class="aec-fieldset" id="aec-usage-electricity">
				<legend><?php esc_html_e( 'Electricity usage', 'aec-energy-compare' ); ?></legend>
				<p class="aec-hint"><?php esc_html_e( 'Fill in the time-of-use rows if your bill splits usage into peak, shoulder and off-peak. Otherwise just the total.', 'aec-energy-compare' ); ?></p>

				<div class="aec-grid">
					<p class="aec-field" data-field="usage.total_kwh">
						<label for="aec-total-kwh"><?php esc_html_e( 'Total usage (kWh)', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-total-kwh" name="usage[total_kwh]" min="0" step="0.01">
					</p>
					<p class="aec-field" data-field="usage.peak_kwh">
						<label for="aec-peak"><?php esc_html_e( 'Peak (kWh)', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-peak" name="usage[peak_kwh]" min="0" step="0.01">
					</p>
					<p class="aec-field" data-field="usage.shoulder_kwh">
						<label for="aec-shoulder"><?php esc_html_e( 'Shoulder (kWh)', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-shoulder" name="usage[shoulder_kwh]" min="0" step="0.01">
					</p>
					<p class="aec-field" data-field="usage.offpeak_kwh">
						<label for="aec-offpeak"><?php esc_html_e( 'Off-peak (kWh)', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-offpeak" name="usage[offpeak_kwh]" min="0" step="0.01">
					</p>
					<p class="aec-field" data-field="usage.controlled_load_kwh">
						<label for="aec-cl"><?php esc_html_e( 'Controlled load (kWh)', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-cl" name="usage[controlled_load_kwh]" min="0" step="0.01">
					</p>
					<p class="aec-field" data-field="usage.solar_export_kwh">
						<label for="aec-solar"><?php esc_html_e( 'Solar exported (kWh)', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-solar" name="usage[solar_export_kwh]" min="0" step="0.01">
					</p>
					<p class="aec-field" data-field="usage.demand_kw">
						<label for="aec-demand"><?php esc_html_e( 'Peak demand (kW)', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-demand" name="usage[demand_kw]" min="0" step="0.01">
					</p>
				</div>
			</fieldset>

			<fieldset class="aec-fieldset" id="aec-usage-gas" hidden>
				<legend><?php esc_html_e( 'Gas usage', 'aec-energy-compare' ); ?></legend>
				<div class="aec-grid">
					<p class="aec-field" data-field="usage.gas_mj">
						<label for="aec-gas"><?php esc_html_e( 'Total usage (MJ)', 'aec-energy-compare' ); ?></label>
						<input type="number" id="aec-gas" name="usage[gas_mj]" min="0" step="0.01">
					</p>
				</div>
			</fieldset>

			<div class="aec-actions">
				<button type="submit" class="aec-btn aec-btn--primary"><?php esc_html_e( 'Compare plans', 'aec-energy-compare' ); ?></button>
				<button type="button" class="aec-btn aec-btn--ghost" data-goto="upload"><?php esc_html_e( 'Start again', 'aec-energy-compare' ); ?></button>
			</div>
		</form>
	</section>

	<!-- Step 3: results ----------------------------------------------------->
	<section class="aec-panel" id="aec-panel-results" aria-labelledby="aec-results-title" hidden>
		<h2 class="aec-panel__title" id="aec-results-title"><?php esc_html_e( 'Your options', 'aec-energy-compare' ); ?></h2>

		<div class="aec-summary" id="aec-summary"></div>
		<div class="aec-notice" id="aec-result-notes" hidden></div>
		<div class="aec-results" id="aec-results"></div>

		<div class="aec-actions">
			<button type="button" class="aec-btn aec-btn--ghost" data-goto="review"><?php esc_html_e( 'Change my details', 'aec-energy-compare' ); ?></button>
		</div>

		<p class="aec-disclaimer">
			<?php
			esc_html_e( 'These figures are estimates based on the usage on your bill scaled to a full year, and on the plan rates held on this site. Your actual cost depends on your real usage and the rates in the retailer\'s own energy fact sheet.', 'aec-energy-compare' );
			?>
		</p>
	</section>

	<!-- Step 4: lead -------------------------------------------------------->
	<section class="aec-panel" id="aec-panel-lead" aria-labelledby="aec-lead-title" hidden>
		<h2 class="aec-panel__title" id="aec-lead-title"><?php esc_html_e( 'Get this sorted', 'aec-energy-compare' ); ?></h2>
		<p class="aec-lede" id="aec-lead-intro"></p>

		<form id="aec-lead-form" class="aec-form">
			<div class="aec-grid">
				<p class="aec-field">
					<label for="aec-name"><?php esc_html_e( 'Your name', 'aec-energy-compare' ); ?> <span class="aec-req">*</span></label>
					<input type="text" id="aec-name" name="name" required autocomplete="name">
				</p>
				<p class="aec-field">
					<label for="aec-email"><?php esc_html_e( 'Email', 'aec-energy-compare' ); ?> <span class="aec-req">*</span></label>
					<input type="email" id="aec-email" name="email" required autocomplete="email">
				</p>
				<p class="aec-field">
					<label for="aec-phone">
						<?php esc_html_e( 'Phone', 'aec-energy-compare' ); ?>
						<?php if ( ! empty( $settings['lead_require_phone'] ) ) : ?>
							<span class="aec-req">*</span>
						<?php endif; ?>
					</label>
					<input type="tel" id="aec-phone" name="phone" autocomplete="tel"
						<?php echo ! empty( $settings['lead_require_phone'] ) ? 'required' : ''; ?>>
				</p>
				<p class="aec-field">
					<label for="aec-contact"><?php esc_html_e( 'Best way to reach you', 'aec-energy-compare' ); ?></label>
					<select id="aec-contact" name="preferred_contact">
						<option value="email"><?php esc_html_e( 'Email', 'aec-energy-compare' ); ?></option>
						<option value="phone"><?php esc_html_e( 'Phone', 'aec-energy-compare' ); ?></option>
						<option value="either"><?php esc_html_e( 'Either', 'aec-energy-compare' ); ?></option>
					</select>
				</p>
			</div>

			<p class="aec-field">
				<label for="aec-message"><?php esc_html_e( 'Anything else we should know?', 'aec-energy-compare' ); ?></label>
				<textarea id="aec-message" name="message" rows="3"></textarea>
			</p>

			<p class="aec-field aec-field--check">
				<label>
					<input type="checkbox" name="consent" value="1" required>
					<span><?php echo esc_html( $settings['consent_text'] ); ?></span>
				</label>
			</p>

			<div class="aec-actions">
				<button type="submit" class="aec-btn aec-btn--primary"><?php esc_html_e( 'Send my details', 'aec-energy-compare' ); ?></button>
				<button type="button" class="aec-btn aec-btn--ghost" data-goto="results"><?php esc_html_e( 'Back to results', 'aec-energy-compare' ); ?></button>
			</div>
		</form>
	</section>

	<!-- Done ---------------------------------------------------------------->
	<section class="aec-panel aec-panel--done" id="aec-panel-done" hidden>
		<h2 class="aec-panel__title"><?php esc_html_e( 'Thanks, that is on its way', 'aec-energy-compare' ); ?></h2>
		<p class="aec-lede" id="aec-done-message"></p>
	</section>
</div>
