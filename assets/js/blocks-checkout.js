/**
 * OpenNode Payment method for WooCommerce Blocks
 */
(function() {
    // Exit early if WooCommerce blocks registry doesn't exist
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcBlocksRegistry.registerPaymentMethod) {
        return;
    }

    // Get dependencies
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement } = window.wp.element;
    const { __ } = window.wp.i18n;
    const { decodeEntities } = window.wp.htmlEntities;
    
    // Get settings from localized data
    const settings = window.opennode_data || {};
    const title = decodeEntities(settings.title) || __('Pay with Bitcoin: on-chain or with Lightning', 'opennode-for-woocommerce');
    
    // Create the content component
    const Content = () => {
        return createElement('div', {
            dangerouslySetInnerHTML: {
                __html: decodeEntities(settings.description || '')
            }
        });
    };
    
    // Register the payment method
    registerPaymentMethod({
        name: 'opennode',
        label: title,
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products', 'refunds', 'checkout_block', 'cart_block', 'cart_checkout_block'],
            showSavedCards: false,
            showSaveOption: false,
            isGatewayEnabled: true,
        },
    });
})(); 