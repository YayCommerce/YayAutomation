<?php
namespace YayAutomation\Tests\Support;

use YayAutomation\Workflow\Data\DataRegistry;
use YayAutomation\Workflow\Data\Ref;
use YayAutomation\Workflow\Data\RefResolver;
use YayAutomation\Workflow\Data\Scope;

/**
 * A v0.1 run context (data item id => object or plain value) as a Scope: objects become trigger
 * refs, already loaded; other keys stay plain values. Lets tests compare Template + Scope with the
 * old context-based renderer (`LegacyVariables`).
 */
final class ContextScope {

    /**
     * @param array $context Data item id => object, or plain values (`from`, `to`).
     */
    public static function of( array $context ): Scope {
        $entities = DataRegistry::entities();
        $resolver = new RefResolver( [] );
        $trigger  = [];
        foreach ( $context as $key => $value ) {
            if ( isset( $entities[ $key ] ) && is_object( $value ) ) {
                $ref = new Ref( $key, 'context' );
                $resolver->prime( $ref, $value );
                $value = $ref;
            }
            $trigger[ $key ] = $value;
        }
        return new Scope( [ 'trigger' => $trigger ], $resolver, $entities );
    }
}
