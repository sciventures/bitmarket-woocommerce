<?php
/**
 * Plugin Name: bitmarket-woocommerce
 * Plugin URI: https://github.com/sciventures/bitmarket-woocommerce
 * Description: Accept Bitcoin on your WooCommerce-powered website with Bitmarket.
 * Version: 1.0
 * Author: Bitmarket.ph Inc.
 * Author URI: https://bitmarket.ph
 * License: MIT
 * Text Domain: bitmarket-woocommerce
 */

/*  Copyright 2014 Bitmarket Inc.

MIT License

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

	function bitmarket_woocommerce_init() {

		if (!class_exists('WC_Payment_Gateway'))
			return;

		/**
		 * Bitmarket Payment Gateway
		 *
		 * Provides a Bitmarket Payment Gateway.
		 *
		 * @class       WC_Gateway_Bitmarket
		 * @extends     WC_Payment_Gateway
		 * @version     1.0
		 * @author      Bitmarket.ph Inc.
		 */
		class WC_Gateway_Bitmarket extends WC_Payment_Gateway {
			var $notify_url;

			public function __construct() {
				$this->id   = 'bitmarket';
				$this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/bitmarket.png';

				$this->has_fields        = false;
				$this->order_button_text = __('Proceed to Bitmarket', 'bitmarket-woocommerce');
				$this->notify_url        = $this->construct_notify_url();

				$this->init_form_fields();
				$this->init_settings();

				$this->title       = $this->get_option('title');
				$this->description = $this->get_option('description');

				// Actions
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
					$this,
					'process_admin_options'
				));
				add_action('woocommerce_receipt_bitmarket', array(
					$this,
					'receipt_page'
				));

				// Payment listener/API hook
				add_action('woocommerce_api_wc_gateway_bitmarket', array(
					$this,
					'check_bitmarket_callback'
				));
			}

			public function admin_options() {
				echo '<h3>' . __('Bitmarket Payment Gateway', 'bitmarket-woocommerce') . '</h3>';
				$bitmarket_account_email = get_option("bitmarket_account_email");
				$bitmarket_error_message = get_option("bitmarket_error_message");
				if ($bitmarket_account_email != false) {
					echo '<p>' . __('Successfully connected Bitmarket account', 'bitmarket-woocommerce') . " '$bitmarket_account_email'" . '</p>';
				} elseif ($bitmarket_error_message != false) {
					echo '<p>' . __('Could not validate API Key:', 'bitmarket-woocommerce') . " $bitmarket_error_message" . '</p>';
				}
				echo '<table class="form-table">';
				$this->generate_settings_html();
				echo '</table>';
			}

			function process_admin_options() {
				if (!parent::process_admin_options())
					return false;

				require_once(plugin_dir_path(__FILE__) . 'bitmarket-php' . DIRECTORY_SEPARATOR . 'Bitmarket.php');
			}

			function construct_notify_url() {
				$callback_secret = get_option("bitmarket_callback_secret");
				if ($callback_secret == false) {
					$callback_secret = sha1(openssl_random_pseudo_bytes(20));
					update_option("bitmarket_callback_secret", $callback_secret);
				}
				$notify_url = WC()->api_request_url('WC_Gateway_Bitmarket');
				$notify_url = add_query_arg('callback_secret', $callback_secret, $notify_url);
				return $notify_url;
			}

			function init_form_fields() {
				$this->form_fields = array(
					'enabled' => array(
						'title' => __('Enable Bitmarket plugin', 'bitmarket-woocommerce'),
						'type' => 'checkbox',
						'label' => __('Show bitcoin as an option to customers during checkout?', 'bitmarket-woocommerce'),
						'default' => 'yes'
					),
					'token' => array(
						'title' => __('Token', 'bitmarket-woocommerce'),
						'type' => 'text',
						'description' => __('')
					)
				);
			}

			function process_payment($order_id) {

				require_once(plugin_dir_path(__FILE__) . 'bitmarket-php' . DIRECTORY_SEPARATOR . 'Bitmarket.php');
				global $woocommerce;

				$order = new WC_Order($order_id);

				$success_url = add_query_arg('return_from_bitmarket', true, $this->get_return_url($order));

				// Bitmarket mangles the order param so we have to put it somewhere else and restore it on init
				$cancel_url = add_query_arg('return_from_bitmarket', true, $order->get_cancel_order_url());
				$cancel_url = add_query_arg('cancelled', true, $cancel_url);
				$cancel_url = add_query_arg('order_key', $order->order_key, $cancel_url);

				$params = array(
					'name'               => 'Order #' . $order_id,
					'price_string'       => $order->get_total(),
					'price_currency_iso' => get_woocommerce_currency(),
					'callback_url'       => $this->notify_url,
					'custom'             => $order_id,
					'success_url'        => $success_url,
					'cancel_url'         => $cancel_url,
				);

				$api_key    = $this->get_option('apiKey');
				$api_secret = $this->get_option('apiSecret');

				if ($api_key == '' || $api_secret == '') {
					$woocommerce->add_error(__('Sorry, but there was an error processing your order. Please try again or try a different payment method. (plugin not configured)', 'bitmarket-woocommerce'));
					return;
				}

				try {
					$bitmarket = Bitmarket::withApiKey($api_key, $api_secret);
					$code     = $bitmarket->createButtonWithOptions($params)->button->code;
				}
				catch (Exception $e) {
					$order->add_order_note(__('Error while processing bitmarket payment:', 'bitmarket-woocommerce') . ' ' . var_export($e, TRUE));
					$woocommerce->add_error(__('Sorry, but there was an error processing your order. Please try again or try a different payment method.', 'bitmarket-woocommerce'));
					return;
				}

				return array(
					'result'   => 'success',
					'redirect' => "https://bitmarket.com/checkouts/$code"
				);
			}

			function check_bitmarket_callback() {
				$callback_secret = get_option("bitmarket_callback_secret");
				if ($callback_secret != false && $callback_secret == $_REQUEST['callback_secret']) {
					$post_body = json_decode(file_get_contents("php://input"));
					if (isset($post_body->order)) {
						$bitmarket_order = $post_body->order;
						$order_id       = $bitmarket_order->custom;
						$order          = new WC_Order($order_id);
					} else if (isset($post_body->payout)) {
						header('HTTP/1.1 200 OK');
						exit("Bitmarket Payout Callback Ignored");
					} else {
						header("HTTP/1.1 400 Bad Request");
						exit("Unrecognized Bitmarket Callback");
					}
				} else {
					header("HTTP/1.1 401 Not Authorized");
					exit("Spoofed callback");
				}

				// Legitimate order callback from Bitmarket
				header('HTTP/1.1 200 OK');

				// Add Bitmarket metadata to the order
				update_post_meta($order->id, __('Bitmarket Order ID', 'bitmarket-woocommerce'), wc_clean($bitmarket_order->id));
				if (isset($bitmarket_order->customer) && isset($bitmarket_order->customer->email)) {
					update_post_meta($order->id, __('Bitmarket Account of Payer', 'bitmarket-woocommerce'), wc_clean($bitmarket_order->customer->email));
				}

				switch (strtolower($bitmarket_order->status)) {

					case 'completed':

						// Check order not already completed
						if ($order->status == 'completed') {
							exit;
						}

						$order->add_order_note(__('Bitmarket payment completed', 'bitmarket-woocommerce'));
						$order->payment_complete();

						break;
					case 'canceled':

						$order->update_status('failed', __('Bitmarket reports payment cancelled.', 'bitmarket-woocommerce'));
						break;

				}

				exit;
			}
		}

		/**
		 * Add this Gateway to WooCommerce
		 **/
		function woocommerce_add_bitmarket_gateway($methods) {
			$methods[] = 'WC_Gateway_Bitmarket';
			return $methods;
		}

		function woocommerce_handle_bitmarket_return() {
			if (!isset($_GET['return_from_bitmarket']))
				return;

			if (isset($_GET['cancelled'])) {
				$order = new WC_Order($_GET['order']['custom']);
				if ($order->status != 'completed') {
					$order->update_status('failed', __('Customer cancelled bitmarket payment', 'bitmarket-woocommerce'));
				}
			}

			// Bitmarket order param interferes with woocommerce
			unset($_GET['order']);
			unset($_REQUEST['order']);
			if (isset($_GET['order_key'])) {
				$_GET['order'] = $_GET['order_key'];
			}
		}

		add_action('init', 'woocommerce_handle_bitmarket_return');
		add_filter('woocommerce_payment_gateways', 'woocommerce_add_bitmarket_gateway');
	}

	add_action('plugins_loaded', 'bitmarket_woocommerce_init', 0);
}