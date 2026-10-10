<?php
/**
 * Receivify for OpenCart — admin controller.
 *
 * Settings live in the standard `payment_receivify_*` setting group. install()
 * creates the webhook de-duplication table; uninstall() drops the settings but
 * deliberately KEEPS the table, so re-installing does not replay old events.
 */
class ControllerExtensionPaymentReceivify extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/payment/receivify');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_receivify', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link(
                'marketplace/extension',
                'user_token=' . $this->session->data['user_token'] . '&type=payment',
                true
            ));
        }

        $data['heading_title'] = $this->language->get('heading_title');
        foreach (array(
            'text_edit', 'text_enabled', 'text_disabled', 'text_all_zones', 'text_yes', 'text_no',
            'text_webhook_help', 'text_rotation_help',
            'entry_api_url', 'entry_api_key', 'entry_webhook_secret', 'entry_webhook_secret_previous',
            'entry_asset', 'entry_total', 'entry_order_status', 'entry_pending_status',
            'entry_confirming_status', 'entry_expired_status', 'entry_failed_status',
            'entry_geo_zone', 'entry_status', 'entry_sort_order', 'entry_callback_url',
            'help_api_url', 'help_api_key', 'help_webhook_secret', 'help_webhook_secret_previous',
            'help_asset', 'help_total', 'help_callback_url',
            'button_save', 'button_cancel', 'tab_general', 'tab_status',
        ) as $key) {
            $data[$key] = $this->language->get($key);
        }

        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['error_api_url'] = isset($this->error['api_url']) ? $this->error['api_url'] : '';
        $data['error_api_key'] = isset($this->error['api_key']) ? $this->error['api_key'] : '';

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true),
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/receivify', 'user_token=' . $this->session->data['user_token'], true),
        );

        $data['action'] = $this->url->link('extension/payment/receivify', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        // The URL the merchant pastes into Receivify (and that we send as
        // `callback_url` on every invoice).
        $data['callback_url'] = HTTPS_CATALOG . 'index.php?route=extension/payment/receivify/callback';

        $fields = array(
            'api_url' => 'https://receivify.io',
            'api_key' => '',
            'webhook_secret' => '',
            'webhook_secret_previous' => '',
            'asset' => 'btc',
            'total' => '0',
            'order_status_id' => 5,      // Complete
            'pending_status_id' => 1,    // Pending
            'confirming_status_id' => 1, // Pending
            'expired_status_id' => 7,    // Cancelled
            'failed_status_id' => 10,    // Failed
            'geo_zone_id' => 0,
            'status' => 0,
            'sort_order' => 1,
        );
        foreach ($fields as $field => $default) {
            $key = 'payment_receivify_' . $field;
            if (isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
            } else {
                $value = $this->config->get($key);
                $data[$key] = ($value === null || $value === '') ? $default : $value;
            }
        }

        // Coins that can settle through Receivify. Keep in step with your
        // Receivify admin → Assets; a coin that is not enabled there is a
        // `bad_request` / `asset_unavailable` at invoice time.
        $data['assets'] = array(
            array('code' => 'btc', 'name' => 'Bitcoin'),
            array('code' => 'ltc', 'name' => 'Litecoin'),
            array('code' => 'doge', 'name' => 'Dogecoin'),
            array('code' => 'bch', 'name' => 'Bitcoin Cash'),
            array('code' => 'xmr', 'name' => 'Monero'),
            array('code' => 'eth', 'name' => 'Ethereum'),
            array('code' => 'usdt', 'name' => 'Tether (USDT)'),
            array('code' => 'usdc', 'name' => 'USD Coin (USDC)'),
        );

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/receivify', $data));
    }

    /**
     * Called by the extension installer. The de-duplication table is what makes
     * a repeat webhook delivery a no-op.
     */
    public function install()
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "receivify_event` ("
            . "`event_key` VARCHAR(191) NOT NULL,"
            . "`order_id` INT(11) NOT NULL DEFAULT '0',"
            . "`date_added` DATETIME NOT NULL,"
            . "PRIMARY KEY (`event_key`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
        );
    }

    /**
     * The event table is intentionally NOT dropped: uninstall/reinstall must not
     * make already-applied webhooks apply a second time.
     */
    public function uninstall()
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('payment_receivify');
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/receivify')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        if (empty($this->request->post['payment_receivify_api_url'])
            || !filter_var($this->request->post['payment_receivify_api_url'], FILTER_VALIDATE_URL)) {
            $this->error['api_url'] = $this->language->get('error_api_url');
        }
        if (empty($this->request->post['payment_receivify_api_key'])) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }
        return !$this->error;
    }
}
