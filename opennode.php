<?php

/*
Plugin Name: WooCommerce Payment Gateway - OpenNode
Plugin URI: https://opennode.com
Description: Accept Bitcoin Instantly via OpenNode
Version: 1.5.5
Author: OpenNode
Author URI: https://opennode.com/about
Text Domain: opennode-for-woocommerce
Domain Path: /languages
WC requires at least: 5.0
WC tested up to: 8.5
*/

add_action('plugins_loaded', 'opennode_init', 11);

define('OPENNODE_WOOCOMMERCE_VERSION', '1.5.5');
define('OPENNODE_CHECKOUT_PATH', 'https://checkout.opennode.com/');
define('OPENNODE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('OPENNODE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Declare compatibility with WooCommerce features
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('block_template_controller', __FILE__, true);
    }
});

function opennode_init()
{
    // Check for WooCommerce
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . 
                 __('OpenNode requires WooCommerce to be installed and active.', 'opennode-for-woocommerce') . 
                 '</p></div>';
        });
        return;
    }

    // Check for OpenNode library
    if (!file_exists(__DIR__ . '/lib/opennode/init.php')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . 
                 __('OpenNode library is missing. Please reinstall the plugin.', 'opennode-for-woocommerce') . 
                 '</p></div>';
        });
        return;
    }

    define('PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');

    // Load OpenNode library
    require_once(__DIR__ . '/lib/opennode/init.php');

    class WC_Gateway_OpenNode extends WC_Payment_Gateway
    {
        /**
         * API secret
         *
         * @var string
         */
        public $api_secret;

        /**
         * API auth token
         *
         * @var string
         */
        public $api_auth_token;

        /**
         * Checkout URL
         *
         * @var string
         */
        public $checkout_url;

        public function __construct()
        {
            global $woocommerce;

            $this->id = 'opennode';
            $this->has_fields = false;
            $this->method_title = 'OpenNode';
            
            // Add support for blocks checkout
            $this->supports = array(
                'products',
                'refunds',
                'blocks',
                'payment_form',
            );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_secret = $this->get_option('api_secret');
            $this->api_auth_token = (empty($this->get_option('api_auth_token')) ? $this->get_option('api_secret') : $this->get_option('api_auth_token'));
            $this->checkout_url = $this->get_option('checkout_url');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_opennode', array($this, 'thankyou'));
            add_action('woocommerce_api_wc_gateway_opennode', array($this, 'payment_callback'));
        }

        public function admin_options()
        {
            ?>
            <h3><?php _e('OpenNode', 'opennode-for-woocommerce'); ?></h3>
            <p><?php _e('Accept Bitcoin instantly through OpenNode.com.', 'opennode-for-woocommerce'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php

        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable OpenNode', 'opennode-for-woocommerce'),
                    'label' => __('Enable Bitcoin payments via OpenNode', 'opennode-for-woocommerce'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => __('Title', 'opennode-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('The payment method title which a customer sees at the checkout of your store.', 'opennode-for-woocommerce'),
                    'default' => __('Pay with Bitcoin: on-chain or with Lightning', 'opennode-for-woocommerce'),
                ),
                'description' => array(
                    'title' => __('Description', 'opennode-for-woocommerce'),
                    'type' => 'textarea',
                    'description' => __('The payment method description which a customer sees at the checkout of your store.', 'opennode-for-woocommerce'),
                    'default' => __('Powered by OpenNode', 'opennode-for-woocommerce'),
                ),
                'api_auth_token' => array(
                    'title' => __('API Auth Token', 'opennode-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Your personal API Key. Generate one <a href="https://app.opennode.com/developers/integrations" target="_blank">here</a>.  ', 'opennode-for-woocommerce'),
                    'default' => (empty($this->get_option('api_secret')) ? '' : $this->get_option('api_secret')),
                ),
                'checkout_url' => array(
                  'title' => __('Checkout URL', 'opennode-for-woocommerce'),
                  'description' => __('URL for the checkout', 'opennode-for-woocommerce'),
                  'type' => 'text',
                  'default' => OPENNODE_CHECKOUT_PATH,
              ),
            );
        }

        public function thankyou()
        {
            if ($description = $this->get_description()) {
                echo wpautop(wptexturize($description));
            }
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            $this->init_opennode();

            //$description = array();
            //foreach ($order->get_items('line_item') as $item) {
            //    $description[] = $item['qty'] . ' × ' . $item['name'];
            //}


            $opennode_order_id = get_post_meta($order->get_id(), 'opennode_order_id', true);

            if (empty($opennode_order_id)) {
                $params = array(
                    'order_id'          => $order->get_id(),
                    'price'             => (strtoupper(get_woocommerce_currency()) === 'BTC') ? number_format($order->get_total()*100000000, 8, '.', '') : number_format($order->get_total(), 8, '.', ''),
                    'fiat'              => get_woocommerce_currency(),
                    'callback_url'      => trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_opennode',
                    'success_url'       => $order->get_checkout_order_received_url(),
                    'description'
                           => 'WooCommerce - #' . $order->get_id(),
                    'name'              => $order->get_formatted_billing_full_name(),
                    'email'             => $order->get_billing_email()
                );
                $opennode_order = \OpenNode\Merchant\Order::create($params);
                $opennode_order_id = $opennode_order->id;
                update_post_meta($order_id, 'opennode_order_id', $opennode_order_id);

                return array(
                    'result' => 'success',
                    'redirect' => $this->checkout_url . $opennode_order_id,
                );
            }
            else {
                return array(
                    'result' => 'success',
                    'redirect' => $this->checkout_url . $opennode_order_id,
                );
            }
        }

        public function payment_callback()
        {
            // Get request data safely
            $request = array();
            if (!empty($_REQUEST)) {
                $request = wp_unslash($_REQUEST);
            }
            
            if (empty($request['order_id'])) {
                die('No order ID provided');
            }
            
            $order = wc_get_order($request['order_id']);

            try {
                if (!$order || !$order->get_id()) {
                    throw new Exception('Order #' . $request['order_id'] . ' does not exists');
                }

                $token = get_post_meta($order->get_id(), 'opennode_order_id', true);

                if (empty($token) ) {
                    throw new Exception('Order has OpenNode ID associated');
                }


                if (strcmp(hash_hmac('sha256', $token, $this->api_auth_token), $request['hashed_order']) != 0) {
                    throw new Exception('Request is not signed with the same API Key, ignoring.');
                }

                $this->init_opennode();
                $cgOrder = \OpenNode\Merchant\Order::find($request['id']);

                if (!$cgOrder) {
                    throw new Exception('OpenNode Order #' . $order->get_id() . ' does not exists');
                }

                switch ($cgOrder->status) {
                    case 'paid':
                        $statusWas = "wc-" . $order->get_status();
                        $order->add_order_note(__('Payment is settled and has been credited to your OpenNode account. Purchased goods/services can be securely delivered to the customer.', 'opennode-for-woocommerce'));
                        $order->payment_complete();

                        // Send customer processing order email if status was expired or canceled
                        if ($order->get_status() === 'processing' && ($statusWas === 'wc-expired' || $statusWas === 'wc-canceled')) {
                            // Attempt to send email through WooCommerce
                            do_action('woocommerce_order_status_processing_notification', $order->get_id(), $order);
                        }
                        
                        // Send store owner new order email if status was expired or canceled
                        if (($order->get_status() === 'processing' || $order->get_status() == 'completed') && ($statusWas === 'wc-expired' || $statusWas === 'wc-canceled')) {
                            // Attempt to send email through WooCommerce
                            do_action('woocommerce_new_order_notification', $order->get_id(), $order);
                        }
                        break;
                    case 'processing':
                        $order->add_order_note(__('Customer has paid via standard on-Chain. Payment is awaiting 1 confirmation by the Bitcoin network, DO NOT SEND purchased goods/services UNTIL payment has been marked as PAID', 'opennode-for-woocommerce'));
                        break;
                    case 'underpaid':
                        $missing_amt = number_format($cgOrder->missing_amt/100000000, 8, '.', '');
                        $order->add_order_note(__('Customer has paid via standard on-Chain, but has underpaid by ' . $missing_amt . ' BTC. Waiting on user to send the remainder before marking as PAID.', 'opennode-for-woocommerce'));
                        break;
                    case 'expired':
                      $order->add_order_note(__('Payment expired', 'opennode-for-woocommerce'));
                      $order->update_status('cancelled');
                      break;
                    case 'refunded':
                        $refund_id = $cgOrder->refund['id'];
                        $order->add_order_note(__('Customer has canceled the payment. Refund ID - ' . $refund_id . ' .', 'opennode-for-woocommerce'));
                        $order->update_status('cancelled');
                        break;
                }
            } catch (Exception $e) {
                die(get_class($e) . ': ' . $e->getMessage());
            }
        }

        private function init_opennode()
        {
            // Get WooCommerce version safely
            global $woocommerce;
            $wc_version = '8.0+';
            
            if (is_object($woocommerce) && isset($woocommerce->version)) {
                $wc_version = $woocommerce->version;
            }
            
            \OpenNode\OpenNode::config(
                array(
                    'auth_token'    => (empty($this->api_auth_token) ? $this->api_secret : $this->api_auth_token),
                    'environment'   => 'live',
                    'user_agent'    => ('OpenNode - WooCommerce v' . $wc_version . ' Plugin v' . OPENNODE_WOOCOMMERCE_VERSION)
                )
            );
        }
    }

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', function($methods) {
        $methods[] = 'WC_Gateway_OpenNode';
        return $methods;
    });

    // Initialize blocks support
    add_action('init', 'opennode_init_blocks_support');
}

