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

    /** @var callable|null fn( string $email ): string[] */
    private $privacy_subjects;

    /** @var array[] Own subjects by email, for this request. */
    private $subjects = [];

    /** @var ExtensionTrigger[] By id. */
    private $triggers = [];

    /** @var ExtensionAction[] By id. */
    private $actions = [];

    /** @var string[] Category id => label. */
    private $categories = [];

    /** @var ExtensionDataItem[] By id. */
    private $data_items = [];

    /** @var array[] By id. */
    private $templates = [];

    /** @var array[] Field keys this module added, by data item id. */
    private $data_fields = [];

    /**
     * @param string        $id
     * @param string        $label
     * @param string        $description
     * @param string        $logo
     * @param string|null   $requires_api
     * @param callable      $open fn(): bool — false once `yaymatik_register` has run.
     * @param callable|null $privacy_subjects
     */
    public function __construct( string $id, string $label, string $description, string $logo, ?string $requires_api, callable $open, ?callable $privacy_subjects = null ) {
        $this->id               = $id;
        $this->label            = $label;
        $this->description      = $description;
        $this->logo             = $logo;
        $this->requires_api     = $requires_api;
        $this->open             = $open;
        $this->privacy_subjects = $privacy_subjects;
    }

    /**
     * The plugin's `privacy_subjects`, kept only as `<own data item>:<id>`; once per email per request.
     *
     * @param string $email
     * @return string[]
     */
    public function privacy_subjects( string $email ): array {
        if ( ! $this->privacy_subjects ) {
            return [];
        }
        if ( ! isset( $this->subjects[ $email ] ) ) {
            $subjects                 = Guard::call( $this->id, 'privacy_subjects', fn() => (array) ( $this->privacy_subjects )( $email ), fn() => [] );
            $this->subjects[ $email ] = array_values( array_filter( $subjects, [ $this, 'own_subject' ] ) );
        }
        return $this->subjects[ $email ];
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

    /** @return ExtensionDataItem[] By id. */
    public function data_items(): array {
        return $this->data_items;
    }

    /** @return array[] Field keys this module added to data items, by item id. */
    public function data_fields(): array {
        return $this->data_fields;
    }

    /** @return array[] Template args from `Declaration::template()`, by id. */
    public function templates(): array {
        return $this->templates;
    }

    /**
     * @param string $id
     * @param array  $args
     */
    public function add_template( string $id, array $args ): bool {
        if ( ! ( $this->open )() ) {
            Declaration::too_late( $this->id, "template {$id}" );
            return false;
        }
        if ( isset( $this->templates[ $id ] ) ) {
            Declaration::refuse( $this->id, "template {$id}", "Template id \"{$id}\" is already declared by this module." );
            return false;
        }
        $args = Declaration::template( $this, $id, $args );
        if ( null === $args ) {
            return false;
        }
        $this->templates[ $id ] = $args;
        return true;
    }

    /**
     * @param string $id
     * @param array  $args
     */
    public function add_data_item( string $id, array $args ): bool {
        if ( ! ( $this->open )() ) {
            Declaration::too_late( $this->id, "data item {$id}" );
            return false;
        }
        if ( isset( $this->data_items[ $id ] ) ) {
            Declaration::refuse( $this->id, "data item {$id}", "Data item id \"{$id}\" is already declared by this module." );
            return false;
        }
        $args = Declaration::data_item( $this, $id, $args );
        if ( null === $args ) {
            return false;
        }
        $this->data_items[ $id ] = new ExtensionDataItem( $this->id, $id, $args );
        return true;
    }

    /**
     * Fields on a core data item or on one of this module's; a key core already has is left alone.
     *
     * @param string $item
     * @param array  $fields
     */
    public function add_data_fields( string $item, array $fields ): bool {
        if ( ! ( $this->open )() ) {
            Declaration::too_late( $this->id, "data fields {$item}" );
            return false;
        }
        $defs = Declaration::data_fields( $this, $item, $fields );
        if ( null === $defs ) {
            return false;
        }
        add_filter( "yaymatik_data_item_{$item}_fields", fn( $definitions ) => (array) $definitions + $defs );
        $this->data_fields[ $item ] = array_merge( $this->data_fields[ $item ] ?? [], array_keys( $defs ) );
        return true;
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
        $this->actions[ $id ] = isset( $args['email'] ) ? new ExtensionEmailAction( $this->id, $id, $args ) : new ExtensionAction( $this->id, $id, $args );
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
     * @param mixed $subject
     */
    private function own_subject( $subject ): bool {
        if ( ! is_string( $subject ) || false === strpos( $subject, ':' ) ) {
            return false;
        }
        list( $item, $id ) = explode( ':', $subject, 2 );
        return isset( $this->data_items[ $item ] ) && '' !== $id && ! preg_match( '/\s/', $id );
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
