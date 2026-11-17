<?php
namespace YayMatik\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Extension\Diagnostics;
use YayMatik\Workflow\Extension\ExtensionModule;
use YayMatik\Workflow\Extension\Extensions;

/** A module's `privacy_subjects`: its own data items' subjects only; a throw gives none and a diagnostic. */
final class PrivacySubjectsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [];
    }

    /**
     * @param mixed $subjects
     */
    private static function module( $subjects ): ?ExtensionModule {
        return Extensions::collect(
            [],
            function ( $registrar ) use ( $subjects ) {
                $registrar->module(
                    [
                        'id'               => 'acme',
                        'label'            => 'Acme',
                        'privacy_subjects' => $subjects,
                    ]
                )->data_item(
                    'acme_reward',
                    [
                        'label'       => 'Reward',
                        'description' => 'A redeemed reward.',
                        'load'        => fn() => null,
                    ]
                );
            }
        )[0] ?? null;
    }

    public function test_only_the_modules_own_subjects_are_kept(): void {
        $module = self::module( fn( string $email ) => [ 'acme_reward:7', 'order:12', 'acme_whatever:1', 'acme_reward: 8', "acme_reward:9\n", 42, "acme_reward:{$email}" ] );

        $this->assertSame( [ 'acme_reward:7', 'acme_reward:ann@example.test' ], $module->privacy_subjects( 'ann@example.test' ) );
    }

    public function test_a_throw_gives_none_and_a_diagnostic(): void {
        $module = self::module(
            function () {
                throw new \RuntimeException( 'Rewards table missing.' );
            }
        );

        $this->assertSame( [], $module->privacy_subjects( 'ann@example.test' ) );
        $this->assertSame( [ 'acme', 'privacy_subjects', 'Rewards table missing.' ], array_values( array_intersect_key( Diagnostics::recent( 24 )[0], array_flip( [ 'module', 'callback', 'message' ] ) ) ) );
    }

    public function test_a_module_with_a_non_callable_is_refused(): void {
        $this->assertNull( self::module( 'acme_subjects_typo' ) );
        $this->assertSame( '"privacy_subjects" must be a callable.', Diagnostics::recent( 24 )[0]['message'] );
    }
}
