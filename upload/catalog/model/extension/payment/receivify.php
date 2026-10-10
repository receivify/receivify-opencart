<?php
/**
 * Receivify for OpenCart — catalog model.
 *
 * Decides whether the method is offered for this address/total. Standard
 * OpenCart geo-zone + minimum-total gating.
 */
class ModelExtensionPaymentReceivify extends Model
{
    public function getMethod($address, $total)
    {
        $this->load->language('extension/payment/receivify');

        $query = $this->db->query(
            "SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '"
            . (int) $this->config->get('payment_receivify_geo_zone_id')
            . "' AND country_id = '" . (int) $address['country_id']
            . "' AND (zone_id = '" . (int) $address['zone_id'] . "' OR zone_id = '0')"
        );

        if ($this->config->get('payment_receivify_total') > 0
            && $this->config->get('payment_receivify_total') > $total) {
            $status = false;
        } elseif (!$this->config->get('payment_receivify_geo_zone_id')) {
            $status = true;
        } elseif ($query->num_rows) {
            $status = true;
        } else {
            $status = false;
        }

        // Without an API key there is nothing to talk to — do not offer a method
        // that is guaranteed to fail at the confirm step.
        if (!$this->config->get('payment_receivify_api_key')
            || !$this->config->get('payment_receivify_api_url')) {
            $status = false;
        }

        $method_data = array();

        if ($status) {
            $method_data = array(
                'code' => 'receivify',
                'title' => $this->language->get('text_title'),
                'terms' => '',
                'sort_order' => $this->config->get('payment_receivify_sort_order'),
            );
        }

        return $method_data;
    }
}
