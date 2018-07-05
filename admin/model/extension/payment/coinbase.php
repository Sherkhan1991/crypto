<?php

class ModelExtensionPaymentCoinbase extends Model
{
    public function install()
    {
        $this->db->query("
	    	CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "coinbase_order` (
	        	`id` INT(11) NOT NULL AUTO_INCREMENT,
	        	`store_order_id` INT(11) NOT NULL,
	        	`store_total_amount` FLOAT NOT NULL,	        	
	        	`coinbase_charge_code` VARCHAR(50) NOT NULL,
	        	`coinbase_transaction_id` VARCHAR(100),
	        	`coinbase_status` TEXT NOT NULL,
	        	`coinbase_coins_expected` FLOAT NOT NULL,	        	
	        	`coinbase_coins_received` FLOAT,
	        	`coinbase_received_currency` TEXT NOT NULL,
	        	PRIMARY KEY (`id`)
	     	) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
    	");

        $this->load->model('setting/setting');

        $settings = array();
        $settings['coinbase_api_test_mode'] = 0;
        $settings['coinbase_order_status_id'] = 1;
        $settings['coinbase_completed_status_id'] = 2;
        $settings['coinbase_resolved_status_id'] = 5;
        $settings['coinbase_unresolved_status_id'] = 8;
        $settings['coinbase_expired_status_id'] = 14;
        $settings['coinbase_total'] = 30;
        $settings['coinbase_sort_order'] = 0;

        $this->model_setting_setting->editSetting('payment_coinbase', $settings);
    }

    public function uninstall()
    {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "coinbase_order`;");
    }
}
