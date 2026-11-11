<?php
namespace YayMatik\Tests\Unit\Core\Storage;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Core\Storage\CartStore;

/** The cart upsert: columns, values and the updated columns line up with their placeholders. */
final class CartStoreTest extends TestCase {

    /** @var FakeWpdb */
    private $db;

    protected function setUp(): void {
        $this->db = new FakeWpdb();
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- no WordPress here.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_upsert_names_each_column_once_and_updates_only_the_given_fields(): void {
        $this->db->result = 1;
        CartStore::upsert(
            'sess',
            [
                'email' => 'oneil@shop.test',
                'total' => 12.5,
                'items' => '[]',
            ]
        );

        $this->assertMatchesRegularExpression(
            "/^INSERT INTO `wp_yaymatik_carts` \(`email`,`total`,`items`,`session_key`,`status`,`created_at`,`updated_at`\) VALUES \('oneil@shop.test', 12.5, '\[\]', 'sess', 'active', '[0-9: -]+', '[0-9: -]+'\) ON DUPLICATE KEY UPDATE `email` = VALUES\(`email`\), `total` = VALUES\(`total`\), `items` = VALUES\(`items`\), `updated_at` = VALUES\(`updated_at`\)$/",
            $this->db->last_sql()
        );
    }
}
