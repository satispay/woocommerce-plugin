<?php
/**
 * Render template for the "Satispay Payment Methods" block.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner content (unused – no innerBlocks).
 * @var WP_Block $block      Block instance.
 */

$label      = isset( $attributes['label'] ) ? $attributes['label'] : __( 'We accept Satispay', 'woo-satispay' );
$show_label = isset( $attributes['showLabel'] ) ? (bool) $attributes['showLabel'] : true;
$inline_content = isset( $attributes['inlineContent'] ) ? (bool) $attributes['inlineContent'] : false;
$text_align = isset( $attributes['textAlign'] ) ? $attributes['textAlign'] : 'left';
$text_align = in_array( $text_align, array( 'left', 'center', 'right' ), true ) ? $text_align : 'left';
$logo_width = isset( $attributes['logoWidth'] ) ? absint( $attributes['logoWidth'] ) : 60;
$logo_width = min( max( $logo_width, 40 ), 400 );

$main_plugin_file = defined( 'WC_SATISPAY_MAIN_FILE' ) ? WC_SATISPAY_MAIN_FILE : dirname( __DIR__, 2 ) . '/woo-satispay.php';
$logo_url         = plugins_url( 'logo.svg', $main_plugin_file );

$inline_style = 'text-align:' . esc_attr( $text_align ) . ';';
$content_style = $inline_content
	? 'display:inline-flex;align-items:center;gap:8px;text-align:left;'
	: 'display:inline-block;';

$wrapper_attributes = get_block_wrapper_attributes( [
	'class' => 'woo-satispay-payment-badge',
	'style' => $inline_style,
] );
?>
<div <?php echo $wrapper_attributes; ?>>
	<div class="woo-satispay-payment-badge__content" style="<?php echo esc_attr( $content_style ); ?>">
		<img
			src="<?php echo esc_url( $logo_url ); ?>"
			alt="<?php esc_attr_e( 'Satispay', 'woo-satispay' ); ?>"
			class="woo-satispay-payment-badge__logo"
			loading="lazy"
			decoding="async"
			style="width: <?php echo esc_attr( $logo_width ); ?>px; height: auto; display: <?php echo esc_attr( $inline_content ? 'inline-block' : 'block' ); ?>;"
		/>
		<?php if ( $show_label && '' !== $label ) : ?>
			<p class="woo-satispay-payment-badge__label" style="margin: <?php echo esc_attr( $inline_content ? '0' : '8px 0 0' ); ?>;"><?php echo esc_html( $label ); ?></p>
		<?php endif; ?>
	</div>
</div>



