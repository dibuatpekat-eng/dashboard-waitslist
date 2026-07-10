<?php
/**
 * Jedda — Product Stock & Button State  (snippet 13979, menggantikan 2384)
 * Type: PHP snippet, Auto Insert, Run Everywhere (output di wp_footer, priority 20).
 *
 *  - PRIVATE (jacket-access) → TIDAK disentuh.
 *  - WAITLIST (Rhea/Phi) habis → semua swatch dicoret + non-clickable; Buy Now jadi
 *    "JOIN WAITLIST" (→ /early-access/), Add-to-Cart "Out of Stock". (jika WAITLIST_LIVE=true.)
 *  - REGULER habis TOTAL → semua opsi dicoret + non-clickable; 1 tombol "OUT OF STOCK".
 *  - REGULER sebagian habis → hanya opsi tak tersedia (selection-aware) dicoret; tombol normal.
 *
 * PENTING (pelajaran 2026-07-08): coretan DITERAPKAN via <style> GLOBAL (targeting data-value),
 * BUKAN dengan menambah class/style ke <li> swatch. Kalau <li> dimutasi, plugin WooCommerce
 * Variation Swatches ikut re-render → variasi ke-reset → ADD TO CART GAGAL/nyangkut.
 * Re-apply dipicu event variasi WooCommerce (bukan MutationObserver luas, yang juga bikin
 * add-to-cart flaky).
 *
 * PRODUCTION: ganti WAITLIST_IDS & PRIVATE_IDS ke ID prod. WAITLIST_LIVE = trigger go-live.
 */
add_action( 'wp_footer', function () {
	?>
	<script>
	(function () {
		var WAITLIST_LIVE = true;
		var WAITLIST_IDS  = ['13006', '13042'];
		var PRIVATE_IDS   = ['13934', '13937', '13939'];
		var WAITLIST_URL  = '/early-access/';

		function parseVariations(form) {
			var raw = form.getAttribute('data-product_variations');
			if (!raw || raw === 'false') return null;
			try { return JSON.parse(raw); } catch (e) { return null; }
		}
		function anyInStock(v) { return v.some(function (x) { return x.is_in_stock; }); }
		function readSelection() {
			var sel = {};
			var ss = document.querySelectorAll('form.variations_form select.woo-variation-raw-select');
			for (var i = 0; i < ss.length; i++) { sel[ss[i].getAttribute('data-attribute_name')] = ss[i].value; }
			return sel;
		}
		function optionAvailable(v, attrName, val, sel) {
			return v.some(function (x) {
				if (!x.is_in_stock) return false;
				var a = x.attributes[attrName];
				if (!(a === val || a === '')) return false;
				for (var k in sel) {
					if (k === attrName) continue;
					var chosen = sel[k];
					if (!chosen) continue;
					var xa = x.attributes[k];
					if (!(xa === chosen || xa === '')) return false;
				}
				return true;
			});
		}
		// Coret opsi via <style> GLOBAL — TIDAK memutasi <li> swatch (lihat catatan di header).
		function setOOSStyle(list) {
			var st = document.getElementById('jd-oos-rules');
			if (!st) { st = document.createElement('style'); st.id = 'jd-oos-rules'; document.head.appendChild(st); }
			var css = '';
			for (var i = 0; i < list.length; i++) {
				var base = 'ul.variable-items-wrapper[data-attribute_name="' + list[i].attr + '"] li.variable-item[data-value="' + list[i].val + '"]';
				css += base + '{cursor:not-allowed !important;pointer-events:none !important;}';
				css += base + ' .variable-item-span{position:relative;color:#b3b3b3 !important;}';
				css += base + ' .variable-item-span::after{content:"";position:absolute;left:-2px;right:-2px;top:0;bottom:0;pointer-events:none;background:linear-gradient(to top right, transparent calc(50% - 0.6px), #8f8f8f calc(50% - 0.6px), #8f8f8f calc(50% + 0.6px), transparent calc(50% + 0.6px));}';
			}
			st.textContent = css;
		}
		function transformBuy(text, asWaitlist) {
			var buy = document.querySelector('.de-single-direct-checkout');
			if (!buy) return;
			var flag = asWaitlist ? 'jdWaitlist' : 'jdOos';
			if (buy.dataset[flag] === '1') return;
			var f = buy.cloneNode(true);
			buy.parentNode.replaceChild(f, buy);
			f.type = 'button'; f.textContent = text; f.dataset[flag] = '1'; f.style.width = '100%';
			if (asWaitlist) {
				f.disabled = false;
				f.addEventListener('click', function (e) { e.preventDefault(); window.location.href = WAITLIST_URL; });
			} else {
				f.disabled = true; f.className = 'de-btn de-btn--boxed'; f.style.opacity = '.55'; f.style.cursor = 'not-allowed';
			}
		}
		function apply(mode, v) {
			var soldOut = !anyInStock(v);
			var sel = readSelection();
			var list = [];
			var uls = document.querySelectorAll('ul.variable-items-wrapper');
			for (var u = 0; u < uls.length; u++) {
				var attrName = uls[u].getAttribute('data-attribute_name');
				var lis = uls[u].querySelectorAll('li.variable-item');
				for (var i = 0; i < lis.length; i++) {
					var val = lis[i].getAttribute('data-value');
					if (!optionAvailable(v, attrName, val, sel)) list.push({ attr: attrName, val: val });
				}
			}
			setOOSStyle(list);
			if (!soldOut) return;
			var add = document.querySelector('.single_add_to_cart_button');
			if (mode === 'waitlist') { transformBuy('JOIN WAITLIST', true); if (add) add.textContent = 'Out of Stock'; }
			else { if (add) add.style.setProperty('display', 'none', 'important'); transformBuy('OUT OF STOCK', false); }
		}
		function init() {
			var form = document.querySelector('form.variations_form');
			if (!form) return;
			var pid = form.getAttribute('data-product_id');
			if (PRIVATE_IDS.indexOf(pid) !== -1) return;
			var mode = (WAITLIST_LIVE && WAITLIST_IDS.indexOf(pid) !== -1) ? 'waitlist' : 'regular';
			var v = parseVariations(form);
			if (!v) return;
			apply(mode, v);
			if (window.jQuery) {
				window.jQuery(form).on('woocommerce_variation_has_changed show_variation hide_variation reset_data check_variations', function () { apply(mode, v); });
			} else {
				form.addEventListener('change', function () { apply(mode, v); });
			}
			[150, 500, 1200].forEach(function (ms) { setTimeout(function () { apply(mode, v); }, ms); });
			// jd-clear-stuck-loading: WooCommerce leaves the add-to-cart button in .loading state
			// after the "select options" alert when no variation is chosen — clear it.
			form.addEventListener('click', function (e) {
				var btn = e.target && e.target.closest ? e.target.closest('.single_add_to_cart_button') : null;
				if (!btn) return;
				var vi = form.querySelector('input.variation_id');
				if (!vi || !vi.value || vi.value === '0') {
					setTimeout(function () { btn.classList.remove('loading'); }, 30);
					setTimeout(function () { btn.classList.remove('loading'); }, 500);
				}
			}, true);
		}
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
		else init();
	})();
	</script>
	<?php
}, 20 );
