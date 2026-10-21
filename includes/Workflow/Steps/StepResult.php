<?php
namespace YayMatik\Workflow\Steps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * What a step's `run()` returns:
 * - `ok( outputs )`: done; later steps can read the outputs at `steps.<id>.*`.
 * - `skipped( reason )`: nothing to do, e.g. the order was deleted. Not an error.
 * - `failed( error )`: the step's error policy decides if the run stops or goes on.
 * - `wait( until )`: the run pauses until a time, then resumes at the next step.
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

    /** @var string Error code when failed. */
    private $code = '';

    /** @var int Unix time to resume at, when waiting. */
    private $until = 0;

    private function __construct( string $status ) {
        $this->status = $status;
    }

    /**
     * @param array $outputs Output key => value, as the step's `outputs()` declares them.
     */
    public static function ok( array $outputs = [] ): self {
        $result          = new self( self::OK );
        $result->outputs = $outputs;
        return $result;
    }

    /**
     * @param string $reason Why there was nothing to do, for run history.
     */
    public static function skipped( string $reason ): self {
        $result          = new self( self::SKIPPED );
        $result->message = $reason;
        return $result;
    }

    /**
     * @param string|\WP_Error $error What went wrong.
     */
    public static function failed( $error ): self {
        $result = new self( self::FAILED );
        if ( $error instanceof \WP_Error ) {
            $result->code    = (string) $error->get_error_code();
            $result->message = $error->get_error_message();
        } else {
            $result->message = (string) $error;
        }
        return $result;
    }

    /**
     * @param int $until Unix timestamp (GMT) to resume at, e.g. `time() + DAY_IN_SECONDS`.
     */
    public static function wait( int $until ): self {
        $result        = new self( self::WAIT );
        $result->until = $until;
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

    /** Error code of a failed step built from a WP_Error; '' otherwise. */
    public function code(): string {
        return $this->code;
    }

    /** Unix time to resume at; 0 unless waiting. */
    public function until(): int {
        return $this->until;
    }
}
