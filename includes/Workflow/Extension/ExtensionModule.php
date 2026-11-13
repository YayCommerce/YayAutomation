<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Modules\Module;

defined( 'ABSPATH' ) || exit;

/**
 * A module another plugin registered through `yaymatik_register`; holds its steps as objects.
 *
 * @internal
 */
final class ExtensionModule extends Module {

    /** @var string */
    private $id;

    /** @var string */
    private $label;

    /** @var string */
    private $description;

    /** @var string */
    private $logo;

    /** @var string|null */
    private $requires_api;

    /** @var callable fn(): bool */
    private $open;

    /** @var ExtensionTrigger[] By id. */
    private $triggers = [];

    /** @var ExtensionAction[] By id. */
    private $actions = [];

    /** @var string[] Category id => label. */
    private $categories = [];

    /**
     * @param string      $id
     * @param string      $label
     * @param string      $description
     * @param string      $logo
     * @param string|null $requires_api
     * @param callable    $open fn(): bool — false once `yaymatik_register` has run.
     */
    public function __construct( string $id, string $label, string $description, string $logo, ?string $requires_api, callable $open ) {
        $this->id           = $id;
        $this->label        = $label;
        $this->description  = $description;
        $this->logo         = $logo;
        $this->requires_api = $requires_api;
        $this->open         = $open;
    }

    public function id(): string {
        return $this->id;
    }

    public function label(): string {
        return $this->label;
    }

    public function description(): string {
        return $this->description;
    }

    public function logo(): string {
        return $this->logo;
    }

    public function requires_api(): ?string {
        return $this->requires_api;
    }

    public function triggers(): array {
        return array_values( $this->triggers );
    }

    public function actions(): array {
        return array_values( $this->actions );
    }

    /** @return string[] Category id => label. */
    public function categories(): array {
        return $this->categories;
    }

    /**
     * @param string $id
     * @param array  $args
     */
    public function add_trigger( string $id, array $args ): bool {
        if ( ! $this->may_add( "trigger {$id}", $id ) ) {
            return false;
        }
        $args = Declaration::trigger( $this, $id, $args );
        if ( null === $args ) {
            return false;
        }
        $this->triggers[ $id ] = new ExtensionTrigger( $this->id, $id, $args );
        return true;
    }

    /**
     * @param string $id
     * @param array  $args
     */
    public function add_action( string $id, array $args ): bool {
        if ( ! $this->may_add( "action {$id}", $id ) ) {
            return false;
        }
        $args = Declaration::action( $this, $id, $args );
        if ( null === $args ) {
            return false;
        }
        $this->actions[ $id ] = new ExtensionAction( $this->id, $id, $args );
        return true;
    }

    /**
     * @param string $id
     * @param string $label
     */
    public function add_category( string $id, string $label ): bool {
        if ( ! ( $this->open )() ) {
            Declaration::too_late( $this->id, "category {$id}" );
            return false;
        }
        if ( ! Declaration::category( $this, $id, $label ) ) {
            return false;
        }
        $this->categories[ $id ] = $label;
        return true;
    }

    /**
     * @param string $what
     * @param string $id
     */
    private function may_add( string $what, string $id ): bool {
        if ( ! ( $this->open )() ) {
            Declaration::too_late( $this->id, $what );
            return false;
        }
        if ( isset( $this->triggers[ $id ] ) || isset( $this->actions[ $id ] ) ) {
            Declaration::refuse( $this->id, $what, "Step id \"{$id}\" is already declared by this module." );
            return false;
        }
        return true;
    }
}
