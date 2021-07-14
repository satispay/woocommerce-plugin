<?php
if (!defined('ABSPATH')) {
  exit;
}

require_once(dirname(__FILE__).'/satispay-sdk/init.php');

class WC_Satispay extends WC_Payment_Gateway {
  public function __construct() {
    $this->id                   = 'satispay';
    $this->method_title         = __('Satispay', 'woo-satispay');
    $this->order_button_text    = __('Pay with Satispay', 'woo-satispay');
    $this->method_description   = __('Save time and money by accepting payments from your customers with Satispay. Free, simple, secure! #doitsmart', 'woo-satispay');
    $this->has_fields           = false;
    $this->supports             = array(
      'products',
      'refunds'
    );

    $this->title                = $this->method_title;
    $this->description          = $this->get_option('description');
    $this->icon                 = plugins_url('/logo.png', __FILE__);
    $this->enabled              = $this->get_option( 'enabled' );

    $this->init_form_fields();
    $this->init_settings();

    add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
    add_action('woocommerce_api_wc_gateway_'.$this->id, array($this, 'gateway_api'));

    if ($this->get_option('sandbox') == 'yes') {
      \SatispayGBusiness\Api::setSandbox(true);
    }

    \SatispayGBusiness\Api::setPublicKey($this->get_option('publicKey'));
    \SatispayGBusiness\Api::setPrivateKey($this->get_option('privateKey'));
    \SatispayGBusiness\Api::setKeyId($this->get_option('keyId'));
  }

  public function process_refund($order, $amount = null, $reason = '') {
    $order = new WC_Order($order);

    \SatispayGBusiness\Payment::create(array(
      'flow' => 'REFUND',
      'amount_unit' => round($amount * 100),
      'currency' => (method_exists($order, 'get_currency')) ? $order->get_currency() : $order->order_currency,
      'parent_payment_uid' => $order->get_transaction_id()
    ));

    return true;
  }

  public function init_form_fields() {
    $this->form_fields = array(
      'enabled' => array(
        'title' => __('Enable/Disable', 'woo-satispay'),
        'label' => __('Enable Satispay', 'woo-satispay'),
        'type' => 'checkbox',
        'default' => 'no'
      ),
      'activationCode' => array(
        'title' => __('Activation Code', 'woo-satispay'),
        'type' => 'text',
        'description' => sprintf(__('Get a six characters Activation Code from Online Shop section on <a href="%s" target="_blank">Satispay Dashboard</a>.', 'woo-satispay'), 'https://business.satispay.com')
      ),
      'description' => array(
        'title' => __('Description', 'woo-satispay'),
        'type' => 'text',
        'description' => __('Enter the description you want to show to customers in your checkout page.', 'woo-satispay') // TODO: add translation
      ),

      // 'advanced' => array(
      //   'title' => __( 'Advanced Options', 'woo-satispay' ),
      //   'type' => 'title',
      //   'description' => '',
      // ),

      'sandbox' => array(
        'title' => __('Sandbox', 'woo-satispay'),
        'label' => __('Sandbox Mode', 'woo-satispay'),
        'type' => 'checkbox',
        'default' => 'no',
        'description' => sprintf(__('Sandbox Mode can be used to test payments. Request a <a href="%s" target="_blank">Sandbox Account</a>.', 'woo-satispay'), 'https://developers.satispay.com/docs/sandbox-account')
      ),
      // 'debug' => array(
      //   'title' => __('Debug Logs', 'woo-satispay'),
      //   'label' => __('Enable Logs', 'woo-satispay'),
      //   'type' => 'checkbox',
      //   'default' => 'no',
      //   'description' => sprintf(__('Log Satispay requests inside %s.<br />Note: this may log personal informations. We recommend using this for debugging purposes only and deleting the logs when finished.', 'woo-satispay'), '<code>'.WC_Log_Handler_File::get_log_file_path('satispay').'</code>')
      // )
    );
  }

