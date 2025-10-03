# QuickPay Payment Plugin for Shopware #
This plugin enables QuickPay as payment option for Shopware
## Installation ##

### Via Composer (Recommended) ###
The easiest way to install the plugin is via Composer:

```bash
composer require store.shopware.com/wexoquickpay
```

After installation, refresh the plugin list and activate the extension:
```bash
bin/console plugin:refresh
bin/console plugin:install --activate WexoQuickpay
bin/console cache:clear
```

### Via ZIP Upload ###
Alternatively, the plugin can be installed manually:
- Download the latest zipped version from the releases page
- Go to Extensions->My Extensions in the Shopware 6 Admin Panel
- Upload the zip-file and activate the extension
- Continue with configuring the plugin

## Updating ##

### Via Composer ###
To update the plugin via Composer:
```bash
composer update store.shopware.com/wexoquickpay
bin/console plugin:update WexoQuickpay
bin/console cache:clear
```

### Via ZIP Upload ###
To update the plugin manually:
- Download the latest release
- Upload it using the Admin-Extensions page
- Click on the update options in the extensions context menu

## Configuration ##
After installing the plugin Shopware offers the possibility to configure it by clicking on the 3 dots and selecting the configuration option.
The QuickPay Payment plugin has the following settings:

|  Name        | Description                                   |
| ------------ | --------------------------------------------- |
|  Public Key  | The API key for the QuickPay integration      |
|  Private Key | The private key for the QuickPay integration  |


The public and private key can be found in the QuickPay management panel under Settings->Integration (Use the API-Key for the public key configuration)

**Note:** For testing, use test keys from your QuickPay account. Branding is now configured directly in the QuickPay admin panel.

In order to use the QuickPay payment method the it has to be activated using the Payment settings. Don't forget to assign the payment method to the sales channel too.

## Administration functionality ##
The following actions can be performed in the Shopware administration:

#### Orders List ####
The plugin adds an additional column to the list or orders in the Shopware 6 administration. If the QuickPay payment status of an order allows capturing this column will contain an icon-button indicating this possibility. Upon clicking the icon a confimation window will be opened. After entering the amount to be captured (or leaving the preselected full amount) the capture can be confirmed and will be sent to the QuickPay API

#### QuickPay panel ####
When opening the detail view for an order in the administration a new QuickPay tab has been added at the top. Selecting it will lead to the QuickPay panel for the order.

This panel contains a List containing the History of the QuickPay payment. That means every requested operation by the user (capture) and every callback response from the QuickPay server is logged and displayed there. The history is automatically updated each time the panel is opened.

Above this list a Capture button is present:

| Button   | Functionality                                      |
| -------- | -------------------------------------------------- |
| Capture  | Send a capture request to the QuickPay API         |


The Capture button is enabled or disabled according to the current status of the QuickPay payment.
