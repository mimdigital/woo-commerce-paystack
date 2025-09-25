<?php
/**
 * Paystack Payment Gateway
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Gateway_Paystack Class
 */
class WC_Gateway_Paystack extends WC_Payment_Gateway {
    /**
     * Test mode
     *
     * @var bool
     */
    public $testmode;

    /**
     * Public key
     *
     * @var string
     */
    public $public_key;

    /**
     * Secret key
     *
     * @var string
     */
    public $secret_key;

    /**
     * Whether to charge transaction fee
     *
     * @var bool
     */
    public $charge_fee;

    /**
     * Transaction fee percentage
     *
     * @var string
     */
    public $fee_percent;

    /**
     * Payment type (popup or redirect)
     *
     * @var string
     */
    public $payment_type;

    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    protected $logger;

    /**
     * Instructions
     *
     * @var string
     */
    public $instructions;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'paystack';
        $this->icon               = WC_PAYSTACK_URL . 'assets/images/paystack.png';
        $this->has_fields         = false;
        $this->method_title       = __('Paystack', 'wc-paystack');
        $this->method_description = __('Accept payments via Paystack', 'wc-paystack');
        $this->supports           = array(
            'products',
            'refunds',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        $this->testmode     = 'yes' === $this->get_option('testmode');
        $this->instructions = $this->get_option('instructions', '');
        
        // Set API keys based on test mode
        $this->public_key = $this->get_option('public_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->payment_type = $this->get_option('payment_type', 'popup');
        
        // Transaction fee
        $this->charge_fee   = 'yes' === $this->get_option('charge_fee');
        $this->fee_percent  = $this->get_option('fee_percent', '1.5');
        
        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wc_gateway_paystack', array($this, 'verify_response'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        
        // Customer Emails
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        
        // Add admin notice if API keys are not set
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Add admin scripts for settings page
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }

    /**
     * Display admin notices
     */
    public function admin_notices() {
        if ('yes' === $this->enabled) {
            $key_to_check = $this->get_option('public_key');
            $mode = $this->testmode ? __('Test', 'wc-paystack') : __('Live', 'wc-paystack');
            
            if (empty($key_to_check)) {
                echo '<div class="error"><p>' . 
                    sprintf(
                        __('Paystack Error: Please enter your %s Public Key <a href="%s">here</a>', 'wc-paystack'),
                        $mode,
                        admin_url('admin.php?page=wc-settings&tab=checkout&section=paystack')
                    ) . 
                    '</p></div>';
            }
        }
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'wc-paystack'),
                'label'       => __('Enable Paystack Payment', 'wc-paystack'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'wc-paystack'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-paystack'),
                'default'     => __('Paystack', 'wc-paystack'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'wc-paystack'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wc-paystack'),
                'default'     => __('Pay with your credit card via Paystack.', 'wc-paystack'),
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __('Test mode', 'wc-paystack'),
                'label'       => __('Enable Test Mode', 'wc-paystack'),
                'type'        => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'wc-paystack'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'public_key' => array(
                'title'       => __('Live/Test Public Key', 'wc-paystack'),
                'type'        => 'text',
                'description' => __('Enter your Paystack Public Key. This is needed to process payments. When test mode is enabled, use your test key.', 'wc-paystack'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'secret_key' => array(
                'title'       => __('Live/Test Secret Key', 'wc-paystack'),
                'type'        => 'password',
                'description' => __('Enter your Paystack Secret Key. This is needed to verify transactions. When test mode is enabled, use your test key.', 'wc-paystack'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_url' => array(
                'title'       => __('Webhook URL', 'wc-paystack'),
                'type'        => 'webhook_url',
                'description' => __('To avoid situations where bad network makes it impossible to verify transactions, set your webhook URL <a href="https://dashboard.paystack.com/#/settings/developer" target="_blank">here</a> to the URL below', 'wc-paystack'),
                'default'     => WC()->api_request_url('WC_Gateway_Paystack'),
                'desc_tip'    => false,
            ),
            'callback_url' => array(
                'title'       => __('Callback URL', 'wc-paystack'),
                'type'        => 'callback_url',
                'description' => __('Set this URL as your callback URL in your Paystack dashboard <a href="https://dashboard.paystack.com/#/settings/developer" target="_blank">here</a> for proper payment verification', 'wc-paystack'),
                'default'     => WC()->api_request_url('WC_Gateway_Paystack'),
                'desc_tip'    => false,
            ),
            'payment_type' => array(
                'title'       => __('Payment Type', 'wc-paystack'),
                'type'        => 'select',
                'description' => __('Choose how customers should be redirected to make payment.', 'wc-paystack'),
                'default'     => 'popup',
                'desc_tip'    => true,
                'options'     => array(
                    'popup'    => __('Popup (Paystack Modal)', 'wc-paystack'),
                    'redirect' => __('Redirect to Paystack', 'wc-paystack'),
                ),
            ),
            'charge_fee' => array(
                'title'       => __('Charge Transaction Fee', 'wc-paystack'),
                'label'       => __('Enable Transaction Fee', 'wc-paystack'),
                'type'        => 'checkbox',
                'description' => __('Add a transaction fee to the order total.', 'wc-paystack'),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'fee_percent' => array(
                'title'       => __('Transaction Fee (%)', 'wc-paystack'),
                'type'        => 'text',
                'description' => __('Transaction fee percentage to charge customers.', 'wc-paystack'),
                'default'     => '1.5',
                'desc_tip'    => true,
            ),
            'instructions' => array(
                'title'       => __('Instructions', 'wc-paystack'),
                'type'        => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', 'wc-paystack'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __('Debug Log', 'wc-paystack'),
                'label'       => __('Enable logging', 'wc-paystack'),
                'type'        => 'checkbox',
                'description' => __('Log Paystack events inside <code>WooCommerce > Status > Logs</code>', 'wc-paystack'),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Generate Webhook URL HTML
     */
    public function generate_webhook_url_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo $this->get_tooltip_html($data); // WPCS: XSS ok. ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <div class="wc-paystack-webhook-url">
                        <code id="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['default']); ?></code>
                        <button type="button" class="wc-paystack-copy-btn button" data-clipboard-target="#<?php echo esc_attr($field_key); ?>">
                            <?php esc_html_e('Copy', 'wc-paystack'); ?>
                        </button>
                    </div>
                    <?php echo $this->get_description_html($data); // WPCS: XSS ok. ?>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    /**
     * Generate Callback URL HTML
     */
    public function generate_callback_url_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo $this->get_tooltip_html($data); // WPCS: XSS ok. ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                <div class="wc-paystack-callback-url">
                    <code id="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['default']); ?></code>
                    <button type="button" class="wc-paystack-copy-btn button" data-clipboard-target="#<?php echo esc_attr($field_key); ?>">
                        <?php esc_html_e('Copy', 'wc-paystack'); ?>
                    </button>
                </div>
                <?php echo $this->get_description_html($data); // WPCS: XSS ok. ?>
            </fieldset>
        </td>
    </tr>
    <?php

    return ob_get_clean();
}

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_scripts($hook) {
        // Only load on WooCommerce settings pages
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        
        // Check if we're on the Paystack settings tab
        if (!isset($_GET['section']) || $_GET['section'] !== 'paystack') {
            return;
        }
        
        // Register and enqueue the admin script
        wp_register_script('clipboard', WC_PAYSTACK_URL . 'assets/js/clipboard.min.js', array(), '2.0.11', true);
        wp_register_script('wc-paystack-admin', WC_PAYSTACK_URL . 'assets/js/admin.js', array('jquery', 'clipboard'), WC_PAYSTACK_VERSION, true);
        wp_enqueue_script('clipboard');
        wp_enqueue_script('wc-paystack-admin');
        
        // Register and enqueue the admin style
        wp_register_style('wc-paystack-admin', WC_PAYSTACK_URL . 'assets/css/admin.css', array(), WC_PAYSTACK_VERSION);
        wp_enqueue_style('wc-paystack-admin');
        
        // Add inline script for immediate execution
        wp_add_inline_script('wc-paystack-admin', '
            jQuery(function($) {
                // Initialize clipboard for webhook URL copying
                if (typeof ClipboardJS !== "undefined") {
                    new ClipboardJS(".wc-paystack-copy-btn").on("success", function(e) {
                        var $button = $(e.trigger);
                        $button.text("Copied!");
                        setTimeout(function() {
                            $button.text("Copy");
                        }, 2000);
                    });
                }
            });
        ');
    }

    /**
     * Check if this gateway is enabled and has the required API keys
     */
    public function is_available() {
        if ('yes' !== $this->enabled) {
            return false;
        }
        
        if (empty($this->public_key) || empty($this->secret_key)) {
            return false;
        }
        
        return true;
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // Check if API keys are set
        if (empty($this->public_key) || empty($this->secret_key)) {
            echo '<div class="woocommerce-error">' . __('Paystack is not available at the moment. Please try another payment method.', 'wc-paystack') . '</div>';
        }
        
        echo '<div id="wc-paystack-form"></div>';
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        // Check if API keys are set
        if (empty($this->public_key) || empty($this->secret_key)) {
            $this->log('API keys not set. Payment processing failed.');
            wc_add_notice(__('Paystack is not properly configured. Please contact the site administrator.', 'wc-paystack'), 'error');
            return array('result' => 'fail', 'redirect' => '');
        }
        
        // Add transaction fee if enabled
        if ($this->charge_fee) {
            $this->add_transaction_fee($order);
        }
        
        // Generate unique reference
        $reference = $this->generate_reference($order_id);
        
        // Store reference in order meta
        $order->update_meta_data('_paystack_payment_txn_ref', $reference);
        $order->save();
        
        $this->log('Payment process initiated for order #' . $order_id . ' with reference: ' . $reference);
        
        // If payment type is redirect, redirect to Paystack
        if ($this->payment_type === 'redirect') {
            // Initialize transaction with Paystack
            $response = $this->initialize_transaction($order, $reference);
            
            if (!$response) {
                $this->log('Failed to initialize transaction with Paystack for order #' . $order_id);
                wc_add_notice(__('Error initializing payment. Please try again.', 'wc-paystack'), 'error');
                return array('result' => 'fail', 'redirect' => '');
            }
            
            $this->log('Redirecting to Paystack: ' . $response['authorization_url']);
            
            return array(
                'result'   => 'success',
                'redirect' => $response['authorization_url'],
            );
        }
        
        // If payment type is popup, prepare for inline payment
        $this->log('Using popup payment for order #' . $order_id);
        
        // For popup payment, we'll use the checkout payment URL
        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    /**
     * Initialize transaction with Paystack
     */
    private function initialize_transaction($order, $reference) {
        $amount = $order->get_total() * 100; // Convert to kobo/pesewas
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'no-cache',
            ),
            'body' => wp_json_encode(array(
                'email'        => $order->get_billing_email(),
                'amount'       => intval($amount),
                'currency'     => $order->get_currency(),
                'reference'    => $reference,
                'callback_url' => $this->get_return_url($order),
                'metadata'     => array(
                    'order_id'     => $order->get_id(),
                    'custom_fields' => array(
                        array(
                            'display_name' => 'Order ID',
                            'variable_name' => 'order_id',
                            'value' => $order->get_id(),
                        ),
                    ),
                ),
            )),
            'timeout' => 30,
        );
        
        $response = wp_remote_post('https://api.paystack.co/transaction/initialize', $args);
        
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            $this->log('Error initializing transaction: ' . wp_remote_retrieve_response_message($response));
            return false;
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$response_body['status']) {
            $this->log('Paystack error: ' . $response_body['message']);
            return false;
        }
        
        return $response_body['data'];
    }

    /**
     * Add transaction fee to order
     */
    private function add_transaction_fee($order) {
        $fee_amount = ($order->get_total() * $this->fee_percent) / 100;
        
        // Add fee to order
        $item_fee = new WC_Order_Item_Fee();
        $item_fee->set_name(__('Transaction Fee', 'wc-paystack'));
        $item_fee->set_amount($fee_amount);
        $item_fee->set_tax_status('taxable');
        $item_fee->set_total($fee_amount);
        
        // Add fee to order
        $order->add_item($item_fee);
        $order->calculate_totals();
        $order->save();
    }

    /**
     * Generate payment reference
     */
    private function generate_reference($order_id) {
        return 'PAYSTACK_' . $order_id . '_' . time();
    }

    /**
     * Receipt page
     */
    public function receipt_page($order_id) {
        $order = wc_get_order($order_id);
        
        // Check if API keys are set
        if (empty($this->public_key) || empty($this->secret_key)) {
            echo '<div class="woocommerce-error">' . __('Paystack is not properly configured. Please contact the site administrator.', 'wc-paystack') . '</div>';
            return;
        }
        
        // Add a message to indicate payment is being processed
        echo '<div class="woocommerce-info">' . __('Please wait while we initialize your payment...', 'wc-paystack') . '</div>';
        
        // Enqueue Paystack scripts
        wp_enqueue_script('paystack');
        wp_enqueue_script('wc-paystack');
        wp_enqueue_style('wc-paystack');
        
        // Generate unique reference if not already set
        $reference = $order->get_meta('_paystack_payment_txn_ref');
        if (empty($reference)) {
            $reference = $this->generate_reference($order_id);
            $order->update_meta_data('_paystack_payment_txn_ref', $reference);
            $order->save();
        }
        
        // Prepare payment parameters
        $paystack_params = array(
            'key'          => $this->public_key,
            'email'        => $order->get_billing_email(),
            'amount'       => intval($order->get_total() * 100),
            'currency'     => $order->get_currency(),
            'ref'          => $reference,
            'metadata'     => array(
                'order_id'     => $order->get_id(),
                'custom_fields' => array(
                    array(
                        'display_name' => 'Order ID',
                        'variable_name' => 'order_id',
                        'value' => $order->get_id(),
                    ),
                ),
            ),
            'callback_url' => $this->get_return_url($order),
            'onclose'      => wc_get_checkout_url(),
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('verify_paystack'),
        );

        // Localize script with payment parameters
        wp_localize_script('wc-paystack', 'wc_paystack_params', $paystack_params);
        
        // Add debug info
        echo '<!-- Paystack Debug: Order ID: ' . esc_html($order_id) . ', Reference: ' . esc_html($reference) . ' -->';
    }

    /**
     * Enqueue payment scripts
     */
    public function payment_scripts() {
        if (!is_checkout() && !is_checkout_pay_page()) {
            return;
        }
        
        if ($this->enabled === 'no') {
            return;
        }
        
        // Only enqueue on the checkout page
        if (is_checkout() || is_checkout_pay_page()) {
            wp_enqueue_script('paystack');
            wp_enqueue_script('wc-paystack');
            wp_enqueue_style('wc-paystack');
        }
    }

    /**
     * Verify and process the payment response
     */
    public function verify_response() {
        if (empty($_GET['reference'])) {
            wp_die('No reference supplied', 'Paystack', ['response' => 400]);
        }

        $reference = sanitize_text_field($_GET['reference']);
        $this->log('Verifying payment with reference: ' . $reference);
        
        // Call Paystack verify endpoint
        $verify = wp_remote_get("https://api.paystack.co/transaction/verify/{$reference}", [
            'headers' => ['Authorization' => 'Bearer ' . $this->secret_key]
        ]);
        
        $body = wp_remote_retrieve_body($verify);
        $result = json_decode($body, true);
        
        $this->log('Verification response: ' . print_r($result, true));
        
        if (isset($result['status']) && $result['status'] === true && $result['data']['status'] === 'success') {
            // Get order ID from reference or metadata
            $order_id = null;
            
            // Try to get order ID from metadata first
            if (isset($result['data']['metadata']['order_id'])) {
                $order_id = intval($result['data']['metadata']['order_id']);
            }
            
            // If not found in metadata, try to extract from reference
            if (!$order_id) {
                $order_id = $this->get_order_id_from_reference($reference);
            }
            
            if ($order_id && ($order = wc_get_order($order_id))) {
                // Disable email notifications
                add_filter('woocommerce_email_enabled_new_order', '__return_false');
                add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
                add_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false');
                add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
                
                // Mark payment complete & set status to "processing"
                if (!$order->is_paid()) {
                    $this->log('Marking order #' . $order_id . ' as paid');
                    $order->payment_complete($result['data']['id']);
                    $order->update_status('processing', __('Payment verified via Paystack', 'wc-paystack'));
                    wc_reduce_stock_levels($order_id);
                    
                    // ─────── Send WooCommerce emails only ───────
                    // First, remove any filters that might be blocking emails
                    remove_all_filters('woocommerce_email_enabled_new_order');
                    remove_all_filters('woocommerce_email_enabled_customer_processing_order');
                    remove_all_filters('woocommerce_email_enabled_customer_on_hold_order');
                    remove_all_filters('woocommerce_email_enabled_customer_completed_order');

                    // Log that we're about to send emails
                    $this->log('Sending WooCommerce order notification emails for order #' . $order_id);

                    // Get mailer instance
                    $mailer = WC()->mailer();

                    // Trigger New Order email (to admin)
                    if (isset($mailer->emails['WC_Email_New_Order'])) {
                        $this->log('Triggering admin New Order email');
                        $mailer->emails['WC_Email_New_Order']->trigger($order_id, $order);
                    }

                    // Trigger Processing Order email (to customer)
                    if (isset($mailer->emails['WC_Email_Customer_Processing_Order'])) {
                        $this->log('Triggering customer Processing Order email');
                        $mailer->emails['WC_Email_Customer_Processing_Order']->trigger($order_id, $order);
                    }
                    // ────────────────────────────────────────────────
                    
                    // Log the transaction if transaction log class exists
                    if (class_exists('WC_Paystack_Transaction_Log')) {
                        try {
                            $transaction_log = new WC_Paystack_Transaction_Log();
                            $log_data = array(
                                'transaction_id'   => $result['data']['id'],
                                'order_id'         => $order_id,
                                'amount'           => $order->get_total(),
                                'currency'         => $order->get_currency(),
                                'payment_method'   => 'paystack',
                                'transaction_type' => 'payment',
                                'status'           => 'success',
                                'customer_email'   => $order->get_billing_email(),
                                'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                                'reference'        => $reference,
                                'message'          => __('Payment completed via callback', 'wc-paystack'),
                                'transaction_date' => current_time('mysql'),
                            );
                            
                            $this->log('Logging transaction: ' . print_r($log_data, true));
                            
                            $log_id = $transaction_log->log_transaction($log_data);
                            
                            if ($log_id) {
                                $this->log('Transaction logged successfully. Log ID: ' . $log_id);
                            } else {
                                $this->log('Failed to log transaction.');
                            }
                        } catch (Exception $e) {
                            $this->log('Error logging transaction: ' . $e->getMessage());
                        }
                    }
                } else {
                    $this->log('Order #' . $order_id . ' is already paid');
                }
                
                // Check if this is a redirect from Paystack (has trxref parameter)
                if (isset($_GET['trxref'])) {
                    // This is a redirect from Paystack, send directly to thank you page
                    wp_redirect($this->get_return_url($order));
                    exit;
                } else {
                    // This is a webhook call, just return 200 OK
                    status_header(200);
                    exit;
                }
            } else {
                $this->log('Order not found for reference: ' . $reference);
            }
        }
        
        // On failure, send them back to checkout with an error notice
        wc_add_notice(__('Payment verification failed. Please try again.', 'wc-paystack'), 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Get order ID from reference
     */
    public function get_order_id_from_reference($reference) {
        global $wpdb;
        
        // Check if HPOS is active
        $is_hpos_active = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
                          \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        
        if ($is_hpos_active) {
            // HPOS compatible query
            $order_id = wc_get_orders(array(
                'meta_key' => '_paystack_payment_txn_ref',
                'meta_value' => $reference,
                'limit' => 1,
                'return' => 'ids',
            ));
            
            return !empty($order_id) ? $order_id[0] : null;
        } else {
            // Legacy query
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->prefix}postmeta 
                WHERE meta_key = %s 
                AND meta_value = %s", 
                '_paystack_payment_txn_ref',
                $reference
            ));
        
            return $order_id;
        }
    }

    /**
     * Process refund
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return false;
        }
        
        $transaction_id = $order->get_transaction_id();
        
        if (!$transaction_id) {
            return false;
        }
        
        $url = 'https://api.paystack.co/refund';
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'no-cache',
            ),
            'body' => json_encode(array(
                'transaction' => $transaction_id,
                'amount'      => $amount * 100, // Convert to kobo/pesewas
                'reason'      => $reason,
            )),
            'method' => 'POST',
            'timeout' => 30,
        );
        
        $response = wp_remote_post($url, $args);
        
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $response_body = json_decode(wp_remote_retrieve_body($response));
            
            if ($response_body && $response_body->status) {
                // Add order note
                $order->add_order_note(
                    sprintf(__('Refund processed via Paystack. Refund ID: %s', 'wc-paystack'), 
                    $response_body->data->id)
                );
                
                // Log the transaction if transaction log class exists
                if (class_exists('WC_Paystack_Transaction_Log')) {
                    $transaction_log = new WC_Paystack_Transaction_Log();
                    $transaction_log->log_transaction(array(
                        'transaction_id'   => $response_body->data->id,
                        'order_id'         => $order_id,
                        'amount'           => $amount,
                        'currency'         => $order->get_currency(),
                        'payment_method'   => 'paystack',
                        'transaction_type' => 'refund',
                        'status'           => 'success',
                        'customer_email'   => $order->get_billing_email(),
                        'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'reference'        => $order->get_meta('_paystack_payment_txn_ref'),
                        'message'          => sprintf(__('Refund processed. Amount: %s', 'wc-paystack'), 
                                            wc_price($amount)),
                        'transaction_date' => current_time('mysql'),
                    ));
                }
                
                return true;
            }
        }
        
        return false;
    }

    /**
     * Log debug messages
     */
    public function log($message) {
        if ('yes' === $this->get_option('debug', 'no')) {
            if (empty($this->logger)) {
                $this->logger = wc_get_logger();
            }
            
            $this->logger->debug($message, array('source' => 'paystack'));
            
            // Also log to error_log for easier debugging
            error_log('Paystack: ' . $message);
        }
    }

    /**
     * Verify a Paystack transaction via API.
     *
     * @param string $reference
     * @return array|false  Transaction data on success, false on failure.
     */
    public function verify_paystack_transaction( $reference ) {
        $url = "https://api.paystack.co/transaction/verify/{$reference}";
        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secret_key,
            ],
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! empty( $body->status ) && $body->status === true && $body->data->status === 'success' ) {
            return $body->data;
        }

        return false;
    }
    
    /**
     * Email instructions
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method()) {
            echo wp_kses_post(wpautop(wptexturize($this->instructions)) . PHP_EOL);
        }
    }
}
