<?php

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

        //Pricing
        $pricing["amount"] = $order_info['total'];
        $pricing["currency"] = $order_info['currency_code'];

        //Metadata attached with Charge
        $metaData["id"] = $order_info['customer_id'];
        $metaData["customer_name"] = $order_info['firstname'] . " " . $order_info['lastname'];
        $metaData["customer_email"] = $order_info['email'];
        $metaData["store_increment_id"] = $order_info['order_id'];

        //Json Data Curl Request
        $data = json_encode([
            "name" => $order_info['store_name'],
            "description" => "Purchased through Coinbase Commerce",
            "local_price" => $pricing,
            "pricing_type" => "fixed_price",
            "metadata" => $metaData,
            "redirect_url" => $this->url->link('extension/payment/coinbase/redirect', array('tbc_secret_key' => $secret_key), true),
        ]);

        //Send Curl Request
        $result = $this->getCurlResponse($data);
        //var_dump($result);
       //exit();
        if($result) {
        $this->model_extension_payment_coinbase->addOrder(array(
            'order_id' => $order_info['order_id'],
            'secret_key' => $secret_key,
            'coinbase_payment_id' => $result['data']['code']
        ));
        $this->model_checkout_order->addOrderHistory($order_info['order_id'], $this->config->get('payment_coinbase_order_status_id'));
        $this->response->redirect($result['data']['hosted_url']);
        } else {
            $this->log->write("Order #" . $order_info['order_id'] . " is not valid. Please check Coinbase Commerce API request logs.");
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

    public function redirect()
    {
        $this->response->redirect($this->url->link('checkout/cart', ''));
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

    public function getJsonHeaders()
    {
        $apiKey = $this->config->get('payment_coinbase_api_key');
        $headers["Content-Type"] = "application/json";
        $headers["X-CC-Api-Key"] = $apiKey;
        $headers["X-CC-Version"] = "2018-07-04";

        return $headers;
    }

    public function getCurlResponse($data)
    {
        $headers   = array();
        $headers[] = $this->getJsonHeaders();

        $curl = curl_init('https://api.commerce.coinbase.com/charges/');
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'X-CC-Api-Key: ' . $this->config->get('payment_coinbase_api_key'),
                'X-CC-Version: 2018-03-22')
        );
        $response = json_decode(curl_exec($curl), TRUE);

        //$http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        //if ($http_status === 200)
            //return $response;

        return $response;
    }
}
