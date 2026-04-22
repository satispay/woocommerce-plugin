<?php
if (!defined('ABSPATH')) {
  exit;
}

require_once(__DIR__ . '/satispay-sdk/init.php');

class WC_Satispay extends WC_Payment_Gateway {

  const SESSION_PAYMENT_ID_KEY = 'satispay_payment_id';

  const SESSION_CART_SNAPSHOT_KEY = 'satispay_cart_snapshot';

    const SUPPORTS = array(
        'products',
        'refunds'
    );

    const SUPPORTED_CURRENCY = 'EUR';

    const ORDER_ENVIRONMENT_META_KEY = '_satispay_environment';

    const TRANSACTION_URL_LIVE = 'https://dashboard.satispay.com/dashboard/transactions/%s';

    const TRANSACTION_URL_STAGING = 'https://staging.dashboard.satispay.com/dashboard/transactions/%s';

  public function __construct() {
    if ('satispay' === filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS)) {
      $GLOBALS['hide_save_button'] = false;
    }
    $this->id                   = 'satispay';
    $this->method_title         = __('Satispay', 'woo-satispay');
    $this->order_button_text    = __('Pay now', 'woo-satispay');
    $this->method_description   = __('Do it smart. Choose Satispay and pay with a tap!', 'woo-satispay');
    $this->has_fields           = false;
    $this->supports             = self::SUPPORTS;

    $this->title                = $this->method_title;
    $this->description          = __('After clicking "Pay now", you will be redirected to Satispay to complete your purchase securely', 'woo-satispay');
    $this->icon                 = plugins_url('/logo.svg', __FILE__);

    $this->init_form_fields();
    $this->init_settings();

    add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
    add_action('woocommerce_api_wc_gateway_'.$this->id, array($this, 'gateway_api'));
    add_filter('woocommerce_order_actions', array($this, 'add_order_actions'), 10, 2);
    add_action('woocommerce_order_action_satispay_capture_authorized_payment', array($this, 'capture_authorized_payment'));
    add_action('woocommerce_order_action_satispay_cancel_authorized_payment', array($this, 'cancel_authorized_payment'));
    add_action('woocommerce_thankyou_'.$this->id, array($this, 'render_thankyou_payment_features'));
    add_action('woocommerce_order_details_after_order_table', array($this, 'render_order_payment_features'));
    add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_admin_order_payment_features'));

    if ($this->get_option('sandbox') == 'yes') {
      \SatispayGBusiness\Api::setSandbox(true);
    }

