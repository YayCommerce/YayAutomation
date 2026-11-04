<?php
/**
 * Just enough WooCommerce and WordPress for the real data items to read an order.
 *
 * @package YayMatik
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO.Mixed, Squiz.Classes.ClassFileName.NoMatch -- one file of stand-ins with WooCommerce's names and signatures.

if ( ! class_exists( 'WC_Order_Item' ) ) {
    /** An order line; the tests' orders have none. */
    class WC_Order_Item {}
}

if ( ! class_exists( 'WC_Order' ) ) {
    /** An order from an array of getter values. */
    class WC_Order {

        /** @var array */
        public $data;

        public function __construct( array $data = [] ) {
            $this->data = $data;
        }

        public function __call( $name, $args ) {
            $key = substr( $name, 4 );
            return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : '';
        }

        public function get_data_store() {
            return new class() {
                public function get_internal_meta_keys() {
                    return [ '_order_total', '_billing_email' ];
                }
            };
        }

        public function get_date_created() {
            return $this->data['date_created'] ?? null;
        }

        public function get_items() {
            return $this->data['line_items'] ?? [];
        }

        public function get_order_item_totals() {
            return [
                [
                    'label' => 'Total:',
                    'value' => '$' . $this->data['total'],
                ],
            ];
        }
    }
}//end if

if ( ! class_exists( 'WC_Customer' ) ) {
    /** An account from an array of getter values. */
    class WC_Customer {

        /** @var array */
        public $data;

        public function __construct( array $data ) {
            $this->data = $data;
        }

        public function __call( $name, $args ) {
            $key = substr( $name, 4 );
            return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : '';
        }
    }
}

if ( ! class_exists( 'WC_Product' ) ) {
    /** A product from an array of getter values. */
    class WC_Product {

        /** @var array */
        public $data;

        public function __construct( array $data ) {
            $this->data = $data;
        }

        public function __call( $name, $args ) {
            $key = substr( $name, 4 );
            return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : '';
        }
    }
}

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
    /**
     * Images by attachment id, as `[ url, width, height ]`: `$GLOBALS['yaymatik_test_images']`.
     *
     * @param int    $attachment_id
     * @param string $size
     */
    function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail' ) {
        return $GLOBALS['yaymatik_test_images'][ $attachment_id ] ?? false;
    }
}

if ( ! function_exists( 'get_userdata' ) ) {
    /**
     * The tests' sites have no users.
     *
     * @param int $user_id
     */
    function get_userdata( $user_id ) {
        return false;
    }
}

if ( ! function_exists( 'get_comments' ) ) {
    /**
     * Records the query in `$GLOBALS['yaymatik_test_comment_queries']`; finds nothing.
     *
     * @param array $args
     */
    function get_comments( $args = [] ) {
        $GLOBALS['yaymatik_test_comment_queries'][] = $args;
        return [];
    }
}

if ( ! function_exists( 'wc_get_image_size' ) ) {
    function wc_get_image_size( $image_size ) {
        return [
            'width'  => 300,
            'height' => 300,
            'crop'   => 1,
        ];
    }
}

if ( ! function_exists( 'wc_placeholder_img_src' ) ) {
    function wc_placeholder_img_src( $size = 'woocommerce_thumbnail' ) {
        return 'https://shop.test/placeholder.png';
    }
}

if ( ! class_exists( 'WC_DateTime' ) ) {
    /** A date with WooCommerce's `date()` getter. */
    class WC_DateTime extends DateTime {
        public function date( $format ) {
            return $this->format( $format );
        }
    }
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    function get_woocommerce_currency() {
        return 'USD';
    }
}

if ( ! function_exists( 'wc_light_or_dark' ) ) {
    function wc_light_or_dark( $color, $dark = '#000000', $light = '#FFFFFF' ) {
        return $light;
    }
}

if ( ! function_exists( 'wc_price' ) ) {
    function wc_price( $amount, $args = [] ) {
        return '<span class="amount">' . ( $args['currency'] ?? '' ) . '&#36;' . number_format( (float) $amount, 2 ) . '</span>';
    }
}

if ( ! function_exists( 'wc_format_datetime' ) ) {
    function wc_format_datetime( $date ) {
        return $date ? $date->format( 'F j, Y' ) : '';
    }
}

if ( ! function_exists( 'wc_get_email_order_items' ) ) {
    function wc_get_email_order_items( $order, $args = [] ) {
        return $args['plain_text'] ? "Mug × 2 = \$10\n" : "<tr><td>Mug & Tea</td><td>2</td><td>\$10</td></tr>\n";
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this is the wp_strip_all_tags() stand-in.
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $text ) {
        return (string) $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return esc_html( $text );
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '' ) {
        return 'Tom &amp; Jerry&#039;s';
    }
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
    function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
        return htmlspecialchars_decode( (string) $text, $quote_style );
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://shop.test' . $path;
    }
}

if ( ! function_exists( 'wc_get_order_statuses' ) ) {
    function wc_get_order_statuses() {
        return [
            'wc-pending'    => 'Pending payment',
            'wc-processing' => 'Processing',
            'wc-on-hold'    => 'On hold',
            'wc-completed'  => 'Completed',
            'wc-cancelled'  => 'Cancelled',
            'wc-refunded'   => 'Refunded',
            'wc-failed'     => 'Failed',
        ];
    }
}

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        return \YayMatik\Tests\Support\FakeOrder::$found[ (int) $order_id ] ?? false;
    }
}
