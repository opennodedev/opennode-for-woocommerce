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
            className: 'opennode-payment-method',
            dangerouslySetInnerHTML: {
                __html: decodeEntities(settings.description || '')
            }
        });
    };

    // Create the label component
    const Label = () => {
        const icon = settings.icons ? createElement('img', {
            src: settings.icons.src,
            alt: settings.icons.alt,
            className: 'opennode-payment-method__icon'
        }) : null;

        return createElement('div', {
            className: 'opennode-payment-method__label'
        }, [
            icon,
            createElement('span', null, title)
        ]);
    };
    
    // Register the payment method
    registerPaymentMethod({
        name: settings.name || 'opennode',
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: title,
        supports: settings.supports || {
            features: ['products', 'refunds', 'blocks', 'payment_form'],
            showSavedCards: false,
            showSaveOption: false,
            isEligible: true,
        },
        icons: settings.icons ? [settings.icons] : [],
    });
})(); 