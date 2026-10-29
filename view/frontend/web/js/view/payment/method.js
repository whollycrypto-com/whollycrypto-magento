define(['Magento_Checkout/js/view/payment/default'], function (Component) {
    'use strict';
    return Component.extend({
        defaults: {template: 'WhollyCrypto_Payment/payment/method'},
        redirectAfterPlaceOrder: false,
        afterPlaceOrder: function () {
            window.location.assign(window.checkoutConfig.payment.whollycrypto.startUrl);
        }
    });
});
