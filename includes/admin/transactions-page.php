<?php
/**
 * Paystack Transactions Page
 */
if (!defined('ABSPATH')) {
    exit;
}

// Auto-refresh transaction statuses on page load (for displayed transactions only)
function paystack_auto_refresh_transactions($transactions) {
    if (empty($transactions)) {
        return $transactions;
    }
    
    $transaction_log = new WC_Paystack_Transaction_Log();
    $updated_count = 0;
    
    // Only check payment transactions that are not already reversed or failed
    foreach ($transactions as $transaction) {
        if ($transaction->transaction_type === 'payment' && 
            !in_array($transaction->status, ['reversed', 'failed'])) {
            
            $new_status = $transaction_log->check_transaction_status($transaction->transaction_id);
            if ($new_status && $new_status !== $transaction->status) {
                $transaction->status = $new_status; // Update the object for display
                $updated_count++;
            }
        }
    }
    
    if ($updated_count > 0) {
        echo '<div class="notice notice-success is-dismissible"><p>' . 
            sprintf(_n('%d transaction status updated automatically.', '%d transaction statuses updated automatically.', $updated_count, 'wc-paystack'), $updated_count) . 
            '</p></div>';
    }
    
    return $transactions;
}

// Check if we need to refresh a transaction status
if (isset($_GET['action']) && $_GET['action'] === 'check_status' && isset($_GET['transaction_id']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'paystack_check_status')) {
    $transaction_id = sanitize_text_field($_GET['transaction_id']);
    $transaction_log = new WC_Paystack_Transaction_Log();
    $status = $transaction_log->check_transaction_status($transaction_id);
    
    if ($status) {
        $message = sprintf(__('Transaction status updated to: %s', 'wc-paystack'), ucfirst($status));
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to update transaction status.', 'wc-paystack') . '</p></div>';
    }
}

// Get transaction log instance
$transaction_log = new WC_Paystack_Transaction_Log();

// Set transactions per page
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
// Ensure per_page is within reasonable limits
$per_page = max(10, min(100, $per_page));

$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

// Get filters
$filters = array();

if (isset($_GET['transaction_id']) && !empty($_GET['transaction_id'])) {
    $filters['transaction_id'] = sanitize_text_field($_GET['transaction_id']);
}

if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
    $filters['order_id'] = intval($_GET['order_id']);
}

if (isset($_GET['reference']) && !empty($_GET['reference'])) {
    $filters['reference'] = sanitize_text_field($_GET['reference']);
}

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $filters['status'] = sanitize_text_field($_GET['status']);
}

if (isset($_GET['type']) && !empty($_GET['type'])) {
    $filters['type'] = sanitize_text_field($_GET['type']);
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $filters['date_from'] = sanitize_text_field($_GET['date_from']);
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $filters['date_to'] = sanitize_text_field($_GET['date_to']);
}

// Get transactions
$args = array_merge($filters, array(
    'per_page' => $per_page,
    'page'     => $current_page,
));

$transactions = $transaction_log->get_transactions($args);

// Auto-refresh transaction statuses
$transactions = paystack_auto_refresh_transactions($transactions);

$total_transactions = $transaction_log->count_transactions($filters);
$total_pages = ceil($total_transactions / $per_page);

// Get transaction statuses and types
$statuses = wc_paystack_get_transaction_statuses();
$types = wc_paystack_get_transaction_types();

