=== Straumur Payments For WooCommerce ===
Contributors: smartmediais, straumur
Tags: woocommerce, payments, straumur, subscriptions
Requires at least: 5.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate Straumur’s Hosted Checkout into your WooCommerce store. Supports subscriptions, customizable payment pages, redirects, and detailed order notes.

== Description ==
Straumur Payments allows you to accept payments via Straumur’s Hosted Checkout. Secure transactions, customizable settings, and seamless integrations enhance your store's payment experience.

**Key Features:**
- **Manual or Automatic Capture:** Authorize payments first and capture later, or capture automatically upon payment confirmation.
- **Subscriptions:** Compatible with WooCommerce Subscriptions.
- **Configurable Payment Page Lifetime:** Define how long customers have to complete their payment sessions.
- **Customizable Redirects:** Define URLs to redirect customers after successful payments or cancellations.
- **Customizable Checkout Look:** Optional Theme Key available from Straumur merchant portal.

**How It Works:**
1. Customer selects Straumur Payments at checkout.
2. Order status initially "payment pending". Products reserved during payment session.
3. Webhooks update order statuses (authorized, processing, completed, or on-hold).
4. Redirect customers as defined upon payment completion or cancellation.

== Installation ==
1. Download the plugin zip file.
2. In your WordPress Admin, navigate to **Plugins → Add New → Upload Plugin**.
3. Choose the Straumur Payments ZIP file and click **Install Now**.
4. After installation, click **Activate**.
5. Go to **WooCommerce → Settings → Payments** and select **Manage** next to Straumur Payments.
6. Configure the following settings:
   - **API Key** *(Required)*: Obtain from your Straumur merchant dashboard.
   - **Payment Page Terminal Identifier** *(Required)*: Available from your Straumur dashboard.
   - **Payment Gateway Terminal Identifier** *(Required for subscriptions)*: Necessary only if you are using subscriptions.
   - **HMAC Key** *(Required)*: Obtain from your Straumur merchant dashboard.
   - **Theme Key** *(Optional)*: For customizing the appearance of the payment page.
   - **Authorize Only (Manual Capture)** *(Optional)*: Enable if you prefer to capture payments manually.
   - **Checkout Expiry (hours)** *(Required)*: Defines the payment session duration.
   - **Abandon URL** *(Optional)*: URL to redirect customers if the payment is abandoned.
   - **Custom Success URL** *(Optional)*: URL to redirect customers upon successful payment completion.
7. Click **Save changes**.
8. Set WooCommerce inventory hold time to match checkout expiry if you want orders to cancel automatically after expiry.

== Frequently Asked Questions ==
= Do I need a Terminal Identifier? =
Yes, the Payment Page Terminal Identifier is mandatory for processing payments. The Payment Gateway Terminal Identifier is required if you use subscriptions.

= Is the Theme Key required? =
No, the Theme Key is optional and allows customization of your checkout page's appearance.

= How do I handle manual captures? =
Enable “Authorize Only” in the plugin settings. Authorized payments place orders "on hold"; you can manually capture funds through WooCommerce's order management system.

= What happens when the checkout session expires? =
The payment session expiry does not automatically cancel orders. To auto-cancel, match WooCommerce’s **Hold Stock minutes** setting to the checkout expiry duration.

= Where do I get my API Key, Terminal Identifiers, and HMAC Key? =
These details are available from the Straumur merchant dashboard at [https://thjonustuvefur.straumur.is/](https://thjonustuvefur.straumur.is/) upon registration.

== Plugin Settings Documentation ==

- **Title** *(Required)*: Display name shown at checkout.
- **Description** *(Optional)*: Short description shown to customers.
- **Theme Key** *(Optional)*: Customize payment page appearance.
- **Authorize Only (Manual Capture)** *(Optional)*: Enables manual capture of authorized payments.
- **Mark Order as Completed** *(Optional)*: Orders marked completed instead of processing after successful payment.
- **Send Cart Items** *(Optional)*: Include detailed cart information during checkout.
- **Checkout Expiry (hours)** *(Required)*: Payment session duration. To auto-cancel orders, match WooCommerce's **Hold Stock minutes** to this setting.
- **Abandon URL** *(Optional)*: Redirect URL for abandoned or cancelled payments.
- **Custom Success URL** *(Optional)*: Redirect URL upon successful payment.
- **Payment Page Terminal Identifier** *(Required)*: Provided by Straumur for processing payments.
- **Payment Gateway Terminal Identifier** *(Required for subscriptions only)*: Needed specifically for subscriptions.
- **API Key** *(Required)*: Obtain from Straumur service portal.
- **HMAC Key** *(Required)*: Obtain from Straumur service portal.
- **Webhook URL** *(Required)*: Configure this URL in the Straumur dashboard.

All credentials available at [https://thjonustuvefur.straumur.is/](https://thjonustuvefur.straumur.is/) upon registration.

== Screenshots ==


== Changelog ==

= 2.0 =
* Subscription support added.
* Configurable payment page session lifetime.
* Option to set orders as "completed" post-capture.
* Customizable redirect URLs for completed or cancelled payments.
* Improved order notes for declined and failed payments.
* Straumur logo included for merchant use.
* Enhanced support for B2B use cases and discount plugins (e.g., Vaskur.is).
* Improved translation handling.
* Tested compatibility with WordPress 6.7.0, WooCommerce 9.7.0, and PHP 8.3.

= 1.1.3 =
* Bug fixes

= 1.1.0 =
* Icelandic translations added
* Improved webhook handling
* Bug fixes

= 1.0.0 =
* Initial release
* Integrated Straumur Hosted Checkout
* Manual/automatic capture options
* Webhook-based order status updates
* Partial refunds meta storage

== Upgrade Notice ==

= 2.0 =
Major update introducing subscriptions, session lifetime settings, customizable redirects, and improved troubleshooting tools.

