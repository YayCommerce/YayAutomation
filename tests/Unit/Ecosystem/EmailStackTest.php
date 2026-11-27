<?php
namespace YayMatik\Tests\Unit\Ecosystem;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeClock;
use YayMatik\Tests\Support\FakeGraphSource;
use YayMatik\Tests\Support\FakeMailer;
use YayMatik\Tests\Support\FakeQueue;
use YayMatik\Tests\Support\FakeRunRepository;
use YayMatik\Tests\Support\FakeWorkflows;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Email\DeliveryStatus;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Runtime\Executor;
use YayMatik\Workflow\Core\Runtime\StepKey;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Extension\Declaration;
use YayMatik\Workflow\Extension\Extensions;
use YayMatik\Workflow\Extension\StepIndex;
use YayMatik\Workflow\ModuleRegistry;

require_once dirname( __DIR__, 2 ) . '/Support/woocommerce-stubs.php';
require_once dirname( __DIR__, 2 ) . '/Support/fake-wc.php';

/** The email stack's matrix (docs/extensions-roadmap.md) on the fake adapters: one email, wrapped once, logged once. */
final class EmailStackTest extends TestCase {

    /** Folder => class prefix. */
    private const PRODUCTS = [
        'yaymail'  => 'YayMail',
        'yaymagic' => 'YayMagic',
        'yaysmtp'  => 'YaySMTP',
    ];

    /** Design source => its step. */
    private const SOURCES = [
        'core'     => 'send_email',
        'yaymail'  => 'yaymail_send_email',
        'yaymagic' => 'yaymagic_send_email',
    ];

    /** @var array Class => property => value, put back after. */
    private $saved = [
        ModuleRegistry::class => [ 'modules', 'built' ],
        StepRegistry::class   => [ 'steps' ],
        DataRegistry::class   => [ 'items', 'entities' ],
        Declaration::class    => [ 'items' ],
        StepIndex::class      => [ 'stored' ],
    ];

    /** @var array */
    private $filters;

    /** @var array[] `yaymatik_email_status` calls: [ mark, status ]. */
    private $reports = [];

    protected function setUp(): void {
        $GLOBALS['yaymatik_test_options'] = [ UnsubscribeLink::SECRET_OPTION => 'secret' ];
        $this->filters                    = $GLOBALS['yaymatik_test_filters'] ?? [];
        foreach ( $this->saved as $class => $names ) {
            $this->saved[ $class ] = [];
            foreach ( $names as $name ) {
                $this->saved[ $class ][ $name ] = self::get( $class, $name );
            }
        }
        self::set( StepIndex::class, 'stored', null );
        FakeMailer::$current                  = new FakeMailer();
        FakeMailer::$current->through_wp_mail = true;
    }

    protected function tearDown(): void {
        foreach ( $this->saved as $class => $values ) {
            foreach ( $values as $name => $value ) {
                self::set( $class, $name, $value );
            }
        }
        $GLOBALS['yaymatik_test_filters'] = $this->filters;
    }

    /**
     * @param string $class_name
     * @param string $name
     */
    private static function get( string $class_name, string $name ) {
        $property = new \ReflectionProperty( $class_name, $name );
        $property->setAccessible( true );
        return $property->getValue();
    }

    /**
     * @param string $class_name
     * @param string $name
     * @param mixed  $value
     */
    private static function set( string $class_name, string $name, $value ): void {
        $property = new \ReflectionProperty( $class_name, $name );
        $property->setAccessible( true );
        $property->setValue( null, $value );
    }

    /**
     * The site: all three were active when the workflow was saved; `$active` still are, with their own mail hooks.
     *
     * @param string[] $active Product folders.
     */
    private function site( array $active ): void {
        $adapters = [];
        foreach ( self::PRODUCTS as $folder => $prefix ) {
            $dir = dirname( __DIR__, 3 ) . "/dev/ecosystem/{$folder}/";
            require_once "{$dir}{$prefix}_YayMatik.php";
            require_once "{$dir}{$prefix}_YayMatik_Fake_Adapter.php";
            $adapter             = "{$prefix}_YayMatik_Fake_Adapter";
            $adapters[ $folder ] = new $adapter();
        }
        $declare = function ( array $folders ) use ( $adapters ) {
            return array_map(
                function ( string $folder ) use ( $adapters ) {
                    $declarations = self::PRODUCTS[ $folder ] . '_YayMatik';
                    return [ new $declarations( $adapters[ $folder ] ), 'register' ];
                },
                $folders
            );
        };

        $core = ModuleRegistry::modules();
        StepIndex::refresh( Extensions::collect( array_keys( $core ), ...$declare( array_keys( self::PRODUCTS ) ) ) );
        $modules = [];
        foreach ( Extensions::collect( array_keys( $core ), ...$declare( $active ) ) as $module ) {
            $modules[ $module->id() ] = $module;
        }
        self::set( ModuleRegistry::class, 'modules', $core + $modules );
        self::set( ModuleRegistry::class, 'built', null );
        self::set( StepRegistry::class, 'steps', null );
        self::set( DataRegistry::class, 'items', null );
        self::set( DataRegistry::class, 'entities', null );

        DeliveryStatus::register();
        foreach ( $active as $folder ) {
            $adapters[ $folder ]->hook_mail();
        }
        add_action(
            'yaymatik_email_status',
            function ( $mark, $status ) {
                $this->reports[] = [ $mark, $status ];
            }
        );
    }