// Move blocks support initialization to a separate function
function opennode_init_blocks_support() {
    // Only load blocks integration if WC Blocks is active
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    // Register the blocks integration
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function($payment_method_registry) {
            if (!class_exists('OpenNode_Payment_Blocks')) {
                require_once OPENNODE_PLUGIN_PATH . 'includes/class-opennode-blocks.php';
            }
            $payment_method_registry->register(new OpenNode_Payment_Blocks());
        }
    );

    // Register script for blocks checkout
    add_action('enqueue_block_editor_assets', function() {
        wp_register_script(
            'opennode-payment-blocks',
            OPENNODE_PLUGIN_URL . 'assets/js/blocks-checkout.js',
            ['wp-element', 'wc-blocks-registry', 'wp-i18n', 'wc-settings', 'wc-blocks-data-store'],
            OPENNODE_WOOCOMMERCE_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'opennode-payment-blocks',
                'opennode-for-woocommerce'
            );
        }

        // Add gateway data for the script
        $gateway = new WC_Gateway_OpenNode();
        wp_localize_script('opennode-payment-blocks', 'opennode_data', [
            'title' => $gateway->title,
            'description' => $gateway->description,
            'supports' => $gateway->supports,
            'name' => 'opennode',
        ]);

        wp_enqueue_script('opennode-payment-blocks');
    });

    // Register script for frontend
    add_action('wp_enqueue_scripts', function() {
        // Only load on cart and checkout pages
        if (!is_checkout() && !is_cart()) {
            return;
        }

        wp_register_script(
            'opennode-payment-blocks',
            OPENNODE_PLUGIN_URL . 'assets/js/blocks-checkout.js',
            ['wp-element', 'wc-blocks-registry', 'wp-i18n', 'wc-settings', 'wc-blocks-data-store'],
            OPENNODE_WOOCOMMERCE_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'opennode-payment-blocks',
                'opennode-for-woocommerce'
            );
        }

        // Add gateway data for the script
        $gateway = new WC_Gateway_OpenNode();
        wp_localize_script('opennode-payment-blocks', 'opennode_data', [
            'title' => $gateway->title,
            'description' => $gateway->description,
            'supports' => $gateway->supports,
            'name' => 'opennode',
        ]);

        wp_enqueue_script('opennode-payment-blocks');
    });
}
