<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Automattic\WooCommerce\Blocks\Payments\PaymentContext;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Satispay_Blocks class.
 *
 * @extends AbstractPaymentMethodType
 */
final class WC_Satispay_Blocks extends AbstractPaymentMethodType {

    /**
     * The gateway instance.
     *
     * @var WC_Satispay
     */
    private $gateway;

    /**
     * Payment method name defined by payment methods extending this class.
     *
     * @var string
     */
    protected $name = 'satispay';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_satispay_settings', [] );
        $this->gateway  = new WC_Satispay();
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_path       = '/assets/js/frontend/blocks.js';
        $script_asset_path = WC_Satispay::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require( $script_asset_path )
            : array(
                'dependencies' => array(),
                'version'      => '1.2.0'
            );
        $script_url        = WC_Satispay::plugin_url() . $script_path;

        wp_register_script(
            'wc-satispay-payments-blocks',
            $script_url,
            $script_asset[ 'dependencies' ],
            $script_asset[ 'version' ],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'wc-satispay-payments-blocks', 'woo-satispay', WC_Satispay::plugin_abspath());
        }

        return [ 'wc-satispay-payments-blocks' ];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $logger  = wc_get_logger();
        $context = array( 'source Log' => 'Satispay' );
        $logger->error( json_encode($this->gateway), $context );
        return [
            'title'       => $this->gateway->title,
            'description' => $this->gateway->description,
            'icon' => $this->gateway->icon,
            'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] )
        ];
    }
}