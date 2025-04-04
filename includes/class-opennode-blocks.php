<?php
/**
 * OpenNode Payment Blocks Integration
 *
 * @package OpenNode_For_WooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * OpenNode Payment Blocks Integration class
 */
final class OpenNode_Payment_Blocks extends AbstractPaymentMethodType {

    /**
     * Payment method name/id/slug
     *
     * @var string
     */
    protected $name = 'opennode';

    /**
     * Gateway instance
     *
     * @var WC_Gateway_OpenNode
     */
    private $gateway;

    /**
     * Initialize the payment method type
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_opennode_settings', []);
        $this->gateway = new WC_Gateway_OpenNode();
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        return ['opennode-payment-blocks'];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'supports' => [
                'features' => $this->gateway->supports,
                'showSavedCards' => false,
                'showSaveOption' => false,
                'isEligible' => true,
            ],
            'name' => $this->name,
            'paymentMethodId' => 'opennode',
            'allowedCountries' => [],
            'icons' => [
                'id' => 'bitcoin',
                'src' => OPENNODE_PLUGIN_URL . 'assets/images/bitcoin.svg',
                'alt' => 'Bitcoin',
            ],
        ];
    }
} 