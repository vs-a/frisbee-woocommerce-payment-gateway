<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!defined('FRISBEE_WOOCOMMERCE_VERSION')) {
    define('FRISBEE_WOOCOMMERCE_VERSION', '2.6.10');
}

/**
 * Gateway class
 */
class WC_frisbee extends WC_Payment_Gateway
{
    const ORDER_APPROVED = 'approved';
    const ORDER_DECLINED = 'declined';
    const ORDER_EXPIRED = 'expired';
    const SIGNATURE_SEPARATOR = '|';
    const ORDER_SEPARATOR = ":";
    const DOMAIN = 'frisbee-woocommerce-payment-gateway';
    const LOCALES = [
        'ru_RU' => 'Russia',
        'uk' => 'Ukraine',
    ];

    public $merchant_id;
    public $salt;
    public $test_mode;
    public $liveurl;
    public $refundurl;
    public $calendar;
    public $redirect_page_id;
    public $page_mode;
    public $page_mode_instant;
    public $on_checkout_page;
    public $payment_type;
    public $force_lang;
    public $default_order_status;
    public $expired_order_status;
    public $declined_order_status;
    public $frisbee_unique;
    public $msg = array();

    /**
     * WC_frisbee constructor.
     */
    public function __construct()
    {
        $this->id = 'frisbee';
        $this->method_title = 'FRISBEE';
        $this->method_description = __('Payment gateway', 'frisbee-woocommerce-payment-gateway');
        $this->has_fields = false;
        $this->init_form_fields();
        $this->init_settings();

        $this->liveurl = 'https://api.fondy.eu/api/checkout/redirect/';
        $this->refundurl = 'https://api.fondy.eu/api/reverse/order_id';
        $this->title = __('Buy now pay later with Frisbee', self::DOMAIN);
        $this->test_mode = $this->get_option('test_mode');
        $this->calendar = $this->get_option('calendar');
        $this->redirect_page_id = $this->get_option('redirect_page_id');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->salt = $this->get_option('salt');
        $this->description = $this->get_option('description');
        $this->page_mode = $this->get_option('page_mode');
        $this->page_mode_instant = $this->get_option('page_mode_instant');
        $this->on_checkout_page = $this->get_option('on_checkout_page') ? $this->get_option('on_checkout_page') : false;
        $this->payment_type = $this->get_option('payment_type') ? $this->get_option('payment_type') : false;
        $this->force_lang = $this->get_option('force_lang') ? $this->get_option('force_lang') : false;
        $this->default_order_status = $this->get_option('default_order_status') ? $this->get_option('default_order_status') : false;
        $this->expired_order_status = $this->get_option('expired_order_status') ? $this->get_option('expired_order_status') : false;
        $this->declined_order_status = $this->get_option('declined_order_status') ? $this->get_option('declined_order_status') : false;
        $this->msg['message'] = "";
        $this->msg['class'] = "";

        $this->page_mode = ($this->get_option('payment_type') == 'page_mode') ? 'yes' : 'no';
        $this->on_checkout_page = ($this->get_option('payment_type') == 'on_checkout_page') ? 'yes' : 'no';
        $this->page_mode_instant = ($this->get_option('payment_type') == 'page_mode_instant') ? 'yes' : 'no';

        $this->supports = array(
            'products',
            'refunds',
            'pre-orders',
            'subscriptions',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_suspension'
        );
        if (FRISBEE_WOOCOMMERCE_VERSION !== get_option('frisbee_woocommerce_version')) {
            update_option('frisbee_woocommerce_version', FRISBEE_WOOCOMMERCE_VERSION);
            $settings = maybe_unserialize(get_option('woocommerce_frisbee_settings'));
            if (!isset($settings['payment_type'])) {
                if ($settings['page_mode'] == 'yes') {
                    $settings['payment_type'] = 'page_mode';
                } elseif ($settings['on_checkout_page'] == 'yes') {
                    $settings['payment_type'] = 'on_checkout_page';
                } elseif ($settings['page_mode_instant'] == 'yes') {
                    $settings['payment_type'] = 'page_mode_instant';
                } else {
                    $settings['payment_type'] = 'page_mode';
                }
            }
            update_option('woocommerce_frisbee_settings', $settings);
        }
        if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
            /* 2.0.0 */
            add_action('woocommerce_api_' . strtolower(get_class($this)), array(
                $this,
                'check_frisbee_response'
            ));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
        } else {
            /* 1.6.6 */
            add_action('init', array(&$this, 'check_frisbee_response'));
            add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
        }
        if (isset($this->on_checkout_page) and $this->on_checkout_page == 'yes') {
            add_filter('woocommerce_order_button_html', array(&$this, 'custom_order_button_html'));
        }
        if ($this->test_mode == 'yes') {
            $this->merchant_id = '1396424';
            $this->salt = 'test';
        }
        if ($this->frisbee_unique = get_option('frisbee_unique', true)) {
            add_option('frisbee_unique', time());
        }
        add_action('woocommerce_receipt_frisbee', array(&$this, 'receipt_page'));
        add_action('wp_enqueue_scripts', array($this, 'frisbee_checkout_scripts'));
        if (class_exists('WC_Pre_Orders_Order')) {
            add_action('wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array($this, 'process_pre_order_payments'));
        }
    }

