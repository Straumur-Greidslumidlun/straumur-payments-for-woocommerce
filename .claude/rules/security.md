# Security Rules (§1.x)

Scope: credentials, card and shopper data, webhooks, admin input, releases. This plugin runs on merchants' own WordPress sites, which Straumur does not control, and handles their Straumur API key and saved card tokens. Treat everything it writes to disk, logs or the browser as readable by people other than the merchant.

## §1.1 No secrets or shopper data in logs `[ENFORCED]`
NEVER pass request headers, request or response bodies, or webhook payloads to a logger. Reduce them with `WC_Straumur_Log_Redactor::summarize()` and log the result. To log a new field, add it to `LOGGABLE_FIELDS` and justify it in the PR. Never add credentials (API key, HMAC key, theme key), card data or tokens (`tokenValue`, `cardNumber`, `cardSummary`, `authCode`), shopper details (IP, name, address, email) or URLs that grant access (payment page URL, return URL with the WooCommerce order key, 3DS action URL). `logger->error()` in the gateway writes even with `WP_DEBUG` off.

## §1.2 Webhook signatures `[ENFORCED]`
WHEN changing `WC_Straumur_Webhook_Handler::validate_hmac_signature()`, keep the field order and `:` join identical to the backend's `HmacValidationHelper`, keep `hash_equals()` for the comparison, and reject a missing or non-hex HMAC key. A webhook that fails validation must not change an order.

## §1.3 Admin input and output `[ENFORCED by CI]`
Escape all output (`esc_html`, `esc_attr`, `esc_url`), sanitise all input, check nonces on anything that changes state. `phpcs.xml.dist` (WordPress.Security) fails the PR otherwise. Use `phpcs:ignore` only with the sniff name and a reason on the same line.

## §1.4 No credentials as defaults `[CONVENTION]`
DO NOT add real API keys, HMAC keys or terminal identifiers as setting defaults or in code. The plugin is public on WordPress.org. Existing defaults in `WC_Straumur_Settings::get_form_fields()` are a known open question (which account they belong to); do not add more.

## §1.5 Releases reach every merchant `[requires human approval]`
NEVER push a version tag or approve the `wordpress-org` deployment. A WordPress.org release is a production deploy to every store with auto-updates on, so it needs a CR (`change-request` skill) and a human approver on the environment. Do not edit `.github/workflows/deploy.yml`, the environment or its secrets without explicit approval.

## §1.6 Supply chain `[CONVENTION]`
Pin GitHub Actions to a full commit SHA with the version in a comment. WHEN adding an npm or Composer dependency, check whether it ships in the package (`.distignore`) and whether it is actually used; the unused `@woocommerce/dependency-extraction-webpack-plugin` broke the build in October 2026.
