<?php

$installer = $this;
$connection = $installer->getConnection();

$installer->startSetup();

$connection->addColumn($this->getTable('sales/order_payment'), 'transaction_tag', 'text null');

$installer->endSetup();
