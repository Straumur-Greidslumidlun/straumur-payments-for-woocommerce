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
The project follows a standard Git Flow pattern:
- `dev` branch: Development branch (default)
- `main` branch: Production branch for releases
- Feature branches: `feature/your-feature-name`

## High-Level Architecture

### Plugin Structure

```
straumur-woocommerce-plugin/
├── straumur-payments-for-woocommerce.php    # Main plugin file & bootstrap
├── includes/                                # Core PHP classes
│   ├── class-wc-straumur-payment-gateway.php    # Main payment gateway
│   ├── class-wc-straumur-api.php                # API communication layer
│   ├── class-wc-straumur-settings.php           # Settings management
│   ├── class-wc-straumur-order-handler.php      # Order lifecycle management
│   ├── class-wc-straumur-block-support.php      # WooCommerce blocks integration
│   └── class-wc-straumur-webhook-handler.php    # Webhook processing
├── src/                                     # JavaScript source files
│   └── index.js                            # Block-based checkout React component
├── assets/                                  # Compiled assets & images
│   ├── js/frontend/                        # Compiled JavaScript
│   └── images/                             # Plugin icons & assets
└── languages/                              # Translation files
```

### Core Components

#### 1. Payment Gateway (`WC_Straumur_Payment_Gateway`)
- Extends `WC_Payment_Gateway`
- Handles payment processing, redirects, and form fields
- Supports subscriptions, blocks, and various WooCommerce features
- Manages order creation and payment session initialization

#### 2. API Layer (`WC_Straumur_API`)
- Singleton pattern for API communication
- Handles HTTP requests to Straumur's REST API
- Manages payment sessions, captures, refunds, and cancellations
- Implements proper error handling and logging

#### 3. Settings Management (`WC_Straumur_Settings`)
- Centralized settings configuration
- Handles form field definitions and validation
- Caches settings to optimize performance
- Provides getter methods for all configuration options

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
// Consistent logging pattern
$this->logger = wc_get_logger();
$this->logger->error('Error message', array('source' => 'straumur'));
```

### Settings Pattern
```php
// Centralized settings with caching
private static array $cached_settings = array();
public static function get_setting($key) {
    if (empty(self::$cached_settings)) {
        self::$cached_settings = get_option(self::$option_key, array());
    }
    return self::$cached_settings[$key] ?? '';
}
```

### API Communication
- **Singleton pattern** for API client
- **WP HTTP API** for requests (`wp_remote_request`)
- **Proper error handling** with `WP_Error` objects
- **JSON validation** with proper error messages

### Subscription Support
- Implements WooCommerce Subscriptions hooks
- Supports payment method changes, renewals, and lifecycle management
- Token-based payment handling for recurring transactions

## Testing & Quality Assurance

### WordPress Environment Requirements
- **WordPress**: 5.2+
- **WooCommerce**: 8.1+ (tested up to 9.9)
- **PHP**: 7.4+
- Subscription support requires WooCommerce Subscriptions

### Development Environment Setup
1. Local WordPress installation (Local by Flywheel, XAMPP, Docker)
2. WooCommerce installed and activated
3. Node.js and npm for asset building
4. Enable WordPress debugging in `wp-config.php`

### Plugin Testing
- Test both traditional and block-based checkout
- Verify subscription functionality if applicable
- Test webhook handling and order status transitions
- Validate payment flows in sandbox environment

## Deployment & Release Process

### Release Workflow
1. **Development**: Work on `dev` branch
2. **Pull Request**: Create PR from `dev` to `main`
3. **Release**: Create tagged release matching version in `readme.txt`
4. **Deployment**: GitHub Actions automatically deploys to WordPress.org SVN

### Version Management
- Version defined in main plugin file header
- Must match `Stable tag` in `readme.txt`
- Follows semantic versioning (MAJOR.MINOR.PATCH)

### Distribution Files
- `.distignore`: Defines files excluded from distribution
- `.gitattributes`: Git export settings for clean releases
- Excludes development files, documentation, and CI/CD configurations

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

This plugin demonstrates modern WordPress development practices with proper separation of concerns, security considerations, and comprehensive WooCommerce integration patterns.