    \SatispayGBusiness\Api::setPublicKey($this->get_option('publicKey'));
    \SatispayGBusiness\Api::setPrivateKey($this->get_option('privateKey'));
    \SatispayGBusiness\Api::setKeyId($this->get_option('keyId'));
    add_filter('woocommerce_available_payment_gateways', array($this, 'check_gateway'), 15);
  }

  const ORDER_FLOW_META_KEY = '_satispay_flow';

  const ORDER_REMOTE_STATUS_META_KEY = '_satispay_remote_status';

  const ORDER_AUTHORIZED_AT_META_KEY = '_satispay_authorized_at';

  const ORDER_LAST_SYNC_AT_META_KEY = '_satispay_last_sync_at';

  const ORDER_PAYMENT_TYPE_META_KEY = '_satispay_payment_type';

  const ORDER_PAYMENT_TYPE_LABEL_META_KEY = '_satispay_payment_type_label';

  const ORDER_IS_BNPL_META_KEY = '_satispay_is_bnpl';

  const ORDER_IS_FRINGE_BENEFITS_META_KEY = '_satispay_is_fringe_benefits';

  const ORDER_FRINGE_AMOUNT_UNIT_META_KEY = '_satispay_fringe_amount_unit';

  public function process_refund($order_id, $amount = null, $reason = '') {
    $order = wc_get_order($order_id);

    try {
      $response = \SatispayGBusiness\Payment::create(array(
        'flow' => 'REFUND',
        'amount_unit' => round($amount * 100),
        'currency' => $order->get_currency(),
        'parent_payment_uid' => $order->get_transaction_id()
      ));

      return isset($response->status) && $response->status === 'ACCEPTED';
    } catch (\Exception $e) {
      error_log('Statispay Refund Error: ' . $e->getMessage());
    }

    return false;
  }

  public function finalize_orders() {
    if ($this->get_option('finalizeUnhandledTransactions') === 'yes' && $this->get_option('enabled') === 'yes') {
      $rangeStart = $this->get_start_date_scheduled_time();
      $rangeEnd = $this->get_end_date_scheduled_time();
      $orders = wc_get_orders(array(
        'limit' => -1,
        'type' => 'shop_order',
        'status' => array('wc-pending','wc-on-hold'),
        'date_created'=> $rangeStart .'...'. $rangeEnd
      )
    );
    foreach ($orders as $order) {
      try {
        if ($order->get_payment_method() === 'satispay') {
          $transactionId = $order->get_transaction_id();
          if (!isset($transactionId)) {
            continue;
          }
          //callback logic
          $payment = \SatispayGBusiness\Payment::get($transactionId);
          if ($order->has_status(wc_get_is_paid_statuses()) && $payment->status !== 'ACCEPTED') {
            continue;
          }
          $this->sync_order_with_payment($order, $payment, 'custom cron action');
        }
      } catch (\Exception $e) {
          if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug('An error occured when finalizing the order ' . $order->get_order_number() .
            '. Error: ' . $e->getMessage(),
            array('source' => 'satispay'));
          }
        }
      }
    }
  }

  public function add_order_actions($actions, $order) {
    if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->id || !$this->is_late_capture_order($order)) {
      return $actions;
    }

    $remoteStatus = $order->get_meta(self::ORDER_REMOTE_STATUS_META_KEY, true);

    if ($remoteStatus === 'AUTHORIZED' && !$order->has_status(wc_get_is_paid_statuses())) {
      $actions['satispay_capture_authorized_payment'] = __('Capture authorized Satispay payment', 'woo-satispay');
      $actions['satispay_cancel_authorized_payment'] = __('Cancel Satispay authorization', 'woo-satispay');
    }

    return $actions;
  }

  public function capture_authorized_payment($order) {
    if (!$order instanceof WC_Order || !$this->is_late_capture_order($order)) {
      return;
    }

    try {
      $payment = \SatispayGBusiness\Payment::update($order->get_transaction_id(), array(
        'action' => 'ACCEPT',
        'amount_unit' => round($order->get_total() * 100)
      ));

      $this->sync_order_with_payment($order, $payment, 'manual capture');
    } catch (\Exception $e) {
      $order->add_order_note(sprintf(__('Satispay capture failed: %s', 'woo-satispay'), $e->getMessage()));
    }
  }

  public function cancel_authorized_payment($order) {
    if (!$order instanceof WC_Order || !$this->is_late_capture_order($order)) {
      return;
    }

    try {
      $payment = \SatispayGBusiness\Payment::update($order->get_transaction_id(), array(
        'action' => 'CANCEL'
      ));

      $this->sync_order_with_payment($order, $payment, 'manual cancellation');
    } catch (\Exception $e) {
      $order->add_order_note(sprintf(__('Satispay cancellation failed: %s', 'woo-satispay'), $e->getMessage()));
    }
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
        'description' => wp_kses_post(sprintf(__('Get a six characters Activation Code from Online Shop section on <a href="%s" target="_blank">Satispay Dashboard</a>.', 'woo-satispay'), 'https://dashboard.satispay.com'))
      ),
      'sandbox' => array(
        'title' => __('Sandbox', 'woo-satispay'),
        'label' => __('Sandbox Mode', 'woo-satispay'),
        'type' => 'checkbox',
        'default' => 'no',
        'description' => wp_kses_post(sprintf(__('Sandbox Mode can be used to test payments. Request a <a href="%s" target="_blank">Sandbox Account</a>.', 'woo-satispay'), 'https://satispay-sandbox.paperform.co/'))
      ),
      'lateCapture' => array(
        'title' => __('Late capture', 'woo-satispay'),
        'label' => __('Enable late capture', 'woo-satispay'),
        'type' => 'checkbox',
        'default' => 'no',
        'description' => __('When enabled, new Satispay payments will use the late capture flow.', 'woo-satispay')
      ),
      'finalizeUnhandledTransactions' => array(
        'title' => __('Finalize unhandled payments', 'woo-satispay'),
        'label' => __('Enable cron', 'woo-satispay'),
        'type' => 'checkbox',
        'default' => 'no',
        'description' => __('Finalize unhandled Satispay payments with a cron.', 'woo-satispay')
      ),
      'finalizeMaxHours' => array(
        'title' => __('Finalize pending payments up to', 'woo-satispay'),
        'label' => __('Finalize pending payments up to', 'woo-satispay'),
        'type' => 'integer',
        'default' => 4,
        'description' => __('Choose a number of hours, default is four and minimum is two.', 'woo-satispay')
      )
    );
  }

  public function gateway_api() {
    $action = (string) filter_input(INPUT_GET, 'action', FILTER_SANITIZE_SPECIAL_CHARS);
    switch($action) {
      case 'redirect':
        $paymentId = (WC()->session) ? WC()->session->get(self::SESSION_PAYMENT_ID_KEY) : '';
        if (!$paymentId) {
            wp_safe_redirect($this->get_return_url());
            exit;
        }
        $payment = \SatispayGBusiness\Payment::get($paymentId);
        $order = wc_get_order($payment->metadata->order_id);
        if (in_array($payment->status, array('ACCEPTED', 'AUTHORIZED'), true)) {
          $this->sync_order_with_payment($order, $payment, 'redirect');
          $this->clear_checkout_cart_snapshot();
          wp_safe_redirect($this->get_return_url($order));
          exit;
        } else {
          \SatispayGBusiness\Payment::update($payment->id, array(
            'action' => 'CANCEL'
          ));

          $this->restore_checkout_cart_snapshot($order);
          wp_safe_redirect(wc_get_checkout_url());
          exit;
        }
      case 'callback':
        $paymentId = (string) filter_input(INPUT_GET, 'payment_id', FILTER_SANITIZE_SPECIAL_CHARS);
        $payment = \SatispayGBusiness\Payment::get($paymentId);
        $order = wc_get_order($payment->metadata->order_id);

        if (!$order instanceof WC_Order) {
          exit;
        }

        $this->sync_order_with_payment($order, $payment, 'callback');
        break;
    }
  }

  public function process_admin_options() {
    $activationCode = $this->get_option('activationCode');
    $postData = $this->get_post_data();

    $newActivationCode = isset($postData['woocommerce_satispay_activationCode'])
      ? sanitize_text_field($postData['woocommerce_satispay_activationCode'])
      : '';
    $newSandbox = !empty($postData['woocommerce_satispay_sandbox']);

    if (!empty($newActivationCode) && $newActivationCode !== $activationCode) {
      if ($newSandbox) {
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
        WC_Admin_Settings::add_error(
          sprintf(__('The Activation Code "%s" is invalid. Please check the code and try again.', 'woo-satispay'), $newActivationCode)
        );

        // Prevent the invalid activation code from being saved by restoring the previous value.
        $_POST['woocommerce_satispay_activationCode'] = $activationCode;
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
    $keyId = $this->get_option('keyId');

    if (!empty($keyId)) {
      try {
        \SatispayGBusiness\Payment::all();
      } catch (\Exception $ex) {
        WC_Admin_Settings::add_error(
          sprintf(
            __('Satispay is not correctly configured, get an Activation Code from Online Shop section on %sSatispay Dashboard%s', 'woo-satispay'),
            '<a href="https://dashboard.satispay.com" target="_blank">',
            '</a>'
          )
        );
      }
    }

    parent::admin_options();
  }

  public function is_available() {
    if ($this->get_option('enabled') === 'no') {
      return false;
    }
    return true;
  }

  public function payment_fields() {
    if (!empty($this->description)) {
      echo wpautop(wp_kses_post($this->description));
    }

    if ($this->is_checkout_currency_supported()) {
      return;
    }

    echo '<p class="woocommerce-error woocommerce-satispay-currency-error" role="alert">' . esc_html($this->get_unsupported_currency_message()) . '</p>';
  }

  public function validate_fields() {
    if ($this->is_checkout_currency_supported()) {
      return true;
    }

    wc_add_notice($this->get_unsupported_currency_message(), 'error');

    return false;
  }

  public function is_late_capture_enabled() {
    return $this->get_option('lateCapture') === 'yes';
  }

  public function get_transaction_url($order) {
    $this->view_transaction_url = $this->get_transaction_url_base($order);

    return parent::get_transaction_url($order);
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);

    if (!$this->is_checkout_currency_supported($order)) {
      $message = $this->get_unsupported_currency_message($order instanceof WC_Order ? $order->get_currency() : null);

      wc_add_notice($message, 'error');

      return array(
        'result' => 'failure',
        'message' => $message,
      );
    }

    $flow = $this->is_late_capture_enabled() ? 'FUND_LOCK' : 'MATCH_CODE';

    $apiUrl = WC()->api_request_url('WC_Gateway_Satispay');
    $callbackUrl = add_query_arg(
        ['action' => 'callback', 'payment_id' => '{uuid}'],
        $apiUrl
    );

    $redirectUrl = add_query_arg(['action' => 'redirect'], $apiUrl);

    $payment = \SatispayGBusiness\Payment::create(array(
      'flow' => $flow,
      'amount_unit' => round($order->get_total() * 100),
      'currency' => $order->get_currency(),
      'callback_url' => $callbackUrl,
      'external_code' => $order->get_id(),
      'redirect_url' => $redirectUrl,
      'metadata' => array(
        'order_id' => $order->get_id()
      )
    ));

    try {
        $order->update_status('wc-on-hold');
        $order->set_transaction_id($payment->id);
        $order->update_meta_data(self::ORDER_ENVIRONMENT_META_KEY, $this->get_environment());
        $order->update_meta_data(self::ORDER_FLOW_META_KEY, $flow);
        $order->update_meta_data(self::ORDER_REMOTE_STATUS_META_KEY, $payment->status);
        $order->update_meta_data(self::ORDER_LAST_SYNC_AT_META_KEY, current_time('mysql', true));
        if (WC()->session) {
            if (!WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
            WC()->session->set(self::SESSION_PAYMENT_ID_KEY, $payment->id);
            $this->store_checkout_cart_snapshot($order, $payment->id);
        }
        $order->save();
    } catch (\Exception $e) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug(
                'Order id - ' . $order->get_id() . ' - Could not save transaction Id for payment due to the following error: ' . $e->getMessage(),
                array('source' => 'satispay')
            );
        }
    }

    return array(
      'result' => 'success',
      'redirect' => $payment->redirect_url
    );
  }

  public function render_thankyou_payment_features($order_id) {
    $order = wc_get_order($order_id);

    if (!$order instanceof WC_Order) {
      return;
    }

    $this->render_order_payment_features_section($order);
  }

  public function render_order_payment_features($order) {
    if (!$order instanceof WC_Order) {
      return;
    }

    $this->render_order_payment_features_section($order);
  }

  public function render_admin_order_payment_features($order) {
    if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->id) {
      return;
    }

    $paymentFeatures = $this->get_order_payment_features($order);

    if (!$paymentFeatures['has_special_payment']) {
      return;
    }

    echo '<div class="order_data_column">';
    echo '<h4>' . esc_html__('Satispay payment details', 'woo-satispay') . '</h4>';

    if (!empty($paymentFeatures['label'])) {
      echo '<p><strong>' . esc_html__('Payment solution', 'woo-satispay') . ':</strong> ' . esc_html($paymentFeatures['label']) . '</p>';
    }

    if ($paymentFeatures['is_fringe_benefits'] && null !== $paymentFeatures['fringe_amount_unit']) {
      echo '<p><strong>' . esc_html__('Fringe benefits used', 'woo-satispay') . ':</strong> ' . wp_kses_post($this->format_amount_from_unit($paymentFeatures['fringe_amount_unit'], $order->get_currency())) . '</p>';
    }

    echo '</div>';
  }

  private function is_late_capture_order($order) {
    return $order instanceof WC_Order && $order->get_meta(self::ORDER_FLOW_META_KEY, true) === 'FUND_LOCK';
  }

  private function sync_order_with_payment($order, $payment, $context = 'sync') {
    if (!$order instanceof WC_Order || !isset($payment->status)) {
      return;
    }

    $previousRemoteStatus = $order->get_meta(self::ORDER_REMOTE_STATUS_META_KEY, true);

    $order->update_meta_data(self::ORDER_REMOTE_STATUS_META_KEY, $payment->status);
    $order->update_meta_data(self::ORDER_LAST_SYNC_AT_META_KEY, current_time('mysql', true));

    if (!$order->get_transaction_id()) {
      $order->set_transaction_id($payment->id);
    }

    $this->sync_order_payment_features($order, $payment, $context);

    if ($payment->status === 'AUTHORIZED') {
      if (!$order->get_meta(self::ORDER_AUTHORIZED_AT_META_KEY, true)) {
        $order->update_meta_data(self::ORDER_AUTHORIZED_AT_META_KEY, current_time('mysql', true));
      }

      if ($order->get_status() !== 'on-hold' && !$order->has_status(wc_get_is_paid_statuses())) {
        $order->update_status('on-hold');
      }

      if ($previousRemoteStatus !== 'AUTHORIZED') {
        $order->add_order_note(sprintf(__('Satispay payment authorized and waiting for capture (%s).', 'woo-satispay'), $context));
      }
    }

    if ($payment->status === 'ACCEPTED') {
      if (!$order->has_status(wc_get_is_paid_statuses())) {
        $order->payment_complete($payment->id);
        $capturedAmount = isset($payment->amount_unit) ? wc_price($payment->amount_unit / 100, array('currency' => $order->get_currency())) : '';
        $order->add_order_note(
          $capturedAmount
            ? sprintf(__('Satispay payment captured for %1$s (%2$s).', 'woo-satispay'), $capturedAmount, $context)
            : sprintf(__('Satispay payment captured (%s).', 'woo-satispay'), $context)
        );
      }
    }

    if ($payment->status === 'CANCELED') {
      if (!$order->has_status('cancelled')) {
        $order->update_status('cancelled');
        $order->add_order_note(sprintf(__('Satispay payment canceled (%s).', 'woo-satispay'), $context));
      }
    }

    $order->save();
  }

  private function sync_order_payment_features($order, $payment, $context) {
    $paymentFeatures = $this->extract_payment_features($payment);

    if (!$paymentFeatures['has_special_payment']) {
      return;
    }

    $previousLabel = (string) $order->get_meta(self::ORDER_PAYMENT_TYPE_LABEL_META_KEY, true);
    $previousFringeAmountUnit = $order->get_meta(self::ORDER_FRINGE_AMOUNT_UNIT_META_KEY, true);

    $order->update_meta_data(self::ORDER_PAYMENT_TYPE_META_KEY, $paymentFeatures['type']);
    $order->update_meta_data(self::ORDER_PAYMENT_TYPE_LABEL_META_KEY, $paymentFeatures['label']);
    $order->update_meta_data(self::ORDER_IS_BNPL_META_KEY, $paymentFeatures['is_bnpl'] ? 'yes' : 'no');
    $order->update_meta_data(self::ORDER_IS_FRINGE_BENEFITS_META_KEY, $paymentFeatures['is_fringe_benefits'] ? 'yes' : 'no');

    if (null !== $paymentFeatures['fringe_amount_unit']) {
      $order->update_meta_data(self::ORDER_FRINGE_AMOUNT_UNIT_META_KEY, $paymentFeatures['fringe_amount_unit']);
    }

    if ($previousLabel !== $paymentFeatures['label'] || (string) $previousFringeAmountUnit !== (string) $paymentFeatures['fringe_amount_unit']) {
      $note = sprintf(
        __('Satispay special payment details detected: %1$s (%2$s).', 'woo-satispay'),
        $paymentFeatures['label'],
        $context
      );

      if ($paymentFeatures['is_fringe_benefits'] && null !== $paymentFeatures['fringe_amount_unit']) {
        $note = sprintf(
          __('Satispay special payment details detected: %1$s. Fringe benefits used: %2$s (%3$s).', 'woo-satispay'),
          $paymentFeatures['label'],
          wp_strip_all_tags($this->format_amount_from_unit($paymentFeatures['fringe_amount_unit'], $order->get_currency())),
          $context
        );
      }

      $order->add_order_note($note);
    }
  }

  private function extract_payment_features($payment) {
    $isBnpl = true === $this->get_nested_value($payment, array('payment_options', 'buy_now_pay_later', 'is_buy_now_pay_later'));
    $fringeAmountUnit = $this->get_nested_value($payment, array('payment_method', 'welfare_fringe', 'amount_unit'));
    $hasFringeBenefits = null !== $fringeAmountUnit && '' !== $fringeAmountUnit;

    if ($hasFringeBenefits) {
      $fringeAmountUnit = (int) $fringeAmountUnit;
    } else {
      $fringeAmountUnit = null;
    }

    $type = '';
    if ($hasFringeBenefits) {
      $type = 'fringe_benefits';
    } elseif ($isBnpl) {
      $type = 'bnpl';
    }

    return array(
      'type' => $type,
      'label' => $this->get_payment_features_label($isBnpl, $hasFringeBenefits),
      'is_bnpl' => $isBnpl,
      'is_fringe_benefits' => $hasFringeBenefits,
      'fringe_amount_unit' => $fringeAmountUnit,
      'has_special_payment' => $isBnpl || $hasFringeBenefits,
    );
  }

  private function get_payment_features_label($isBnpl, $hasFringeBenefits) {
    if ($isBnpl && $hasFringeBenefits) {
      return __('Satispay BNPL + fringe benefits', 'woo-satispay');
    }

    if ($hasFringeBenefits) {
      return __('Satispay fringe benefits', 'woo-satispay');
    }

    if ($isBnpl) {
      return __('Satispay BNPL', 'woo-satispay');
    }

    return '';
  }

  private function get_nested_value($source, $path) {
    $value = $source;

    foreach ($path as $segment) {
      if (is_array($value) && array_key_exists($segment, $value)) {
        $value = $value[$segment];
        continue;
      }

      if (is_object($value) && isset($value->{$segment})) {
        $value = $value->{$segment};
        continue;
      }

      return null;
    }

    return $value;
  }

  private function get_order_payment_features($order): array
  {
    $isBnpl = $order->get_meta(self::ORDER_IS_BNPL_META_KEY, true) === 'yes';
    $isFringeBenefits = $order->get_meta(self::ORDER_IS_FRINGE_BENEFITS_META_KEY, true) === 'yes';
    $fringeAmountUnit = $order->get_meta(self::ORDER_FRINGE_AMOUNT_UNIT_META_KEY, true);

    if ($fringeAmountUnit === '') {
      $fringeAmountUnit = null;
    } elseif (null !== $fringeAmountUnit) {
      $fringeAmountUnit = (int) $fringeAmountUnit;
    }

    return array(
      'type' => (string) $order->get_meta(self::ORDER_PAYMENT_TYPE_META_KEY, true),
      'label' => (string) $order->get_meta(self::ORDER_PAYMENT_TYPE_LABEL_META_KEY, true),
      'is_bnpl' => $isBnpl,
      'is_fringe_benefits' => $isFringeBenefits,
      'fringe_amount_unit' => $fringeAmountUnit,
      'has_special_payment' => $isBnpl || $isFringeBenefits,
    );
  }

  private function render_order_payment_features_section($order) {
    if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->id) {
      return;
    }

    $paymentFeatures = $this->get_order_payment_features($order);

    if (!$paymentFeatures['has_special_payment']) {
      return;
    }

    echo '<section class="woocommerce-satispay-payment-details">';
    echo '<h2 class="woocommerce-order-details__title">' . esc_html__('Satispay payment details', 'woo-satispay') . '</h2>';
    echo '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details satispay-payment-details">';

    if (!empty($paymentFeatures['label'])) {
      echo '<li class="woocommerce-order-overview__payment-method method">';
      echo esc_html__('Payment solution:', 'woo-satispay') . ' ';
      echo '<strong>' . esc_html($paymentFeatures['label']) . '</strong>';
      echo '</li>';
    }

    if ($paymentFeatures['is_fringe_benefits'] && null !== $paymentFeatures['fringe_amount_unit']) {
      echo '<li class="woocommerce-order-overview__payment-method method">';
      echo esc_html__('Fringe benefits used:', 'woo-satispay') . ' ';
      echo '<strong>' . wp_kses_post($this->format_amount_from_unit($paymentFeatures['fringe_amount_unit'], $order->get_currency())) . '</strong>';
      echo '</li>';
    }

    echo '</ul>';
    echo '</section>';
  }

  private function format_amount_from_unit($amountUnit, $currency) {
    return wc_price(((int) $amountUnit) / 100, array('currency' => $currency));
  }

  private function is_checkout_currency_supported($order = null) {
    return $this->get_checkout_currency($order) === self::SUPPORTED_CURRENCY;
  }

  private function get_checkout_currency($order = null) {
    if ($order instanceof WC_Order) {
      return strtoupper((string) $order->get_currency());
    }

    $contextOrder = $this->get_current_checkout_order();

    if ($contextOrder instanceof WC_Order) {
      return strtoupper((string) $contextOrder->get_currency());
    }

    return strtoupper((string) get_woocommerce_currency());
  }

  private function get_current_checkout_order() {
    $orderId = (int) filter_input(INPUT_GET, 'order-pay', FILTER_SANITIZE_NUMBER_INT);

    if ($orderId <= 0) {
      return null;
    }

    $order = wc_get_order($orderId);

    return $order instanceof WC_Order ? $order : null;
  }

  private function store_checkout_cart_snapshot($order, $paymentId = '') {
    if (!WC()->session || !WC()->cart || !$order instanceof WC_Order) {
      return;
    }

    WC()->session->set(
      self::SESSION_CART_SNAPSHOT_KEY,
      array(
        'order_id' => $order->get_id(),
        'payment_id' => (string) $paymentId,
        'cart' => WC()->cart->get_cart_for_session(),
        'applied_coupons' => WC()->cart->get_applied_coupons(),
      )
    );
  }

  private function restore_checkout_cart_snapshot($order = null) {
    if (!WC()->session || !WC()->cart) {
      return false;
    }

    $snapshot = WC()->session->get(self::SESSION_CART_SNAPSHOT_KEY);

    if (empty($snapshot['cart']) || !is_array($snapshot['cart'])) {
      return false;
    }

    if ($order instanceof WC_Order && !empty($snapshot['order_id']) && (int) $snapshot['order_id'] !== (int) $order->get_id()) {
      return false;
    }

    WC()->cart->empty_cart();

    foreach ($snapshot['cart'] as $cartItem) {
      if (!is_array($cartItem)) {
        continue;
      }

      $productId = isset($cartItem['product_id']) ? (int) $cartItem['product_id'] : 0;
      $quantity = isset($cartItem['quantity']) ? (int) $cartItem['quantity'] : 0;
      $variationId = isset($cartItem['variation_id']) ? (int) $cartItem['variation_id'] : 0;
      $variation = isset($cartItem['variation']) && is_array($cartItem['variation']) ? $cartItem['variation'] : array();

      if ($productId <= 0 || $quantity <= 0) {
        continue;
      }

      $cartItemData = $cartItem;
      unset(
        $cartItemData['key'],
        $cartItemData['product_id'],
        $cartItemData['variation_id'],
        $cartItemData['variation'],
        $cartItemData['quantity'],
        $cartItemData['data'],
        $cartItemData['data_hash'],
        $cartItemData['line_total'],
        $cartItemData['line_tax'],
        $cartItemData['line_subtotal'],
        $cartItemData['line_subtotal_tax'],
        $cartItemData['line_tax_data']
      );

      WC()->cart->add_to_cart($productId, $quantity, $variationId, $variation, $cartItemData);
    }

    if (!empty($snapshot['applied_coupons']) && is_array($snapshot['applied_coupons'])) {
      foreach ($snapshot['applied_coupons'] as $couponCode) {
        WC()->cart->apply_coupon($couponCode);
      }
    }

    WC()->cart->calculate_totals();
    $this->clear_checkout_cart_snapshot();

    return true;
  }

  private function clear_checkout_cart_snapshot() {
    if (!WC()->session) {
      return;
    }

    WC()->session->set(self::SESSION_PAYMENT_ID_KEY, null);
    WC()->session->set(self::SESSION_CART_SNAPSHOT_KEY, null);
  }

  private function get_unsupported_currency_message($currency = null): string
  {
    $currency = strtoupper((string) ($currency ?: $this->get_checkout_currency()));

    return sprintf(
      __('Satispay is available only for orders in %1$s. Your current order currency is %2$s. Please choose a different payment method to complete your purchase.', 'woo-satispay'),
      self::SUPPORTED_CURRENCY,
      $currency
    );
  }

  /**
   * Get the start criteria for the scheduled datetime
  */
  private function get_start_date_scheduled_time()
  {
    $maxHours = $this->get_option('finalizeMaxHours');
    $now = new \DateTime('now', new DateTimeZone('UTC'));
    $scheduledTimeFrame = $maxHours;
    if (is_null($scheduledTimeFrame) || $scheduledTimeFrame == 0 || $scheduledTimeFrame < 0) {
      $scheduledTimeFrame = 4; // DEFAULT_MAX_HOURS
    }
    $tosub = new \DateInterval('PT'. $scheduledTimeFrame . 'H');
    return strtotime( $now->sub($tosub)->format('Y-m-d H:i:s'));
  }

  /**
   * Get the end criteria for the scheduled datetime
  */
  private function get_end_date_scheduled_time()
  {
    $now = new \DateTime('now', new DateTimeZone('UTC'));
    // remove just 1 hour so normal transactions can still be processed
    $tosub = new \DateInterval('PT'. 1 . 'H');
    return strtotime($now->sub($tosub)->format('Y-m-d H:i:s'));
  }

    /**
     * Get current gateway environment.
     *
     * @return string
     */
    private function get_environment() {
        return $this->get_option('sandbox') === 'yes' ? 'staging' : 'live';
    }

    /**
     * Get the Satispay environment for an order.
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    private function get_order_environment($order) {
        $environment = $order->get_meta(self::ORDER_ENVIRONMENT_META_KEY, true);

        if (!empty($environment)) {
            return $environment;
        }

        return $this->get_environment();
    }

    /**
     * Get transaction dashboard URL base for an order.
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    private function get_transaction_url_base($order) {
        return $this->get_order_environment($order) === 'staging'
            ? self::TRANSACTION_URL_STAGING
            : self::TRANSACTION_URL_LIVE;
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_abspath() {
        return trailingslashit( plugin_dir_path( __FILE__ ) );
    }

    /**
     * Plugin url.
     *
     * @return string
     */
    public static function plugin_url() {
        return untrailingslashit( plugins_url( '/', __FILE__ ) );
    }

    /**
     * Check if method has been added correctly
     *
     * @param array
     * @return array
     */
    public function check_gateway($gateways)
    {
        if (isset($gateways[$this->id])) {
            return $gateways;
        }
        if ($this->is_available()) {
            $gateways[$this->id] = $this;
        }

        return $gateways;
    }

}

function wc_satispay_finalize_orders()
{
  $model = new WC_Satispay();
  $model->finalize_orders();
}