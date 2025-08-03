-- Upgrade script to change status_code from INT to VARCHAR
ALTER TABLE `PREFIX_monei2_payment` 
MODIFY COLUMN `status_code` VARCHAR(10) DEFAULT NULL;