// Available per page options
$per_page_options = array(10, 20, 50, 100);
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('Paystack Transactions', 'wc-paystack'); ?></h1>
    
    <!-- Prominent Refresh All Button -->
    <a href="<?php echo esc_url(add_query_arg(array('refresh_all' => '1', '_wpnonce' => wp_create_nonce('paystack_refresh_all')), admin_url('admin.php?page=wc-paystack-transactions'))); ?>" class="page-title-action"><?php _e('Refresh All Transactions', 'wc-paystack'); ?></a>
    
    <hr class="wp-header-end">
    
    <form method="get">
        <input type="hidden" name="page" value="wc-paystack-transactions">
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <label for="filter-by-transaction-id" class="screen-reader-text"><?php _e('Filter by Transaction ID', 'wc-paystack'); ?></label>
                <input type="text" name="transaction_id" id="filter-by-transaction-id" value="<?php echo isset($_GET['transaction_id']) ? esc_attr($_GET['transaction_id']) : ''; ?>" placeholder="<?php esc_attr_e('Transaction ID', 'wc-paystack'); ?>">
                
                <label for="filter-by-order-id" class="screen-reader-text"><?php _e('Filter by Order ID', 'wc-paystack'); ?></label>
                <input type="text" name="order_id" id="filter-by-order-id" value="<?php echo isset($_GET['order_id']) ? esc_attr($_GET['order_id']) : ''; ?>" placeholder="<?php esc_attr_e('Order ID', 'wc-paystack'); ?>">
                
                <label for="filter-by-reference" class="screen-reader-text"><?php _e('Filter by Reference', 'wc-paystack'); ?></label>
                <input type="text" name="reference" id="filter-by-reference" value="<?php echo isset($_GET['reference']) ? esc_attr($_GET['reference']) : ''; ?>" placeholder="<?php esc_attr_e('Reference', 'wc-paystack'); ?>">
                
                <label for="filter-by-status" class="screen-reader-text"><?php _e('Filter by Status', 'wc-paystack'); ?></label>
                <select name="status" id="filter-by-status">
                    <option value=""><?php _e('All statuses', 'wc-paystack'); ?></option>
                    <?php foreach ($statuses as $status_key => $status_label) : ?>
                        <option value="<?php echo esc_attr($status_key); ?>" <?php selected(isset($_GET['status']) ? $_GET['status'] : '', $status_key); ?>><?php echo esc_html($status_label); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <label for="filter-by-type" class="screen-reader-text"><?php _e('Filter by Type', 'wc-paystack'); ?></label>
                <select name="type" id="filter-by-type">
                    <option value=""><?php _e('All types', 'wc-paystack'); ?></option>
                    <?php foreach ($types as $type_key => $type_label) : ?>
                        <option value="<?php echo esc_attr($type_key); ?>" <?php selected(isset($_GET['type']) ? $_GET['type'] : '', $type_key); ?>><?php echo esc_html($type_label); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'wc-paystack'); ?>">
                
                <?php if (!empty($filters)) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-paystack-transactions')); ?>" class="button"><?php _e('Reset', 'wc-paystack'); ?></a>
                <?php endif; ?>
                
                <button type="button" class="button" id="wc-paystack-export-transactions"><?php _e('Export', 'wc-paystack'); ?></button>
            </div>
            
            <div class="alignright">
                <label for="per-page"><?php _e('Show:', 'wc-paystack'); ?></label>
                <select name="per_page" id="per-page" onchange="this.form.submit()">
                    <?php foreach ($per_page_options as $option) : ?>
                        <option value="<?php echo esc_attr($option); ?>" <?php selected($per_page, $option); ?>><?php echo esc_html($option); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="displaying-num">
                    <?php printf(
                        _n('%s item', '%s items', $total_transactions, 'wc-paystack'),
                        number_format_i18n($total_transactions)
                    ); ?>
                </span>
            </div>
            
            <div class="tablenav-pages">
                <?php if ($total_pages > 1) : ?>
                    <span class="pagination-links">
                        <?php
                        echo paginate_links(array(
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                        ));
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <br class="clear">
        </div>
    
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-transaction-id"><?php _e('Transaction ID', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-order-id"><?php _e('Order ID', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-amount"><?php _e('Amount', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-type"><?php _e('Type', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-status"><?php _e('Status', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-customer"><?php _e('Customer', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-reference"><?php _e('Reference', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-date"><?php _e('Date', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-actions"><?php _e('Actions', 'wc-paystack'); ?></th>
                </tr>
            </thead>
            
            <tbody>
                <?php if (empty($transactions)) : ?>
                    <tr>
                        <td colspan="9"><?php _e('No transactions found', 'wc-paystack'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($transactions as $transaction) : ?>
                        <tr class="<?php echo $transaction->status === 'reversed' ? 'transaction-reversed' : ''; ?>">
                            <td class="column-transaction-id">
                                <?php
                                $transaction_url = wc_paystack_get_transaction_url($transaction->transaction_id);
                                
                                if ($transaction_url) {
                                    echo '<a href="' . esc_url($transaction_url) . '" target="_blank">' . esc_html($transaction->transaction_id) . '</a>';
                                } else {
                                    echo esc_html($transaction->transaction_id);
                                }
                                ?>
                            </td>
                            <td class="column-order-id">
                                <?php
                                $order_url = admin_url('post.php?post=' . $transaction->order_id . '&action=edit');
                                echo '<a href="' . esc_url($order_url) . '">' . esc_html($transaction->order_id) . '</a>';
                                ?>
                            </td>
                            <td class="column-amount">
                                <?php 
                                // Ensure proper currency formatting with symbol
                                echo wp_kses_post(wc_price($transaction->amount, array('currency' => $transaction->currency))); 
                                ?>
                            </td>
                            <td class="column-type">
                                <?php echo isset($types[$transaction->transaction_type]) ? esc_html($types[$transaction->transaction_type]) : esc_html(ucfirst($transaction->transaction_type)); ?>
                            </td>
                            <td class="column-status">
                                <?php
                                $status_class = 'status-' . $transaction->status;
                                $status_label = isset($statuses[$transaction->status]) ? $statuses[$transaction->status] : ucfirst($transaction->status);
                                
                                echo '<span class="transaction-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';
                                ?>
                            </td>
                            <td class="column-customer">
                                <?php echo esc_html($transaction->customer_name); ?><br>
                                <small><?php echo esc_html($transaction->customer_email); ?></small>
                            </td>
                            <td class="column-reference">
                                <?php echo esc_html($transaction->reference); ?>
                            </td>
                            <td class="column-date">
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->transaction_date))); ?>
                            </td>
                            <td class="column-actions">
                                <?php
                                // Only show refresh button for payment transactions
                                if ($transaction->transaction_type === 'payment') {
                                    echo '<a href="' . esc_url(admin_url('admin.php?page=wc-paystack-transactions&action=check_status&transaction_id=' . $transaction->transaction_id . '&_wpnonce=' . wp_create_nonce('paystack_check_status'))) . '" class="button button-small" title="' . esc_attr__('Check current status in Paystack', 'wc-paystack') . '">' . esc_html__('Refresh', 'wc-paystack') . '</a>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            
            <tfoot>
                <tr>
                    <th scope="col" class="manage-column column-transaction-id"><?php _e('Transaction ID', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-order-id"><?php _e('Order ID', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-amount"><?php _e('Amount', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-type"><?php _e('Type', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-status"><?php _e('Status', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-customer"><?php _e('Customer', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-reference"><?php _e('Reference', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-date"><?php _e('Date', 'wc-paystack'); ?></th>
                    <th scope="col" class="manage-column column-actions"><?php _e('Actions', 'wc-paystack'); ?></th>
                </tr>
            </tfoot>
        </table>
    
        <div class="tablenav bottom">
            <div class="alignright">
                <label for="per-page-bottom"><?php _e('Show:', 'wc-paystack'); ?></label>
                <select name="per_page" id="per-page-bottom" onchange="this.form.submit()">
                    <?php foreach ($per_page_options as $option) : ?>
                        <option value="<?php echo esc_attr($option); ?>" <?php selected($per_page, $option); ?>><?php echo esc_html($option); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="displaying-num">
                    <?php printf(
                        _n('%s item', '%s items', $total_transactions, 'wc-paystack'),
                        number_format_i18n($total_transactions)
                    ); ?>
                </span>
            </div>
            
            <div class="tablenav-pages">
                <?php if ($total_pages > 1) : ?>
                    <span class="pagination-links">
                        <?php
                        echo paginate_links(array(
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                        ));
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <br class="clear">
        </div>
    </form>
</div>

<form id="wc-paystack-export-form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
    <input type="hidden" name="action" value="wc_paystack_export_transactions">
    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('wc_paystack_export_transactions')); ?>">
    
    <?php if (isset($_GET['transaction_id']) && !empty($_GET['transaction_id'])) : ?>
        <input type="hidden" name="transaction_id" value="<?php echo esc_attr($_GET['transaction_id']); ?>">
    <?php endif; ?>
    
    <?php if (isset($_GET['order_id']) && !empty($_GET['order_id'])) : ?>
        <input type="hidden" name="order_id" value="<?php echo esc_attr($_GET['order_id']); ?>">
    <?php endif; ?>
    
    <?php if (isset($_GET['reference']) && !empty($_GET['reference'])) : ?>
        <input type="hidden" name="reference" value="<?php echo esc_attr($_GET['reference']); ?>">
    <?php endif; ?>
    
    <?php if (isset($_GET['status']) && !empty($_GET['status'])) : ?>
        <input type="hidden" name="status" value="<?php echo esc_attr($_GET['status']); ?>">
    <?php endif; ?>
    
    <?php if (isset($_GET['type']) && !empty($_GET['type'])) : ?>
        <input type="hidden" name="type" value="<?php echo esc_attr($_GET['type']); ?>">
    <?php endif; ?>
    
    <?php if (isset($_GET['date_from']) && !empty($_GET['date_from'])) : ?>
        <input type="hidden" name="date_from" value="<?php echo esc_attr($_GET['date_from']); ?>">
    <?php endif; ?>
    
    <?php if (isset($_GET['date_to']) && !empty($_GET['date_to'])) : ?>
        <input type="hidden" name="date_to" value="<?php echo esc_attr($_GET['date_to']); ?>">
    <?php endif; ?>
</form>
