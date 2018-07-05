<?php

class ControllerExtensionPaymentCoinbase extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/payment/coinbase');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/geo_zone');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('coinbase_commerce', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['action'] = $this->url->link('extension/payment/coinbase', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->error['api_key'])) {
            $data['error_api_key'] = $this->error['api_key'];
        } else {
            $data['error_api_key'] = '';
        }

        if (isset($this->error['api_secret'])) {
            $data['error_api_secret'] = $this->error['api_secret'];
        } else {
            $data['error_api_secret'] = '';
        }

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/coinbase', 'user_token=' . $this->session->data['user_token'], true)
        );

        $fields = array(
            'coinbase_commerce_extension_status',
            'coinbase_commerce_api_key',
            'coinbase_commerce_api_secret',
            'coinbase_commerce_api_test_mode',
            'coinbase_commerce_order_status_id',
            'coinbase_commerce_completed_status_id',
            'coinbase_commerce_resolved_status_id',
            'coinbase_commerce_unresolved_status_id',
            'coinbase_commerce_expired_status_id',
            'coinbase_commerce_geo_zone_id',
            'coinbase_commerce_total',
            'coinbase_commerce_sort_order'
        );

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/coinbase', $data));
    }

    protected function validate()
    {
        $this->load->model('setting/setting');

        if (!$this->user->hasPermission('modify', 'extension/payment/coinbase')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post['coinbase_commerce_api_key'] ||
            empty($this->request->post['coinbase_commerce_api_key'])) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }

        if (!$this->request->post['coinbase_commerce_api_secret'] ||
            empty($this->request->post['coinbase_commerce_api_secret'])) {
            $this->error['api_secret'] = $this->language->get('error_api_secret');
        }

        return !$this->error;
    }

    public function install()
    {
        $this->load->model('extension/payment/coinbase');

        $this->model_extension_payment_coinbase->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/payment/coinbase');

        $this->model_extension_payment_coinbase->uninstall();
    }
}
