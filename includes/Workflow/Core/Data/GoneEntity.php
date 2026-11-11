<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A ref whose entity no longer exists; the step that reads it is skipped.
 */
final class GoneEntity extends \RuntimeException {

    /** @var Ref */
    private $ref;

    public function __construct( Ref $ref ) {
        $this->ref = $ref;
        /* translators: %s: what was deleted, e.g. "Order" or order:12 */
        parent::__construct( esc_html( sprintf( __( '%s no longer exists.', 'yaymatik' ), $ref->key() ) ) );
    }

    public function ref(): Ref {
        return $this->ref;
    }
}
