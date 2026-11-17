<?php
namespace YayMatik\Tests\Unit\Core\Steps;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Steps\Field;

/** Step settings: each widget's sanitize (never fails) and validate, the modifiers, the catalog shape. */
final class FieldTest extends TestCase {

    private const REQUIRED = 'This field is required.';

    /** Inputs no widget expects; sanitize must still return its stored shape. */
    private function junk(): array {
        return [ null, true, 12, 1.5, '', 'text', [], [ 'a' => [ 'b' ] ], new \stdClass() ];
    }

    public function test_text_is_one_clean_line(): void {
        $field = Field::text( 'key', 'Meta key' );

        $this->assertSame( 'a b', $field->sanitize( " a\n<b>b</b>\t" ) );
        $this->assertSame( '', $field->sanitize( [ 'x' ] ) );
        $this->assertNull( $field->validate( '' ) );
        $this->assertSame( self::REQUIRED, $field->required()->validate( '' ) );
        $this->assertNull( $field->validate( 'x' ) );
    }

    public function test_text_with_variables_keeps_tags_inside_angle_brackets(): void {
        $field = Field::text( 'subject', 'Subject' )->variables();

        $this->assertTrue( $field->accepts_variables() );
        $this->assertFalse( Field::text( 'key', 'Key' )->accepts_variables() );

        $this->assertSame( 'To <{{ customer.email }}> now', $field->sanitize( "To <{{ customer.email }}>\r\nnow" ) );
    }

    public function test_textarea_keeps_line_breaks(): void {
        $this->assertSame( "a\nb", Field::textarea( 'note', 'Note' )->sanitize( "a\r\n<i>b</i> " ) );
        $this->assertSame( "Hi <{{ order.number }}>\nbye", Field::textarea( 'note', 'Note' )->variables()->sanitize( "Hi <{{ order.number }}>\r\nbye\x07" ) );
    }

    public function test_html_is_cleaned_and_keeps_merge_tags(): void {
        $field = Field::html( 'message', 'Message' )->variables();

        $this->assertSame(
            "<p>Hi <a href=\"{{ site.url }}\">{{ customer.first_name }}</a></p>\n<p>bye</p>",
            $field->sanitize( "<p onclick=\"x()\">Hi <a href=\"{{ site.url }}\">{{ customer.first_name }}</a></p>\r\n<p>bye</p>\x07<script>alert(1)</script> " )
        );
        foreach ( $this->junk() as $raw ) {
            $this->assertIsString( $field->sanitize( $raw ) );
        }
    }

    public function test_an_emptied_rich_editor_is_blank(): void {
        $field = Field::html( 'message', 'Message' )->required( '{{ step }} needs a message.' );

        $this->assertSame( 'Email needs a message.', $field->validate( '<p>&nbsp;</p>', [], 'Email' ) );
        $this->assertSame( 'Email needs a message.', $field->validate( "<p>\u{00A0}</p><p></p>", [], 'Email' ) );
        $this->assertNull( $field->validate( '<p><img src="a.png" alt=""></p>', [], 'Email' ), 'an image alone counts' );
        $this->assertNull( $field->validate( '<p>Hi</p>', [], 'Email' ) );
    }

    public function test_a_text_default_reaches_the_catalog_but_not_a_save(): void {
        $field = Field::html( 'message', 'Message' )->default( '<p>Sample</p>' );

        $this->assertSame( '<p>Sample</p>', $field->to_array()['default'], 'a new step starts with it' );
        $this->assertSame( '', $field->sanitize( null ), 'a missing value stays blank' );
        $this->assertSame( 'Hi', Field::text( 'subject', 'Subject' )->default( 'Hi' )->to_array()['default'] );
    }