    /**
     * Runs Order created → the source's email step, as the background job does.
     *
     * @param string $source A `SOURCES` key.
     * @return array[] The run's step rows by node id.
     */
    private function run_workflow( string $source ): array {
        $config                  = 'core' === $source
            ? [
                'to'      => '{{ customer.email }}',
                'subject' => 'Thanks, {{ customer.first_name }}',
                'heading' => '',
                'message' => '<p>Hi {{ customer.first_name }}</p>',
            ]
            : [
                'subject'   => 'Thanks, {{ customer.first_name }}',
                'design_id' => '1',
            ];
        $graphs                  = new FakeGraphSource();
        $graphs->graphs[3]       = [
            'nodes' => [
                [
                    'id'   => 't',
                    'kind' => 'trigger',
                    'data' => [
                        'block'  => 'order_created',
                        'config' => [],
                    ],
                ],
                [
                    'id'   => 'mail',
                    'kind' => 'action',
                    'data' => [
                        'block'  => self::SOURCES[ $source ],
                        'config' => $config,
                    ],
                ],
            ],
            'edges' => [
                [
                    'source' => 't',
                    'target' => 'mail',
                ],
            ],
        ];
        $workflows               = new FakeWorkflows();
        $workflows->workflows[9] = [
            'id'     => '9',
            'status' => 'active',
            'graph'  => [],
        ];
        $runs                    = new FakeRunRepository();
        $id                      = $runs->create_run(
            [
                'workflow_id' => 9,
                'version_id'  => 3,
                'scope'       => [
                    'trigger' => [
                        'customer' => [
                            'email'      => 'ann@example.test',
                            'first_name' => 'Ann',
                        ],
                    ],
                ],
            ]
        );
        $runs->claim_run( $id, [ 'queued' ] );
        ( new Executor( $runs, $graphs, new FakeQueue(), $workflows, new FakeClock() ) )->run( $runs->find_run( $id ) );
        return array_column( $runs->steps( $id ), null, 'step_id' );
    }

    /**
     * Each design source × YaySMTP on / off × the other design source(s) on / off × the source's product removed.
     *
     * @return array[] Cell name => [ source, active products ].
     */
    public function cells(): array {
        $cells = [];
        foreach ( array_keys( self::SOURCES ) as $source ) {
            $others = array_values( array_diff( [ 'yaymail', 'yaymagic' ], [ $source ] ) );
            foreach ( [ true, false ] as $smtp ) {
                foreach ( [ true, false ] as $other ) {
                    foreach ( 'core' === $source ? [ false ] : [ false, true ] as $removed ) {
                        $active         = array_merge(
                            'core' === $source || $removed ? [] : [ $source ],
                            $other ? $others : [],
                            $smtp ? [ 'yaysmtp' ] : []
                        );
                        $name           = sprintf( '%s, smtp %s, %s %s%s', $source, $smtp ? 'on' : 'off', implode( '+', $others ), $other ? 'on' : 'off', $removed ? ', removed' : '' );
                        $cells[ $name ] = [ $source, $active ];
                    }
                }
            }
        }
        return $cells;
    }

    /**
     * @dataProvider cells
     * @param string   $source
     * @param string[] $active
     */
    public function test_cell( string $source, array $active ): void {
        $this->site( $active );

        $rows = $this->run_workflow( $source );

        $removed = 'core' !== $source && ! in_array( $source, $active, true );
        if ( $removed ) {
            $this->assertSame( [], FakeMailer::$current->mails, 'no email' );
            $this->assertSame( 'skipped', $rows['mail']['status'] );
            $this->assertSame( self::PRODUCTS[ $source ] . ' isn’t active.', $rows['mail']['error'] );
            return;
        }

        $this->assertCount( 1, FakeMailer::$current->mails, 'one email' );
        $mail = FakeMailer::$current->mails[0];
        $mark = StepKey::of( (int) $rows['mail']['id'], '2026-10-12 10:00:00' );
        $this->assertSame( $mark, DeliveryStatus::mark_of( $mail['headers'] ) );
        $this->assertSame( 0, substr_count( $mail['message'], '<body' ), 'no design plugin wrapped it again' );
        $this->assertSame( 1, substr_count( $mail['message'], '<h1>' ), 'WooCommerce’s wrapper, once' );
        $this->assertStringContainsString( 'yaymatik_unsub=', $mail['message'], 'unsubscribe link' );
        $this->assertStringContainsString( 'List-Unsubscribe: <', $mail['headers'] );

        $smtp = in_array( 'yaysmtp', $active, true );
        $log  = (array) get_option( \YaySMTP_YayMatik_Fake_Adapter::SENT_LOG, [] );
        $this->assertSame( $smtp ? [ $mark ] : [], array_column( $log, 'mark' ), 'logged once' );
        $this->assertSame( $smtp ? [ [ $mark, 'delivered' ] ] : [], $this->reports );

        $this->assertSame( 'completed', $rows['mail']['status'] );
        $this->assertSame( 'ann@example.test', $rows['mail']['output']['to'] );
        $this->assertSame( $smtp ? 'delivered' : 'sent', $rows['mail']['output']['delivery']['status'] );
    }

    public function test_the_fakes_wrap_and_log_an_email_yaymatik_didnt_mark(): void {
        $this->site( array_keys( self::PRODUCTS ) );

        WC()->mailer()->send( 'ann@example.test', 'Order received', '<p>Thanks</p>', "Content-Type: text/html\r\n" );

        $message = FakeMailer::$current->mails[0]['message'];
        $this->assertStringContainsString( 'data-wrapper="yaymail"', $message );
        $this->assertStringContainsString( 'data-wrapper="yaymagic"', $message );
        $this->assertSame( [ '' ], array_column( (array) get_option( \YaySMTP_YayMatik_Fake_Adapter::SENT_LOG, [] ), 'mark' ) );
        $this->assertSame( [], $this->reports, 'not YayMatik’s' );
    }
}
