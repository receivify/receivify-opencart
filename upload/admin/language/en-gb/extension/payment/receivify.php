<?php
// Receivify for OpenCart — admin language (en-gb)

// Heading
$_['heading_title']                   = 'Receivify (Bitcoin &amp; crypto)';

// Tabs
$_['tab_general']                     = 'General';
$_['tab_status']                      = 'Order statuses';

// Text
$_['text_extension']                  = 'Extensions';
$_['text_success']                    = 'Success: you have modified the Receivify payment module.';
$_['text_edit']                       = 'Edit Receivify';
$_['text_receivify']                  = '<a href="https://receivify.io" target="_blank">Receivify</a>';
$_['text_all_zones']                  = 'All zones';
$_['text_webhook_help']               = 'Paste this URL into Receivify as a webhook endpoint. It is also sent as the per-invoice <code>callback_url</code>, so it works even before you register an account-level endpoint.';
$_['text_rotation_help']              = 'When you rotate your webhook secret, move the old value into "Previous webhook secret" and put the new one in "Webhook secret". Receivify signs with the CURRENT secret, but the previous one stays acceptable until its grace window closes (24 hours by default), so a receiver has to accept either. Clear the previous field once the window has passed.';

// Entry
$_['entry_api_url']                   = 'Receivify API URL';
$_['entry_api_key']                   = 'API key';
$_['entry_webhook_secret']            = 'Webhook secret';
$_['entry_webhook_secret_previous']   = 'Previous webhook secret';
$_['entry_asset']                     = 'Settlement coin';
$_['entry_callback_url']              = 'Webhook / callback URL';
$_['entry_total']                     = 'Minimum order total';
$_['entry_order_status']              = 'Paid status';
$_['entry_pending_status']            = 'Awaiting payment status';
$_['entry_confirming_status']         = 'Confirming status';
$_['entry_expired_status']            = 'Expired / cancelled status';
$_['entry_failed_status']             = 'Failed status';
$_['entry_geo_zone']                  = 'Geo zone';
$_['entry_status']                    = 'Status';
$_['entry_sort_order']                = 'Sort order';

// Help
$_['help_api_url']                    = 'Base URL of your Receivify instance, e.g. https://receivify.io — no trailing path.';
$_['help_api_key']                    = 'Your rcv_live_… key, from the Receivify dashboard → API keys. Server-side only; it is never sent to the browser.';
$_['help_webhook_secret']             = 'Your whsec_… secret. Every callback is verified against it before the order is touched.';
$_['help_webhook_secret_previous']    = 'Only while a rotation is inside its grace window. Leave empty otherwise.';
$_['help_asset']                      = 'The coin customers pay in. It must be enabled AND settleable in your Receivify admin → Assets.';
$_['help_total']                      = 'The checkout total this method becomes available on. 0 = always.';
$_['help_callback_url']               = 'Read-only. Copy this into Receivify.';

// Error
$_['error_permission']                = 'Warning: you do not have permission to modify the Receivify payment module.';
$_['error_api_url']                   = 'A valid API URL is required.';
$_['error_api_key']                   = 'An API key is required.';
