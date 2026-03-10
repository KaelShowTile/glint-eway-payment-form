<?php
/**
 * Plugin Name: ST Eway public Payment Link
 * Description: Generates Eway public payment links via a frontend form using AJAX.
 * Version: 1.0.0
 * Author: Kael
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Eway_Quick_Pay {

    private $option_group = 'eqp_options_group';

    public function __construct() {
        // Admin Menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Frontend Assets & Shortcode
        add_shortcode('eway_payment_form', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX Handler
        add_action('wp_ajax_eqp_generate_link', [$this, 'handle_ajax_request']);
        add_action('wp_ajax_nopriv_eqp_generate_link', [$this, 'handle_ajax_request']);
    }

    /**
     * 1. Backend: Add Menu Item
     */
    public function add_admin_menu() {
        add_options_page(
            'Eway Quick Pay',
            'Eway Quick Pay',
            'manage_options',
            'eway-quick-pay',
            [$this, 'render_settings_page']
        );
    }

    /**
     * 1. Backend: Register Settings
     */
    public function register_settings() {
        register_setting($this->option_group, 'eqp_api_key');
        register_setting($this->option_group, 'eqp_api_password');
        register_setting($this->option_group, 'eqp_mode'); // Sandbox or Production
        register_setting($this->option_group, 'eqp_redirect_url');
        register_setting($this->option_group, 'eqp_cancel_url');
    }

    /**
     * 1. Backend: Render Settings Page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Eway Quick Pay Settings</h1>
            <p>Shortcode of payment form: [eway_payment_form]</p>
            <form method="post" action="options.php">
                <?php settings_fields($this->option_group); ?>
                <?php do_settings_sections($this->option_group); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">API Mode</th>
                        <td>
                            <select name="eqp_mode">
                                <option value="sandbox" <?php selected(get_option('eqp_mode'), 'sandbox'); ?>>Sandbox</option>
                                <option value="production" <?php selected(get_option('eqp_mode'), 'production'); ?>>Production</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">API Key</th>
                        <td><input type="text" name="eqp_api_key" value="<?php echo esc_attr(get_option('eqp_api_key')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">API Password</th>
                        <td><input type="password" name="eqp_api_password" value="<?php echo esc_attr(get_option('eqp_api_password')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Redirect URL (Success)</th>
                        <td><input type="url" name="eqp_redirect_url" value="<?php echo esc_attr(get_option('eqp_redirect_url')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Cancel URL</th>
                        <td><input type="url" name="eqp_cancel_url" value="<?php echo esc_attr(get_option('eqp_cancel_url')); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * 2. Frontend: Render Form Shortcode
     */
    public function render_shortcode($atts) {
        ob_start();
        ?>
        <div id="eqp-form-wrapper">
            <form id="eqp-payment-form">
                <div class="eqp-row two-col">
                    <div class="eqp-field">
                        <label for="eqp_invoice">Invoice Number/Reference *</label>
                        <input type="text" id="eqp_invoice" name="invoice" required>
                    </div>
                    <div class="eqp-field">
                        <label for="eqp_amount">Payment Amount ($) *</label>
                        <input type="number" id="eqp_amount" name="amount" step="0.01" min="0.01" required>
                    </div>
                </div>
                <div class="eqp-row two-col">
                    <div class="eqp-field">
                        <label for="eqp_first_name">First Name *</label>
                        <input type="text" id="eqp_first_name" name="first_name" required>
                    </div>
                    <div class="eqp-field">
                        <label for="eqp_last_name">Last Name *</label>
                        <input type="text" id="eqp_last_name" name="last_name" required>
                    </div>
                </div>
                <div class="eqp-row two-col">
                    <div class="eqp-field">
                        <label for="eqp_email">Email *</label>
                        <input type="email" id="eqp_email" name="email" required>
                    </div>
                    <div class="eqp-field">
                        <label for="eqp_phone">Phone *</label>
                        <input type="text" id="eqp_phone" name="phone" required>
                    </div>
                </div>
                <div class="eqp-field">
                    <label for="eqp_address">Billing Address</label>
                    <input type="text" id="eqp_address" name="address">
                </div>
                
                <div class="eqp-submit">
                    <button type="submit" id="eqp-submit-btn">Pay Now</button>
                </div>
                <div id="eqp-message"></div>
            </form>
        </div>
        
        <?php
        return ob_get_clean();
    }

    /**
     * 3. Frontend: Enqueue Scripts & Ajax
     */
    public function enqueue_assets() {
        wp_enqueue_script('jquery');
        
        // Inline JS for simplicity of a single file plugin
        $custom_js = "
        jQuery(document).ready(function($) {
            $('#eqp-payment-form').on('submit', function(e) {
                e.preventDefault();
                
                var \$form = $(this);
                var \$btn = $('#eqp-submit-btn');
                var \$msg = $('#eqp-message');
                
                \$btn.prop('disabled', true).text('Processing...');
                \$msg.text('');

                var formData = {
                    action: 'eqp_generate_link',
                    invoice: $('#eqp_invoice').val(),
                    amount: $('#eqp_amount').val(),
                    first_name: $('#eqp_first_name').val(),
                    last_name: $('#eqp_last_name').val(),
                    email: $('#eqp_email').val(),
                    phone: $('#eqp_phone').val(),
                    address: $('#eqp_address').val()
                };

                $.post('" . admin_url('admin-ajax.php') . "', formData, function(response) {
                    if (response.success) {
                        \$btn.text('Redirecting...');
                        window.location.href = response.data.url;
                    } else {
                        \$btn.prop('disabled', false).text('Pay Now');
                        \$msg.text('Error: ' + (response.data || 'Unknown error occurred.'));
                    }
                }).fail(function() {
                    \$btn.prop('disabled', false).text('Pay Now');
                    \$msg.text('Server error. Please try again.');
                });
            });
        });
        ";
        
        wp_add_inline_script('jquery', $custom_js);
    }

    /**
     * 4. Backend: Handle Ajax & Generate Link
     */
    public function handle_ajax_request() {
        // 1. Retrieve and Sanitize Inputs
        $invoice = sanitize_text_field($_POST['invoice']);
        $amount_float = floatval($_POST['amount']);
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $address = sanitize_text_field($_POST['address']);

        // 2. Validation
        if (empty($invoice) || $amount_float <= 0) {
            wp_send_json_error('Invalid invoice number or amount.');
        }

        // 3. Prepare Eway Payload
        // Eway requires amount in cents (integer)
        $amount_cents = intval(round($amount_float * 100));
        
        $api_key = get_option('eqp_api_key');
        $api_password = get_option('eqp_api_password');
        $mode = get_option('eqp_mode');
        $redirect_url = get_option('eqp_redirect_url');
        $cancel_url = get_option('eqp_cancel_url');

        if (!$api_key || !$api_password) {
            wp_send_json_error('Payment configuration missing.');
        }

        // Determine Endpoint
        $endpoint = ($mode === 'sandbox') 
            ? 'https://api.sandbox.ewaypayments.com/AccessCodesShared' 
            : 'https://api.ewaypayments.com/AccessCodesShared';

        $payload = [
            'Payment' => [
                'TotalAmount' => $amount_cents,
                'InvoiceNumber' => $invoice,
                'CurrencyCode' => 'AUD' // Defaulting to AUD, change if needed
            ],
            'RedirectUrl' => $redirect_url,
            'CancelUrl' => $cancel_url,
            'TransactionType' => 'Purchase',
            "CustomerReadOnly" => true,
            'Customer' => [
                'FirstName' => $first_name,
                'LastName' => $last_name,
                'Email' => $email,
                'Phone' => $phone,
                'Street1' => $address
            ],
            'Method' => 'ProcessPayment'
        ];

        // 4. Call Eway API
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_password),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ],
            'body' => json_encode($payload),
            'timeout' => 45
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // 5. Handle Response
        if (isset($data['SharedPaymentUrl'])) {
            wp_send_json_success(['url' => $data['SharedPaymentUrl']]);
        } else {
            // Extract Eway error message if available
            $error_msg = 'Payment gateway error.';
            if (!empty($data['Errors'])) {
                $error_msg .= ' (' . $data['Errors'] . ')';
            }
            wp_send_json_error($error_msg);
        }
    }
}

new Eway_Quick_Pay();
