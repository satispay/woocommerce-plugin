(function ($) {
  'use strict';

  function handleAction(action, orderId, button) {
    var confirmMsg = action === 'capture'
      ? satispay_admin.confirm_capture
      : satispay_admin.confirm_cancel;

    if (!confirm(confirmMsg)) {
      return;
    }

    var $btn = $(button);
    $btn.prop('disabled', true);

    $.post(satispay_admin.ajax_url, {
      action: 'satispay_' + action + '_payment',
      order_id: orderId,
      security: satispay_admin.nonce
    })
    .done(function (response) {
      if (response.success) {
        location.reload();
      } else {
        alert(response.data && response.data.message ? response.data.message : 'Error');
        $btn.prop('disabled', false);
      }
    })
    .fail(function () {
      alert('Request failed.');
      $btn.prop('disabled', false);
    });
  }

  $(document).on('click', '.satispay-capture-payment', function () {
    handleAction('capture', $(this).data('order-id'), this);
  });

  $(document).on('click', '.satispay-cancel-payment', function () {
    handleAction('cancel', $(this).data('order-id'), this);
  });
})(jQuery);

