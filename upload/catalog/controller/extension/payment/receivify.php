<?php
/**
 * Receivify for OpenCart — catalog controller.
 *
 *   index()    renders the "Confirm order" button on the checkout payment step
 *   confirm()  creates the Receivify invoice and hands back the hosted checkout URL
 *   callback() receives the SIGNED webhook and advances the order
 *
 * All HTTP and HMAC work is done by the shared PHP SDK in
 * system/library/receivify/Receivify.php — this file only translates between
 * Receivify events and OpenCart order history.
 */
if (!class_exists('Receivify')) {
    require_once DIR_SYSTEM . 'library/receivify/Receivify.php';
}

class ControllerExtensionPaymentReceivify extends Controller
{
    /** The payment step template: a confirm button that calls confirm(). */
    public function index()
    {
        $this->load->language('extension/payment/receivify');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_redirect'] = $this->language->get('text_redirect');
        $data['confirm_url'] = $this->url->link('extension/payment/receivify/confirm', '', true);

        return $this->load->view('extension/payment/receivify', $data);
    }

    /**
     * Create the invoice and return `{redirect: <checkout_url>}`.
     *
     * The order id is the invoice `reference`, which is how the webhook finds
     * the order again. `Idempotency-Key` plus the server-side `reference`
     * de-duplication mean a customer who double-clicks cannot mint two invoices.
     */
    public function confirm()
    {
        $this->load->language('extension/payment/receivify');
        $json = array();

        if (!isset($this->session->data['order_id'])) {
            $json['error'] = $this->language->get('error_order');
        } elseif (!isset($this->session->data['payment_method']['code'])
            || $this->session->data['payment_method']['code'] != 'receivify') {
            $json['error'] = $this->language->get('error_method');
        }

        if (!$json) {
            $this->load->model('checkout/order');
            $order_id = (int) $this->session->data['order_id'];
            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                $json['error'] = $this->language->get('error_order');
            } else {
                try {
                    // The order total in the STORE currency, converted to the
                    // order's currency exactly as OpenCart displays it, and sent
                    // as a string — money never round-trips through a float.
                    $total = $this->currency->format(
                        $order_info['total'],
                        $order_info['currency_code'],
                        $order_info['currency_value'],
                        false
                    );

                    $reference = Receivify::orderReference('oc', $this->storeToken(), (string) $order_id);
                    $invoice = $this->receivify()->createInvoice(array(
                        'amount_fiat' => (string) $total,
                        'currency' => $order_info['currency_code'],
                        'asset' => $this->config->get('payment_receivify_asset') ?: 'btc',
                        'reference' => $reference,
                        'callback_url' => $this->callbackUrl(),
                        'metadata' => array('order_id' => $order_id, 'source' => 'opencart'),
                    ), array('idempotency_key' => $reference));

                    if (empty($invoice['checkout_url'])) {
                        $json['error'] = $this->language->get('error_checkout_url');
                    } else {
                        $status_id = (int) $this->config->get('payment_receivify_pending_status_id');
                        $this->addHistory($order_id, $status_id ?: 1, $this->language->get('text_awaiting'), false);
                        $json['redirect'] = $invoice['checkout_url'];
                    }
                } catch (ReceivifyApiError $e) {
                    $this->log->write('Receivify createInvoice failed [' . $e->getErrorCode() . ']: ' . $e->getDetail());
                    $json['error'] = $this->language->get('error_api');
                } catch (Exception $e) {
                    $this->log->write('Receivify createInvoice failed: ' . $e->getMessage());
                    $json['error'] = $this->language->get('error_api');
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * The signed webhook: index.php?route=extension/payment/receivify/callback
     *
     * Verify FIRST, trust nothing before that. Then de-duplicate on
     * (event, sequence) — delivery is at-least-once and a manual replay re-sends
     * the identical bytes.
     */
    public function callback()
    {
        // The RAW body. Never json_encode(json_decode($body)) — re-encoding
        // changes key order and escaping and the HMAC will not match.
        $raw = file_get_contents('php://input');
        $header = isset($this->request->server['HTTP_X_RECEIVIFY_SIGNATURE'])
            ? $this->request->server['HTTP_X_RECEIVIFY_SIGNATURE']
            : '';

        $event = $this->receivify()->readWebhook($raw, $header);
        if ($event === null) {
            $this->log->write('Receivify webhook rejected: signature verification failed.');
            $this->respond(401, 'bad signature');
            return;
        }

        $name = Receivify::eventName($event);
        $data = Receivify::webhookData($event);
        $dedupe_key = Receivify::dedupeKey($event);       // "invoice.paid:42"
        // 0 when the reference belongs to another shop (see storeToken()).
        $parsed = isset($data['reference'])
            ? Receivify::parseOrderReference((string) $data['reference'], 'oc', $this->storeToken())
            : null;
        $order_id = ($parsed !== null && ctype_digit($parsed)) ? (int) $parsed : 0;

        // Claim the event. A duplicate delivery loses the race on the primary
        // key and is acknowledged without touching the order.
        if (!$this->claimEvent($dedupe_key, $order_id)) {
            $this->respond(200, 'duplicate');
            return;
        }

        if ($order_id > 0) {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($order_id);

            if ($order_info) {
                $paid_status = (int) $this->config->get('payment_receivify_order_status_id');
                $current = (int) $order_info['order_status_id'];

                switch (Receivify::classifyEvent($name)) {
                    case 'paid':
                        if ($current !== $paid_status) {
                            $this->addHistory($order_id, $paid_status ?: 5, sprintf(
                                $this->lang('text_paid'),
                                $name,
                                isset($data['id']) ? $data['id'] : ''
                            ), true);
                        }
                        break;

                    case 'confirming':
                        $this->addHistory(
                            $order_id,
                            (int) $this->config->get('payment_receivify_confirming_status_id') ?: $current,
                            $this->lang('text_confirming'),
                            false
                        );
                        break;

                    case 'underpaid':
                    case 'underpaid_expired':
                        $this->addHistory($order_id, $current, sprintf(
                            $this->lang('text_underpaid'),
                            isset($data['amount_received']) ? $data['amount_received'] : '?',
                            isset($data['amount_expected']) ? $data['amount_expected'] : '?'
                        ), false);
                        break;

                    case 'expired':
                    case 'cancelled':
                        $this->addHistory(
                            $order_id,
                            (int) $this->config->get('payment_receivify_expired_status_id') ?: 7,
                            $this->lang('text_expired'),
                            false
                        );
                        break;

                    case 'failed':
                        $this->addHistory(
                            $order_id,
                            (int) $this->config->get('payment_receivify_failed_status_id') ?: 10,
                            $this->lang('text_failed'),
                            false
                        );
                        break;

                    // 'test'   — POST /api/v1/webhooks/test. Acknowledge, change nothing.
                    // 'ignore' — forward.* and any event name added after this
                    //            plugin was written. Receivers MUST ignore them.
                }
            }
        }

        // Any 2xx marks the delivery delivered; anything else is retried with
        // exponential backoff for up to 24 hours.
        $this->respond(200, 'ok');
    }

    /* ----------------------------------------------------------- internals */

    /** @return Receivify */
    private function receivify()
    {
        return new Receivify(
            (string) $this->config->get('payment_receivify_api_url'),
            (string) $this->config->get('payment_receivify_api_key'),
            array(
                // Current secret first, previous second. A rotation keeps the
                // previous secret acceptable for its grace window, so during a
                // rotation two secrets are legitimately valid.
                'webhook_secrets' => array_filter(array(
                    (string) $this->config->get('payment_receivify_webhook_secret'),
                    (string) $this->config->get('payment_receivify_webhook_secret_previous'),
                )),
                'timeout' => 30,
                'user_agent' => 'opencart',
            )
        );
    }

    /**
     * A random token for THIS store install, created once.
     *
     * The invoice `reference` is `oc-<token>-<order_id>`. A bare order id
     * collides when two shops share one Receivify account, or a reinstalled
     * shop restarts its order numbers: Receivify would replay the other order's
     * invoice or refuse with 409.
     *
     * Stored under its own setting code, `receivify_internal`, because saving
     * the extension settings deletes and rewrites every `payment_receivify` key,
     * and uninstalling deletes them. This token must survive both, or pending
     * orders' webhooks would look like another shop's.
     */
    private function storeToken()
    {
        $token = (string) $this->config->get('receivify_internal_store_token');
        if (preg_match('/^[a-f0-9]{10}$/', $token)) {
            return $token;
        }
        $row = $this->db->query(
            "SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' "
            . "AND `code` = 'receivify_internal' AND `key` = 'receivify_internal_store_token' LIMIT 1"
        );
        if ($row->num_rows && preg_match('/^[a-f0-9]{10}$/', $row->row['value'])) {
            $token = $row->row['value'];
        } else {
            $token = Receivify::newStoreToken();
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'receivify_internal', "
                . "`key` = 'receivify_internal_store_token', `value` = '" . $this->db->escape($token) . "', `serialized` = '0'"
            );
        }
        $this->config->set('receivify_internal_store_token', $token);
        return $token;
    }

    private function callbackUrl()
    {
        return $this->url->link('extension/payment/receivify/callback', '', true);
    }

    /**
     * Insert the event key, or report that we have already seen it. The PRIMARY
     * KEY makes this atomic even if two deliveries arrive at once.
     *
     * @return bool true when this process owns the event
     */
    private function claimEvent($dedupe_key, $order_id)
    {
        $key = $this->db->escape($dedupe_key);
        try {
            $existing = $this->db->query(
                "SELECT `event_key` FROM `" . DB_PREFIX . "receivify_event` WHERE `event_key` = '" . $key . "'"
            );
            if ($existing->num_rows) {
                return false;
            }
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "receivify_event` SET "
                . "`event_key` = '" . $key . "', "
                . "`order_id` = '" . (int) $order_id . "', "
                . "`date_added` = NOW()"
            );
            return true;
        } catch (Exception $e) {
            // Either a concurrent delivery just won the primary-key race — in
            // which case this one IS a duplicate — or the table is missing and
            // the extension needs re-installing. Either way, refuse to act
            // rather than risk acting twice, and say so in the log.
            $this->log->write('Receivify webhook de-duplication failed: ' . $e->getMessage());
            return false;
        }
    }

    /** OpenCart 3.x renamed addOrderHistory() to addHistory(). Support both. */
    private function addHistory($order_id, $status_id, $comment, $notify)
    {
        $this->load->model('checkout/order');
        if (method_exists($this->model_checkout_order, 'addHistory')) {
            $this->model_checkout_order->addHistory($order_id, $status_id, $comment, $notify);
        } else {
            $this->model_checkout_order->addOrderHistory($order_id, $status_id, $comment, $notify);
        }
    }

    /** Language strings, loaded lazily — the webhook has no checkout context. */
    private function lang($key)
    {
        static $loaded = false;
        if (!$loaded) {
            $this->load->language('extension/payment/receivify');
            $loaded = true;
        }
        return $this->language->get($key);
    }

    private function respond($status, $body)
    {
        $protocol = isset($this->request->server['SERVER_PROTOCOL'])
            ? $this->request->server['SERVER_PROTOCOL']
            : 'HTTP/1.1';
        $reason = ($status === 200) ? 'OK' : 'Unauthorized';

        $this->response->addHeader($protocol . ' ' . (int) $status . ' ' . $reason);
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->setOutput($body);
    }
}
