<?php

require_once(DIR_SYSTEM . 'library/coinbase/coinbaseclient/init.php');
require_once(DIR_SYSTEM . 'library/coinbase/coinbase_version.php');

class ControllerExtensionPaymentCoinbase extends Controller
{

    public function index()
    {
        $this->load->language('extension/payment/coinbase');
        $this->load->model('checkout/order');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['action'] = $this->url->link('extension/payment/coinbase/checkout', '', true);

        return $this->load->view('extension/payment/coinbase', $data);
    }

    public function checkout()
    {

        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $secret_key = md5(uniqid(rand(), true));

        $total = $order_info['total'] * $this->currency->getvalue($order_info['currency_code']);
        $items = $this->model_extension_payment_coinbase->getOrderItems($this->cart->getProducts(), $order_info);
        $shipping = isset($this->session->data['shipping_method']['cost']) ? $this->session->data['shipping_method']['cost'] : 0;
        $discount = 0.00;

        $order_totals = $this->model_checkout_order->getOrderTotals($order_info['order_id']);
        if ($order_totals && count($order_totals) > 0) {
            foreach ($order_totals as $key => $order_total) {
                if ($order_total['code'] == 'coupon') {
                    $discount += $order_total['value'] < 0 ? abs($order_total['value']) : $order_total['value'];
                }
            }
        }

        $tbcClient = new \CoinBaseClient\CoinBaseClient(
            array(
                'project_id' => $this->config->get('payment_coinbase_project_id'),
                'api_key' => $this->config->get('payment_coinbase_api_key'),
                'api_secret' => $this->config->get('payment_coinbase_api_secret'),
                'env' => $this->config->get('payment_coinbase_api_test_mode') == 1 ? 'test' : 'live',
                'user_agent' =>
                    'CoinBase OpenCart Extension: ' . COINBASE_OPENCART_EXTENSION_VERSION . '/' . 'OpenCart: ' . VERSION
            )
        );

        $payment = $tbcClient->addPayment(
            array(
                'order_id' => $order_info['order_id'],
                'currency' => $order_info['currency_code'],
                'amount' => number_format($total, 6, '.', ''),
                'items_amount' => number_format($this->cart->getSubTotal(), 6, '.', ''),
                'shipping_amount' => number_format($shipping, 6, '.', ''),
                'discount_amount' => number_format($discount, 6, '.', ''),
                'buyer_email' => $order_info['email'] ? $order_info['email'] : '',
                'callback_url' =>
                    html_entity_decode($this->url->link('extension/payment/coinbase/callback', array('tbc_secret_key' => $secret_key), true), ENT_QUOTES, 'UTF-8'),
                'complete_url' =>
                    html_entity_decode($this->url->link('extension/payment/coinbase/complete', array('tbc_secret_key' => $secret_key), true), ENT_QUOTES, 'UTF-8'),
                'cancel_url' => $this->url->link('extension/payment/coinbase/cancel', '', true),
                'items' => $items ? $items : false
            )
        );

        if ($payment && isset($payment['data'])
            && !empty($payment['data'])
            && $payment['data']['payment_url']
            && $payment['data']['payment_status'] == 'awaiting_payment'
        ) {
            $this->model_extension_payment_coinbase->addOrder(array(
                'order_id' => $order_info['order_id'],
                'secret_key' => $secret_key,
                'coinbase_payment_id' => $payment['data']['id']
            ));
            $this->model_checkout_order->addOrderHistory($order_info['order_id'], $this->config->get('payment_coinbase_order_status_id'));
            $this->response->redirect($payment['data']['payment_url']);
        } else {
            $this->log->write("Order #" . $order_info['order_id'] . " is not valid.");
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
    }

    public function cancel()
    {
        $this->response->redirect($this->url->link('checkout/cart', ''));
    }

    public function complete()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');

        $order_info = $this->model_extension_payment_coinbase->getOrder($this->session->data['order_id']);

        if (empty($order_info) || strcmp($order_info['coinbase_secret_key'], $this->request->get['tbc_secret_key']) !== 0) {
            $this->response->redirect($this->url->link('common/home', '', true));
        } else {
            $this->response->redirect($this->url->link('checkout/success', '', true));
        }
    }

    public function callback()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');

        $order_id = isset($this->request->post['order_id']) ? $this->request->post['order_id'] : false;
        if ($order_id) {
            $order_info = $this->model_checkout_order->getOrder($order_id);
            $coinbase_order = $this->model_extension_payment_coinbase->getOrder($order_id);

            if ($order_info && $coinbase_order) {
                if (strcmp($coinbase_order['coinbase_secret_key'], $this->request->get['tbc_secret_key']) === 0) {
                    $tbcClient = new \CoinBaseClient\CoinBaseClient(
                        array(
                            'project_id' => $this->config->get('payment_coinbase_project_id'),
                            'api_key' => $this->config->get('payment_coinbase_api_key'),
                            'api_secret' => $this->config->get('payment_coinbase_api_secret'),
                            'env' => $this->config->get('payment_coinbase_api_test_mode') == 1 ? 'test' : 'live',
                            'user_agent' =>
                                'CoinBase OpenCart Extension: ' . COINBASE_OPENCART_EXTENSION_VERSION . '/' . 'OpenCart: ' . VERSION
                        )
                    );

                    $payment = $tbcClient->getPayment($coinbase_order['coinbase_payment_id']);
                    if (false === isset($payment) || !$payment || empty($payment)) {
                        $this->log->write('CoinBase: Payment data not found. Order ID: ' . $order_id . ', Payment ID: ' . $coinbase_order['coinbase_payment_id']);
                    } else {
                        $payment_status = $payment['data']['payment_status'];
                        $order_status = NULL;
                        $status_message = '';

                        if (((float)$order_info['total'] * $this->currency->getvalue($order_info['currency_code'])) > ((float)$payment['data']['amount'])) {
                            $order_status = 'payment_coinbase_invalid_status_id';
                            $status_message = 'Coinbase: Payment invalid.';
                        } else {
                            switch ($payment_status) {
                                case 'completed':
                                    $order_status = 'payment_coinbase_completed_status_id';
                                    $status_message = 'Coinbase: Payment completed.';
                                    break;
                                case 'confirmed':
                                    $order_status = 'payment_coinbase_confirmed_status_id';
                                    $status_message = 'Coinbase: Payment confirmed. Awaiting network confirmation and payment completed status.';
                                    break;
                                case 'underpayment':
                                    $order_status = 'payment_coinbase_underpayment_status_id';
                                    $status_message = 'Coinbase: Payment has been underpaid.';
                                    break;
                                case 'invalid':
                                    $order_status = 'payment_coinbase_invalid_status_id';
                                    $status_message = 'Coinbase: Payment is invalid for this order.';
                                    break;
                                case 'expired':
                                    $order_status = 'payment_coinbase_expired_status_id';
                                    $status_message = 'Coinbase: Payment has expired.';
                                    break;
                                case 'canceled':
                                    $order_status = 'payment_coinbase_canceled_status_id';
                                    $status_message = 'Coinbase: Payment was canceled.';
                                    break;
                            }
                        }

                        if ($order_status) {
                            $this->model_checkout_order->addOrderHistory(
                                $order_id,
                                $this->config->get($order_status),
                                $status_message
                            );
                        } else {
                            $this->log->write('Coinbase: Unknown payment status');
                        }
                    }
                }
            }
        }

        $this->response->addHeader('HTTP/1.1 200 OK');
    }
}
