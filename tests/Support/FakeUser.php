<?php
namespace YayMatik\Tests\Support;

/** A `WP_User` with roles and capabilities that a test sets, and that records role changes. */
final class FakeUser {

    /** @var int */
    public $ID;

    /** @var string[] */
    public $roles;

    /** @var array Capability => bool, from the roles and given directly. */
    public $allcaps;

    /** @var array Meta key => value. */
    public $meta = [];

    /** @var string[] "+role" / "-role" in call order. */
    public $changes = [];

    /**
     * @param int      $id
     * @param string[] $roles
     * @param array    $allcaps
     */
    public function __construct( int $id, array $roles, array $allcaps = [] ) {
        $this->ID      = $id;
        $this->roles   = $roles;
        $this->allcaps = $allcaps;
    }

    public function add_role( $role ) {
        $this->changes[] = '+' . $role;
        $this->roles[]   = $role;
    }

    public function remove_role( $role ) {
        $this->changes[] = '-' . $role;
        $this->roles     = array_values( array_diff( $this->roles, [ $role ] ) );
    }
}
