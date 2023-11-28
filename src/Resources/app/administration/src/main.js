import './service/QuickpayApiService';
import './component/quickpay-api-test-button';
import './component/quickpay-order-payment-details'
import './page/sw-order-details'

import localeDE from './snippet/de_DE.json';
import localeEN from './snippet/en_GB.json';
import localeDK from './snippet/da_DK.json';

Shopware.Locale.extend('de-DE', localeDE);
Shopware.Locale.extend('en-GB', localeEN);
Shopware.Locale.extend('da-DK', localeDK);

Shopware.Module.register('quickpay-order-payment-details-tab', {
    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.order.detail') {
            currentRoute.children.push({
                name: 'quickpay.order.payment.details',
                path: '/sw/order/detail/:id/quickpay-payment',
                component: 'quickpay-order-payment-details',
                meta: {
                    parentPath: "sw.order.index",
                    privilege: 'order.viewer'
                }
            });
        }
        next(currentRoute);
    }
});

if (module.hot) {
  module.hot.accept();
}
