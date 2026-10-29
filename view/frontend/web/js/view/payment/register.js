define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], function (Component, renderers) {
    'use strict';
    renderers.push({type: 'whollycrypto', component: 'WhollyCrypto_Payment/js/view/payment/method'});
    return Component.extend({});
});
