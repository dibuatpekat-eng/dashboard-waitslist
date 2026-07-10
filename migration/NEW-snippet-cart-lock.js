// Jedda — Cart Lock Notice (PROACTIVE, bidirectional / safe)  [snippet 13996, code_type: js]
// Item undangan (private) HARUS dibeli sendirian (cart campur private+reguler tidak bisa checkout).
// Snippet ini mencegah dari awal & memberi feedback. Aman: hanya GET fetch cart Store API,
// TIDAK membungkus XMLHttpRequest (versi lama merusak add-to-cart).
(function () {
  var REG_MSG        = 'You have a reserved invitation item in your cart. Please complete that order first before adding other products.';
  var PRIV_EMPTY_MSG = 'Please empty your cart first — your invitation item must be ordered on its own.';
  var PRIV_DUP_MSG   = 'This invitation item is already in your cart. Please proceed to checkout.';

  function currentTitle() {
    return ((document.querySelector('.product_title, .de-product-single__title, h1') || {}).textContent || '').trim();
  }
  function isPrivatePage() {
    if (/jd_token=/.test(location.search)) return true;
    return /\(private\)/i.test(currentTitle());
  }
  function lockUI(msg) {
    var add = document.querySelector('.single_add_to_cart_button');
    var buy = document.querySelector('.de-single-direct-checkout');
    [add, buy].forEach(function (el) {
      if (!el) return;
      el.style.setProperty('pointer-events', 'none', 'important');
      el.style.setProperty('opacity', '.4', 'important');
      el.style.setProperty('cursor', 'not-allowed', 'important');
    });
    var n = document.getElementById('jd-cartlock-notice');
    if (!n) {
      n = document.createElement('div');
      n.id = 'jd-cartlock-notice';
      n.style.cssText = 'margin:0 0 14px;padding:12px 16px;background:#faf8f5;border-left:3px solid #9f4d3f;color:#333;font-family:Jost,sans-serif;font-size:12.5px;line-height:1.55;';
      // taruh TEPAT di atas tombol Add to Cart
      var anchor = add ? (add.closest('.woocommerce-variation-add-to-cart') || add) : buy;
      if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(n, anchor);
    }
    n.textContent = msg;
  }
  function check() {
    if (!document.querySelector('.single_add_to_cart_button, .de-single-direct-checkout')) return;
    var priv = isPrivatePage();
    var title = currentTitle();
    fetch('/wp-json/wc/store/v1/cart', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (c) {
        var items = c.items || [];
        if (priv) {
          if (items.length === 0) return; // cart kosong -> boleh tambah item undangan
          var onlyThis = items.every(function (it) { return (it.name || '').indexOf(title) === 0; });
          lockUI(onlyThis ? PRIV_DUP_MSG : PRIV_EMPTY_MSG);
        } else {
          var hasPrivate = items.some(function (it) { return /\(private\)/i.test(it.name || ''); });
          if (hasPrivate) lockUI(REG_MSG);
        }
      })
      .catch(function () {});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check);
  else check();
})();
