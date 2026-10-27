<?php
namespace YayMatik\Tests\Unit\Core\Cart;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\RequestOrigin;

/** The capture endpoint's CSRF check. */
final class RequestOriginTest extends TestCase {

    private const HOME = 'https://shop.test';

    /**
     * @dataProvider cases
     * @param array  $headers
     * @param string $home
     * @param bool   $allowed
     */
    public function test_allowed( array $headers, string $home, bool $allowed ): void {
        $this->assertSame( $allowed, RequestOrigin::allowed( $headers, $home ) );
    }

    public function cases(): array {
        return [
            'same-origin'                       => [ [ 'sec-fetch-site' => 'same-origin' ], self::HOME, true ],
            'cross-site'                        => [ [ 'sec-fetch-site' => 'cross-site' ], self::HOME, false ],
            'same-site (a subdomain)'           => [ [ 'sec-fetch-site' => 'same-site' ], self::HOME, false ],
            'Sec-Fetch-Site wins over Origin'   => [
                [
                    'sec-fetch-site' => 'cross-site',
                    'origin'         => self::HOME,
                ],
                self::HOME,
                false,
            ],
            'Origin matches'                    => [ [ 'origin' => 'https://shop.test' ], self::HOME, true ],
            'Origin case-insensitive host'      => [ [ 'origin' => 'https://SHOP.test' ], self::HOME, true ],
            'Origin other host'                 => [ [ 'origin' => 'https://evil.test' ], self::HOME, false ],
            'Origin www. differs'               => [ [ 'origin' => 'https://www.shop.test' ], self::HOME, false ],
            'Origin null'                       => [ [ 'origin' => 'null' ], self::HOME, false ],
            'localhost with the same port'      => [ [ 'origin' => 'http://localhost:8080' ], 'http://localhost:8080', true ],
            'localhost with another port'       => [ [ 'origin' => 'http://localhost:8081' ], 'http://localhost:8080', false ],
            'default https port written out'    => [ [ 'origin' => 'https://shop.test:443' ], self::HOME, true ],
            'http vs https: other default port' => [ [ 'origin' => 'http://shop.test' ], self::HOME, false ],
            'home in a subfolder'               => [ [ 'origin' => 'https://shop.test' ], 'https://shop.test/store', true ],
            'Referer fallback matches'          => [ [ 'referer' => 'https://shop.test/checkout/?x=1' ], self::HOME, true ],
            'Referer fallback other host'       => [ [ 'referer' => 'https://evil.test/shop.test' ], self::HOME, false ],
            'Origin wins over Referer'          => [
                [
                    'origin'  => 'https://evil.test',
                    'referer' => 'https://shop.test/checkout/',
                ],
                self::HOME,
                false,
            ],
            'no header'                         => [ [], self::HOME, false ],
            'empty headers'                     => [
                [
                    'sec-fetch-site' => '',
                    'origin'         => '',
                    'referer'        => '',
                ],
                self::HOME,
                false,
            ],
        ];
    }
}