    public function test_emails_checks_each_address(): void {
        $field = Field::emails( 'recipients', 'Recipients' );

        $this->assertSame( 'a@x.com, b@x.com', $field->sanitize( " a@x.com,\nb@x.com " ) );
        $this->assertNull( $field->validate( '' ), 'blank is allowed unless required' );
        $this->assertNull( $field->validate( 'a@x.com, b@x.com,' ) );
        $this->assertSame( 'nope isn’t a valid email address.', $field->validate( 'a@x.com, nope' ) );
        $this->assertSame( '{{ customer.email }} isn’t a valid email address.', $field->validate( '{{ customer.email }}' ) );
        $this->assertNull( $field->variables()->validate( '{{ customer.email }}, a@x.com' ), 'tags are checked by the Validator' );
    }

    public function test_select_falls_back_to_its_default_or_keeps_unknown_for_validate(): void {
        $types        = [
            'private'  => 'Private',
            'customer' => 'Note to customer',
        ];
        $with_default = Field::select( 'type', 'Type', $types )->default( 'private' );
        $no_default   = Field::select( 'status', 'Status', $types );

        $this->assertSame( 'customer', $with_default->sanitize( 'customer' ) );
        $this->assertSame( 'private', $with_default->sanitize( 'other' ) );
        $this->assertSame( 'private', $with_default->sanitize( null ) );
        $this->assertSame( 'other', $no_default->sanitize( 'other' ) );
        $this->assertSame( 'Choose an option from the list.', $no_default->validate( 'other' ) );
        $this->assertSame( self::REQUIRED, $no_default->required()->validate( '' ) );
        $this->assertNull( $no_default->validate( 'private' ) );
    }

    public function test_select_options_can_be_lazy_and_an_empty_list_accepts_anything(): void {
        $calls = 0;
        $field = Field::select(
            'status',
            'Status',
            function () use ( &$calls ) {
                ++$calls;
                return [];
            }
        );

        $this->assertSame( 0, $calls, 'options are read only when needed' );
        $this->assertNull( $field->validate( 'anything' ) );
        $this->assertSame( 1, $calls );
    }

    public function test_multiselect_is_a_unique_list_of_known_values(): void {
        $field = Field::multiselect(
            'to',
            'To',
            [
                'pending'    => 'Pending',
                'processing' => 'Processing',
            ]
        );

        $this->assertSame( [ 'pending', 'processing' ], $field->sanitize( [ 'pending', '', [ 'x' ], 'processing', 'pending' ] ) );
        $this->assertSame( [], $field->sanitize( 'pending' ) );
        $this->assertNull( $field->validate( [] ) );
        $this->assertNull( $field->validate( [ 'processing' ] ) );
        $this->assertSame( 'Choose options from the list.', $field->validate( [ 'processing', 'gone' ] ) );
        $this->assertSame( self::REQUIRED, $field->required()->validate( [] ) );
    }

    public function test_toggle_is_a_boolean(): void {
        $field = Field::toggle( 'only_checkout', 'Only checkout orders' );

        $this->assertTrue( $field->sanitize( '1' ) );
        $this->assertFalse( $field->sanitize( '' ) );
        $this->assertFalse( $field->sanitize( null ) );
        $this->assertTrue( Field::toggle( 'on', 'On' )->default( true )->sanitize( null ), 'missing → default' );
        $this->assertNull( $field->required()->validate( false ), 'off is a value' );
    }

    public function test_number_reads_numbers_and_checks_its_range(): void {
        $field = Field::number( 'amount', 'Amount' )->min( 1 )->max( 10 );

        $this->assertSame( 5, $field->sanitize( ' 5 ' ) );
        $this->assertSame( 2.5, $field->sanitize( 2.5 ) );
        $this->assertNull( $field->sanitize( 'five' ) );
        $this->assertNull( $field->sanitize( true ) );
        $this->assertNull( $field->validate( null ), 'blank is allowed unless required' );
        $this->assertSame( 'Enter 1 or more.', $field->validate( 0 ) );
        $this->assertSame( 'Enter 10 or less.', $field->validate( 11 ) );
        $this->assertNull( $field->validate( 10 ) );
        $this->assertSame( self::REQUIRED, $field->required()->validate( null ) );
    }