    /**
     * Frisbee Logo
     * @return string
     */
    public function get_icon()
    {
        $icon =
            '<img 
                    style="float: left;" 
                    src="'  . FRISBEE_BASE_PATH . 'assets/img/frisbee.png' . '" 
                    alt="Frisbee" />';
        if ($this->get_option('showlogo') == "yes") {
            return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
        } else {
            return false;
        }
    }

    /**
     * Process checkout func
     */
    function generate_ajax_order_frisbee_info()
    {
        check_ajax_referer('frisbee-submit-nonce', 'nonce_code');
        wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);
        WC()->checkout()->process_checkout();
        wp_die(0);
    }

    /**
     * Custom button order
     * @param $button
     * @return string
     */
    function custom_order_button_html($button)
    {
        $order_button_text = __('Place order', 'frisbee-woocommerce-payment-gateway');
        $js_event = "frisbee_submit_order(event);";
        $button = '<button type="submit" onClick="' . esc_attr($js_event) . '" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr($order_button_text) . '" data-value="' . esc_attr($order_button_text) . '" >' . esc_attr($order_button_text) . '</button>';

        return $button;
    }

    /**
     * Enqueue checkout page scripts
     */
    function frisbee_checkout_scripts()
    {
        if (is_checkout()) {
            wp_enqueue_style('frisbee-checkout', FRISBEE_BASE_PATH . 'assets/css/frisbee_styles.css');
            if (isset($this->on_checkout_page) and $this->on_checkout_page == 'yes') {
                wp_enqueue_script('frisbee_pay_v2', 'https://unpkg.com/ipsp-js-sdk@latest/dist/checkout.min.js', array('jquery'), null, true);
                wp_enqueue_script('frisbee_pay_v2_woocom', FRISBEE_BASE_PATH . 'assets/js/frisbee.js', array('frisbee_pay_v2'), '2.4.9', true);
                wp_enqueue_script('frisbee_pay_v2_card', FRISBEE_BASE_PATH . 'assets/js/payform.min.js', array('frisbee_pay_v2_woocom'), '2.4.9', true);
                if (isset($this->force_lang) and $this->force_lang == 'yes') {
                    $endpoint = new WC_AJAX();
                    $endpoint = $endpoint::get_endpoint('checkout');
                } else {
                    $endpoint = admin_url('admin-ajax.php');
                }
                wp_localize_script('frisbee_pay_v2_woocom', 'frisbee_info',
                    array(
                        'url' => $endpoint,
                        'nonce' => wp_create_nonce('frisbee-submit-nonce')
                    )
                );
            } else {
                wp_enqueue_script('frisbee_pay', '//api.fondy.eu/static_common/v1/checkout/ipsp.js', array(), null, false);
            }
        }
    }

    /**
     * Admin fields
     */
    function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Frisbee Payment Module.', 'frisbee-woocommerce-payment-gateway'),
                'default' => 'no',
                'description' => __('Show in the Payment List as a payment option', 'frisbee-woocommerce-payment-gateway')
            ),
            'test_mode' => array(
                'title' => __('Test mode:', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Test Mode', 'frisbee-woocommerce-payment-gateway'),
                'default' => 'no',
                'description' => __('Place the payment gateway in test mode using test Merchant id.', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'title' => array(
                'title' => __('Title:', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'text',
                'default' => __('Frisbee Online Payments', 'frisbee-woocommerce-payment-gateway'),
                'description' => __('This controls the title which the user sees during checkout.', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description:', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'textarea',
                'default' => __('Pay securely by Credit or Debit Card or Internet Banking through frisbee service.', 'frisbee-woocommerce-payment-gateway'),
                'description' => __('This controls the description which the user sees during checkout.', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'text',
                'description' => __('Given to Merchant by frisbee'),
                'desc_tip' => true
            ),
            'salt' => array(
                'title' => __('Merchant secretkey', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'text',
                'description' => __('Given to Merchant by frisbee', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'showlogo' => array(
                'title' => __('Show MasterCard & Visa logos', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Show the logo in the payment method section for the user', 'frisbee-woocommerce-payment-gateway'),
                'default' => 'yes',
                'description' => __('Tick to show "frisbee" logo', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'payment_type' => array(
                'title' => __('Payment type', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'select',
                'options' => $this->frisbee_get_payment_type(),
                'description' => __('Payment type', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'calendar' => array(
                'title' => __('Show calendar on checkout', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Show recurring payment calendar on checkout', 'frisbee-woocommerce-payment-gateway'),
                'default' => 'no',
                'description' => __('Tick to show show recurring payment calendar on checkout', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'force_lang' => array(
                'title' => __('Enable force detect lang', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable detecting site lang if it used', 'frisbee-woocommerce-payment-gateway'),
                'default' => 'no',
                'description' => __('Enable detecting site lang if it used', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'redirect_page_id' => array(
                'title' => __('Return Page', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'select',
                'options' => $this->frisbee_get_pages(__('Default order page', 'frisbee-woocommerce-payment-gateway')),
                'description' => __('URL of success page', 'frisbee-woocommerce-payment-gateway'),
                'desc_tip' => true
            ),
            'default_order_status' => array(
                'title' => __('Payment completed order status', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'select',
                'options' => $this->getPaymentOrderStatuses(),
                'default' => 'none',
                'description' => __('The default order status after successful payment.', 'frisbee-woocommerce-payment-gateway')
            ),
            'expired_order_status' => array(
                'title' => __('Payment expired order status', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'select',
                'options' => $this->getPaymentOrderStatuses(),
                'default' => 'none',
                'description' => __('Order status when payment was expired.', 'frisbee-woocommerce-payment-gateway')
            ),
            'declined_order_status' => array(
                'title' => __('Payment declined order status', 'frisbee-woocommerce-payment-gateway'),
                'type' => 'select',
                'options' => $this->getPaymentOrderStatuses(),
                'default' => 'none',
                'description' => __('Order status when payment was declined.', 'frisbee-woocommerce-payment-gateway')
            ),
        );
    }

    /*
     * Getting all available woocommerce order statuses
     */
    private function getPaymentOrderStatuses()
    {
        $order_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
        $statuses = array(
            'default' => __('Default status', 'frisbee-woocommerce-payment-gateway')
        );
        if ($order_statuses) {
            foreach ($order_statuses as $k => $v) {
                $statuses[str_replace('wc-', '', $k)] = $v;
            }
        }
        return $statuses;
    }

    /**
     * Admin Panel Options
     **/
    public function admin_options()
    {
        echo '<h3>' . __('Frisbee', 'frisbee-woocommerce-payment-gateway') . '</h3>';
        echo '<p>' . __('Payment gateway', 'frisbee-woocommerce-payment-gateway') . '</p>';
        echo '<table class="form-table">';
        // Generate the HTML For the settings form.
        $this->generate_settings_html();
        echo '</table>';
    }

    /**
     * CCard fields on generating order
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        if (isset($this->on_checkout_page) and $this->on_checkout_page == 'yes') {
            ?>
            <form autocomplete="on" class="frisbee-ccard" id="checkout_frisbee_form">
                <input type="hidden" name="payment_system" value="card">
                <div class="f-container">
                    <div class="input-wrapper">
                        <div class="input-label w-1">
                            <?php esc_html_e('Card Number:', 'frisbee-woocommerce-payment-gateway') ?>
                        </div>
                        <div class="input-field w-1">
                            <input required type="tel" name="card_number" class="input frisbee-credit-cart"
                                   id="frisbee_ccard"
                                   autocomplete="cc-number"
                                   placeholder="<?php esc_html_e('XXXXXXXXXXXXXXXX', 'frisbee-woocommerce-payment-gateway') ?>"/>
                            <div id="f_card_sep"></div>
                        </div>
                    </div>
                    <div class="input-wrapper">
                        <div class="input-label w-3-2">
                            <?php esc_html_e('Expiry Date:', 'frisbee-woocommerce-payment-gateway') ?>
                        </div>
                        <div class="input-label w-4 w-rigth">
                            <?php esc_html_e('CVV2:', 'frisbee-woocommerce-payment-gateway') ?>
                        </div>
                        <div class="input-field w-4">
                            <input required type="tel" name="expiry_month" id="frisbee_expiry_month"
                                   onkeydown="nextInput(this,event)" class="input"
                                   maxlength="2" placeholder="MM"/>
                        </div>
                        <div class="input-field w-4">
                            <input required type="tel" name="expiry_year" id="frisbee_expiry_year"
                                   onkeydown="nextInput(this,event)" class="input"
                                   maxlength="2" placeholder="YY"/>
                        </div>
                        <div class="input-field w-4 w-rigth">
                            <input autocomplete="off" required type="tel" name="cvv2" id="frisbee_cvv2"
                                   onkeydown="nextInput(this,event)"
                                   class="input"
                                   placeholder="<?php esc_html_e('XXX', 'frisbee-woocommerce-payment-gateway') ?>"/>
                        </div>
                    </div>
                    <div style="display: none" class="input-wrapper stack-1">
                        <div class="input-field w-1">
                            <input id="submit_frisbee_checkout_form" type="submit" class="button"
                                   value="<?php esc_html_e('Pay', 'frisbee-woocommerce-payment-gateway') ?>"/>
                        </div>
                    </div>
                    <div class="error-wrapper"></div>
                </div>
            </form>
            <?php
        }
    }

    /**
     * Order page
     * @param $order
     */
    function receipt_page($order)
    {
        echo $this->generate_frisbee_form($order);
    }

    /**
     * filter empty var for signature
     * @param $var
     * @return bool
     */
    protected function frisbee_filter($var)
    {
        return $var !== '' && $var !== null;
    }

    /**
     * Frisbee signature generation
     * @param $data
     * @param $password
     * @param bool $encoded
     * @return string
     */
    protected function getSignature($data, $password, $encoded = true)
    {
        if (isset($data['additional_info'])) {
            $data['additional_info'] = str_replace("\\", "", $data['additional_info']);
        }

        $data = array_filter($data, array($this, 'frisbee_filter'));
        ksort($data);

        $str = $password;
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $str .= self::SIGNATURE_SEPARATOR . str_replace('"', "'", json_encode($v, JSON_HEX_APOS));
            } else {
                $str .= self::SIGNATURE_SEPARATOR.$v;
            }
        }

        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }

    /**
     * @param int $order_id
     * @return string
     */
    protected function getUniqueId($order_id)
    {
        return $order_id . self::ORDER_SEPARATOR . $this->frisbee_unique;
    }

    /**
     * @param $order_id
     * @return string
     */
    private function getProductInfo($order_id)
    {
        return __('Order: ', 'frisbee-woocommerce-payment-gateway') . $order_id;
    }

    /**
     * Generate checkout
     * @param $order_id
     * @return string
     */
    function generate_frisbee_form($order_id)
    {
        $order = new WC_Order($order_id);
        $amount = round( $order->get_total() * 100 );
        $frisbee_args = array(
            'order_id' => $this->getUniqueId($order_id),
            'merchant_id' => $this->merchant_id,
            'order_desc' => $this->getProductInfo($order_id),
            'amount' => $amount,
            'currency' => get_woocommerce_currency(),
            'server_callback_url' => $this->getCallbackUrl() . '&is_callback=true',
            'response_url' => $this->getCallbackUrl(),
            'lang' => $this->getLanguage(),
            'sender_email' => $this->getEmail($order),
            'payment_systems' => 'frisbee',
        );
        if ($this->calendar == 'yes') {
            $frisbee_args['required_rectoken'] = 'Y';
            $frisbee_args['subscription'] = 'Y';
            $frisbee_args['subscription_callback_url'] = $this->getCallbackUrl() . '&is_callback=true';
        }

        if ($this->checkPreOrders($order_id)) {
            $frisbee_args['preauth'] = 'Y';
        }
        if ($this->is_subscription($order_id)) {
            $frisbee_args['required_rectoken'] = 'Y';
            if ((int) $amount === 0) {
                $order->add_order_note( __('Payment free trial verification', 'frisbee-woocommerce-payment-gateway') );
                $frisbee_args['verification'] = 'Y';
                $frisbee_args['amount'] = 1;
            }
        }
        $frisbee_args['signature'] = $this->getSignature($frisbee_args, $this->salt);

        $url = $this->get_redirect($frisbee_args);
        $out = '';
        $url = WC()->session->get('session_token_' . $this->merchant_id . '_' . $order_id);
        if (empty($url)) {
            $url = $this->get_redirect($frisbee_args);
            WC()->session->set('session_token_' . $this->merchant_id . '_' . $order_id, $url);
        }
        if ($this->page_mode == 'no') {
            $out .= '<a class="button alt f-custom-button" href="' . $url . '" id="submit_frisbee_payment_form">' . __('Pay via Frisbee', 'frisbee-woocommerce-payment-gateway') . '</a>';
            if ($this->page_mode_instant == 'yes')
                $out .= "<script type='text/javascript'> document.getElementById('submit_frisbee_payment_form').click(); </script>";
        } else {
            $out = '<div id="checkout"><div id="checkout_wrapper"></div></div>';
            $out .= '
			    <script>
			    function checkoutInit(url) {
			    	$ipsp("checkout").scope(function() {
					this.setCheckoutWrapper("#checkout_wrapper");
					this.addCallback(__DEFAULTCALLBACK__);
					this.action("show", function(data) {
						jQuery("#checkout_loader").remove();
						jQuery("#checkout").show();
					});
					this.action("hide", function(data) {
						jQuery("#checkout").hide();
					});
					this.action("resize", function(data) {
						jQuery("#checkout_wrapper").height(data.height);
						});
					this.loadUrl(url);
				});
				}
				checkoutInit("' . $url . '");
				</script>';
        }

        return $out;
    }

    /**
     * @return string
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Request to api
     * @param $args
     * @return mixed
     */
    protected function get_checkout($args)
    {
        $conf = array(
            'redirection' => 2,
            'user-agent' => 'CMS Woocommerce',
            'headers' => array("Content-type" => "application/json;charset=UTF-8"),
            'body' => json_encode(array('request' => $args))
        );

        try {
            $response = wp_remote_post('https://api.fondy.eu/api/checkout/url/', $conf);

            if (is_wp_error($response))
                throw new Exception($response->get_error_message());

            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code != 200)
                throw new Exception("Frisbee API return code is $response_code");

            $result = json_decode($response['body']);
        } catch (Exception $e) {
            $error = '<p>' . __("There has been a critical error on your website.") . '</p>';
            $error .= '<p>' . $e->getMessage() . '</p>';

            wp_die($error, __('Error'), array('response' => '500'));
        }

        if ($result->response->response_status == 'failure') {
            if ($result->response->error_code == 1013 && !$this->checkPreOrders($args['order_id'], true)) {
                $args['order_id'] = $args['order_id'] . self::ORDER_SEPARATOR . time();
                unset($args['signature']);
                $args['signature'] = $this->getSignature($args, $this->salt);
                return $this->get_checkout($args);
            } else {
                wp_die($result->response->error_message);
            }
        }
        $url = $result->response->checkout_url;
        return $url;
    }

    /**
     * Request to api
     * @param $args
     * @return mixed
     */
    protected function get_redirect($args)
    {
        $conf = array(
            'redirection' => 2,
            'user-agent' => 'CMS Woocommerce',
            'headers' => array("Content-type" => "application/json;charset=UTF-8"),
            'body' => json_encode($args)
        );

        try {
            $response = wp_remote_post($this->liveurl, $conf);

            var_dump($response);exit;
            if (is_wp_error($response))
                throw new Exception($response->get_error_message());

            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code != 200)
                throw new Exception("Frisbee API return code is $response_code");

            $result = json_decode($response['body']);
        } catch (Exception $e) {
            $error = '<p>' . __("There has been a critical error on your website.") . '</p>';
            $error .= '<p>' . $e->getMessage() . '</p>';

            wp_die($error, __('Error'), array('response' => '500'));
        }

        if ($result->response->response_status == 'failure') {
            if ($result->response->error_code == 1013 && !$this->checkPreOrders($args['order_id'], true)) {
                $args['order_id'] = $args['order_id'] . self::ORDER_SEPARATOR . time();
                unset($args['signature']);
                $args['signature'] = $this->getSignature($args, $this->salt);
                return $this->get_redirect($args);
            } else {
                wp_die($result->response->error_message);
            }
        }
        $url = $result->response->checkout_url;
        return $url;
    }

    /**
     * Getting payment token for js ccrad
     * @param $args
     * @return array
     */
    protected function get_token($args)
    {
        $conf = array(
            'redirection' => 2,
            'user-agent' => 'CMS Woocommerce',
            'headers' => array("Content-type" => "application/json;charset=UTF-8"),
            'body' => json_encode(array('request' => $args))
        );

        try {
            $response = wp_remote_post('https://api.fondy.eu/api/checkout/token/', $conf);

            if (is_wp_error($response))
                throw new Exception($response->get_error_message());

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code != 200)
                throw new Exception("Frisbee API return code is $response_code");

            $result = json_decode($response['body']);
        } catch (Exception $e) {
            return array('result' => 'failture', 'messages' => $e->getMessage());
        }

        if ($result->response->response_status == 'failure') {
            return array('result' => 'failture', 'messages' => $result->response->error_message);
        }
        $token = $result->response->token;
        return array('result' => 'success', 'token' => esc_attr($token));
    }

    /**
     * @param int $order_id
     * @param bool $must_be_logged_in
     * @return array|string
     */
    function process_payment($order_id, $must_be_logged_in = false)
    {
        global $woocommerce;
        if ( $must_be_logged_in && get_current_user_id() === 0 ) {
            wc_add_notice( __( 'You must be logged in.', 'frisbee-woocommerce-payment-gateway' ), 'error' );
            return array(
                'result'   => 'fail',
                'redirect' => $woocommerce->cart->get_checkout_url()
            );
        }

        $order = new WC_Order($order_id);

        if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>=')) {
            /* 2.1.0 */
            $checkout_payment_url = $order->get_checkout_payment_url(true);
        } else {
            /* 2.0.0 */
            $checkout_payment_url = get_permalink(get_option('woocommerce_pay_page_id'));
        }
        if (!$this->is_subscription($order_id)) {
            $redirect = add_query_arg('order_pay', $order_id, $checkout_payment_url);
        } else {
            $redirect = add_query_arg(array(
                'order_pay' => $order_id,
                'is_subscription' => true
            ), $checkout_payment_url);
        }
        if ($this->on_checkout_page == 'yes') {
            $amount = round($order->get_total() * 100);
            $frisbee_args = array(
                'order_id' => $this->getUniqueId($order_id),
                'merchant_id' => esc_attr($this->merchant_id),
                'amount' => $amount,
                'order_desc' => $this->getProductInfo($order_id),
                'currency' => esc_attr(get_woocommerce_currency()),
                'server_callback_url' => $this->getCallbackUrl() . '&is_callback=true',
                'response_url' => $this->getCallbackUrl(),
                'lang' => esc_attr($this->getLanguage()),
                'sender_email' => esc_attr($this->getEmail($order))
            );
            if ($this->checkPreOrders($order_id)) {
                $frisbee_args['preauth'] = 'Y';
            }
            if ($this->is_subscription($order_id)) {
                $frisbee_args['required_rectoken'] = 'Y';
                if ((int) $amount === 0) {
                    $order->add_order_note( __('Payment free trial verification', 'frisbee-woocommerce-payment-gateway') );
                    $frisbee_args['verification'] = 'Y';
                    $frisbee_args['amount'] = 1;
                }
            }

            $frisbee_args['signature'] = $this->getSignature($frisbee_args, $this->salt);
            $token = WC()->session->get('session_token_' . md5($this->merchant_id . '_' . $order_id . '_' . $frisbee_args['amount'] . '_' . $frisbee_args['currency']));

            if (empty($token)) {
                $token = $this->get_token($frisbee_args);
                WC()->session->set('session_token_' . md5($this->merchant_id . '_' . $order_id . '_' . $frisbee_args['amount'] . '_' . $frisbee_args['currency']), $token);
            }

            if ($token['result'] === 'success') {
                return $token;
            } else {
                wp_send_json($token);
            }

        } else {
            return array(
                'result' => 'success',
                'redirect' => $redirect
            );
        }
    }


    /**
     * @param int $order_id
     * @param null $amount
     * @param string $reason
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if (!$order = new WC_Order($order_id)) {
            return new WP_Error('fallen', 'Order not found');
        }

        $data = array(
            'request' => array(
                'amount' => round($amount * 100),
                'order_id' => $this->getUniqueId($order->get_id()),
                'currency' => $order->get_currency(),
                'merchant_id' => esc_sql($this->merchant_id),
                'comment' => esc_attr($reason)
            )
        );
        $data['request']['signature'] = $this->getSignature($data['request'], esc_sql($this->salt));
        try {
            $args = array(
                'redirection' => 2,
                'user-agent' => 'CMS Woocommerce',
                'headers' => array("Content-type" => "application/json;charset=UTF-8"),
                'body' => json_encode($data)
            );
            $response = wp_remote_post($this->refundurl, $args);
            $frisbee_response = json_decode($response['body'], TRUE);
            $frisbee_response = $frisbee_response['response'];
            if (isset($frisbee_response['response_status']) and $frisbee_response['response_status'] == 'success') {
                switch ($frisbee_response['reverse_status']) {
                    case 'approved':
                        return true;
                    case 'processing':
                        $order->add_order_note(__('Refund Frisbee status: processing', 'frisbee-woocommerce-payment-gateway'));
                        return true;
                    case 'declined':
                        $order->add_order_note(__('Refund Frisbee status: Declined', 'frisbee-woocommerce-payment-gateway'));
                        return new WP_Error('error', __('Refund Frisbee status: Declined', 'frisbee-woocommerce-payment-gateway'), 'frisbee-woocommerce-payment-gateway');
                    default:
                        $order->add_order_note(__('Refund Frisbee status: Unknown', 'frisbee-woocommerce-payment-gateway'));
                        return new WP_Error('error', __('Refund Frisbee status: Unknown. Try to contact support', 'frisbee-woocommerce-payment-gateway'), 'frisbee-woocommerce-payment-gateway');
                }
            } else {
                return new WP_Error('error', __($frisbee_response['error_code'] . '. ' . $frisbee_response['error_message'], 'frisbee-woocommerce-payment-gateway'));
            }
        } catch (Exception $e) {
            return new WP_Error('error', __($e->getMessage(), 'frisbee-woocommerce-payment-gateway'));
        }
    }

    /**
     * Answer Url
     * @return string
     */
    private function getCallbackUrl()
    {
        if (isset($this->force_lang) and $this->force_lang == 'yes') {
            $site_url = get_home_url();
        } else {
            $site_url = get_site_url() . "/";
        }

        $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? $site_url : get_permalink($this->redirect_page_id);

        //For wooCoomerce 2.0
        return add_query_arg('wc-api', get_class($this), $redirect_url);
    }

    /**
     * Site lang cropped
     * @return string
     */
    private function getLanguage()
    {
        return substr(get_bloginfo('language'), 0, 2);
    }

    /**
     * Order Email
     * @param $order
     * @return string
     */
    private function getEmail($order)
    {
        $current_user = wp_get_current_user();
        $email = $current_user->user_email;

        if (empty($email)) {
            $order_data = $order->get_data();
            $email = $order_data['billing']['email'];
        }

        return $email;
    }

    /**
     * Validation responce
     * @param $response
     * @return bool
     *
     */
    protected function isPaymentValid($response)
    {
        global $woocommerce;
        list($orderId,) = explode(self::ORDER_SEPARATOR, $response['order_id']);
        $order = new WC_Order($orderId);
        $total = round($order->get_total() * 100);
        if ($order === false) {
            $this->clear_frisbee_cache($orderId, $total, $response['currency']);
            return __('An error has occurred during payment. Please contact us to ensure your order has submitted.', 'frisbee-woocommerce-payment-gateway');
        }
        if ($response['amount'] != $total and $total != 0) {
            $this->clear_frisbee_cache($orderId, $total, $response['currency']);
            return __('Amount incorrect.', 'frisbee-woocommerce-payment-gateway');
        }
        if ($this->merchant_id != $response['merchant_id']) {
            $this->clear_frisbee_cache($orderId, $total, $response['currency']);
            return __('An error has occurred during payment. Merchant data is incorrect.', 'frisbee-woocommerce-payment-gateway');
        }
        if (version_compare(WOOCOMMERCE_VERSION, '2.7.0', '>=')) {
            if ($order->get_payment_method() != $this->id) {
                $this->clear_frisbee_cache($orderId, $total, $response['currency']);
                return __('Payment method incorrect.', 'frisbee-woocommerce-payment-gateway');
            }
        }
        $responseSignature = $response['signature'];
        if (isset($response['response_signature_string'])) {
            unset($response['response_signature_string']);
        }
        if (isset($response['signature'])) {
            unset($response['signature']);
        }

        if ($this->getSignature($response, $this->salt) != $responseSignature) {
            $order->update_status('failed');
            $order->add_order_note(__('Transaction ERROR: signature is not valid', 'frisbee-woocommerce-payment-gateway'));
            $this->clear_frisbee_cache($orderId, $total, $response['currency']);
            return __('An error has occurred during payment. Signature is not valid.', 'frisbee-woocommerce-payment-gateway');
        }

        if ($response['order_status'] == self::ORDER_DECLINED) {
            $errorMessage = __("Thank you for shopping with us. However, the transaction has been declined.", 'frisbee-woocommerce-payment-gateway');
            $order->add_order_note('Transaction ERROR: order declined<br/>Frisbee ID: ' . $response['payment_id']);
            if ($this->declined_order_status and $this->declined_order_status != 'default') {
                $order->update_status($this->declined_order_status);
            } else {
                $order->update_status('failed');
            }

            wp_mail($response['sender_email'], 'Order declined', $errorMessage);
            $this->clear_frisbee_cache($orderId, $total, $response['currency']);
            return $errorMessage;
        }

        if ($response['order_status'] == self::ORDER_EXPIRED) {
            $errorMessage = __("Thank you for shopping with us. However, the transaction has been expired.", 'frisbee-woocommerce-payment-gateway');
            $order->add_order_note(__('Transaction ERROR: order expired<br/>FRISBEE ID: ', 'frisbee-woocommerce-payment-gateway') . $response['payment_id']);
            if ($this->expired_order_status and $this->expired_order_status != 'default') {
                $order->update_status($this->expired_order_status);
            } else {
                $order->update_status('cancelled');
            }
            $this->clear_frisbee_cache($orderId, $total, $response['currency']);
            return $errorMessage;
        }

        if ($response['tran_type'] == 'purchase' and $response['order_status'] != self::ORDER_APPROVED) {
            $this->msg['class'] = 'woocommerce-error';
            $this->msg['message'] = __("Thank you for shopping with us. But your payment declined.", 'frisbee-woocommerce-payment-gateway');
            $order->add_order_note("Frisbee order status: " . $response['order_status']);
        }
        if (($response['tran_type'] == 'purchase' or $response['tran_type'] == 'verification')
            and !$order->is_paid()
            and $response['order_status'] == self::ORDER_APPROVED
            and ($total == $response['amount'] or $total == 0)) {
            if ($this->checkPreOrders($orderId, true)) {
                WC_Pre_Orders_Order::mark_order_as_pre_ordered($order);
            } else {
                $order->payment_complete();
                $order->add_order_note(__('Frisbee payment successful.<br/>FRISBEE ID: ', 'frisbee-woocommerce-payment-gateway') . ' (' . $response['payment_id'] . ')');
                if ($this->default_order_status and $this->default_order_status != 'default') {
                    $order->update_status($this->default_order_status);
                }
            }
        } elseif ($total != $response['amount'] and $response['tran_type'] != 'verification') {
            $order->add_order_note(__('Transaction ERROR: amount incorrect<br/>FRISBEE ID: ', 'frisbee-woocommerce-payment-gateway') . $response['payment_id']);
            if ($this->declined_order_status and $this->declined_order_status != 'default') {
                $order->update_status($this->declined_order_status);
            } else {
                $order->update_status('failed');
            }
        }
        $this->clear_frisbee_cache($orderId, $total, $response['currency']);
        $woocommerce->cart->empty_cart();

        return true;
    }

    /**
     * @param $orderId
     * @param $total
     * @param $cur
     */
    function clear_frisbee_cache($orderId, $total, $cur)
    {
        WC()->session->__unset('session_token_' . $this->merchant_id . '_' . $orderId);
        WC()->session->__unset('session_token_' . md5($this->merchant_id . '_' . $orderId . '_' . $total . '_' . $cur));
    }

    /**
     * Response Handler
     */
    function check_frisbee_response()
    {
        if (empty($_POST)) {
            $callback = json_decode(file_get_contents("php://input"));
            if (empty($callback)) {
                wp_die('go away!');
            }
            $_POST = array();
            foreach ($callback as $key => $val) {
                $_POST[esc_sql($key)] = esc_sql($val);
            }
        }
        list($orderId,) = explode(self::ORDER_SEPARATOR, $_POST['order_id']);
        $order = new WC_Order($orderId);
        $paymentInfo = $this->isPaymentValid($_POST);
        if ($paymentInfo === true and $_POST['order_status'] == 'reversed') {
            $order->add_order_note(__('Refund Frisbee status: ' . esc_sql($_POST['order_status']) . ', Refund payment id: ' . esc_sql($_POST['payment_id']), 'frisbee-woocommerce-payment-gateway'));
            die('Order Reversed');
        }
        if ($paymentInfo === true and !$order->is_paid()) {
            if ($_POST['order_status'] == self::ORDER_APPROVED) {
                $this->msg['message'] = __("Thank you for shopping with us. Your account has been charged and your transaction is successful.", 'frisbee-woocommerce-payment-gateway');
            }
            $this->msg['class'] = 'woocommerce-message';
        } elseif (!$order->is_paid()) {
            $this->msg['class'] = 'error';
            $this->msg['message'] = $paymentInfo;
            $order->add_order_note("ERROR: " . $paymentInfo);
        }
        if ($this->is_subscription($orderId)) {
            if (!empty($_POST['rectoken'])) {
                $this->save_card($_POST, $order);
            } else {
                $order->add_order_note('Transaction Subscription ERROR: no card token');
            }
        }

        if (isset($callback) && isset($_REQUEST['is_callback'])) { // return 200 to callback
            die();
        } else { // redirect
            if ($this->redirect_page_id == "" || $this->redirect_page_id == 0) {
                $redirect_url = $order->get_checkout_order_received_url();
            } else {
                $redirect_url = get_permalink($this->redirect_page_id);
                if ($this->msg['class'] == 'woocommerce-error' or $this->msg['class'] == 'error') {
                    wc_add_notice($this->msg['message'], 'error');
                } else {
                    wc_add_notice($this->msg['message']);
                }
            }
            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * @param $data
     * @param $order
     * @return bool|false|int
     */
    private function save_card($data, $order)
    {
        $userid = $order->get_user_id();
        $token = false;
        if ($this->isTokenAlreadySaved($data['rectoken'], $userid)) {
            update_user_meta($userid, 'frisbee_token', array(
                'token' => $data['rectoken'],
                'payment_id' => $this->id
            ));

            return true;
        }
        $token = add_user_meta($userid, 'frisbee_token', array(
            'token' => $data['rectoken'],
            'payment_id' => $this->id
        ));
        if ($token) {
            wc_add_notice(__('Card saved.', 'woocommerce-frisbee'));
        }

        return $token;
    }

    /**
     * @param $token
     * @param $userid
     * @return bool
     */
    private function isTokenAlreadySaved( $token, $userid ) {
        $tokens = get_user_meta( $userid, 'frisbee_token' );
        foreach ( $tokens as $t ) {
            if ( $t['token'] === $token ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $order_id
     * @return bool
     * Checking if subsciption order
     */
    protected function is_subscription($order_id)
    {
        return (function_exists('wcs_order_contains_subscription') && (wcs_order_contains_subscription($order_id) || wcs_is_subscription($order_id) || wcs_order_contains_renewal($order_id)));
    }

    /**
     * @param bool $title
     * @param bool $indent
     * @return array
     */
    function frisbee_get_pages($title = false, $indent = true)
    {
        $wp_pages = get_pages('sort_column=menu_order');
        $page_list = array();
        if ($title) {
            $page_list[] = $title;
        }
        foreach ($wp_pages as $page) {
            $prefix = '';
            // show indented child pages?
            if ($indent) {
                $has_parent = $page->post_parent;
                while ($has_parent) {
                    $prefix .= ' - ';
                    $next_page = get_post($has_parent);
                    $has_parent = $next_page->post_parent;
                }
            }
            // add to page list array array
            $page_list[$page->ID] = $prefix . $page->post_title;
        }

        return $page_list;
    }

    /**
     * @return array
     */
    function frisbee_get_payment_type()
    {
        return array(
            'on_checkout_page' => __('Built-in form', 'frisbee-woocommerce-payment-gateway'),
            'page_mode' => __('In-store payment page', 'frisbee-woocommerce-payment-gateway'),
            'page_mode_instant' => __('Redirection', 'frisbee-woocommerce-payment-gateway'),
        );
    }

    /**
     * Send capture request
     * @param $args
     * @return array
     * */
    protected function get_capture($args)
    {
        $conf = array(
            'redirection' => 2,
            'user-agent' => 'CMS Woocommerce',
            'headers' => array("Content-type" => "application/json;charset=UTF-8"),
            'body' => json_encode(array('request' => $args))
        );
        $response = wp_remote_post('https://api.fondy.eu/api/capture/order_id', $conf);
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 200) {
            $error = "Return code is {$response_code}";
            return array('result' => 'failture', 'messages' => $error);
        }
        $result = json_decode($response['body'], true);
        return array('data' => $result['response']);
    }

    /**
     * Process capture
     * @param $order
     * @return WP_Error
     */
    public function process_pre_order_payments($order)
    {
        if (!$order) {
            return new WP_Error('fallen', 'Order not found');
        }
        $frisbee_args = array(
            'order_id' => $this->getUniqueId($order->get_id()),
            'currency' => esc_attr(get_woocommerce_currency()),
            'amount' => round($order->get_total() * 100),
            'merchant_id' => esc_attr($this->merchant_id),
        );
        $frisbee_args['signature'] = $this->getSignature($frisbee_args, $this->salt);
        $result = $this->get_capture($frisbee_args);
        if (isset($result) && $result) {
            if (isset($result['result']) && $result['result'] == 'failture') {
                $order->add_order_note('Transaction ERROR:<br/> ' . $result['messages']);
            } else {
                if ($result['data']['response_status'] == 'success' && $result['data']['capture_status'] == 'captured') {
                    $order->add_order_note(__('Frisbee payment successful.<br/>FRISBEE ID: ', 'frisbee-woocommerce-payment-gateway') . ' (' . $result['data']['order_id'] . ')');
                    $order->payment_complete();
                } else {
                    $request_id = '<br>Request_id: ' . $result['data']['request_id'];
                    $order->add_order_note('Transaction: ' . $result['data']['response_status'] . '  <br/> ' . $result['data']['error_message'] . $request_id);
                }
            }
        }
    }

    /**
     * Check pre order class and order status
     * @param $order_id
     * @param bool $withoutToken
     * @return boolean
     */
    public function checkPreOrders($order_id, $withoutToken = false)
    {
        if (class_exists('WC_Pre_Orders_Order')
            && WC_Pre_Orders_Order::order_contains_pre_order($order_id)) {
            if ($withoutToken) {
                return true;
            } else {
                if (WC_Pre_Orders_Order::order_requires_payment_tokenization($order_id)) {
                    return true;
                }
            }
        }
        return false;
    }
}
