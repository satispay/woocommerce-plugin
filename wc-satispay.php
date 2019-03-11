<?php
if (!defined('ABSPATH')) {
  exit;
}

require_once(dirname(__FILE__).'/includes/gbusiness-api-php-sdk/init.php');

class WC_Satispay extends WC_Payment_Gateway {
  public function __construct() {
    $this->id                   = 'satispay';
    $this->method_title         = __('Satispay', 'woo-satispay');
    $this->order_button_text    = __('Proceed to Satispay', 'woo-satispay');
    $this->method_description   = __('Satispay is a secure payment system that allows you to pay in physical and online stores, buy phone top-ups and even transfer money with friends for free.', 'woo-satispay');
    $this->has_fields           = false;
    $this->supports             = array(
      'products',
      'refunds'
    );

    $this->title                = $this->method_title;
    $this->description          = $this->method_description;
    $this->icon                 = plugins_url('/images/logo.png', __FILE__);

    $this->init_form_fields();
    $this->init_settings();

    add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
    add_action('woocommerce_api_wc_gateway_'.$this->id, array($this, 'gateway_api'));

    if ($this->get_option('sandbox') == 'yes') {
      \SatispayGBusiness\Api::setSandbox(true);
    }

    $authenticationType = $this->get_authentication_type();
    if ($authenticationType == 'signature') {
      \SatispayGBusiness\Api::setPublicKey($this->get_option('publicKey'));
      \SatispayGBusiness\Api::setPrivateKey($this->get_option('privateKey'));
      \SatispayGBusiness\Api::setKeyId($this->get_option('keyId'));
    } else if ($authenticationType == 'bearer') {
      \SatispayGBusiness\Api::setSecurityBearer($this->get_option('securityBearer'));
    }
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
        'description' => sprintf(__('Get a six characters Activation Code from <a href="%s" target="_blank">Dashboard</a>.', 'woo-satispay'), 'https://business.satispay.com')
      ),
      
      'advanced' => array(
        'title' => __( 'Advanced Options', 'woo-satispay' ),
        'type' => 'title',
        'description' => '',
      ),

      'sandbox' => array(
        'title' => __('Sandbox', 'woo-satispay'),
        'label' => __('Enable Sandbox', 'woo-satispay'),
        'type' => 'checkbox',
        'default' => 'no',
        'description' => sprintf(__('Sandbox can be used to test payments. Request a <a href="%s" target="_blank">Sandbox Account</a>.', 'woo-satispay'), 'https://developers.satispay.com/docs/sandbox-account')
      ),
      'debug' => array(
        'title' => __('Debug Logs', 'woo-satispay'),
        'label' => __('Enable Logs', 'woo-satispay'),
        'type' => 'checkbox',
        'default' => 'no',
        'description' => sprintf(__('Log Satispay requests inside %s.<br />Note: this may log personal informations. We recommend using this for debugging purposes only and deleting the logs when finished.', 'woo-satispay'), '<code>'.WC_Log_Handler_File::get_log_file_path('satispay').'</code>')
      )
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
        $this->update_option('securityBearer', '');
      } catch(\Exception $ex) {
        $this->add_error(sprintf(__('The Activation Code "%s" is invalid', 'woo-satispay'), $newActivationCode));
        $this->display_errors();
      }
    } else if (empty($newActivationCode)) {
      $this->update_option('keyId', '');
      $this->update_option('privateKey', '');
      $this->update_option('publicKey', '');
      $this->update_option('activationCode', '');
      $this->update_option('securityBearer', '');
    }

    return parent::process_admin_options();
  }

  function admin_options() {
    $authenticationType = $this->get_authentication_type();

    if ($authenticationType == 'none') {
      echo '<div class="notice-error notice">';
      echo '<p>'.__('Satispay is not configured', 'woo-satispay').'</p>';
      echo '</div>';
    }
    
    if ($authenticationType == 'bearer') {
      echo '<div class="notice-error notice">';
      echo '<p>'.__('You are using an old authentication method, please reconfigure Satispay with an Activation Code', 'woo-satispay').'</p>';
      echo '</div>';
    }

    if ($this->get_option('sandbox') == 'yes') {
      echo '<div class="notice-warning notice">';
      echo '<p>'.__('Sandbox is Enabled, remember to disable it after tests', 'woo-satispay').'</p>';
      echo '</div>';
    }
    
    return parent::admin_options();
  }

  function is_sandbox_enabled() {
    $sandbox = $this->get_option('sandbox');
    if (!empty($sandbox) && $sandbox == 'yes') {
      return true;
    }
    return false;
  }

  function get_authentication_type() {
    if (!empty($this->get_option('keyId')) && !empty($this->get_option('privateKey')) && !empty($this->get_option('publicKey'))) {
      return 'signature';
    } else if (!empty($this->get_option('securityBearer'))) {
      return 'bearer';
    } else {
      return 'none';
    }
  }

  public function is_available() {
    if (!$this->enabled || $this->get_authentication_type() == 'none') {
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
      'amount_unit' => round($order->get_total() * 100),
      'currency' => (method_exists($order, 'get_currency')) ? $order->get_currency() : $order->order_currency,
      'callback_url' => $callbackUrl,
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
