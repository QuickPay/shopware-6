import './service/QuickpayApiTestService';
import './component/quickpay-api-test-button';

import localeDE from './snippet/de_DE.json';
import localeEN from './snippet/en_GB.json';
import localeDK from './snippet/da_DK.json';

Shopware.Locale.extend('de-DE', localeDE);
Shopware.Locale.extend('en-GB', localeEN);
Shopware.Locale.extend('da-DK', localeDK);
