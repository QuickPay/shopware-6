<?php


declare(strict_types=1);

namespace QuickPay\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1612870835CreatePaymentTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1612870835;
    }
    
    public function update(Connection $connection): void
    {
        $sql =
            <<<SQL
    CREATE TABLE IF NOT EXISTS `quickpay_payment` (
        `id` BINARY(16) NOT NULL,
        `version_id` BINARY(16) NOT NULL,
        `transaction_id` BINARY(16) NOT NULL,
        `transaction_version_id` BINARY(16) NOT NULL,
        `quickpay_id` VARCHAR(50) NOT NULL,
        `quickpay_order_id` VARCHAR(20) NOT NULL,
        `link` VARCHAR(1000) NOT NULL,
        `currency` VARCHAR(20) NOT NULL,
        `status` INT(11) NOT NULL DEFAULT 0,
        `amount` INT(11) NOT NULL,
        `amount_authorized` INT(11) NOT NULL DEFAULT 0,
        `amount_captured` INT(11) NOT NULL DEFAULT 0,
        `amount_refunded` INT(11) NOT NULL DEFAULT 0,
        `authorized_at` DATETIME(3),
        `created_at` DATETIME(3) NOT NULL,
        `updated_at` DATETIME(3),
        PRIMARY KEY (`id`, `version_id`),
        CONSTRAINT `fk.quickpay.payment.transaction_id` FOREIGN KEY (`transaction_id`, `transaction_version_id`)
        REFERENCES `order_transaction` (`id`, `version_id`) ON DELETE CASCADE
    )
    ENGINE = InnoDB
    DEFAULT CHARSET = utf8mb4
    COLLATE = utf8mb4_unicode_ci;
SQL;
        $connection->executeUpdate($sql);
    }
    
    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
