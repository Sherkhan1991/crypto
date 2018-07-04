<?php

class ModelExtensionPaymentCoinbase extends Model
{
    public function install()
    {
        $this->db->query("
	    	CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "coinbase_order` (
	        	`coinbase_order_id` INT(11) NOT NULL AUTO_INCREMENT,
	        	`order_id` INT(11) NOT NULL,
	        	`coinbase_payment_id` VARCHAR(120),
	        	`coinbase_secret_key` VARCHAR(100) NOT NULL,
	        	PRIMARY KEY (`coinbase_order_id`)
	     	) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
    	");

        $this->load->model('setting/setting');

        $settings = array();
        $settings['payment_coinbase_api_test_mode'] = 0;
        $settings['payment_coinbase_order_status_id'] = 1;
        $settings['payment_coinbase_completed_status_id'] = 2;
        $settings['payment_coinbase_confirmed_status_id'] = 2;
        $settings['payment_coinbase_underpayment_status_id'] = 8;
        $settings['payment_coinbase_invalid_status_id'] = 8;
        $settings['payment_coinbase_expired_status_id'] = 14;
        $settings['payment_coinbase_canceled_status_id'] = 7;

        $this->model_setting_setting->editSetting('payment_coinbase', $settings);
    }

    public function uninstall()
    {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "coinbase_order`;");
    }
}
