<?php
/**
 * Settings page.
 */

defined( 'ABSPATH' ) || exit;

$aec_s  = AEC_Settings::all();
$aec_op = AEC_Settings::OPTION;

// The key is never echoed back. A masked placeholder tells the admin one is
// saved; submitting the mask unchanged leaves the stored key alone.
$aec_key_set = '' !== trim( (string) $aec_s['anthropic_api_key'] );
?>
<div class="wrap aec-wrap">
	<h1><?php esc_html_e( 'Energy Comparison Settings', 'aec-energy-compare' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'aec_settings_group' ); ?>

		<h2><?php esc_html_e( 'Bill reading', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-mode"><?php esc_html_e( 'How bills are read', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-mode" name="<?php echo esc_attr( $aec_op ); ?>[extraction_mode]">
							<option value="local_then_ai" <?php selected( $aec_s['extraction_mode'], 'local_then_ai' ); ?>>
								<?php esc_html_e( 'Built-in reader first, AI only if needed (recommended)', 'aec-energy-compare' ); ?>
							</option>
							<option value="ai_only" <?php selected( $aec_s['extraction_mode'], 'ai_only' ); ?>>
								<?php esc_html_e( 'Always use AI', 'aec-energy-compare' ); ?>
							</option>
							<option value="local_only" <?php selected( $aec_s['extraction_mode'], 'local_only' ); ?>>
								<?php esc_html_e( 'Built-in reader only, never call the API', 'aec-energy-compare' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'The first option keeps costs down: the API is only called when the built-in reader comes up short, which is most bills it has not been tuned for.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-key"><?php esc_html_e( 'Claude API key', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="password" id="aec-key" class="regular-text" autocomplete="off"
							name="<?php echo esc_attr( $aec_op ); ?>[anthropic_api_key]"
							value="<?php echo $aec_key_set ? '****************' : ''; ?>">
						<p class="description">
							<?php esc_html_e( 'From console.anthropic.com. Leave the masked value alone to keep the current key; clear the field to remove it.', 'aec-energy-compare' ); ?>
							<?php if ( $aec_key_set ) : ?>
								<?php esc_html_e( 'There is a connection test below the Save button.', 'aec-energy-compare' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-model"><?php esc_html_e( 'Model', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-model" name="<?php echo esc_attr( $aec_op ); ?>[anthropic_model]">
							<option value="claude-opus-5" <?php selected( $aec_s['anthropic_model'], 'claude-opus-5' ); ?>>
								<?php esc_html_e( 'Claude Opus 5 - most accurate', 'aec-energy-compare' ); ?>
							</option>
							<option value="claude-sonnet-5" <?php selected( $aec_s['anthropic_model'], 'claude-sonnet-5' ); ?>>
								<?php esc_html_e( 'Claude Sonnet 5 - cheaper, slightly less accurate', 'aec-energy-compare' ); ?>
							</option>
							<option value="claude-haiku-4-5" <?php selected( $aec_s['anthropic_model'], 'claude-haiku-4-5' ); ?>>
								<?php esc_html_e( 'Claude Haiku 4.5 - cheapest and fastest', 'aec-energy-compare' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Money figures come off these bills, so accuracy is worth more than the few cents a read costs. Start on Opus 5 and only step down if the bills you see are simple.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-effort"><?php esc_html_e( 'Reading effort', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-effort" name="<?php echo esc_attr( $aec_op ); ?>[anthropic_effort]">
							<?php foreach ( array( 'low', 'medium', 'high', 'xhigh', 'max' ) as $aec_effort ) : ?>
								<option value="<?php echo esc_attr( $aec_effort ); ?>" <?php selected( $aec_s['anthropic_effort'], $aec_effort ); ?>>
									<?php echo esc_html( $aec_effort ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Higher effort reads awkward layouts better but takes longer. Drop to medium if your host has a short execution limit.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fallback model', 'aec-energy-compare' ); ?></th>
					<td>
						<label>
							<input type="checkbox" value="1" name="<?php echo esc_attr( $aec_op ); ?>[anthropic_fallbacks]"
								<?php checked( $aec_s['anthropic_fallbacks'], 1 ); ?>>
							<?php esc_html_e( 'If a read is declined, let another model answer in the same request', 'aec-energy-compare' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Recommended. Without it an occasional declined read fails the customer\'s upload outright.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-maxmb"><?php esc_html_e( 'Maximum upload size (MB)', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="number" id="aec-maxmb" min="1" max="30" step="1"
							name="<?php echo esc_attr( $aec_op ); ?>[max_upload_mb]"
							value="<?php echo esc_attr( $aec_s['max_upload_mb'] ); ?>">
						<p class="description">
							<?php
							printf(
								/* translators: %s: PHP limit */
								esc_html__( 'This server\'s own PHP limit is %s, which wins if it is lower.', 'aec-energy-compare' ),
								esc_html( ini_get( 'upload_max_filesize' ) )
							);
							?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Privacy and retention', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep the PDF', 'aec-energy-compare' ); ?></th>
					<td>
						<label>
							<input type="checkbox" value="1" name="<?php echo esc_attr( $aec_op ); ?>[retain_pdf]"
								<?php checked( $aec_s['retain_pdf'], 1 ); ?>>
							<?php esc_html_e( 'Store uploaded bills on the server after reading them', 'aec-energy-compare' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Off by default. A bill carries a name, address and meter number, so the safest thing is to read the figures and delete the file.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep the extracted text', 'aec-energy-compare' ); ?></th>
					<td>
						<label>
							<input type="checkbox" value="1" name="<?php echo esc_attr( $aec_op ); ?>[retain_raw_text]"
								<?php checked( $aec_s['retain_raw_text'], 1 ); ?>>
							<?php esc_html_e( 'Store the text read from each bill', 'aec-energy-compare' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Useful while the reader is being tuned to the bill layouts your customers actually send. Switch it off once you are happy.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-retention"><?php esc_html_e( 'Delete bills after (days)', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="number" id="aec-retention" min="0" max="3650" step="1"
							name="<?php echo esc_attr( $aec_op ); ?>[bill_retention_days]"
							value="<?php echo esc_attr( $aec_s['bill_retention_days'] ); ?>">
						<p class="description"><?php esc_html_e( 'Bills with an enquiry attached are never auto-deleted. Set to 0 to keep everything.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Comparison', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-count"><?php esc_html_e( 'Plans to show', 'aec-energy-compare' ); ?></label></th>
					<td><input type="number" id="aec-count" min="1" max="20" step="1"
						name="<?php echo esc_attr( $aec_op ); ?>[results_count]"
						value="<?php echo esc_attr( $aec_s['results_count'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-rank"><?php esc_html_e( 'Rank plans by', 'aec-energy-compare' ); ?></label></th>
					<td>
						<select id="aec-rank" name="<?php echo esc_attr( $aec_op ); ?>[rank_by]">
							<option value="guaranteed" <?php selected( $aec_s['rank_by'], 'guaranteed' ); ?>>
								<?php esc_html_e( 'Cost with guaranteed discounts only (recommended)', 'aec-energy-compare' ); ?>
							</option>
							<option value="conditional" <?php selected( $aec_s['rank_by'], 'conditional' ); ?>>
								<?php esc_html_e( 'Cost assuming conditional discounts are earned', 'aec-energy-compare' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'The first is the figure a customer will actually pay if they ever miss a condition. It ranks conservatively, which is the safer claim to make.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rates include GST', 'aec-energy-compare' ); ?></th>
					<td>
						<label>
							<input type="checkbox" value="1" name="<?php echo esc_attr( $aec_op ); ?>[rates_include_gst]"
								<?php checked( $aec_s['rates_include_gst'], 1 ); ?>>
							<?php esc_html_e( 'The rates I enter already include GST', 'aec-energy-compare' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Australian fact sheets normally quote rates including GST, and bills show GST-inclusive totals. Keep this on and enter rates exactly as the fact sheet shows them so the comparison is like for like.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Assumed time-of-use split', 'aec-energy-compare' ); ?></th>
					<td>
						<label>
							<?php esc_html_e( 'Peak', 'aec-energy-compare' ); ?>
							<input type="number" min="0" max="100" step="1" class="small-text"
								name="<?php echo esc_attr( $aec_op ); ?>[tou_profile_peak]"
								value="<?php echo esc_attr( $aec_s['tou_profile_peak'] ); ?>">%
						</label>
						<label>
							<?php esc_html_e( 'Shoulder', 'aec-energy-compare' ); ?>
							<input type="number" min="0" max="100" step="1" class="small-text"
								name="<?php echo esc_attr( $aec_op ); ?>[tou_profile_shoulder]"
								value="<?php echo esc_attr( $aec_s['tou_profile_shoulder'] ); ?>">%
						</label>
						<label>
							<?php esc_html_e( 'Off-peak', 'aec-energy-compare' ); ?>
							<input type="number" min="0" max="100" step="1" class="small-text"
								name="<?php echo esc_attr( $aec_op ); ?>[tou_profile_offpeak]"
								value="<?php echo esc_attr( $aec_s['tou_profile_offpeak'] ); ?>">%
						</label>
						<p class="description">
							<?php esc_html_e( 'Used only when a customer\'s bill is a single flat rate and a plan is time of use, so there is no real split to work from. Those plans are labelled as an estimate on the results page. The three must add up to 100.', 'aec-energy-compare' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Enquiries', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-notify"><?php esc_html_e( 'Notify these addresses', 'aec-energy-compare' ); ?></label></th>
					<td>
						<input type="text" id="aec-notify" class="regular-text"
							name="<?php echo esc_attr( $aec_op ); ?>[lead_notify_email]"
							value="<?php echo esc_attr( $aec_s['lead_notify_email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Comma separated for more than one.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Phone number', 'aec-energy-compare' ); ?></th>
					<td>
						<label>
							<input type="checkbox" value="1" name="<?php echo esc_attr( $aec_op ); ?>[lead_require_phone]"
								<?php checked( $aec_s['lead_require_phone'], 1 ); ?>>
							<?php esc_html_e( 'Require a phone number on the enquiry form', 'aec-energy-compare' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-consent"><?php esc_html_e( 'Consent wording', 'aec-energy-compare' ); ?></label></th>
					<td>
						<textarea id="aec-consent" rows="2" class="large-text"
							name="<?php echo esc_attr( $aec_op ); ?>[consent_text]"><?php echo esc_textarea( $aec_s['consent_text'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'The wording shown next to the tick box. The exact text is stored with each enquiry, so editing it later does not rewrite what past customers agreed to.', 'aec-energy-compare' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-privacy"><?php esc_html_e( 'Privacy policy URL', 'aec-energy-compare' ); ?></label></th>
					<td><input type="url" id="aec-privacy" class="regular-text"
						name="<?php echo esc_attr( $aec_op ); ?>[privacy_url]"
						value="<?php echo esc_attr( $aec_s['privacy_url'] ); ?>"></td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Appearance', 'aec-energy-compare' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="aec-accent"><?php esc_html_e( 'Accent colour', 'aec-energy-compare' ); ?></label></th>
					<td><input type="color" id="aec-accent"
						name="<?php echo esc_attr( $aec_op ); ?>[accent_colour]"
						value="<?php echo esc_attr( $aec_s['accent_colour'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-currency"><?php esc_html_e( 'Currency symbol', 'aec-energy-compare' ); ?></label></th>
					<td><input type="text" id="aec-currency" size="4"
						name="<?php echo esc_attr( $aec_op ); ?>[currency_symbol]"
						value="<?php echo esc_attr( $aec_s['currency_symbol'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aec-intro"><?php esc_html_e( 'Intro text', 'aec-energy-compare' ); ?></label></th>
					<td><textarea id="aec-intro" rows="2" class="large-text"
						name="<?php echo esc_attr( $aec_op ); ?>[intro_text]"><?php echo esc_textarea( $aec_s['intro_text'] ); ?></textarea></td>
				</tr>
			</tbody>
		</table>

		<?php submit_button(); ?>
	</form>

	<?php if ( $aec_key_set ) : ?>
		<?php
		// Deliberately outside the settings form: a form inside a form is
		// invalid markup and the inner submit never posts where you expect.
		?>
		<h2><?php esc_html_e( 'Connection test', 'aec-energy-compare' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Sends one tiny request so you find out the key works from this server, rather than finding out when a customer uploads a bill.', 'aec-energy-compare' ); ?>
		</p>
		<?php AEC_Admin::button_form( 'aec_test_api', array(), __( 'Test the connection', 'aec-energy-compare' ), 'button' ); ?>
	<?php endif; ?>
</div>
