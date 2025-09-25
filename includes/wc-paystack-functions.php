<?php
/**
 * Paystack Functions
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get Paystack gateway instance
 */
if (!function_exists('wc_paystack_get_gateway')) {
    function wc_paystack_get_gateway() {
        if (!class_exists('WC_Payment_Gateways')) {
            return false;
        }
        
        $gateways = WC()->payment_gateways();
        if (!$gateways) {
            return false;
        }
        
        $available_gateways = $gateways->get_available_payment_gateways();
        return isset($available_gateways['paystack']) ? $available_gateways['paystack'] : false;
    }
}

/**
 * Get Paystack transaction URL
 */
function wc_paystack_get_transaction_url($transaction_id) {
    $gateway = wc_paystack_get_gateway();
    
    if (!$gateway) {
        return false;
    }
    
    $url = $gateway->testmode ? 'https://dashboard.paystack.co/#/test/transactions/' : 'https://dashboard.paystack.co/#/transactions/';
    
    return $url . $transaction_id;
}

/**
 * Add Paystack transaction meta box
 */
function wc_paystack_add_meta_box($post) {
    // Check if $post is a WP_Post object
    if (!($post instanceof WP_Post)) {
        return;
    }
    
    $order = wc_get_order($post->ID);
    
    if (!$order || $order->get_payment_method() !== 'paystack') {
        return;
    }
    
    add_meta_box(
        'wc-paystack-transaction-details',
        __('Paystack Transaction Details', 'wc-paystack'),
        'wc_paystack_transaction_details_meta_box',
        'shop_order',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'wc_paystack_add_meta_box');

/**
 * Paystack transaction details meta box
 */
function wc_paystack_transaction_details_meta_box($post) {
    // Check if $post is a WP_Post object
    if (!($post instanceof WP_Post)) {
        return;
    }
    
    $order = wc_get_order($post->ID);
    
    if (!$order || $order->get_payment_method() !== 'paystack') {
        return;
    }
    
    $transaction_id = $order->get_transaction_id();
    $reference = $order->get_meta('_paystack_payment_txn_ref');
    
    echo '<p><strong>' . __('Transaction ID:', 'wc-paystack') . '</strong> ' . esc_html($transaction_id) . '</p>';
    echo '<p><strong>' . __('Reference:', 'wc-paystack') . '</strong> ' . esc_html($reference) . '</p>';
    
    if ($transaction_id) {
        $transaction_url = wc_paystack_get_transaction_url($transaction_id);
        
        if ($transaction_url) {
            echo '<p><a href="' . esc_url($transaction_url) . '" target="_blank" class="button">' . __('View on Paystack', 'wc-paystack') . '</a></p>';
        }
    }
    
    // Show transaction log if available
    if (class_exists('WC_Paystack_Transaction_Log')) {
        $transaction_log = new WC_Paystack_Transaction_Log();
        $transactions = $transaction_log->get_transactions_by_order_id($post->ID);
        
        if (!empty($transactions)) {
            echo '<h4>' . __('Transaction Log', 'wc-paystack') . '</h4>';
            echo '<ul>';
            
            foreach ($transactions as $transaction) {
                echo '<li>';
                echo '<strong>' . esc_html(ucfirst($transaction->transaction_type)) . ':</strong> ';
                echo esc_html($transaction->status) . ' - ';
                echo esc_html(wc_price($transaction->amount, array('currency' => $transaction->currency)));
                echo '<br>';
                echo '<small>' . esc_html($transaction->transaction_date) . '</small>';
                echo '</li>';
            }
            
            echo '</ul>';
        }
    }
}

/**
 * Add Paystack transaction data to order emails
 */
function wc_paystack_email_transaction_data($order, $sent_to_admin, $plain_text, $email) {
    if ($order->get_payment_method() !== 'paystack') {
        return;
    }
    
    $transaction_id = $order->get_transaction_id();
    $reference = $order->get_meta('_paystack_payment_txn_ref');
    
    if ($plain_text) {
        echo "\n\n" . __('Paystack Transaction Details', 'wc-paystack') . "\n";
        echo __('Transaction ID:', 'wc-paystack') . ' ' . $transaction_id . "\n";
        echo __('Reference:', 'wc-paystack') . ' ' . $reference . "\n";
    } else {
        echo '<h2>' . __('Paystack Transaction Details', 'wc-paystack') . '</h2>';
        echo '<p><strong>' . __('Transaction ID:', 'wc-paystack') . '</strong> ' . esc_html($transaction_id) . '</p>';
        echo '<p><strong>' . __('Reference:', 'wc-paystack') . '</strong> ' . esc_html($reference) . '</p>';
    }
}
add_action('woocommerce_email_order_meta', 'wc_paystack_email_transaction_data', 10, 4);

/**
 * Format Paystack amount
 */
function wc_paystack_format_amount($amount, $currency = '') {
    if (empty($currency)) {
        $currency = get_woocommerce_currency();
    }
    
    // Convert to kobo/pesewas
    return $amount * 100;
}

/**
 * Get Paystack supported currencies
 */
function wc_paystack_get_supported_currencies() {
    return array(
        'NGN', // Nigerian Naira
        'GHS', // Ghanaian Cedi
        'USD', // US Dollar
        'ZAR', // South African Rand
        'KES', // Kenyan Shilling
        'XOF', // CFA Franc BCEAO
        'EGP', // Egyptian Pound
        'GBP', // British Pound
        'EUR', // Euro
    );
}

/**
 * Check if currency is supported by Paystack
 */
function wc_paystack_is_currency_supported($currency = '') {
    if (empty($currency)) {
        $currency = get_woocommerce_currency();
    }
    
    return in_array($currency, wc_paystack_get_supported_currencies());
}

/**
 * Add Paystack fee to order
 */
function wc_paystack_add_fee($order, $fee_amount, $fee_name = '') {
    if (empty($fee_name)) {
        $fee_name = __('Transaction Fee', 'wc-paystack');
    }
    
    $item_fee = new WC_Order_Item_Fee();
    $item_fee->set_name($fee_name);
    $item_fee->set_amount($fee_amount);
    $item_fee->set_tax_status('taxable');
    $item_fee->set_total($fee_amount);
    
    // Add fee to order
    $order->add_item($item_fee);
    $order->calculate_totals();
    $order->save();
}

/**
 * Get Paystack payment icon
 */
function wc_paystack_get_payment_icon() {
    return WC_PAYSTACK_URL . 'assets/images/paystack.png';
}

/**
 * Get Paystack payment methods
 */
function wc_paystack_get_payment_methods() {
    return array(
        'card'          => __('Card', 'wc-paystack'),
        'bank'          => __('Bank', 'wc-paystack'),
        'ussd'          => __('USSD', 'wc-paystack'),
        'qr'            => __('QR', 'wc-paystack'),
        'mobile_money'  => __('Mobile Money', 'wc-paystack'),
        'bank_transfer' => __('Bank Transfer', 'wc-paystack'),
    );
}

/**
 * Get Paystack transaction statuses
 */
function wc_paystack_get_transaction_statuses() {
    return array(
        'success'  => __('Success', 'wc-paystack'),
        'failed'   => __('Failed', 'wc-paystack'),
        'pending'  => __('Pending', 'wc-paystack'),
        'reversed' => __('Reversed', 'wc-paystack'),
    );
}

/**
 * Get Paystack transaction types
 */
function wc_paystack_get_transaction_types() {
    return array(
        'payment'  => __('Payment', 'wc-paystack'),
        'refund'   => __('Refund', 'wc-paystack'),
        'dispute'  => __('Dispute', 'wc-paystack'),
        'reversal' => __('Reversal', 'wc-paystack'),
    );
}

/**
 * Format currency amount with proper symbol
 */
function wc_paystack_format_currency($amount, $currency = '') {
    if (empty($currency)) {
        $currency = get_woocommerce_currency();
    }
    
    // Special handling for Ghanaian Cedi
    if ($currency === 'GHS') {
        return '₵' . number_format($amount, 2);
    }
    
    return wc_price($amount, array('currency' => $currency));
}
