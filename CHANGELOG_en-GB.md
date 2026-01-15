# 8.1.4
* Added QuickPay callback route.
* Updated execptions which were either removed or deprecated.

# 8.1.3
* Updated SubscriptionQuickpayService for 6.6

# 8.1.2
* Added missing shippingOrderAddress association on subscriptionQuickpay service

# 8.1.1
* Refactored recurring payment logic to differentiate initial subscription payments from subsequent ones.

# 8.1.0
* Fixed an issue that prevented orders from being cancelled in Quickpay.

# 8.0.3
* Added StateMachineState association to avoid "Call to member function getTechnicalName() on null"

# 8.0.2
* Change CartPersister typehint to AbstractTypePersister in CartOrderRouteDecorator to allow for decoration

# 8.0.1
* Fixed issue causing "Call to a member function getTechnicalName() on null" by ensuring stateMachineState association is loaded for OrderTransaction and Order entities in QuickpayPayment service.

# 8.0.0
* Shopware 6.6 compatible.

# 7.1.3
* Fix renew() to recalculate price on changePayment() in product-subscription plugin

# 7.1.2
* Added seperate capture for subscription orders only

# 7.1.0
* Fixed an issue that prevented orders from being cancelled in Quickpay.

# 7.0.8
* Fixed an issue where the new subscription order wasn't fetched correctly.

# 7.0.7
* Added renew functionality for subscription orders.

# 7.0.6
* Change CartPersister typehint to AbstractTypePersister in CartOrderRouteDecorator to allow for decoration

# 7.0.5
* Fixed invalid validation of refund status

# 7.0.4
* Change loading status if there is no quickpay response to fix infinite loading on the admin panel.

# 7.0.3 
* Improved conditional check on tax rate to ensure safety against potential null pointer exceptions.

# 7.0.2
* Added service to handle refunding (For developers)

# 7.0.1
* Added payment brand info on QuickPay Payment tab on the order

# 7.0.0
* Shopware 6.5 compatible

# 6.2.4
* Event handling performance optimizations
* Added settings for disabling capture and cancel payments in QuickPay

# 6.2.3
* Improve exception logs, include sw_status_code response to quickpay finalize transaction request

# 6.2.2
* Remove the use of @RouteScope for Shopware 6.5 compatibility

# 6.2.1
* Go back to checkout confirm page when cancelling in the payment window

# 6.2.0
* Internal API changes to allow more customizability

# 6.1.8
* Added Anyday payment method
* Changed Vipps paymentmethod from vipps to vippspsp, which is Vipps via Quickpay

# 6.1.7
* Fixing Deprecated Exception message

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
