<?php

/*
* Plugin Name: noBorder.tech payment gateway for Woocommerce
* Author: noBorder.tech
* Description: <a href="https://noborder.tech">noBorder.tech</a> secure payment gateway for Woocommerce.
* Version: 2.2.3
* Author URI: https://noborder.tech
* Author Email: info@noborder.tech
* Text Domain: woo-noborder-gateway
* WC requires at least: 3.0
* WC tested up to: 6.1
*/

if (!defined('ABSPATH')) {
	exit;
}

function wc_gateway_noborder_init(){

    if (class_exists('WC_Payment_Gateway')) {
        
		add_filter('woocommerce_payment_gateways', 'wc_add_noborder_gateway');
		
		//Registers class WC_noBorder as a payment method
        function wc_add_noborder_gateway($methods){
            $methods[] = 'WC_noBorder';
            return $methods;
        }
		
		//start main class
        class WC_noBorder extends WC_Payment_Gateway {

            protected $api_key;
            protected $pay_currency;
            protected $success_message;
            protected $failed_message;
            protected $payment_endpoint;
            protected $verify_endpoint;
            protected $order_status;

            public function __construct() {
                
				$this->id = 'WC_noBorder';
                $this->method_title = __('noBorder.tech', 'woo-noborder-gateway');
                $this->method_description = __('Redirect customers to noBorder.tech to process their payments with crypto currencies.', 'woo-noborder-gateway');
                $this->has_fields = FALSE;
                $this->icon = apply_filters('WC_noBorder_logo', 'https://noborder.tech/file/image/gate/logo-icon.png');

                // Load the form fields.
                $this->init_form_fields();

                // Load the settings.
                $this->init_settings();

                // Get setting values.
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');

                $this->api_key = $this->get_option('api_key');
                $this->pay_currency = $this->get_option('pay_currency');

                $this->order_status = $this->get_option('order_status');

                $this->payment_endpoint = 'https://noborder.tech/action/ws/request_create';
                $this->verify_endpoint = 'https://noborder.tech/action/ws/request_status';

                $this->success_message = $this->get_option('success_message');
                $this->failed_message = $this->get_option('failed_message');

                if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                } else {
                    add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
                }

                add_action('woocommerce_receipt_' . $this->id, array($this, 'noborder_checkout_receipt_page'));
                add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'noborder_checkout_return_handler'));
            }

            //Admin options for the gateway
            public function admin_options() {
                parent::admin_options();
            }

            //Processes and saves the gateway options in the admin page
            public function process_admin_options() {
                parent::process_admin_options();
            }

            //Initiate some form fields for the gateway settings
            public function init_form_fields() {
                // Populates the inherited property $form_fields.
                $this->form_fields = apply_filters('WC_noBorder_Config', array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'woo-noborder-gateway'),
                        'type' => 'checkbox',
                        'label' => 'Enable noBorder.tech gateway',
                        'description' => '',
                        'default' => 'yes',
                    ),
                    'title' => array(
                        'title' => __('Title', 'woo-noborder-gateway'),
                        'type' => 'text',
                        'description' => __('This title will be shown when a customer is going to checkout.', 'woo-noborder-gateway'),
                        'default' => __('noBorder.tech crypto payment gateway', 'woo-noborder-gateway'),
                    ),
                    'description' => array(
                        'title' => __('Description', 'woo-noborder-gateway'),
                        'type' => 'textarea',
                        'description' => __('This description will be shown when a customer is going to checkout.', 'woo-noborder-gateway'),
                        'default' => __('Pay your invoice with crypto currencies such as Bitcoin, Ethereum, Dogecoin,...', 'woo-noborder-gateway'),
                    ),
                    'webservice_config' => array(
                        'title' => __('Webservice Configuration', 'woo-noborder-gateway'),
                        'type' => 'title',
                        'description' => '',
                    ),
                    'api_key' => array(
                        'title' => __('API Key', 'woo-noborder-gateway'),
                        'type' => 'text',
                        'description' => __('You can create an API Key by going to <a href="https://noborder.tech/cryptosite" target="_blank">https://noborder.tech/cryptosite</a>', 'woo-noborder-gateway'),
                        'default' => '',
                    ),
                    'pay_currency' => array(
                        'title' => __('Pay Currencies', 'woo-noborder-gateway'),
                        'type' => 'text',
                        'description' => __('By default, customers can pay through all <a href="https://noborder.tech/cryptosite" target="_blank">active currencies</a> in the gate, but if you want to limit the customer to pay through one or more specific crypto currencies, you can declare the name of the crypto currencies through this variable. If you want to declare more than one currency, separate them with a dash ( - ).', 'woo-noborder-gateway'),
                        'default' => '',
                    ),
                    'order_status' => array(
                        'title' => __('Order status', 'woo-noborder-gateway'),
                        'label' => __('Choose order status', 'woo-noborder-gateway'),
                        'description' => __('Choose order status after payment has been successfully done.', 'woo-noborder-gateway'),
                        'type' => 'select',
                        'options' => $this->valid_order_statuses(),
                        'default' => 'completed',
                    ),
                    'message_confing' => array(
                        'title' => __('Payment message configuration', 'woo-noborder-gateway'),
                        'type' => 'title',
                        'description' => __('Configure the messages which are displayed when a customer is brought back to the site from gateway.', 'woo-noborder-gateway'),
                    ),
                    'success_message' => array(
                        'title' => __('Success message', 'woo-noborder-gateway'),
                        'type' => 'textarea',
                        'description' => __('Enter the message you want to display to the customer after a successful payment. You can also choose these placeholders {request_id}, {order_id} for showing the order id and the tracking id respectively.', 'woo-noborder-gateway'),
                        'default' => __('Your payment has been successfully completed. <br><br> Order id : {order_id} <br> Track id: {request_id}', 'woo-noborder-gateway'),
                    ),
                    'failed_message' => array(
                        'title' => __('Failure message', 'woo-noborder-gateway'),
                        'type' => 'textarea',
                        'description' => __('Enter the message you want to display to the customer after a failure occurred in payment. You can also choose these placeholders {request_id}, {order_id} for showing the order id and the tracking id respectively.', 'woo-noborder-gateway'),
                        'default' => __('Your payment has failed. Please try again or contact the site administrator in case of a problem. <br><br> Order id : {order_id} <br> Track id: {request_id}', 'woo-noborder-gateway'),
                    ),
                ));
            }

            //Process payment and return the result
            //see process_order_payment() in the Woocommerce APIs
            //return array
            public function process_payment($order_id){
                $order = new WC_Order($order_id);
                return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(TRUE));
            }

            //Add noBorder.tech Checkout items to receipt page
            public function noborder_checkout_receipt_page($order_id) {
                
				global $woocommerce;

                $order = new WC_Order($order_id);
                $currency = $order->get_currency();

                $api_key = $this->api_key;
                $pay_currency = $this->pay_currency;

                $customer = $woocommerce->customer;
                $mail = $customer->get_billing_email();

                $amount = $order->get_total();
                $callback = add_query_arg('wc_order', $order_id, WC()->api_request_url('wc_noborder'));

                $data = array(
					'api_key' => $api_key,
					'amount_value' => $amount,
					'amount_currency' => $currency,
                    'pay_currency' => $pay_currency,
                    'order_id' => $order_id,
                    'respond_type' => 'link',
                    'callback' => $callback,
                );

                $result = $this->call_gateway_endpoint($this->payment_endpoint, $data);
                
                if ($result->status != 'success') {
                    $note = '';
					$note .= sprintf(__('Process failed. <br> gateway respond: %s', 'woo-noborder-gateway'), $result->respond);
					$order->add_order_note($note);
                    wc_add_notice($note, 'error');
                    wp_redirect($woocommerce->cart->get_checkout_url());
                    exit;
                }

                //Save ID of this request
                update_post_meta($order_id, 'noborder_request_id', $result->request_id);

                //Set remote status of the request to 1 as it's primary value.
                update_post_meta($order_id, 'noborder_request_status', 1);

                $note = sprintf(__('request id: %s', 'woo-noborder-gateway'), $result->request_id);
                $order->add_order_note($note);
                wp_redirect($result->respond);
                exit;
            }

            //Handles the return from processing the payment
            public function noborder_checkout_return_handler(){
                
				global $woocommerce;
				$order_id = sanitize_text_field($_GET['wc_order']);
                $order = wc_get_order($order_id);

                if (empty($order)) {
                    $this->noborder_display_invalid_order_message();
                    wp_redirect($woocommerce->cart->get_checkout_url());
                    exit;
                }

                if ($order->get_status() == 'completed' || $order->get_status() == 'processing') {
                    $this->noborder_display_success_message($order_id);
                    wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                    exit;
                }

                if (get_post_meta($order_id, 'noborder_request_status', TRUE) >= 100) {
                    $this->noborder_display_success_message($order_id);
                    wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                    exit;
                }

                $api_key = $this->api_key;
                $pay_currency = $this->pay_currency;
                $request_id = get_post_meta($order_id, 'noborder_request_id', TRUE);

                $data = array(
					'api_key' => $api_key,
                    'order_id' => $order_id,
                    'request_id' => $request_id,
                );

                $result = $this->call_gateway_endpoint($this->verify_endpoint, $data);
				
				if ($result->status != 'success') {
                    
					$note = '';
					$note .= sprintf(__('Payment failed. <br> gateway respond: %s', 'woo-noborder-gateway'), $result->respond);
                    $order->add_order_note($note);
                    $order->update_status('failed');
					wc_add_notice($note, 'error');
                    wp_redirect($woocommerce->cart->get_checkout_url());
                    exit;
					
                } else {
					
					$verify_status = !empty($this->valid_order_statuses()[$this->order_status]) ? $this->order_status : 'completed';
					
                    $verify_request_id = $result->request_id;
                    $verify_order_id = $result->order_id;
                    $verify_amount = $result->amount_value;
                    $verify_currency = $result->amount_currency;

                    // Completed
                    $note = sprintf(__('Transaction payment status: %s', 'woo-noborder-gateway'), $verify_status);
                    $note .= '<br/>';
                    $note .= sprintf(__('noBorder.tech request id: %s', 'woo-noborder-gateway'), $verify_request_id);
                    $order->add_order_note($note);

                    // Updates order's meta data after verifying the payment.
                    update_post_meta($order_id, 'noborder_request_status', $verify_status);
                    update_post_meta($order_id, 'noborder_request_id', $verify_request_id);
                    update_post_meta($order_id, 'noborder_order_id', $verify_order_id);
                    update_post_meta($order_id, 'noborder_request_amount', $verify_amount);
                    update_post_meta($order_id, 'noborder_request_currency', $verify_currency);
                    update_post_meta($order_id, 'noborder_payment_date', $verify_date);

                    $currency = strtolower($order->get_currency());
                    $amount = $order->get_total();

                    if (empty($verify_status) || empty($verify_request_id) || number_format($verify_amount, 5) != number_format($amount) || $verify_currency != $currency) {
                        $note = __('Error in request status or inconsistency with payment gateway information', 'woo-noborder-gateway');
						wc_add_notice($note, 'error');
                        $order->add_order_note($note);
                        $order->update_status('failed');
                        $this->noborder_display_failed_message($order_id);
                        wp_redirect($woocommerce->cart->get_checkout_url());
                        exit;
					}

                    $order->payment_complete($verify_request_id);
                    $order->update_status($verify_status);
                    $woocommerce->cart->empty_cart();
                    $this->noborder_display_success_message($order_id);
                    wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                    exit;
                }
            }

            //Shows an invalid order message
            private function noborder_display_invalid_order_message($message=''){
                $notice = __('There is no order number referenced. Please try again or contact the site administrator in case of a problem.', 'woo-noborder-gateway');
                $notice = $notice . "<br>" . $message;
                wc_add_notice($notice, 'error');
            }
			
            //Shows a success message
			//This message is configured at the admin page of the gateway
            private function noborder_display_success_message($order_id){
                $request_id = get_post_meta($order_id, 'noborder_request_id', TRUE);
                $notice = wpautop(wptexturize($this->success_message));
                $notice = str_replace("{request_id}", $request_id, $notice);
                $notice = str_replace("{order_id}", $order_id, $notice);
                wc_add_notice($notice, 'success');
            }

            //Calls the gateway endpoints
           private function call_gateway_endpoint($url, $params){
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				$response = curl_exec($ch);
				curl_close($ch);
				$result = json_decode($response);
                return $result;
            }

            //Shows a failure message for the unsuccessful payments
			//This message is configured at the admin page of the gateway
            private function noborder_display_failed_message($order_id, $message=''){
                $request_id = get_post_meta($order_id, 'noborder_request_id', TRUE);
                $notice = wpautop(wptexturize($this->failed_message));
                $notice = str_replace("{request_id}", $request_id, $notice);
                $notice = str_replace("{order_id}", $order_id, $notice);
                $notice = $notice . "<br>" . $message;
                wc_add_notice($notice, 'error');
            }
			
			//
            private function valid_order_statuses(){
                return ['completed' => 'completed', 'processing' => 'processing'];
            }
        }

    }
}

//Add a function when hook 'plugins_loaded' is fired.
add_action('plugins_loaded', 'wc_gateway_noborder_init');

