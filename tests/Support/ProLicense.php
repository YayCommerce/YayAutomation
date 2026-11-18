<?php
namespace YayMatik\Tests\Support;

/** Stored license options of YayMatik Pro, as admin-shell writes them. */
final class ProLicense {

    /**
     * @param string $status
     * @param string $expires
     */
    public static function active( string $status = 'valid', string $expires = 'lifetime' ): array {
        return [
            'yaymatik-pro_license_key'  => 'test-key',
            'yaymatik-pro_license_info' => [
                'license' => $status,
                'expires' => $expires,
            ],
        ];
    }
}
