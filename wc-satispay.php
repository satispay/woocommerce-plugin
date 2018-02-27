<?php
if (!defined('ABSPATH')) {
  exit;
}

require_once(dirname(__FILE__).'/includes/online-api-php-sdk/init.php');

class WC_Satispay extends WC_Payment_Gateway {
  public function __construct() {
    $this->id                   = 'satispay';
    $this->has_fields           = false;
    $this->method_title         = __('Satispay', 'woo-satispay');
    $this->order_button_text    = __('Proceed to Satispay', 'woo-satispay');
    $this->method_description   = __('Satispay is a new payment system that allows you to pay stores or friends from your smartphone.', 'woo-satispay');
    $this->has_fields           = false;
    $this->supports             = array(
      'products',
      'refunds'
    );

    $this->title                = __('Satispay', 'woo-satispay');
    $this->description          = $this->method_description;
    $this->icon                 = plugins_url('/images/pay_logo.png', __FILE__);

    $this->init_form_fields();
    $this->init_settings();

    foreach ($this->settings as $setting_key => $value) {
      $this->$setting_key = $value;
    }

    add_action('woocommerce_update_options_payment_gateways_satispay', array($this, 'process_admin_options'));
    add_action('woocommerce_api_wc_gateway_satispay', array($this, 'gateway_api'));

    \SatispayOnline\Api::setSecurityBearer($this->securityBearer);
    \SatispayOnline\Api::setStaging($this->staging == 'yes');

    \SatispayOnline\Api::setPluginName('WooCommerce');
    \SatispayOnline\Api::setPlatformVersion(WC_VERSION);
    \SatispayOnline\Api::setType('ECOMMERCE-PLUGIN');
  }

  public function process_refund($order, $amount = null, $reason = '') {
    $order = new WC_Order($order);

    $refund = \SatispayOnline\Refund::create(array(
      'charge_id' => $order->get_transaction_id(),
      'currency' => (method_exists($order, 'get_currency')) ? $order->get_currency() : $order->order_currency,
      'amount' => round($amount * 100),
      'description' => '#'.$order->get_order_number()
    ));

    return true;
  }

  public function init_form_fields() {
    $this->form_fields = array(
      'enabled' => array(
        'title'       => __('Enable/Disable', 'woo-satispay'),
        'label'       => __('Enable Satispay', 'woo-satispay'),
        'type'        => 'checkbox',
        'default'     => 'no'
      ),
      'staging' => array(
        'title'       => __('Sandbox', 'woo-satispay'),
        'label'       => __('Enable sandbox', 'woo-satispay'),
        'type'        => 'checkbox',
        'default'     => 'no'
      ),
      'securityBearer' => array(
        'title'       => __('Security Bearer', 'woo-satispay'),
        'type'        => 'text'
      )
    );
  }

  public function gateway_api() {
    switch($_GET['action']) {
      case 'redirect':
        $charge = \SatispayOnline\Charge::get($_GET['charge_id']);
        $order = new WC_Order($charge->metadata->order_id);

        if ($charge->status === 'SUCCESS')
          header('Location: '.$this->get_return_url($order));
        else
          header('Location: '.$order->get_cancel_order_url_raw());
        break;
      case 'callback':
        $charge = \SatispayOnline\Charge::get($_GET['charge_id']);
        $order = new WC_Order($charge->metadata->order_id);

        if ($order->has_status(wc_get_is_paid_statuses()))
          exit;

        if ($charge->status === 'SUCCESS')
          $order->payment_complete($charge->id);
        break;
    }
  }

  public function is_available() {
    if (!$this->enabled || !$this->securityBearer) {
      return false;
    }
    return true;
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);

    $baseUrl = WC()->api_request_url('WC_Gateway_Satispay');
    if (strpos($baseUrl, '?') !== FALSE) {
      $callbackUrl = $baseUrl.'&action=callback&charge_id={uuid}';
      $redirectUrl = $baseUrl.'&action=redirect';
    } else {
      $callbackUrl = $baseUrl.'?action=callback&charge_id={uuid}';
      $redirectUrl = $baseUrl.'?action=redirect';
    }

    $checkout = \SatispayOnline\Checkout::create(array(
      'description' => '#'.$order->get_order_number(),
      'phone_number' => '',
      'redirect_url' => $redirectUrl,
      'callback_url' => $callbackUrl,
      'amount_unit' => round($order->get_total() * 100),
      'currency' => (method_exists($order, 'get_currency')) ? $order->get_currency() : $order->order_currency,
			'metadata' => array(
				'order_id' => $order->get_id()
			)
    ));

    return array(
      'result' => 'success',
      'redirect' => $checkout->checkout_url
    );
  }
}
