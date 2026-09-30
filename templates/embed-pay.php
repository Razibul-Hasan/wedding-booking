<?php
/**
 * Chrome-less order-pay page, loaded inside the booking form's payment
 * iframe (?wedding_booking_embed=1 on the order-pay endpoint). Gateway scripts load
 * through wp_head()/wp_footer() exactly as on the normal checkout page,
 * so PayPal / card gateways render their own secure fields here.
 */

defined('ABSPATH') || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php wp_head(); ?>
    <style>
        html {
            margin-top: 0 !important;
        }

        body.wbook-embed-pay {
            margin: 0;
            padding: 0.9rem;
            background: #fff;
        }

        /* The booking summary is already shown in step 4 of the booking
           form — the pay page's order review table would duplicate it. */
        .wbook-embed-pay-content #order_review>table.shop_table {
            display: none !important;
        }

        /* Floating UI injected by themes/plugins (admin bar, back-to-top,
           chat bubbles) does not belong inside the payment frame. */
        #wpadminbar,
        .back-to-top,
        #back-to-top,
        .scroll-to-top,
        #scroll-top,
        #toTop,
        .go-top,
        .goto-top,
        .scrollup,
        .scroll-up,
        .elementor-scroll-to-top,
        .joinchat,
        .ht-ctc,
        .qlwapp,
        .wa-chat-box,
        [class*="whatsapp"],
        [id*="whatsapp"] {
            display: none !important;
        }
    </style>
</head>

<body <?php body_class('wbook-embed-pay'); ?>>
    <div class="woocommerce wbook-embed-pay-content">
        <?php echo do_shortcode('[woocommerce_checkout]'); ?>
    </div>
    <?php wp_footer(); ?>
    <script>
        (function() {
            // Catch-all for floating widgets the CSS above doesn't know:
            // hide fixed/sticky elements outside the payment content.
            // Runs only around page load, so payment overlays injected
            // later (PayPal popups, 3-D Secure) are never touched.
            function sweep() {
                var content = document.querySelector(".wbook-embed-pay-content");
                var allow = /paypal|ppcp|stripe|klarna|payment|checkout|woocommerce|blockUI/i;
                Array.prototype.forEach.call(
                    document.body.getElementsByTagName("*"),
                    function(el) {
                        if (content && (content.contains(el) || el.contains(content))) {
                            return;
                        }
                        var sig = (el.id || "") + " " + (el.getAttribute("class") || "");
                        if (allow.test(sig)) {
                            return;
                        }
                        var pos = window.getComputedStyle(el).position;
                        if (pos === "fixed" || pos === "sticky") {
                            el.style.setProperty("display", "none", "important");
                        }
                    }
                );
            }
            window.addEventListener("load", function() {
                sweep();
                setTimeout(sweep, 800); // widgets that inject themselves after load
            });
        })();
    </script>
</body>

</html>
