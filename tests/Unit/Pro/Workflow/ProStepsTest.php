<?php
namespace YayMatik\Tests\Unit\Pro\Workflow;

use PHPUnit\Framework\TestCase;
use YayMatik\Pro\Workflow\Core\Runtime\InactiveSweep;
use YayMatik\Pro\Workflow\DataItems\CustomerProData;
use YayMatik\Pro\Workflow\DataItems\OrderProData;
use YayMatik\Workflow\Core\Data\DataItem;
use YayMatik\Workflow\Core\Definition\ProCatalog;
use YayMatik\Workflow\Core\Runtime\DailyJobs;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;

require_once dirname( __DIR__, 3 ) . '/Support/woocommerce-stubs.php';

/** Pro's steps as the free plugin knows them by name, and their outputs. */
final class ProStepsTest extends TestCase {

    public function test_the_catalog_names_every_pro_step_with_its_kind(): void {
        $steps = StepRegistry::all();
        $kinds = [];
        foreach ( ProCatalog::STEPS as $id => $kind ) {
            $this->assertArrayHasKey( $id, $steps );
            $kinds[ $id ] = $steps[ $id ]->kind();
        }
        $this->assertSame( ProCatalog::STEPS, $kinds );
    }

    public function test_outputs_are_what_run_history_shows(): void {
        $this->assertSame(
            [
                'customer_inactive'            => [],
                'customer_order_count_reaches' => [],
                'customer_total_spend_reaches' => [],
                'wait_until'                   => [ 'until' ],
                'resend_wc_email'              => [ 'email' ],
                'update_order_meta'            => [ 'key', 'changed' ],
                'update_customer_meta'         => [ 'key', 'changed' ],
                'change_customer_role'         => [ 'from', 'to' ],
            ],
            array_map( fn( string $id ) => array_keys( StepRegistry::all()[ $id ]->outputs() ), array_combine( array_keys( ProCatalog::STEPS ), array_keys( ProCatalog::STEPS ) ) )
        );
    }

    public function test_the_free_plugin_unschedules_the_sweep_by_its_hook_names(): void {
        $this->assertSame( [ InactiveSweep::HOOK => [ InactiveSweep::HOOK, InactiveSweep::BATCH_HOOK ] ], DailyJobs::LEFT_OVER );
    }

    public function test_the_catalog_names_every_field_pro_adds(): void {
        $keys = fn( array $items ) => array_merge( ...array_map( fn( DataItem $item ) => array_map( fn( string $key ) => $item->id() . '.' . $key, array_keys( $item->fields() ) ), $items ) );
        $pro  = $keys( [ new CustomerProData(), new OrderProData() ] );
        $core = $keys( [ new CustomerData(), new OrderData() ] );

        $this->assertEqualsCanonicalizing( ProCatalog::FIELDS, array_values( array_diff( $pro, $core ) ) );
    }
}
