<?php
/**
 * WC Deposit Stock Adapter
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_DS_Adapter Class
 */
class WC_DS_Adapter {
    /**
     * Initialize the adapter
     */
    public static function init() {
        // Hook into WooCommerce
        add_action('woocommerce_before_checkout_process', array(__CLASS__, 'enforce_deposit_gateway'));
        add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'filter_payment_gateways'));
        add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'save_deposit_meta'));
        add_action('woocommerce_admin_order_data_after_billing_address', array(__CLASS__, 'display_deposit_info'));
    }

    /**
     * Enforce deposit gateway for orders with deposit items
     */
    public static function enforce_deposit_gateway() {
        // bail if there is no cart (e.g. in admin)
        if (!WC()->cart) {
            return;
        }
        
        $cart_items = WC()->cart->get_cart();
        $has_deposit = false;
        
        foreach ($cart_items as $cart_item) {
            if (isset($cart_item['deposit_required']) && $cart_item['deposit_required']) {
                $has_deposit = true;
                break;
            }
        }
        
        if ($has_deposit) {
            // Store in session that this order requires deposit
            WC()->session->set('requires_deposit', true);
        } else {
            // Remove the flag if no deposit items
            WC()->session->set('requires_deposit', false);
        }
    }

    /**
     * Filter payment gateways based on deposit requirements
     */
    public static function filter_payment_gateways($gateways) {
        if (!is_checkout()) {
            return $gateways;
        }
        
        $requires_deposit = WC()->session->get('requires_deposit');
        
        if ($requires_deposit) {
            // Get allowed gateways for deposit
            $allowed_gateways = get_option('wc_deposit_allowed_gateways', array());
            
            foreach ($gateways as $gateway_id => $gateway) {
                if (!in_array($gateway_id, $allowed_gateways)) {
                    unset($gateways[$gateway_id]);
                }
            }
        }
        
        return $gateways;
    }

    /**
     * Save deposit meta to order
     */
    public static function save_deposit_meta($order_id) {
        $requires_deposit = WC()->session->get('requires_deposit');
        
        if ($requires_deposit) {
            update_post_meta($order_id, '_requires_deposit', 'yes');
            
            // Calculate deposit amount
            $order = wc_get_order($order_id);
            $deposit_amount = 0;
            
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $deposit_required = get_post_meta($product_id, '_deposit_required', true);
                
                if ($deposit_required === 'yes') {
                    $deposit_percentage = get_post_meta($product_id, '_deposit_percentage', true);
                    $line_total = $item->get_total();
                    $item_deposit = ($line_total * $deposit_percentage) / 100;
                    $deposit_amount += $item_deposit;
                }
            }
            
            update_post_meta($order_id, '_deposit_amount', $deposit_amount);
            update_post_meta($order_id, '_deposit_paid', 'no');
        }
    }

    /**
     * Display deposit information in admin order page
     */
    public static function display_deposit_info($order) {
        $requires_deposit = get_post_meta($order->get_id(), '_requires_deposit', true);
        
        if ($requires_deposit === 'yes') {
            $deposit_amount = get_post_meta($order->get_id(), '_deposit_amount', true);
            $deposit_paid = get_post_meta($order->get_id(), '_deposit_paid', true);
            
            echo '<div class="deposit-info">';
            echo '<h3>' . __('Deposit Information', 'wc-deposit-stock-adapter') . '</h3>';
            echo '<p><strong>' . __('Deposit Amount:', 'wc-deposit-stock-adapter') . '</strong> ' . wc_price($deposit_amount) . '</p>';
            echo '<p><strong>' . __('Deposit Status:', 'wc-deposit-stock-adapter') . '</strong> ' . ($deposit_paid === 'yes' ? __('Paid', 'wc-deposit-stock-adapter') : __('Pending', 'wc-deposit-stock-adapter')) . '</p>';
            echo '</div>';
        }
    }
}

// Initialize the adapter
WC_DS_Adapter::init();
