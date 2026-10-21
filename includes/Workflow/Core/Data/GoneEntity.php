<?php
namespace YayMatik\Workflow\Core\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A ref whose entity no longer exists, e.g. the order was deleted during a wait. The step that
 * reads it is skipped, and run history says why.
 */
final class GoneEntity extends \RuntimeException {

    /** @var Ref */
    private $ref;

    public function __construct( Ref $ref ) {
        $this->ref = $ref;
        /* translators: %s: entity, e.g. order:12 */
        parent::__construct( sprintf( __( '%s no longer exists.', 'yaymatik' ), $ref->key() ) );
    }

    public function ref(): Ref {
        return $this->ref;
    }
}
