<?php


namespace QuickPay\Entity;

use DateTime;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class PaymentOperationEntity extends Entity
{
    use EntityIdTrait;
    /**
     * @var PaymentEntity linked QuickPay payment
     */
    protected $payment;
    /**
     * @var string Payment id from Quickpay
     */
    protected $quickpayPaymentId;
    /**
     * @var string Operation id from Quickpay
     */
    protected $quickpayOperationId;
    /**
     * @var string type of the operations
     */
    protected $type;
    /**
     * @var string status of the operation
     */
    const PAYMENT_OPERATION_APPROVED = '20000';
    const PAYMENT_OPERATION_WAITING_APPROVAL = '20200';
    const PAYMENT_OPERATION_3D_SECURE_REQUIRED = '30100';
    const PAYMENT_OPERATION_REJECTED_BY_ACQUIRER = '40000';
    const PAYMENT_OPERATION_DATA_ERROR = '40001';
    const PAYMENT_OPERATION_AUTHORIZATION_EXPIRED = '40002';
    const PAYMENT_OPERATION_ABORTED = '40003';
    const PAYMENT_OPERATION_GATEWAY_ERROR = '50000';
    const PAYMENT_OPERATION_COMMUNICATIONS_ERROR = '50300';
    /**
     *
     * @var integer the Amount for the operation
     */
    protected $amount;
    /**
     * @var string Raw JSON of the message
     */
    protected $rawJson;
    /**
     * Get the internal id of the payment operation
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }
    
    /**
     * Get the linked payment
     * @return PaymentEntity
     */
    public function getPayment()
    {
        return $this->payment;
    }
    
    /**
     * Get the QuickPay payment operation id
     * @return integer
     */
    public function getOperationId()
    {
        return $this->quickpayOperationId;
    }
    
    /**
     * Get the QuickPay payment id
     * @return integer
     */
    public function getPaymentIdId()
    {
        return $this->quickpayPaymentId;
    }
    
    /**
     * Get the type of the operation
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }
    
    /**
     * Get the status of the operation
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }
    
    /**
     * Checks wether the operation was successfully
     * @return boolean
     */
    public function isSuccessfull()
    {
        return $this->status == self::PAYMENT_OPERATION_APPROVED;
    }
    
    /**
     * Checks wether the operation was finished
     * @return boolean
     */
    public function isFinished()
    {
        return array_search($this->status, [
            self::PAYMENT_OPERATION_WAITING_APPROVAL,
            self::PAYMENT_OPERATION_3D_SECURE_REQUIRED,
        ]) === false;
    }
    
    /**
     * Get the amount fo the operation
     * @return integer
     */
    public function getAmount()
    {
        return $this->amount;
    }
    
    /**
     * get the raw JSON of the message
     * @return string
     */
    public function getRawJson()
    {
        return $this->rawJson;
    }
}
