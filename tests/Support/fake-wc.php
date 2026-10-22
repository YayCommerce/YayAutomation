<?php
/**
 * `WC()` for tests that send email. Countries and gateways are empty, as without WooCommerce, so every test sees the same option lists.
 *
 * @package YayMatik
 */

use YayMatik\Tests\Support\FakeMailer;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- one file of stand-ins.

if ( ! function_exists( 'WC' ) ) {
    function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WooCommerce's name.
        static $wc = null;
        if ( null === $wc ) {
            $wc = new class() {
                /** @var object */
                public $countries;

                public function __construct() {
                    $this->countries = new class() {
                        public function get_countries() {
                            return [];
                        }
                    };
                }

                public function mailer() {
                    return FakeMailer::$current;
                }

                public function payment_gateways() {
                    return new class() {
                        public function payment_gateways() {
                            return [];
                        }
                    };
                }
            };
        }//end if
        return $wc;
    }
}//end if
