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
        //$secret_key = md5(uniqid(rand(), true));

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
            "redirect_url" => $this->url->link('extension/payment/coinbase/redirect', true)
        ]);

        //Receive Curl Response
        $result = $this->getCurlResponse($data);

        if($result) {

            //Fetch Expected Price
        $this->model_extension_payment_coinbase->addOrder(array(
            'store_order_id' => $order_info['order_id'],
            'store_total_amount' => $order_info['total'],
            'coinbase_commerce_charge_code' => $result['data']['code'],
            //'coinbase_commerce_transaction_id' => $result['payments']['transaction_id'],
            //'coinbase_commerce_status' => $result['timeline']['status']
            //'coinbase_commerce_coins_expected' => $result['data']['pricing']['amount'], //Need to add logic after pricing
            //'coinbase_commerce_coins_received' => $result['payments']['value']['local']['amount'],
            //'coinbase_commerce_received_currency' => $result['payments']['value']['local']['currency']
        ));
        $this->model_checkout_order->addOrderHistory($order_info['order_id'], $this->config->get('payment_coinbase_order_status_id'));
            //var_dump($result);
            //exit();
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
        //Read Input
        $input = file_get_contents('php://input');

        if (!$this->authenticate($input)) {
            $this->log->write("Authentication Failed");
            return null;
        }
        //$this->log->write("Authentication");

        //Retrieve Order Details
        $jsonInput = json_decode($input);
        //$this->log->write("jsonInput " . $input);

        $data['incrementId'] = $jsonInput->event->data->metadata->store_increment_id;
        $data['chargeCode'] = $jsonInput->event->data->code;
        $data['type'] = $jsonInput->event->type;
        $data['timeline'] = end($jsonInput->event->data->timeline);
        $data['coinbaseStatus'] = end($jsonInput->event->data->timeline)->status;
        $data['coinbaseContext'] = isset(end($jsonInput->event->data->timeline)->context) ? end($jsonInput->event->data->timeline)->context : "" ;
        $data['coinbasePayment'] = reset($jsonInput->event->data->payments);
        $data['eventDataNode'] = $jsonInput->event->data;
        //$this->log->write('Data '. implode(" ", $data));
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');

        $order_id = $data['incrementId'];
        //print_r($order_id);
        //exit();

        $order_info = $this->model_checkout_order->getOrder($order_id);
        $coinbase_order = $this->model_extension_payment_coinbase->getOrder($order_id);
        //var_dump($coinbase_order);
        //exit();

        if ($order_info && $coinbase_order) {
            //Replace with authentication
            $this->log->write("Order Validated");
            $status = $data['coinbaseStatus']; // COMPLETED etc
            $event = $data['type']; //Charge: Created/ etc
            $order_status = '';
            $status_message = '';
            if ($status == 'NEW' && $event == 'charge:created') {
                $order_status = 'coinbase_created_status_id';  //Pending
                $data['store_order_id'] = $data['incrementId'];
                $data['fields']['coinbase_commerce_status'] = $data['coinbaseStatus'];
                $this->model_extension_payment_coinbase->updateOrder($data);
                $status_message .= 'Status UPdated';
            } elseif ($status == 'COMPLETED' && $event == 'charge:confirmed') {
                $order_status = 'coinbase_completed_status_id';  //Processing
            } elseif ($status == 'RESOLVED') {
                $order_status = 'coinbase_resolved_status_id'; //Complete
            } elseif ($status == 'UNRESOLVED') {
                $order_status = 'coinbase_unresolved_status_id'; //Denied
                $status_message = $status_message . $data['coinbaseContext'];
            } elseif ($event == 'charge:failed' && $status == 'EXPIRED') {
                $order_status = 'coinbase_expired_status_id'; //Expired
                $status_message .= 'Status' . $data['coinbaseContext'];
            }

            $this->log->write('Coinbase Commerce: Order Status ' . $order_status);
            $this->log->write('Coinbase Commerce: Status Message ' . $status_message);

            if ($order_status) {
                $this->model_checkout_order->addOrderHistory(
                    $order_id,
                    $this->config->get($order_status),
                    $status_message
                );
                $this->log->write('Coinbase Commerce: payment status updated');
            } else {
                $this->log->write('Coinbase Commerce: Unknown payment status');
            }
        }

        $this->response->addHeader('HTTP/1.1 200 OK');
    }

    public function getCurlResponse($data)
    {
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

    public function authenticate($payload)
    {
        $key = $this->config->get('payment_coinbase_api_secret');
        $headerSignature = $this->request->server['HTTP_X_CC_WEBHOOK_SIGNATURE'];
        $computedSignature = hash_hmac('sha256', $payload, $key);
        return $headerSignature === $computedSignature;
    }

    public function testCallback() {
        //Dummy Response Data
        $order = 36;
        $charge = 'WWHA2C6Y';
        $new = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-25T07:20:56Z","data":{"code":"' . $charge . '","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.00000270","currency":"BTC"},"ethereum":{"amount":"0.000034000","currency":"ETH"},"litecoin":{"amount":"0.00016785","currency":"LTC"},"bitcoincash":{"amount":"0.00001993","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","store_increment_id":"' . $order .'"},"payments":[],"timeline":[{"time":"2018-05-25T07:20:55Z","status":"NEW"}],"addresses":{"bitcoin":"14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR","ethereum":"0x1054e2f85cb4150e257dc1bad075faa8791064b3","litecoin":"LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN","bitcoincash":"qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"},"created_at":"2018-05-25T07:20:55Z","expires_at":"2018-05-25T07:35:55Z","hosted_url":"https://commerce.coinbase.com/charges/ZWJGYHBL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"},"id":"9e6d3666-389b-4b64-93ec-0928ba710a4f","type":"charge:created"},"id":"c3ad5df4-d054-4bfd-b3a8-023579527c79","scheduled_for":"2018-05-25T07:20:56Z"}';
        $underPaid = '{   "attempt_number": 1,   "event": {     "api_version": "2018-03-22",     "created_at": "2018-05-25T07:20:56Z",     "data": {       "code": "'. $charge  .'",       "name": "Your Store",       "pricing": {         "local": {           "amount": "131.20",           "currency": "USD"         },         "bitcoin": {           "amount": "0.00000270",           "currency": "BTC"         },         "ethereum": {           "amount": "0.000034000",           "currency": "ETH"         },         "litecoin": {           "amount": "0.00016785",           "currency": "LTC"         },         "bitcoincash": {           "amount": "0.00001993",           "currency": "BCH"         }       },       "metadata": {         "id": "0",         "customer_name": "Mr.tester Amin",         "customer_email": "arslanaziz@appsgenii.eu",         "'. $order  .'": "19"       },       "payments": [                ],       "timeline": [         {           "time": "2018-05-25T07:20:55Z",           "status": "UNRESOLVED",           "context": "UNDERPAID"         }       ],       "addresses": {         "bitcoin": "14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR",         "ethereum": "0x1054e2f85cb4150e257dc1bad075faa8791064b3",         "litecoin": "LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN",         "bitcoincash": "qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"       },       "created_at": "2018-05-25T07:20:55Z",       "expires_at": "2018-05-25T07:35:55Z",       "hosted_url": "https://commerce.coinbase.com/charges/ZWJGYHBL",       "description": "Purchased through Coinbase Commerce",       "pricing_type": "fixed_price",       "redirect_url": "http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"     },     "id": "9e6d3666-389b-4b64-93ec-0928ba710a4f",     "type": "charge:failed"   },   "id": "c3ad5df4-d054-4bfd-b3a8-023579527c79",   "scheduled_for": "2018-05-25T07:20:56Z" }';
        $resolved = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-25T07:20:56Z","data":{"code":"'. $charge  .'","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.00000270","currency":"BTC"},"ethereum":{"amount":"0.000034000","currency":"ETH"},"litecoin":{"amount":"0.00016785","currency":"LTC"},"bitcoincash":{"amount":"0.00001993","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","'. $order  .'":"21"},"payments":[],"timeline":[{"time":"2018-05-25T07:20:55Z","status":"RESOLVED"}],"addresses":{"bitcoin":"14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR","ethereum":"0x1054e2f85cb4150e257dc1bad075faa8791064b3","litecoin":"LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN","bitcoincash":"qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"},"created_at":"2018-05-25T07:20:55Z","expires_at":"2018-05-25T07:35:55Z","hosted_url":"https://commerce.coinbase.com/charges/ZWJGYHBL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"},"id":"9e6d3666-389b-4b64-93ec-0928ba710a4f","type":"charge:confirmed"},"id":"c3ad5df4-d054-4bfd-b3a8-023579527c79","scheduled_for":"2018-05-25T07:20:56Z"}';
        $expired = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-25T07:20:56Z","data":{"code":"'. $charge  .'","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.00000270","currency":"BTC"},"ethereum":{"amount":"0.000034000","currency":"ETH"},"litecoin":{"amount":"0.00016785","currency":"LTC"},"bitcoincash":{"amount":"0.00001993","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","'. $order  .'":"21"},"payments":[],"timeline":[{"time":"2018-05-25T07:20:55Z","status":"EXPIRED"}],"addresses":{"bitcoin":"14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR","ethereum":"0x1054e2f85cb4150e257dc1bad075faa8791064b3","litecoin":"LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN","bitcoincash":"qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"},"created_at":"2018-05-25T07:20:55Z","expires_at":"2018-05-25T07:35:55Z","hosted_url":"https://commerce.coinbase.com/charges/ZWJGYHBL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"},"id":"9e6d3666-389b-4b64-93ec-0928ba710a4f","type":"charge:failed"},"id":"c3ad5df4-d054-4bfd-b3a8-023579527c79","scheduled_for":"2018-05-25T07:20:56Z"}';
        $completed = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-25T07:20:56Z","data":{"code":"'. $charge  .'","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.00000270","currency":"BTC"},"ethereum":{"amount":"0.000034000","currency":"ETH"},"litecoin":{"amount":"0.00016785","currency":"LTC"},"bitcoincash":{"amount":"0.00001993","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","'. $order  .'":"21"},"payments":[],"timeline":[{"time":"2018-05-25T07:20:55Z","status":"COMPLETED"}],"addresses":{"bitcoin":"14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR","ethereum":"0x1054e2f85cb4150e257dc1bad075faa8791064b3","litecoin":"LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN","bitcoincash":"qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"},"created_at":"2018-05-25T07:20:55Z","expires_at":"2018-05-25T07:35:55Z","hosted_url":"https://commerce.coinbase.com/charges/ZWJGYHBL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"},"id":"9e6d3666-389b-4b64-93ec-0928ba710a4f","type":"charge:confirmed"},"id":"c3ad5df4-d054-4bfd-b3a8-023579527c79","scheduled_for":"2018-05-25T07:20:56Z"}';

        $input = $new;

        //Retrieve Order Details
        $jsonInput = json_decode($input);

        $this->log->write($jsonInput);

        $signature = '';

        if (!$this->authenticate($jsonInput, $signature)) {
            $this->log->write("Authentication Failed");
            return null;
        }
        $this->log->write("Authenticated");

        $data['incrementId'] = $jsonInput->event->data->metadata->store_increment_id;
        $data['chargeCode'] = $jsonInput->event->data->code;
        $data['type'] = $jsonInput->event->type;
        $data['timeline'] = end($jsonInput->event->data->timeline);
        $data['coinbaseStatus'] = end($jsonInput->event->data->timeline)->status;
        $data['coinbasePayment'] = reset($jsonInput->event->data->payments);
        $data['eventDataNode'] = $jsonInput->event->data;

        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');

        $order_id = $data['incrementId'];
        //print_r($order_id);
        //exit();

        $order_info = $this->model_checkout_order->getOrder($order_id);
        $coinbase_order = $this->model_extension_payment_coinbase->getOrder($order_id);
        //var_dump($coinbase_order);
        //exit();

        if ($order_info && $coinbase_order) {
            //Replace with authentication

            $status = $data['coinbaseStatus']; // COMPLETED etc
            $event = $data['type']; //Charge: Created/ etc
            $order_status = NULL;
            if ($status == 'NEW' && $event == 'charge:created') {
                $order_status = 'coinbase_created_status_id';  //Pending
                $data['store_order_id'] = $data['incrementId'];
                $data['fields']['coinbase_commerce_status'] = $data['coinbaseStatus'];
            } elseif ($status == 'COMPLETED' && $event == 'charge:confirmed') {
                $order_status = 'coinbase_completed_status_id';  //Processing
            } elseif ($status == 'RESOLVED') {
                $order_status = 'coinbase_resolved_status_id'; //Complete
            } elseif ($status == 'UNRESOLVED') {
                $order_status = 'coinbase_unresolved_status_id'; //Denied
                $context = $data['timeline']->context;
            } elseif ($event == 'charge:failed' && $status == 'EXPIRED') {
                $order_status = 'coinbase_expired_status_id'; //Expired
                $context = $data['timeline']->context;
            }

            $status_message = 'Coinbase: Payment ' . $data['coinbaseStatus'] . isset($context) ? $context : ''  ;
            $this->log->write('Coinbase Commerce: Order Status ' . $order_status);
            $this->log->write('Coinbase Commerce: Status Message ' . $status_message);

            if ($order_status) {
                $this->model_checkout_order->addOrderHistory(
                    $order_id,
                    $this->config->get($order_status),
                    $status_message
                );
                $this->log->write('Coinbase Commerce: payment status updated');
            } else {
                $this->log->write('Coinbase Commerce: Unknown payment status');
            }
        }
    }
/*
    public function test()
    {
        $this->load->model('extension/payment/coinbase');
        $data['store_order_id'] = 27;
        $data['fields']['coinbase_commerce_status'] = 'PENDING';
        $this->model_extension_payment_coinbase->updateOrder($data);
    }
*/
}
