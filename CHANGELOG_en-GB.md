# 6.1.6
* Added Vipps as a payment method

# 6.1.5
* Bugfix when updating plugin and subscription customField already exist

# 6.1.4
* Bugfix for Swish payment callback and Shopware state transition

# 6.1.3
* Update so capture function always have the newest info from Quickpay

# 6.1.2
* Fixed so state changes on non-quickpay payments doesn't trigger quickpay functions

# 6.1.1
* Fixed API config test button

# 6.1.0
* Added Googlepay and Applepay as a payment methods
* Config to hide payment methods that is not supported in current user-agent 

# 6.0.0
* Subscriptions added for Quickpay

# 5.2.0

* Added Paypal as a payment method
* Quickpay payment window will now only show alternative payment methods that are enabled in the current sales channel 

# 5.1.3

* Bugfixes for Swish payment

# 5.1.2

* Changes to Swish payment:
  * As Swish is a banktransfer, capture happens on finalize transaction.
  * Payment Status in Shopware updates directly to Paid (skipping Authorized), as capture has already happened
  * When trying to capture on a Swish payment, we skip capturing and update Shopware states for shipping and order status
    * Shipping state: Shipped
    * Order state: Done

# 5.1.1

* Shopware 6.4.9.0 compatibility

# 5.1.0

* Added Swish as a payment method

# 5.0.11

* Fixed an issue where manual payment capture failed if order state was "Authorized"

# 5.0.10

* Fixed an issue where payment did not finalize when receiving callback from Quickpay

# 5.0.9

* Fix for double token invalidation when using MobilePay and Mastercard
* Shopware 6.4.5.x compatability

# 5.0.7

* Ensure cart is cleared after succesful payment on Shopware 6.4.4.1

# 5.0.6

* Fix issue with double token invalidation in Shopware 6.4.3.0

# 5.0.5

* Use sales channel specific settings for cancel payment
* Do not try to cancel authorized payments

# 5.0.3

* Minor tweaks and cleanups. No longer supporting PHP 7.2

# 5.0.2

* API test of API & private key
* Fixed order cancel bug

# 5.0.0

* Shopware 6.4 compatability
* Callback check
* Payment and shipping automations

# 3.2.0

* Viabill added
* Klarna added
* Set payment window language based on sales channel language
* Better callback handling

# 3.0.4

* Fix the API test button

# 3.0.3

* Removed need for MobilePay ID in admin config
