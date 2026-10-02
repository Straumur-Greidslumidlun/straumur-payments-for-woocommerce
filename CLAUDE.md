# Straumur WooCommerce Plugin Development Guide

## Overview

The Straumur Payments for WooCommerce plugin is a PHP-based WordPress plugin that integrates Straumur's Hosted Checkout payment system with WooCommerce. It supports both traditional checkout and the new WooCommerce block-based checkout, subscriptions, and various payment management features.

## Development Commands

### Asset Building & Development
```bash
# Build JavaScript assets for production (frontend blocks)
npm run build

# Start development server with watch mode
npm start

# Update @wordpress/scripts and related packages
npm run packages-update

# Check if project meets engine requirements
npm run check-engines
```

### Internationalization (i18n)
```bash
# Generate .pot file from source code
npm run make:pot

# Update existing .po files with new strings from .pot
npm run merge:po

# Compile .po files to .mo files
npm run make:mo

# Complete translation workflow (pot → po → mo)
npm run update:translations
```

### Git Workflow
- `dev` branch: Development branch (default). PRs target `dev`.
- `main` branch: Production branch. Only release PRs from `dev`; every release is a tag on `main`.
- Branches: `feat|fix|bug|task/SD-XXXX-description`, with the Jira key in the PR title as well.
- Both `dev` and `main` require the six CI checks (below), an up-to-date branch and one approving review.
- Merge with a merge commit, not squash: `.git-blame-ignore-revs` refers to commits by hash.

Security and release rules are in `.claude/rules/security.md` and apply to every change.

