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
            "redirect_url" => $this->url->link('extension/payment/coinbase/redirect&orderId=' . $order_info["order_id"], true)
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

    public function redirect()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');

        $order_info = $this->model_extension_payment_coinbase->getOrder($this->session->data['order_id']);
        if(isset($_GET['orderId']) == $this->session->data['order_id']) {
            print_r('Matched');
        }
        exit();
        if (empty($order_info) || strcmp($order_info['coinbase_secret_key'], $this->request->get['tbc_secret_key']) !== 0) {
            $this->response->redirect($this->url->link('common/home', '', true));
        } else {
            $this->response->redirect($this->url->link('checkout/success', '', true));
            $this->response->redirect($this->url->link('checkout/cart', ''));
        }
    }

    public function callback()
    {
        //Read Input
        $input = file_get_contents('php://input');
        $this->log->write("Raw Post " . $input);
        $this->log->write("Signature " . $this->request->server['HTTP_X_CC_WEBHOOK_SIGNATURE']);
        if (!$this->authenticate($input)) {
            $this->log->write("Authentication Failed");
            return null;
        }
        //$this->log->write("Authentication Successfull");

        //Retrieve Order Details
        $jsonInput = json_decode($input);

        $data['orderId'] = $jsonInput->event->data->metadata->store_increment_id;
        $data['chargeCode'] = $jsonInput->event->data->code;
        $data['type'] = $jsonInput->event->type;
        $data['timeline'] = end($jsonInput->event->data->timeline);
        $data['coinbaseStatus'] = end($jsonInput->event->data->timeline)->status;
        $data['coinbaseContext'] = isset(end($jsonInput->event->data->timeline)->context) ? end($jsonInput->event->data->timeline)->context : "" ;
        $data['coinbasePayment'] = reset($jsonInput->event->data->payments);
        $data['eventDataNode'] = $jsonInput->event->data;

        //Update Order Status and DB Record
        $this->updateRecord($data);

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

    public function authenticate($payload, $signature = NULL)
    {
        //print_r($signature);
        $key = $this->config->get('payment_coinbase_api_secret');
        $headerSignature = isset($signature) ? $signature : $this->request->server['HTTP_X_CC_WEBHOOK_SIGNATURE'];
        $computedSignature = hash_hmac('sha256', $payload, $key);
        return $headerSignature === $computedSignature;
    }

    public function updateRecord($data){
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');

        $order_info = $this->model_checkout_order->getOrder($data['orderId']);
        $coinbase_order = $this->model_extension_payment_coinbase->getOrder($data['orderId']);

        try {
            if ($order_info && $coinbase_order) {
                $this->log->write("Order Validated");

                $order_status = '';
                $status_message = 'Coinbase Commerce Status ' . $data['coinbaseStatus'] . ' Type ' . $data['type'];

                if ($data['coinbaseStatus'] == 'NEW' && $data['type'] == 'charge:created') {
                    $order_status = 'coinbase_created_status_id';  //Pending
                } elseif ($data['coinbaseStatus'] == 'COMPLETED' && $data['type'] == 'charge:confirmed') {
                    $order_status = 'payment_coinbase_completed_status_id';  //Processing
                    $recordToUpdate['fields']['coinbase_commerce_status'] = $data['coinbaseStatus'];
                } elseif ($data['coinbaseStatus'] == 'RESOLVED') {
                    $order_status = 'payment_coinbase_resolved_status_id'; //Complete
                } elseif ($data['coinbaseStatus'] == 'UNRESOLVED') {
                    $order_status = 'payment_coinbase_unresolved_status_id'; //Denied
                    $status_message .= ' Context ' . $data['coinbaseContext'];
                } elseif ($data['type'] == 'charge:failed' && $data['coinbaseStatus'] == 'EXPIRED') {
                    $order_status = 'payment_coinbase_expired_status_id'; //Expired
                    $status_message .= ' Context ' . $data['coinbaseContext'];
                }

                $this->log->write('Coinbase Commerce: Order Status ' . $order_status);
                $this->log->write('Coinbase Commerce: ' . $status_message);

                if ($order_status) {

                    //Update DB Record
                    $recordToUpdate['store_order_id'] = $data['orderId'];
                    $recordToUpdate['fields']['coinbase_commerce_status'] = $data['coinbaseStatus'];

                    //Update Coinbase info when Payment Done
                    if($data['type'] != 'charge:created' && $data['coinbaseStatus'] != 'EXPIRED') {
                        $coinsExpected = $data['coinbasePayment']->network;
                        $recordToUpdate['fields']['coinbase_commerce_transaction_id'] = $t = $data['coinbasePayment']->transaction_id;
                        $recordToUpdate['fields']['coinbase_commerce_coins_expected'] = $e = $data['eventDataNode']->pricing->$coinsExpected->amount;
                        $recordToUpdate['fields']['coinbase_commerce_coins_received'] = $p = $data['coinbasePayment']->value->crypto->amount;
                        $recordToUpdate['fields']['coinbase_commerce_received_currency'] = $c = $coinsExpected . "(" . $data['coinbasePayment']->value->crypto->currency . ")";
                        $this->log->write('Updated Coinbase Payment info in DB');
                        $status_message .= '<br/><b>Transaction Details </b><br/>';
                        $status_message .= 'Transaction Id: <b>' . $t . '</b><br/>';
                        $status_message .= 'Expected Amount: <b>' . $e . '</b><br/>';
                        $status_message .= 'Amount Paid: <b>' . $p . ' ' . $c . '</b><br/>';
                    }
                    $this->model_extension_payment_coinbase->updateOrder($recordToUpdate);

                    //Update History Status
                    $this->model_checkout_order->addOrderHistory(
                        $data['orderId'],
                        $this->config->get($order_status),
                        $status_message
                    );
                    $this->log->write('Coinbase Commerce: payment status updated');
                } else {
                    $this->log->write('Coinbase Commerce: Unknown payment status');
                }
            }
        }catch(Exception $e) {
            echo 'Exception: ' .$e->getMessage();
        }
    }
/*
    public function updateOrderHistory()
    {
        //Testing if Order HIstory commented added
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');
        $orderId = 39;
        $order_status = 'payment_coinbase_unresolved_status_id';
        $status_message = 'Status Updated';
        var_dump($this->config);
        print_r('Status Config Value' );
        var_dump($this->config->get($order_status));
        $this->model_checkout_order->addOrderHistory(
            $orderId,
            $this->config->get($order_status),
            $status_message
        );
    }
*/
    public function testCallback() {
        //Dummy Response Data
        $order = 66;
        $charge = 'LW3VFDGG';
        $rawPost = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-07-09T09:14:56Z","data":{"code":"LW3VFDGG","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.01956126","currency":"BTC"},"ethereum":{"amount":"0.272315000","currency":"ETH"},"litecoin":{"amount":"1.60912492","currency":"LTC"},"bitcoincash":{"amount":"0.17565586","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","store_increment_id":"66"},"payments":[{"block":{"hash":"000000000000000000ecc881ef4a2b878dc5aa32a057a740628243ce2db45d31","height":531719,"confirmations":0,"confirmations_required":1},"value":{"local":{"amount":"0.02","currency":"USD"},"crypto":{"amount":"0.00001993","currency":"BCH"}},"status":"CONFIRMED","network":"bitcoincash","transaction_id":"df76f01aec7d3547f67792b6b1b3f281df58c4fbd22537867f66770857571e28"}],"timeline":[{"time":"2018-07-09T09:14:55Z","status":"COMPLETED"}],"addresses":{"bitcoin":"1CZjuiC63osQDUKsPGQmZRoHubakp2ZeHr","ethereum":"0xa319011207eaf4726f73a2989a773bc22fe36ecd","litecoin":"LMQCT41ffDh9Ymt8sEcxZ6DMyq6GjqnvVy","bitcoincash":"qr7qj5t07wslwt66gjulrn00daq2lwhamcghx8wrp2"},"created_at":"2018-07-09T09:14:55Z","expires_at":"2018-07-09T09:29:55Z","hosted_url":"https://commerce.coinbase.com/charges/LW3VFDGG","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://opencart.stagingbuilds.com/index.php?route=extension/payment/coinbase/redirect&orderId=$order_info[\"order_id\"]&amp;1"},"id":"0a63937f-d292-4bb3-bc40-c16df1c2bc3a","type":"charge:confirmed"},"id":"9db32709-7a8b-45b0-a092-099626ea454a","scheduled_for":"2018-07-09T09:14:56Z"}';
        $new = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-25T07:20:56Z","data":{"code":"' . $charge . '","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.00000270","currency":"BTC"},"ethereum":{"amount":"0.000034000","currency":"ETH"},"litecoin":{"amount":"0.00016785","currency":"LTC"},"bitcoincash":{"amount":"0.00001993","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","store_increment_id":"' . $order .'"},"payments":[],"timeline":[{"time":"2018-05-25T07:20:55Z","status":"NEW"}],"addresses":{"bitcoin":"14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR","ethereum":"0x1054e2f85cb4150e257dc1bad075faa8791064b3","litecoin":"LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN","bitcoincash":"qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"},"created_at":"2018-05-25T07:20:55Z","expires_at":"2018-05-25T07:35:55Z","hosted_url":"https://commerce.coinbase.com/charges/ZWJGYHBL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"},"id":"9e6d3666-389b-4b64-93ec-0928ba710a4f","type":"charge:created"},"id":"c3ad5df4-d054-4bfd-b3a8-023579527c79","scheduled_for":"2018-05-25T07:20:56Z"}';
        $underPaid = '{   "attempt_number": 1,   "event": {     "api_version": "2018-03-22",     "created_at": "2018-05-25T07:20:56Z",     "data": {       "code": "'. $charge  .'",       "name": "Your Store",       "pricing": {         "local": {           "amount": "131.20",           "currency": "USD"         },         "bitcoin": {           "amount": "0.00000270",           "currency": "BTC"         },         "ethereum": {           "amount": "0.000034000",           "currency": "ETH"         },         "litecoin": {           "amount": "0.00016785",           "currency": "LTC"         },         "bitcoincash": {           "amount": "0.00001993",           "currency": "BCH"         }       },       "metadata": {         "id": "0",         "customer_name": "Mr.tester Amin",         "customer_email": "arslanaziz@appsgenii.eu",         "store_increment_id":"'. $order  .'"       },       "payments": [                ],       "timeline": [         {           "time": "2018-05-25T07:20:55Z",           "status": "UNRESOLVED",           "context": "UNDERPAID"         }       ],       "addresses": {         "bitcoin": "14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR",         "ethereum": "0x1054e2f85cb4150e257dc1bad075faa8791064b3",         "litecoin": "LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN",         "bitcoincash": "qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"       },       "created_at": "2018-05-25T07:20:55Z",       "expires_at": "2018-05-25T07:35:55Z",       "hosted_url": "https://commerce.coinbase.com/charges/ZWJGYHBL",       "description": "Purchased through Coinbase Commerce",       "pricing_type": "fixed_price",       "redirect_url": "http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"     },     "id": "9e6d3666-389b-4b64-93ec-0928ba710a4f",     "type": "charge:failed"   },   "id": "c3ad5df4-d054-4bfd-b3a8-023579527c79",   "scheduled_for": "2018-05-25T07:20:56Z" }';
        $resolved = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-25T07:20:56Z","data":{"code":"'. $charge  .'","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.00000270","currency":"BTC"},"ethereum":{"amount":"0.000034000","currency":"ETH"},"litecoin":{"amount":"0.00016785","currency":"LTC"},"bitcoincash":{"amount":"0.00001993","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","store_increment_id":"'. $order  .'"},"payments":[],"timeline":[{"time":"2018-05-25T07:20:55Z","status":"RESOLVED"}],"addresses":{"bitcoin":"14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR","ethereum":"0x1054e2f85cb4150e257dc1bad075faa8791064b3","litecoin":"LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN","bitcoincash":"qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"},"created_at":"2018-05-25T07:20:55Z","expires_at":"2018-05-25T07:35:55Z","hosted_url":"https://commerce.coinbase.com/charges/ZWJGYHBL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"},"id":"9e6d3666-389b-4b64-93ec-0928ba710a4f","type":"charge:confirmed"},"id":"c3ad5df4-d054-4bfd-b3a8-023579527c79","scheduled_for":"2018-05-25T07:20:56Z"}';
        $expired = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-25T07:20:56Z","data":{"code":"'. $charge  .'","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.00000270","currency":"BTC"},"ethereum":{"amount":"0.000034000","currency":"ETH"},"litecoin":{"amount":"0.00016785","currency":"LTC"},"bitcoincash":{"amount":"0.00001993","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","store_increment_id":"'. $order  .'"},"payments":[],"timeline":[{"time":"2018-05-25T07:20:55Z","status":"EXPIRED"}],"addresses":{"bitcoin":"14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR","ethereum":"0x1054e2f85cb4150e257dc1bad075faa8791064b3","litecoin":"LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN","bitcoincash":"qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"},"created_at":"2018-05-25T07:20:55Z","expires_at":"2018-05-25T07:35:55Z","hosted_url":"https://commerce.coinbase.com/charges/ZWJGYHBL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"},"id":"9e6d3666-389b-4b64-93ec-0928ba710a4f","type":"charge:failed"},"id":"c3ad5df4-d054-4bfd-b3a8-023579527c79","scheduled_for":"2018-05-25T07:20:56Z"}';
        $completed = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-25T07:20:56Z","data":{"code":"'. $charge  .'","name":"Your Store","pricing":{"local":{"amount":"131.20","currency":"USD"},"bitcoin":{"amount":"0.00000270","currency":"BTC"},"ethereum":{"amount":"0.000034000","currency":"ETH"},"litecoin":{"amount":"0.00016785","currency":"LTC"},"bitcoincash":{"amount":"0.00001993","currency":"BCH"}},"metadata":{"id":"0","customer_name":"Mr.tester Amin","customer_email":"arslanaziz@appsgenii.eu","store_increment_id":"'. $order  .'"},"payments":[{"block":{"hash":"000000000000000000ecc881ef4a2b878dc5aa32a057a740628243ce2db45d31","height":531719,"confirmations":0,"confirmations_required":1},"value":{"local":{"amount":"0.02","currency":"USD"},"crypto":{"amount":"0.00001993","currency":"BCH"}},"status":"CONFIRMED","network":"bitcoincash","transaction_id":"df76f01aec7d3547f67792b6b1b3f281df58c4fbd22537867f66770857571e28"}],"timeline":[{"time":"2018-05-25T07:20:55Z","status":"COMPLETED"}],"addresses":{"bitcoin":"14Me4JXRQ7fK7XFLLL1LMwUQ5KXczfPXKR","ethereum":"0x1054e2f85cb4150e257dc1bad075faa8791064b3","litecoin":"LUVzEJSzYDwsMu7ddyayKarpzxmqEKr5SN","bitcoincash":"qqefm48ttp8hl2rn02ldkt9yttusssfd2q5cjaq343"},"created_at":"2018-05-25T07:20:55Z","expires_at":"2018-05-25T07:35:55Z","hosted_url":"https://commerce.coinbase.com/charges/ZWJGYHBL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price","redirect_url":"http://coinbase.stagingbuilds.com/coinbasecommerce/webhook/redirect/"},"id":"9e6d3666-389b-4b64-93ec-0928ba710a4f","type":"charge:confirmed"},"id":"c3ad5df4-d054-4bfd-b3a8-023579527c79","scheduled_for":"2018-05-25T07:20:56Z"}';

        $input = $completed;

        //Retrieve Order Details
        $jsonInput = json_decode($input);

        print_r('Json Decode Input ' . $input . '<br/>');
        /*
        //Authentication Test - To Test it require 2 inputs from Callback Function - 1.Signature[HTTP_X_CC_WEBHOOK_SIGNATURE] 2.Raw Post
        //Will work only when original raw post will pass
        $signature = '8d60e4b68050080982d792a31e9d128c0be66ac4333e68a307a79ca4e3fcba51';

        if (!$this->authenticate($input, $signature)) {
            print_r("Authentication Failed");
            return null;
        }
        print_r("Authenticated");
        */
        $data['orderId'] = $jsonInput->event->data->metadata->store_increment_id;
        $data['chargeCode'] = $jsonInput->event->data->code;
        $data['type'] = $jsonInput->event->type;
        $data['timeline'] = end($jsonInput->event->data->timeline);
        $data['coinbaseStatus'] = end($jsonInput->event->data->timeline)->status;
        $data['coinbaseContext'] = isset(end($jsonInput->event->data->timeline)->context) ? end($jsonInput->event->data->timeline)->context : "" ;
        $data['coinbasePayment'] = reset($jsonInput->event->data->payments);
        $data['eventDataNode'] = $jsonInput->event->data;
        print_r('Data List For Record update <br />');
        var_dump($data);
        //Included For Testing Purpose Only - Remove it
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/coinbase');

        $order_info = $this->model_checkout_order->getOrder($data['orderId']);
        print_r('Opencart Order Info <br/>');
        var_dump($order_info);
        $coinbase_order = $this->model_extension_payment_coinbase->getOrder($data['orderId']);
        print_r('Database Order Info <br/>');
        var_dump($coinbase_order);
        //Included - End

        $this->updateRecord($data);
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
