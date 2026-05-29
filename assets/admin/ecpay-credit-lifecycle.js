/**
 * 綠界信用卡交易動作 meta box — 客戶端 AJAX 觸發。
 *
 * 流程：
 *   1. 預設顯示「查詢交易狀態」按鈕
 *   2. 點擊 → AJAX query → render 狀態 + 動態按鈕（依 ECPay 回傳的 Status）
 *   3. 點 [取消授權 / 請款 / 退款] → confirm dialog → AJAX action → 重 query 刷新
 *
 * 對標 RY pro pattern，但走 mowp 自己的 nonce / capability check。
 */
(function ($) {
  'use strict';

  if (typeof moEcpayCreditLifecycle === 'undefined' || !moEcpayCreditLifecycle.ajaxUrl) {
    return;
  }

  const i18n = moEcpayCreditLifecycle.i18n || {};

  function ajax(action, payload) {
    return $.ajax({
      url: moEcpayCreditLifecycle.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: $.extend({ action: action }, payload),
    });
  }

  function getRoot(el) {
    return $(el).closest('.mo-ecpay-credit-lifecycle');
  }

  function setBusy($root, busy) {
    $root.find('button').prop('disabled', !!busy);
  }

  function showError($root, msg) {
    $root.find('.mo-ecpay-credit-lifecycle__error').remove();
    $root.append(
      $('<p>')
        .addClass('mo-ecpay-credit-lifecycle__error')
        .css({ color: '#d63638', margin: '8px 0 0', fontSize: '12px' })
        .text((i18n.genericErr || 'Error: ') + msg)
    );
  }

  function refresh($root) {
    const orderId = $root.data('order-id');
    const nonce = $root.data('nonce');
    setBusy($root, true);
    $root.find('.mo-ecpay-credit-lifecycle__error').remove();
    return ajax('mo_ecpay_credit_query', { order_id: orderId, nonce: nonce })
      .done(function (resp) {
        if (resp && resp.success && resp.data && resp.data.html) {
          $root.html(resp.data.html);
        } else {
          showError($root, (resp && resp.data && resp.data.message) || '');
        }
      })
      .fail(function () {
        showError($root, 'network');
      })
      .always(function () {
        setBusy($root, false);
      });
  }

  // 點「查詢交易狀態」/「重新查詢」 → query
  $(document).on('click', '.mo-ecpay-credit-lifecycle__refresh', function (e) {
    e.preventDefault();
    refresh(getRoot(this));
  });

  // 點 [取消授權 / 請款 / 退款] → confirm + AJAX action
  $(document).on('click', '.mo-ecpay-credit-lifecycle__action', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const $root = getRoot($btn);
    const action = $btn.data('action');
    const orderId = $root.data('order-id');
    const nonce = $root.data('nonce');

    let amount = 0;
    let confirmMsg = '';
    if ('R' === action) {
      amount = parseInt($root.find('.mo-ecpay-credit-lifecycle__amount').val(), 10) || 0;
      if (amount <= 0) {
        window.alert(i18n.refundAmtErr || 'Amount must be > 0');
        return;
      }
      confirmMsg = (i18n.refundConfirm || 'Refund? ') + 'NT$' + amount;
    } else if ('N' === action) {
      confirmMsg = i18n.cancelConfirm || 'Cancel authorization?';
    } else if ('C' === action) {
      confirmMsg = i18n.closureConfirm || 'Capture?';
    }
    if (!window.confirm(confirmMsg)) {
      return;
    }

    setBusy($root, true);
    $root.find('.mo-ecpay-credit-lifecycle__error').remove();

    ajax('mo_ecpay_credit_action', {
      order_id: orderId,
      nonce: nonce,
      credit_action: action,
      amount: amount,
    })
      .done(function (resp) {
        if (resp && resp.success && resp.data && resp.data.html) {
          $root.html(resp.data.html);
        } else {
          showError($root, (resp && resp.data && resp.data.message) || '');
        }
      })
      .fail(function () {
        showError($root, 'network');
      })
      .always(function () {
        setBusy($root, false);
      });
  });
})(jQuery);
