<?php
// Jedda M10 — Buy Now (theme direct-checkout, classic full-page add) goes straight to /checkout/.
// [snippet 14045, code_type: php]
// IMPORTANT: skip AJAX 'Add to Cart' (XHR) so the drawer flow stays intact — only the
// classic full-page Buy Now submit should redirect. XHR adds must return the normal URL.
add_filter( 'woocommerce_add_to_cart_redirect', function ( $url ) {
    $is_xhr = ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) === 'xmlhttprequest' )
           || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
           || isset( $_REQUEST['wc-ajax'] );
    if ( $is_xhr ) { return $url; }
    if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() && function_exists( 'wc_get_checkout_url' ) ) {
        return wc_get_checkout_url();
    }
    return $url;
}, 20 );