### Checklist for a user-facing change
1. Rebuild and commit `assets/js/frontend` if `src/` changed (CI fails otherwise).
2. New or changed admin strings: add them to `languages/straumur-payments-for-woocommerce.pot` and the Icelandic `.po`, then compile the `.mo`. Most merchants run the admin in Icelandic.
3. New setting: document it under *Plugin Settings Documentation* in `readme.txt` and keep stores that upgrade unchanged (a missing option must mean today's behaviour).
4. Add a line to the next version's `== Changelog ==` entry in `readme.txt`.
5. The public docs page (straumur-documentation `docs/04-ecommerce-plugins/02-woocommerce.md`) lists features and the tested-up-to versions; update it in the same release.

## High-Level Architecture

### Plugin Structure

```
straumur-payments-for-woocommerce/
├── straumur-payments-for-woocommerce.php    # Main plugin file & bootstrap (header, version constant, require_once list)
├── includes/                                # Core PHP classes
│   ├── class-wc-straumur-payment-gateway.php    # Main payment gateway, checkout logos (get_icon)
│   ├── class-wc-straumur-api.php                # API communication layer
│   ├── class-wc-straumur-settings.php           # Settings fields, getters, payment logo registry
│   ├── class-wc-straumur-order-handler.php      # Order lifecycle management
│   ├── class-wc-straumur-block-support.php      # WooCommerce blocks integration
│   ├── class-wc-straumur-webhook-handler.php    # Webhook processing
│   └── class-wc-straumur-log-redactor.php       # Allow-list for everything written to logs
├── src/                                     # JavaScript source files
│   └── index.js                            # Block-based checkout React component
├── assets/                                  # Compiled assets & images (committed)
│   ├── js/frontend/                        # Compiled JavaScript
│   ├── css/                                # Checkout logo styles
│   └── images/                             # Plugin icons & payment method logos
├── languages/                              # .pot, Icelandic .po/.mo
├── .github/workflows/                      # ci.yml (PR checks), deploy.yml (WordPress.org release)
├── .github/scripts/check-versions.sh       # Version consistency check used by both workflows
├── phpcs.xml.dist                          # Coding standards ruleset used by CI
└── .nvmrc                                  # Node version for builds (CI and release)
```

### Core Components

#### 1. Payment Gateway (`WC_Straumur_Payment_Gateway`)
- Extends `WC_Payment_Gateway`
- Handles payment processing, redirects, and form fields
- Supports subscriptions, blocks, and various WooCommerce features
- Manages order creation and payment session initialization

#### 2. API Layer (`WC_Straumur_API`)
- A new instance per use (`new WC_Straumur_API()`), reading credentials from `WC_Straumur_Settings`
- Handles HTTP requests to Straumur's REST API
- Manages payment sessions, token payments, captures, refunds, and cancellations
- Logs only through `WC_Straumur_Log_Redactor` (see `.claude/rules/security.md` §1.1)

#### 3. Settings Management (`WC_Straumur_Settings`)
- Form field definitions (`get_form_fields()`) for the gateway settings page
- One static getter per setting, reading the cached `woocommerce_straumur_settings` option
- Payment method logo registry and `get_payment_logos()`; a store that never saved the setting gets the four default logos

#### 4. Order Handler (`WC_Straumur_Order_Handler`)
- Manages order lifecycle transitions
- Handles captures, refunds, and cancellations
- Integrates with WooCommerce order status hooks
- Provides error handling and logging for payment operations

#### 5. Block Support (`WC_Straumur_Block_Support`)
- Extends `AbstractPaymentMethodType` for WooCommerce Blocks
- Registers React-based payment method for block checkout
- Handles script enqueuing and configuration data

#### 6. Webhook Handler (`WC_Straumur_Webhook_Handler`)
- Processes incoming webhooks from Straumur
- Validates HMAC signatures for security
- Updates order statuses based on payment events
- Implements REST API endpoint for webhook reception

### JavaScript Architecture

#### Block-Based Checkout Integration
- **Source**: `src/index.js` (React/JSX)
- **Build Output**: `assets/js/frontend/straumur-block-payment-method.js`
- **Dependencies**: WordPress Scripts (@wordpress/scripts)
- **External Dependencies**: WooCommerce Blocks Registry, Settings API

The JavaScript component provides:
- Payment method registration with WooCommerce Blocks
- Localized labels and descriptions
- Integration with WooCommerce's payment processing flow

## WordPress/WooCommerce Integration Patterns

### Plugin Initialization
```php
// Bootstrap pattern with dependency checking
add_action('plugins_loaded', __NAMESPACE__ . '\\straumur_payments_init');
```

### Payment Gateway Registration
```php
// Hook into WooCommerce payment gateways filter
add_filter('woocommerce_payment_gateways', __NAMESPACE__ . '\\add_straumur_payment_gateway');
```

### Order Status Hooks
```php
// Hook into order status transitions for payment management
add_action('woocommerce_order_status_on-hold_to_processing', $capture_handler);
add_action('woocommerce_order_status_on-hold_to_cancelled', $cancel_handler);
```

### Block Integration
```php
// Register payment method with WooCommerce Blocks
add_action('woocommerce_blocks_payment_method_type_registration', $register_callback);
```

### Feature Compatibility Declarations
```php
// Declare support for WooCommerce features
\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
    'cart_checkout_blocks', __FILE__, true
);
\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
    'custom_order_tables', __FILE__, true
);
```

## Asset Building Process

### WordPress Scripts (@wordpress/scripts)
- Uses WordPress's official build tools
- Webpack-based bundling with WordPress-specific externals
- React/JSX compilation for block components
- Automatic dependency extraction

### Build Configuration
```json
{
  "build": "wp-scripts build src/index.js --output-path=assets/js/frontend --output-filename=straumur-block-payment-method.js --externals=@woocommerce/blocks-registry=wc-blocks-registry --externals=@woocommerce/settings=wc-settings"
}
```

### External Dependencies
- `@woocommerce/blocks-registry`: Payment method registration
- `@woocommerce/settings`: Configuration data access
- WordPress core packages (i18n, html-entities, element)

## Development Patterns & Conventions

### PHP Standards
- **PHP 7.4+** minimum requirement
- **Strict typing**: `declare(strict_types=1)` in all files
- **Namespacing**: `Straumur\Payments` namespace
- **WordPress Coding Standards**: Follows WordPress PHP coding conventions
- **Security**: Input sanitization, output escaping, nonce verification

### Error Handling & Logging
```php
// Never log a payload as is: reduce it to the allow-listed fields first.
$this->logger = wc_get_logger();
$this->logger->error(
	'Token payment failed: ' . wp_json_encode( WC_Straumur_Log_Redactor::summarize( (array) $response ) ),
	array( 'source' => 'straumur-payments' )
);
```
- `WC_Straumur_API::log()` and the webhook handler's `log_message()` only write info/warning when `WP_DEBUG` is on; `logger->error()` always writes.
- After `json_decode()`, read `json_last_error()` before calling anything else that encodes JSON (including a log call).

### Settings Pattern
```php
// One typed getter per setting, over a cached copy of the option.
public static function get_terminal_identifier(): string {
	$settings = self::get_settings();
	return $settings['terminal_identifier'] ?? '';
}
```
Add a setting by adding its field to `get_form_fields()` and a getter. Decide what a store that has never saved the new field should get (`array_key_exists()`, not `empty()`, when "nothing selected" is a valid choice).

### API Communication
- **WP HTTP API** for requests (`wp_remote_request`), 60 s timeout
- **Error handling** with `WP_Error` objects; non-2xx responses return `false`
- **JSON validation** with the decode error logged

### Subscription Support
- Implements WooCommerce Subscriptions hooks
- Supports renewals (token payments), cancellation, suspension and reactivation. Payment method changes are not supported (removed in 2.1.0)
- Token-based payment handling for recurring transactions

## Testing & Quality Assurance

### WordPress Environment Requirements
- **WordPress**: 5.2+
- **WooCommerce**: 8.1+ (tested up to 11.1)
- **PHP**: 7.4+
- Subscription support requires WooCommerce Subscriptions

### Development Environment Setup
1. Local WordPress installation (Local by Flywheel, XAMPP, Docker), or WordPress Playground without Docker: `npx @wp-playground/cli server --mount-dir <this repo> /wordpress/wp-content/plugins/straumur-payments-for-woocommerce` with a blueprint that installs WooCommerce. Playground needs a Node version its native dependencies ship binaries for (24 LTS works).
2. WooCommerce installed and activated
3. Node.js and npm for asset building (version in `.nvmrc`)
4. Enable WordPress debugging in `wp-config.php`. Debug logs are redacted, but treat them as sensitive anyway.

### Plugin Testing
- Test both traditional and block-based checkout
- Verify subscription functionality if applicable
- Test webhook handling and order status transitions
- Validate payment flows in sandbox environment

## Deployment & Release Process

### Continuous Integration
`.github/workflows/ci.yml` runs on every PR to `dev`/`main`:
- `php -l` on PHP 7.4 and 8.4
- PHPCS with `phpcs.xml.dist` (WordPress-Core, WordPress security sniffs, PHP 7.4 compatibility). Run `phpcbf` with the same ruleset to fix formatting
- `npm ci && npm run build`, failing if `assets/js/frontend` differs from the commit — always commit the rebuilt bundle
- `.github/scripts/check-versions.sh`: plugin header, `STRAUMUR_PAYMENTS_VERSION`, `Stable tag` and `package.json` must agree, and `readme.txt` needs a changelog entry for that version
- WordPress.org Plugin Check on the package as `.distignore` assembles it

### Release Workflow
1. **Development**: Work on `dev` branch
2. **Pull Request**: Create PR from `dev` to `main`
3. **Change request**: raise a CR for the release (workspace `change-request` skill). A WordPress.org release is a production deploy to every merchant with auto-updates on.
4. **Release**: a human tags the release commit on `main` with the bare version, e.g. `2.2.0`
5. **Deployment**: `deploy.yml` checks the tag is on `main` and matches the version, rebuilds, then waits for approval on the `wordpress-org` environment (required reviewers) before deploying to WordPress.org SVN. Other tags are ignored.

### Version Management
- Version defined in main plugin file header and `STRAUMUR_PAYMENTS_VERSION`
- Must match `Stable tag` in `readme.txt` and `version` in `package.json` (and the root entries in `package-lock.json`); `check-versions.sh` enforces this
- Also bump the `.pot` `Project-Id-Version` and the `Tested up to` / `WC tested up to` headers
- Follows semantic versioning (MAJOR.MINOR.PATCH)
- A version merged to `dev` is not released until it is tagged on `main`. Check the latest tag before writing a changelog entry; 2.1.0 was never released and was folded into 2.2.0.

### Distribution Files
- `.distignore` is what decides the WordPress.org package: the deploy action and the Plugin Check job both use it. Add every new development-only file or folder here, or it ships (`.gitattributes` once did).
- `.gitattributes` `export-ignore` only affects `git archive`, e.g. a hand-built test zip. Keep it in step with `.distignore`.
- `src/` and `package.json` ship on purpose: WordPress.org requires the readable source of compiled scripts.

## Key Integration Points

### WooCommerce Hooks
- Payment gateway registration and initialization
- Order status transition handling
- Subscription payment processing
- Admin settings page integration

### WordPress Hooks
- Plugin initialization and dependency checking
- REST API endpoint registration (webhooks)
- Asset enqueuing for block-based checkout
- Internationalization and text domain loading

### Third-Party Integrations
- **Straumur API**: Payment processing and management
- **WooCommerce Subscriptions**: Recurring payment support
- **WooCommerce Blocks**: Modern checkout experience
- **WordPress REST API**: Webhook endpoint handling