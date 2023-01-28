<?php

/*
Plugin Name: WooCommerce Payment Gateway - OpenNode
Plugin URI: https://opennode.com
Description: Accept Bitcoin Instantly via OpenNode
Version: 1.5.2
Author: OpenNode
Author URI: https://opennode.com/about
*/

add_action('plugins_loaded', 'opennode_init');

define('OPENNODE_WOOCOMMERCE_VERSION', '1.5.2');
define('OPENNODE_CHECKOUT_PATH', 'https://checkout.opennode.com/');

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
            $this->method_title = __('OpenNode','opennode-for-woocommerce');

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
                    'description' => __('Your personal API Key. Generate one <a href="https://app.opennode.com/developers/integrations" target="_blank">here</a>.', 'opennode-for-woocommerce'),
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
            $request = $_REQUEST;
            $order = wc_get_order($request['order_id']);

            try {
                if (!$order || !$order->get_id()) {
                    throw new Exception(__('Order #','opennode-for-woocommerce') . $request['order_id'] . __(' does not exist','opennode-for-woocommerce'));
                }

                $token = get_post_meta($order->get_id(), 'opennode_order_id', true);

                if (empty($token) ) {
                    throw new Exception(__('Order has OpenNode ID associated','opennode-for-woocommerce'));
                }


                if (strcmp(hash_hmac('sha256', $token, $this->api_auth_token), $request['hashed_order']) != 0) {
                    throw new Exception(__('Request is not signed with the same API Key, ignoring.','opennode-for-woocommerce'));
                }

                $this->init_opennode();
                $cgOrder = \OpenNode\Merchant\Order::find($request['id']);

                if (!$cgOrder) {
                    throw new Exception(__('OpenNode Order #','opennode-for-woocommerce') . $order->get_id() . __(' does not exist','opennode-for-woocommerce'));
                }

                switch ($cgOrder->status) {
                    case 'paid':
                        $statusWas = "wc-" . $order->get_status();
                        $order->add_order_note(__('Payment is settled and has been credited to your OpenNode account. Purchased goods/services can be securely delivered to the customer.', 'opennode-for-woocommerce'));
                        $order->payment_complete();

                        if ($order->get_status() === 'processing' && ($statusWas === 'wc-expired' || $statusWas === 'wc-canceled')) {
                            WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
                        }
                        if (($order->get_status() === 'processing' || $order->get_status() == 'completed') && ($statusWas === 'wc-expired' || $statusWas === 'wc-canceled')) {
                            WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id());
                        }
                        break;
                    case 'processing':
                        $order->add_order_note(__('Customer has paid via standard on-Chain. Payment is awaiting 1 confirmation by the Bitcoin network, DO NOT SEND purchased goods/services UNTIL payment has been marked as PAID', 'opennode-for-woocommerce'));
                        break;
                    case 'underpaid':
                        $missing_amt = number_format($cgOrder->missing_amt/100000000, 8, '.', '');
                        $order->add_order_note(__('Customer has paid via standard on-Chain, but has underpaid by ','opennode-for-woocommerce') . $missing_amt . __(' BTC. Waiting on user to send the remainder before marking as PAID.', 'opennode-for-woocommerce'));
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
