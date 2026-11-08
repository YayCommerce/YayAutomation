<?php
namespace YayMatik\Workflow\Core\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * What a step's `run()` returns: ok, skipped (not an error), failed or wait.
 */
final class StepResult {

    public const OK      = 'ok';
    public const SKIPPED = 'skipped';
    public const FAILED  = 'failed';
    public const WAIT    = 'wait';

    /** @var string */
    private $status;

    /** @var array Output key => value. */
    private $outputs = [];

    /** @var string Why it was skipped or failed. */
    private $message = '';

    /** @var int Unix time to resume at, when waiting. */
    private $until = 0;

    private function __construct( string $status ) {
        $this->status = $status;
    }

    /**
     * @param array $outputs As the step's `outputs()` declares them.
     */
    public static function ok( array $outputs = [] ): self {
        $result          = new self( self::OK );
        $result->outputs = $outputs;
        return $result;
    }

    public static function skipped( string $reason ): self {
        $result          = new self( self::SKIPPED );
        $result->message = $reason;
        return $result;
    }

    public static function failed( string $message ): self {
        $result          = new self( self::FAILED );
        $result->message = $message;
        return $result;
    }

    /**
     * @param int   $until   Unix time to resume at.
     * @param array $outputs As the step's `outputs()` declares them.
     */
    public static function wait( int $until, array $outputs = [] ): self {
        $result          = new self( self::WAIT );
        $result->until   = $until;
        $result->outputs = $outputs;
        return $result;
    }

    public function status(): string {
        return $this->status;
    }

    public function is_ok(): bool {
        return self::OK === $this->status;
    }

    public function is_skipped(): bool {
        return self::SKIPPED === $this->status;
    }

    public function is_failed(): bool {
        return self::FAILED === $this->status;
    }

    public function is_wait(): bool {
        return self::WAIT === $this->status;
    }

    public function outputs(): array {
        return $this->outputs;
    }

    /** Why the step was skipped or failed; '' otherwise. */
    public function message(): string {
        return $this->message;
    }

    /** Unix time to resume at; 0 unless waiting. */
    public function until(): int {
        return $this->until;
    }
}