    public function test_number_unit_keeps_a_known_unit(): void {
        $field = Field::number_unit(
            'delay',
            'Wait for',
            [
                'hours' => 'Hours',
                'days'  => 'Days',
            ]
        )->min( 1 );

        $this->assertSame(
            [
                'value' => 3,
                'unit'  => 'days',
            ],
            $field->sanitize(
                [
                    'value' => '3',
                    'unit'  => 'days',
                ]
            )
        );
        $this->assertSame(
            [
                'value' => null,
                'unit'  => 'hours',
            ],
            $field->sanitize( 'junk' ),
            'unknown unit → first unit'
        );
        $this->assertSame(
            [
                'value' => 1,
                'unit'  => 'days',
            ],
            $field->default(
                [
                    'value' => 1,
                    'unit'  => 'days',
                ]
            )->sanitize( [ 'unit' => 'years' ] ),
            'unknown unit → the default unit'
        );
        $this->assertSame(
            'Enter 1 or more.',
            $field->validate(
                [
                    'value' => 0,
                    'unit'  => 'days',
                ]
            )
        );
        $this->assertSame(
            self::REQUIRED,
            $field->required()->validate(
                [
                    'value' => null,
                    'unit'  => 'days',
                ]
            )
        );
    }

    public function test_notice_holds_no_value(): void {
        $field = Field::notice( 'from', 'From', 'Store <shop@x.com>' );

        $this->assertFalse( $field->has_value() );
        $this->assertNull( $field->validate( null ) );
        $this->assertSame( [], Field::sanitize_all( [ $field ], [ 'from' => 'hacked' ] ) );
        $this->assertSame( 'Store <shop@x.com>', $field->to_array()['text'] );
        $this->assertArrayNotHasKey( 'default', $field->to_array() );
    }

    public function test_rules_keeps_a_list_and_needs_one_started_rule_when_required(): void {
        $field = Field::rules( 'rules', 'Rules' )->required();

        $this->assertSame( [], $field->sanitize( 'junk' ) );
        $this->assertSame( [ [ 'field' => 'a' ] ], $field->sanitize( [ 'x' => [ 'field' => 'a' ] ] ) );
        $this->assertSame( self::REQUIRED, $field->validate( [] ) );
        $this->assertSame( self::REQUIRED, $field->validate( [ [ 'field' => '' ], 'junk' ] ) );
        $this->assertNull( $field->validate( [ [ 'field' => 'order.total' ] ] ) );
    }

    public function test_sanitize_never_fails_and_returns_the_stored_shape(): void {
        $fields = [
            Field::text( 'a', 'A' ),
            Field::textarea( 'b', 'B' ),
            Field::emails( 'c', 'C' ),
            Field::select( 'd', 'D', [ 'x' => 'X' ] ),
            Field::multiselect( 'e', 'E', [ 'x' => 'X' ] ),
            Field::toggle( 'f', 'F' ),
            Field::number( 'g', 'G' ),
            Field::number_unit( 'h', 'H', [ 'days' => 'Days' ] ),
            Field::rules( 'i', 'I' ),
        ];
        $shapes = [
            'a' => 'is_string',
            'b' => 'is_string',
            'c' => 'is_string',
            'd' => 'is_string',
            'e' => 'is_array',
            'f' => 'is_bool',
            'g' => fn( $v ) => null === $v || is_int( $v ) || is_float( $v ),
            'h' => fn( $v ) => is_array( $v ) && array_key_exists( 'value', $v ) && 'days' === $v['unit'],
            'i' => 'is_array',
        ];
        foreach ( $this->junk() as $raw ) {
            foreach ( $fields as $field ) {
                $value = $field->sanitize( $raw );
                $this->assertTrue( ( $shapes[ $field->key() ] )( $value ), $field->type() . ' from ' . wp_json_encode( $raw ) );
                $field->validate( $value );
            }
        }
    }

    public function test_show_if_hides_a_field_and_skips_its_checks(): void {
        $field = Field::text( 'note', 'Note' )->required()->show_if( 'type', [ 'customer', 'private' ] );

        $this->assertTrue( $field->visible( [ 'type' => 'customer' ] ) );
        $this->assertFalse( $field->visible( [ 'type' => 'none' ] ) );
        $this->assertNull( $field->validate( '', [ 'type' => 'none' ] ) );
        $this->assertSame( self::REQUIRED, $field->validate( '', [ 'type' => 'private' ] ) );
    }

