# Energy Bill Comparison

A WordPress plugin that takes an Australian electricity or gas bill as a PDF,
reads the usage and rates off it, lets the customer confirm what was read, and
compares it against the energy plans you hold in WordPress.

Everything runs inside WordPress. There is no external service beyond the
optional AI reader, and no build step.

---

## What it does

1. **Upload.** The customer drops in a bill PDF. The file is validated on its
   bytes, not its extension, and password-protected documents are rejected with
   a plain-English message.
2. **Read.** The plugin pulls the text layer out of the PDF and extracts the
   fields. Two readers are available and they stack (see *Reading a bill*).
3. **Confirm.** Anything the reader was not sure about is highlighted on screen
   with a "check" badge. Nothing is ever guessed: a figure that could not be
   read confidently comes back blank for the customer to fill in.
4. **Compare.** The confirmed usage is scaled to a full year and costed against
   every matching plan. Results are ranked cheapest first with a full,
   line-by-line breakdown behind a toggle.
5. **Capture.** The customer picks a plan and leaves their details. The enquiry
   lands in the dashboard, and optionally in your inbox.

## Getting started

1. Upload the plugin folder to `wp-content/plugins/` and activate it.
2. **Energy Compare → Retailers** — add the retailers whose plans you sell.
3. **Energy Compare → Plans & Tariffs** — add each plan and its rates. There is
   a *Load sample plans* button on an empty catalogue if you want to try the
   calculator before entering real data. Sample rates are placeholders and say
   so on the front end; delete them before going live.
4. **Energy Compare → Settings** — paste a Claude API key if you want AI
   reading, and set an address for enquiry notifications.
5. Put `[aec_compare]` on a page.

Shortcode attributes, all optional:

```
[aec_compare fuel="electricity" state="NSW" title="Compare your power bill"]
```

## Reading a bill

| Reader | What it is | Cost |
|---|---|---|
| Built-in | `pdftotext` when the host has it, otherwise a bundled PDF parser, then a set of rules tuned to how Australian bills label things. | Free |
| AI | The PDF (or its text) is sent to Claude with a strict JSON schema and instructions not to guess. Handles layouts the rules have never seen, and scanned bills with no text layer. | Per read |

The default mode is **built-in first, AI only if needed**: the API is called
only when the rules come up short, which keeps the running cost down. You can
also force AI on every bill, or switch the API off entirely.

The AI request pins the response to a JSON schema where every field is nullable,
so an unreadable figure arrives as `null` rather than as a confident wrong
number. The prompt spells out the traps on a real bill: year-on-year comparison
graphs, average-daily-usage boxes, the usage-history table, and the difference
between "total amount due" and this period's charges.

### What the readers extract

Fuel type, retailer, plan name, NMI or MIRN, postcode and state, billing period
and day count, peak / shoulder / off-peak kWh, total kWh, controlled load, solar
export, gas MJ, peak demand in kW, the daily supply charge, usage rates, the
solar feed-in rate, and the bill total.

## How the comparison works

Everything is annualised first. A bill covering 91 days at 1,000 kWh becomes
`1000 × 365 ÷ 91` kWh a year, and every rate is applied to that annual figure.

For each plan:

```
  daily supply charge × 365
+ usage, stepped through the plan's rate blocks
+ controlled load
+ demand charge (kW × c/kW/day × 365)
- guaranteed discount
- solar feed-in credit
= estimated annual cost
```

Conditional discounts (pay-on-time and similar) are shown **separately** rather
than folded into the headline price, and ranking uses the guaranteed figure by
default. That is the number a customer actually pays the first time they miss a
condition, so it is the safer claim to make. You can switch ranking to the
conditional figure in Settings.

Sign-up credits appear in a separate first-year figure, never in the ongoing
annual cost.

### Rate blocks

A block's size is the size of that band for the period you pick, which is how
energy fact sheets word it: *first 1000 kWh per quarter, then...*. Leave the
last block's size blank to mean "everything after that".

### When the plugin has to assume something, it says so

| Situation | What happens | Shown as |
|---|---|---|
| Bill is a single flat rate, plan is time-of-use | Usage is split by the configurable profile in Settings (default 35/25/40) | "Estimate" tag + explanation |
| Plan has a demand charge, bill shows no demand figure | The charge is left out and the plan is flagged, so a demand plan never looks artificially cheap | "Estimate" tag + explanation |
| Customer has controlled load, plan has no controlled-load rate | That energy is charged at the normal rate rather than given away free | Explanation on the card |
| Plan has no shoulder band | Shoulder usage is charged at the peak rate | Explanation on the card |
| Usage exceeds every block the plan defines | The overflow is charged at the last listed rate, not dropped | Explanation on the card |
| Plan has no usage rate at all | The plan is skipped and listed as skipped in the admin, never costed at zero | Admin only |

The principle throughout: a missing figure must never make a plan look cheaper
than it is.

## Privacy

A bill carries a name, address and meter number, so the defaults are cautious:

- The PDF is **deleted from the server as soon as it has been read**. Only the
  extracted figures are kept. You can turn retention on if you need it.
- Stored bills live in `wp-content/uploads/aec-bills/` with unguessable
  filenames and a deny rule for Apache.
- IP addresses are stored as a salted hash, never in the clear.
- Bills with no enquiry attached are deleted after a configurable number of days.
  Bills attached to an enquiry are never auto-deleted; they are business records.
- The consent wording shown on screen is stored **with each enquiry**, so editing
  the wording later does not rewrite what past customers agreed to.
- Deleting the plugin does not drop your data. To remove everything, define
  `AEC_REMOVE_ALL_DATA` as `true` in `wp-config.php` first.

## Requirements

- WordPress 6.0 or newer (tested on 7.1)
- PHP 7.4 or newer (tested on 8.3)
- `zlib` for reading compressed PDFs, which is standard
- `pdftotext` is used when available but is not required

The dashboard reports what this particular server can do, including whether
`pdftotext` is present and whether the PHP execution limit is long enough for AI
reads.

## Admin screens

| Screen | What it is for |
|---|---|
| Dashboard | Counts, a setup checklist, and what this server supports |
| Retailers | Retailers, their logos, and the keywords used to recognise them on a bill |
| Plans & Tariffs | Plans, rate blocks, discounts, contract terms, postcode coverage |
| Uploaded Bills | Every upload, what was read off it, and the comparison as it stands today |
| Enquiries | Leads, status tracking, CSV export |
| Settings | Reading mode, API key, privacy, ranking, consent wording, appearance |

## Theming

The form inherits your theme's fonts and spacing and takes its accent colour
from Settings. Everything is scoped under `.aec-app` so it will not disturb the
rest of the site.

To change the markup, copy `templates/compare-form.php` into your theme at
`aec-energy-compare/compare-form.php` and edit it there; it will survive plugin
updates.

## Tests

The build is covered by three layers, all run against a real WordPress install:

- a unit suite over the calculator, extractor, storage and settings
- an end-to-end pass that puts real PDFs through extraction and comparison
- a browser pass that drives the form in Chromium and screenshots each step

Expected costs in the suite are worked out longhand from the tariffs rather than
by calling the plugin's own helpers, so the tests can actually disagree with the
calculator.

## Status

The comparison engine, the admin, the storage and the front end are complete and
tested. The rule-based reader is tuned against the bill layouts available so far;
it gets better the more real bills it is pointed at, and the AI reader is there
to cover everything it has not seen. Send through real bills from the retailers
your customers actually use and the rules get extended to match them.
