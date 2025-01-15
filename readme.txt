=== Straumur Payments For WooCommerce ===
Contributors: Smartmedia
Tags: woocommerce, payments, straumur, 
Requires at least: 5.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate Straumur’s Hosted Checkout into your WooCommerce store. Supports both automatic and manual capture modes, refunds, cancellations

== Description ==
Straumur Payments Payments allows you to accept payments via Straumur’s Hosted Checkout. With a simple setup, you can process transactions securely, offering a seamless payment experience to your customers.

**Key Features:**
- **Manual or Automatic Capture:** Authorize payments first and capture later, or capture automatically as soon as payment is confirmed.
- **Webhooks for Status Updates:** Receive webhook events for authorized and captured payments, updating orders accordingly.
- **Partial refunds Tracking:** Store historical data of partial refunds in order meta, ensuring accurate transaction records.
- **Customizable Checkout Look:** Optionally provide a Theme Key for a branded payment experience.  A Theme Key can be optained in Straumur merchant portal.
**How It Works:**
1. Customer chooses Straumur Payments Payments at checkout.
2. Order is initially pending. If manual capture is enabled, funds are authorized but not captured until you initiate it.
3. Webhooks update order statuses to ‘on-hold’ (authorized) or ‘processing’ (captured).
4. For incomplete payments, customers may be directed back to order pay page to try again.
== Installation ==
1. Download the plugin zip file.
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
3. Select the Straumur Payments ZIP and click **Install Now**.
4. Once installed, click **Activate**.
5. Go to **WooCommerce → Settings → Payments** and click **Manage** next to Straumur Payments.
6. Enter your API Key and Terminal Identifier (required).  
   - **Terminal Identifier** is mandatory.
   - **Theme Key** is optional for customizing your checkout’s appearance.
   - **Authorize Only (Manual Capture)**: Enable if you want to capture funds later.
7. Click **Save changes**.
== Frequently Asked Questions ==
= Do I need a Terminal Identifier? =  
Yes, Terminal Identifier is required for the Payments to function. Without it, transactions cannot be processed.
= Is the Theme Key required? =  
No, the Theme Key is optional. If provided by Straumur, it customizes your hosted checkout’s look and feel.
= How do I handle manual captures? =  
If you enable “Authorize Only,” orders are authorized but not captured automatically. Once a webhook notifies you of authorization, the order moves to “on-hold.” You can then capture funds from the order screen in WooCommerce by changing status to processing. 

= How are partial refunds tracked? =  
Because Straumur’s webhooks do not provide historical data for partial captures/refunds, all partial refund details are stored in order meta and detailed order notes. This ensures accurate record-keeping.

== Screenshots ==


== Changelog ==

= 1.0.0 =
* Initial release
* Integrated Straumur Hosted Checkout
* Added manual/automatic capture options
* Webhook-based order status updates
* Order meta storage for partial refunds

== Upgrade Notice ==

= 1.0.0 =
Initial release; no upgrade required.