    public function test_sanitize_all_and_first_error_follow_field_order(): void {
        $fields = [
            Field::text( 'key', 'Key' )->required(),
            Field::text( 'value', 'Value' )->variables(),
            Field::number( 'count', 'Count' )->required(),
        ];
        $config = Field::sanitize_all(
            $fields,
            [
                'value' => 'v',
                'extra' => 'dropped',
            ]
        );

        $this->assertSame(
            [
                'key'   => '',
                'value' => 'v',
                'count' => null,
            ],
            $config
        );
        $this->assertSame(
            [
                'field'   => 'key',
                'message' => self::REQUIRED,
            ],
            Field::first_error( $fields, $config )
        );
        $this->assertSame( 'count', Field::first_error( $fields, [ 'key' => 'k' ] + $config )['field'] );
        $this->assertNull(
            Field::first_error(
                $fields,
                [
                    'key'   => 'k',
                    'count' => 1,
                ] + $config
            )
        );
    }

    public function test_suggest_names_the_list_and_keeps_any_text(): void {
        $field = Field::text( 'key', 'Field name' )->suggest( 'customer-meta-keys' );
        $this->assertSame( 'customer-meta-keys', $field->to_array()['suggest'] );
        $this->assertSame( 'not_suggested', $field->sanitize( 'not_suggested' ) );
        $this->assertNull( $field->validate( 'not_suggested' ) );
    }

    public function test_to_array_sends_only_what_is_set(): void {
        $this->assertSame(
            [
                'key'     => 'key',
                'type'    => 'text',
                'label'   => 'Meta key',
                'default' => '',
            ],
            Field::text( 'key', 'Meta key' )->to_array()
        );
        $this->assertSame(
            [
                'key'         => 'type',
                'type'        => 'select',
                'label'       => 'Type',
                'required'    => true,
                'variables'   => true,
                'placeholder' => 'Pick one',
                'help'        => 'Customer notes are emailed.',
                'showIf'      => [
                    'field' => 'on',
                    'value' => true,
                ],
                'options'     => [
                    [
                        'value' => 'private',
                        'label' => 'Private',
                    ],
                ],
                'default'     => 'private',
                'min'         => 1,
                'max'         => 2,
            ],
            Field::select( 'type', 'Type', [ 'private' => 'Private' ] )
                ->required()
                ->variables()
                ->placeholder( 'Pick one' )
                ->help( 'Customer notes are emailed.' )
                ->show_if( 'on', true )
                ->default( 'private' )
                ->min( 1 )
                ->max( 2 )
                ->to_array()
        );
    }

    public function test_a_required_message_names_the_step(): void {
        $field = Field::textarea( 'note', 'Note' )->required( '{{ step }} needs a note.' );

        $this->assertSame( 'Add order note needs a note.', $field->validate( '', [], 'Add order note' ) );
        $this->assertSame( '100% {{ step }} needs a note.', $field->validate( '', [], '100% {{ step }}' ), 'the label is never parsed again' );
        $this->assertSame( '{{ step }} needs a note.', $field->to_array()['requiredMessage'], 'the catalog gets the template' );
        $this->assertArrayNotHasKey( 'requiredMessage', Field::text( 'a', 'A' )->required()->to_array() );
        $this->assertSame( 'This field is required.', Field::text( 'a', 'A' )->required()->validate( '' ), 'without a message' );
    }

    public function test_rules_of_only_groups_read_as_started_when_a_group_has_a_rule(): void {
        $error = fn( array $rule ) => Field::first_error(
            [ Field::rules( 'rules', 'Rules' )->required() ],
            [
                'rules' => [
                    [
                        'match' => 'all',
                        'rules' => [ $rule ],
                    ],
                ],
            ],
            'Condition'
        );
        $this->assertNull( $error( [ 'field' => 'order.total' ] ) );
        $this->assertNotNull( $error( [ 'field' => '' ] ) );
    }
}
