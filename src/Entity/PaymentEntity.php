<?php


namespace QuickPay\Entity;

use DateTime;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class PaymentEntity extends Entity
{
    use EntityIdTrait;
    /**
     * @var string QuickPay Id of the payment
     */
    protected $quickpayId;
    /**
     * @var string QuickPay Id of the payment
     */
    protected $quickpayOrderId;
    /**
     * @var integer Status of the payment
     */
    protected $status;
    const PAYMENT_CREATED = 0;
    const PAYMENT_FULLY_AUTHORIZED = 5;
    const PAYMENT_CAPTURE_REQUESTED = 10;
    const PAYMENT_PARTLY_CAPTURED = 12;
    const PAYMENT_FULLY_CAPTURED = 15;
    const PAYMENT_CANCEL_REQUSTED = 20;
    const PAYMENT_CANCELLED = 25;
    const PAYMENT_REFUND_REQUSTED = 30;
    const PAYMENT_PARTLY_REFUNDED = 32;
    const PAYMENT_FULLY_REFUNDED = 35;
    const PAYMENT_INVALIDATED = 100;
    /**
     * @var string The Id of the transaction
     */
    protected $transactionId;
    /**
     * @var OrderTransactionEntity The transaction object
     */
    protected $transaction;
    /**
     * @var string link for the payment
     */
    protected $link;
    /**
     * @var string currency for the payment
     */
    protected $currency;
    /**
     * @var integer Amount to pay
     */
    protected $amount;
    /**
     * @var integer Amount authorized through QuickPay
     */
    protected $amountAuthorized;
    /**
     *
     * @var integer Amount captured through QuickPay
     */
    protected $amountCaptured;
    /**
     *
     * @var integer Amount refunded through QuickPay
     */
    protected $amountRefunded;
    /**
     * @var DateTime timestamp of the payment authorization
     */
    protected $authorizedAt;
    /**
     * @var PaymentOperationCollection List of operations
     */
    protected $operations;
    /**
     * Get the QuickPay payment id
     * @return string
     */
    public function getQuickpayId()
    {
        return $this->quickpayId;
    }
    
    /**
     * Get the QuickPay payment id
     * @return string
     */
    public function getQuickpayOrderId()
    {
        return $this->quickpayOrderId;
    }
    
    /**
     * Get the status of the payment
     * @return integer
     */
    public function getStatus()
    {
        return $this->status;
    }
    
    /**
     * get the Id of the linked transaction
     * @return string
     */
    public function getTransactionId()
    {
        return $this->transactionId;
    }
    
    /**
     * get the object of the linked transaction
     * @return OrderTransactionEntity
     */
    public function getTransaction()
    {
        return $this->transaction;
    }
    
    /**
     * Get the currency of the order
     * @return string currency
     */
    public function getCurrency()
    {
        return $this->currency;
    }
    
    /**
     * Get the amount authorized to pay
     * @return integer amount in cents
     */
    public function getAmount()
    {
        return $this->amount;
    }
    
    /**
     * Get the amount authorized through Quickpay
     * @return integer amount in cents
     */
    public function getAmountAuthorized()
    {
        return $this->amountAuthorized;
    }
    
    /**
     * Get the amount captured through Quickpay
     * @return integer amount in cents
     */
    public function getAmountCaptured()
    {
        return $this->amountCaptured;
    }
    
    /**
     * Get the amount refunded through Quickpay
     * @return integer amount in cents
     */
    public function getAmountRefunded()
    {
        return $this->amountRefunded;
    }
    
    /**
     * get the time the Payment was authorized at
     * @return DateTime
     */
    public function getAuthorizedAt(): DateTime
    {
        return $this->authorizedAt;
    }
    
    /**
     * Get the List of linked operations
     * @return PaymentOperationCollection operations for the payment
     */
    public function getOperations()
    {
        return $this->operations;
    }
    
    /**
     * Get the payment link
     * @return string
     */
    public function getLink()
    {
        return $this->link;
    }
}
