# Paystack for WooCommerce – Gateway with Transaction Fees

This plugin enables seamless integration of the Paystack payment gateway into your WooCommerce store, with advanced support for charging transaction fees to customers during checkout. Perfect for Nigerian businesses using WooCommerce that want to pass payment processing charges to buyers transparently.

## Features

- **Paystack Gateway Integration:** Accept secure payments via Paystack.
- **Transaction Fee Handling:** Automatically add transaction fees to customer orders when they pay with Paystack.
- **Admin Configuration:** Easily enable/disable transaction fees and configure the fee structure in WooCommerce settings.
- **Accurate Fee Calculation:** Ensures Paystack fees (fixed and/or percentage) are correctly applied to order totals.
- **Naira (₦) Support:** Supports shops operating in NGN.

## Installation

1. Download the plugin ZIP or clone this repository.
2. Upload to your WordPress `/wp-content/plugins/` directory.
3. Activate the plugin via the WordPress Plugins menu.
4. Go to **WooCommerce → Settings → Payments → Paystack** to enter your API keys and configure transaction fee options.

## Configuration

- **Set Paystack API Keys:** Obtain your secret and public keys from [Paystack Dashboard](https://dashboard.paystack.com/#/settings/developer).
- **Enable Transaction Fees:** Find the option to enable/disable and set the fee type (fixed, percentage, or both).
- **Customize Fee Label:** Edit how the transaction fee appears on checkout.

## How It Works

- When selected on checkout, the Paystack payment gateway automatically adds a transaction fee to the order total according to your settings.
- The fee is displayed to customers before payment, ensuring transparency.
- After successful payment, the order status updates and the admin can see fees in order admin.

## Screenshots

*(Add screenshots of plugin settings, checkout with fees, and order admin as needed.)*

## Frequently Asked Questions

**Is it legal to charge customers transaction fees?**  
Check your local regulation and the Paystack terms of service before enabling transaction surcharges.

**Does the plugin work with other currencies?**  
Currently, NGN is supported. Other Paystack-supported currencies may be added later.

**Will customers see the fee on their receipt?**  
Yes, the fee is displayed at checkout, in the order summary, and in WooCommerce emails.

## Support

For issues and requests, please open an [issue on GitHub](https://github.com/mimdigital/woo-commerce-paystack/issues).

## License

MIT License

---

*This plugin is not developed or endorsed by Paystack or WooCommerce/WooThemes. Use at your own risk.*
