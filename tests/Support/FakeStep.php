<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Data\Scope;
use YayMatik\Workflow\Data\Type;
use YayMatik\Workflow\Steps\Core\Action;
use YayMatik\Workflow\Steps\Core\StepResult;

/**
 * An action whose run() a test supplies. Declares one output, `to`. Config is kept as given;
 * `[ 'invalid' => true ]` fails validate(), `[ 'sanitize_throws' => true ]` makes sanitize() throw.
 * Counts its runs.
 */
final class FakeStep extends Action {

    /** @var string */
    private $id;

    /** @var callable|null fn( Scope, array $config ): StepResult; null = ok( [ 'to' => 'a@x.com' ] ). */
    private $runner;

    /** @var string */
    private $on_error;

    /** @var int */
    public $runs = 0;

    /**
     * @param string        $id       Step type id.
     * @param callable|null $runner   What run() does.
     * @param string        $on_error Error policy.
     */
    public function __construct( string $id, ?callable $runner = null, string $on_error = self::ON_ERROR_STOP ) {
        $this->id       = $id;
        $this->runner   = $runner;
        $this->on_error = $on_error;
    }

    public function id(): string {
        return $this->id;
    }

    public function label(): string {
        return 'Fake';
    }

    public function description(): string {
        return '';
    }

    public function plugin(): string {
        return 'yaymatik';
    }

    public function module(): string {
        return 'core';
    }

    public function icon(): string {
        return 'mail';
    }

    public function fields(): array {
        return [];
    }

    public function summary(): string {
        return '';
    }

    public function category(): string {
        return self::CATEGORY_ORDER;
    }

    public function requires(): array {
        return [];
    }

    public function outputs(): array {
        return [
            'to' => [
                'label' => 'To',
                'type'  => Type::string(),
            ],
        ];
    }

    public function on_error(): string {
        return $this->on_error;
    }

    /**
     * @param mixed $config Raw config.
     */
    public function sanitize( $config ): array {
        if ( ! empty( $config['sanitize_throws'] ) ) {
            throw new \RuntimeException( 'Bad stored config.' );
        }
        return is_array( $config ) ? $config : [];
    }

    public function validate( array $config ) {
        return empty( $config['invalid'] ) ? true : new \WP_Error( 'invalid', 'Fake needs a recipient.' );
    }

    public function run( Scope $scope, array $config ): StepResult {
        ++$this->runs;
        return $this->runner ? ( $this->runner )( $scope, $config ) : StepResult::ok( [ 'to' => 'a@x.com' ] );
    }
}
