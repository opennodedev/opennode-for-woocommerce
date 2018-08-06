<?php

/*
Plugin Name: WooCommerce Payment Gateway - OpenNode
Plugin URI: https://opennode.co
Description: Accept Bitcoin Instantly via OpenNode
Version: 1.2
Author: OpenNode
Author URI: https://opennode.co/about
*/

add_action('plugins_loaded', 'opennode_init');

define('OPENNODE_WOOCOMMERCE_VERSION', '1.1');
define('OPENNODE_CHECKOUT_PATH', 'https://opennode.co/checkout/');

function opennode_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    };

    define('PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');

    require_once(__DIR__ . '/lib/opennode/init.php');

    class WC_Gateway_OpenNode extends WC_Payment_Gateway
    {
        public function __construct()
        {
            global $woocommerce;

            $this->id = 'opennode';
            $this->has_fields = false;
            $this->method_title = 'OpenNode';
            $this->icon = apply_filters('woocommerce_opennode_icon', PLUGIN_DIR . 'assets/bitcoin.png');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_secret = $this->get_option('api_secret');
            $this->api_auth_token = (empty($this->get_option('api_auth_token')) ? $this->get_option('api_secret') : $this->get_option('api_auth_token'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_opennode', array($this, 'thankyou'));
            add_action('woocommerce_api_wc_gateway_opennode', array($this, 'payment_callback'));
        }

        public function admin_options()
        {
            ?>
            <h3><?php _e('OpenNode', 'woothemes'); ?></h3>
            <p><?php _e('Accept Bitcoin instantly through the OpenNode.co.', 'woothemes'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php

        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable OpenNode', 'woocommerce'),
                    'label' => __('Enable Bitcoin payments via OpenNode', 'woocommerce'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('The payment method title which a customer sees at the checkout of your store.', 'woocommerce'),
                    'default' => __('Pay with Bitcoin: on-chain or with Lightning', 'woocommerce'),
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('The payment method description which a customer sees at the checkout of your store.', 'woocommerce'),
                    'default' => __('Powered by OpenNode'),
                ),
                'api_auth_token' => array(
                    'title' => __('API Auth Token', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Your personal API Key. Generate one <a href="https://opennode.co/settings" target="_blank">here</a>.  ', 'woocommerce'),
                    'default' => (empty($this->get_option('api_secret')) ? '' : $this->get_option('api_secret')),
                )
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

            $description = array();
            foreach ($order->get_items('line_item') as $item) {
                $description[] = $item['qty'] . ' × ' . $item['name'];
            }


            $opennode_order_id = get_post_meta($order->get_id(), 'opennode_order_id', true);

            if (empty($opennode_order_id)) {
                $params = array(
                    'order_id'          => $order->get_id(),
                    'price'             => (strtoupper(get_woocommerce_currency()) === 'BTC') ? number_format($order->get_total()*100000000, 8, '.', '') : number_format($order->get_total(), 8, '.', ''),
                    'fiat'              => get_woocommerce_currency(),
                    'callback_url'      => trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_opennode',
                    'success_url'       => add_query_arg('order', $order->get_id(), add_query_arg('key', $order->get_order_key(), $this->get_return_url($order))),
                    'description'       => implode($description, ', '),
                    'name'              => $order->get_formatted_billing_full_name(),
                    'email'             => $order->get_billing_email()
                );
                $opennode_order = \OpenNode\Merchant\Order::create($params);
                $opennode_order_id = $opennode_order->id;
                update_post_meta($order_id, 'opennode_order_id', $opennode_order_id);

                return array(
                    'result' => 'success',
                    'redirect' => OPENNODE_CHECKOUT_PATH . $opennode_order_id,
                );
            }
            else {
                return array(
                    'result' => 'success',
                    'redirect' => OPENNODE_CHECKOUT_PATH . $opennode_order_id,
                );
            }
        }

        public function payment_callback()
        {
            $request = $_REQUEST;
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
                        $order->add_order_note(__('Payment is confirmed and has been credited to your OpenNode account. Purchased goods/services can be securely delivered to the buyer.', 'opennode'));
                        $order->payment_complete();

                        if ($order->get_status() === 'processing' && ($statusWas === 'wc-expired' || $statusWas === 'wc-canceled')) {
                            WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
                        }
                        if (($order->get_status() === 'processing' || $order->get_status() == 'completed') && ($statusWas === 'wc-expired' || $statusWas === 'wc-canceled')) {
                            WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id());
                        }
                        break;
                    case 'processing':
                        $order->add_order_note(__('Customer has paid via Chain. Payment is awaiting 1 confirmation by Bitcoin network, do not send purchased goods/services yet.', 'opennode'));
                        break;
                }
            } catch (Exception $e) {
                die(get_class($e) . ': ' . $e->getMessage());
            }
        }



        private function init_opennode()
        {
            \OpenNode\OpenNode::config(
                array(
                    'auth_token'    => (empty($this->api_auth_token) ? $this->api_secret : $this->api_auth_token),
                    'environment'   => 'live',
                    'user_agent'    => ('OpenNode - WooCommerce v' . WOOCOMMERCE_VERSION . ' Plugin v' . OPENNODE_WOOCOMMERCE_VERSION)
                )
            );
        }
    }

    function add_opennode_gateway($methods)
    {
        $methods[] = 'WC_Gateway_OpenNode';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_opennode_gateway');
}