  public function gateway_api() {
    switch($_GET['action']) {
      case 'redirect':
        $payment = \SatispayGBusiness\Payment::get($_GET['payment_id']);
        $order = new WC_Order($payment->metadata->order_id);

        if ($payment->status === 'ACCEPTED') {
          header('Location: '.$this->get_return_url($order));
        } else {
          \SatispayGBusiness\Payment::update($payment->id, array(
            'action' => 'CANCEL'
          ));

          header('Location: '.$order->get_cancel_order_url_raw());
        }
        break;
      case 'callback':
        $payment = \SatispayGBusiness\Payment::get($_GET['payment_id']);
        $order = new WC_Order($payment->metadata->order_id);

        if ($order->has_status(wc_get_is_paid_statuses())) {
          exit;
        }

        if ($payment->status === 'ACCEPTED') {
          $order->payment_complete($payment->id);
        }
        break;
    }
  }

  public function process_admin_options() {
    $activationCode = $this->get_option('activationCode');
    $sandbox = $this->get_option('sandbox');

    $postData = $this->get_post_data();

    $newActivationCode = $postData['woocommerce_satispay_activationCode'];
    $newSandbox = $postData['woocommerce_satispay_sandbox'];

    if (!empty($newActivationCode) && $newActivationCode != $activationCode) {
      if ($newSandbox == '1') {
        \SatispayGBusiness\Api::setSandbox(true);
      }

      try {
        $authentication = \SatispayGBusiness\Api::authenticateWithToken($newActivationCode);

        $this->update_option('keyId', $authentication->keyId);
        $this->update_option('privateKey', $authentication->privateKey);
        $this->update_option('publicKey', $authentication->publicKey);
        $this->update_option('activationCode', $newActivationCode);

        \SatispayGBusiness\Api::setKeyId($authentication->keyId);
        \SatispayGBusiness\Api::setPrivateKey($authentication->privateKey);
        \SatispayGBusiness\Api::setPublicKey($authentication->publicKey);
      } catch(\Exception $ex) {
        echo '<div class="notice-error notice">';
        echo '<p>'.sprintf(__('The Activation Code "%s" is invalid', 'woo-satispay'), $newActivationCode).'</p>';
        echo '</div>';
      }
    } else if (empty($newActivationCode)) {
      $this->update_option('keyId', '');
      $this->update_option('privateKey', '');
      $this->update_option('publicKey', '');
      $this->update_option('activationCode', '');
    }

    return parent::process_admin_options();
  }

  public function admin_options() {
    try {
      \SatispayGBusiness\Payment::all();
    } catch (\Exception $ex) {
      echo '<div class="notice-error notice">';
      echo '<p>'.sprintf(__('Satispay is not correctly configured, get an Activation Code from Online Shop section on <a href="%s" target="_blank">Satispay Dashboard</a>', 'woo-satispay'), 'https://business.satispay.com').'</p>';
      echo '</div>';
    }

    return parent::admin_options();
  }

  public function is_available() {
    if ( 'no' === $this->enabled ) {
      return false;
    }
    return true;
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);

    $apiUrl = WC()->api_request_url('WC_Gateway_Satispay');
    if (strpos($apiUrl, '?') !== FALSE) {
      $callbackUrl = $apiUrl.'&action=callback&payment_id={uuid}';
      $redirectUrl = $apiUrl.'&action=redirect&payment_id={uuid}';
    } else {
      $callbackUrl = $apiUrl.'?action=callback&payment_id={uuid}';
      $redirectUrl = $apiUrl.'?action=redirect&payment_id={uuid}';
    }

    $payment = \SatispayGBusiness\Payment::create(array(
      'flow' => 'MATCH_CODE',
      'amount_unit' => $order->get_total() * 100,
      'currency' => (method_exists($order, 'get_currency')) ? $order->get_currency() : $order->order_currency,
      'callback_url' => $callbackUrl,
      'external_code' => $order->get_id(),
      'metadata' => array(
        'order_id' => $order->get_id(),
        'redirect_url' => $redirectUrl
      )
    ));

    $paymentUrl = 'https://online.satispay.com/pay/';
    if ($this->get_option('sandbox') == 'yes') {
      $paymentUrl = 'https://staging.online.satispay.com/pay/';
    }

    return array(
      'result' => 'success',
      'redirect' => $paymentUrl.$payment->id
    );
  }
}
