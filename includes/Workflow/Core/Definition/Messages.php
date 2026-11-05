<?php
namespace YayMatik\Workflow\Core\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Messages the admin app shows too, placeholders unfilled.
 *
 * The app's parity test fails when its copy (apps/admin-page/src/routes/workflows/model/messages.ts) differs.
 */
final class Messages {

    public static function all(): array {
        return [
            // Workflow-level.
            'nameRequired'      => __( 'Give this workflow a name.', 'yaymatik' ),
            'noTrigger'         => __( 'Choose a trigger.', 'yaymatik' ),
            'noAction'          => __( 'Add at least one action.', 'yaymatik' ),
            'unlinked'          => __( 'Some steps aren’t connected. Connect them or remove them.', 'yaymatik' ),
            'noBlock'           => __( 'Choose an action.', 'yaymatik' ),
            'deleteActive'      => __( 'Deactivate this workflow before you delete it.', 'yaymatik' ),
            /* translators: %s: data item or step, e.g. "Order" */
            'missingData'       => __( '%s data isn’t available at this step.', 'yaymatik' ),
            'mayRefire'         => __( 'This step can set off this workflow’s own trigger. The workflow won’t start again from it.', 'yaymatik' ),
            // Merge tags.
            /* translators: %s: merge tag, e.g. {{ order.foo }} */
            'unknownVariable'   => __( '%s isn’t an available variable.', 'yaymatik' ),
            /* translators: 1: filter name, e.g. shout; 2: the available filters, e.g. "default, upper" */
            'unknownFilter'     => __( '%1$s isn’t a filter. Available filters: %2$s.', 'yaymatik' ),
            /* translators: %s: merge tag, e.g. {{ steps.coupon.code }} */
            'stepGone'          => __( '%s comes from a step that isn’t in this workflow anymore. Add that step above this one, or remove the variable.', 'yaymatik' ),
            /* translators: 1: merge tag, e.g. {{ steps.coupon.code }}; 2: step name, e.g. "Generate coupon" */
            'stepNotAbove'      => __( '%1$s needs the %2$s step above this one. Move it above, or remove the variable.', 'yaymatik' ),
            /* translators: %s: a merge tag as written, e.g. {{ order. }}. Keep {{ variable }} and {{ variable | filter }} as they are. */
            'malformedTag'      => __( '%s is written incorrectly. Use {{ variable }} or {{ variable | filter }}.', 'yaymatik' ),
            // Step settings.
            'fieldRequired'     => __( 'This field is required.', 'yaymatik' ),
            /* translators: %s: the text entered as an email address */
            'invalidEmail'      => __( '%s isn’t a valid email address.', 'yaymatik' ),
            'chooseOption'      => __( 'Choose an option from the list.', 'yaymatik' ),
            'chooseOptions'     => __( 'Choose options from the list.', 'yaymatik' ),
            /* translators: %s: smallest number allowed */
            'numberMin'         => __( 'Enter %s or more.', 'yaymatik' ),
            /* translators: %s: largest number allowed */
            'numberMax'         => __( 'Enter %s or less.', 'yaymatik' ),
            // Condition rules.
            'rulesRequired'     => __( 'Add a rule, or remove this condition.', 'yaymatik' ),
            'ruleVariable'      => __( 'Choose data for each rule.', 'yaymatik' ),
            'ruleComparison'    => __( 'Choose a comparison for each rule.', 'yaymatik' ),
            'ruleCompareWith'   => __( 'Choose data to compare with.', 'yaymatik' ),
            /* translators: %s: variable, e.g. "Order total" */
            'ruleValueRequired' => __( 'Enter a value for %s.', 'yaymatik' ),
            /* translators: %s: comparison name, "is empty" */
            'ruleBlankHint'     => __( 'Or choose “%s” to check for a blank value.', 'yaymatik' ),
            'ruleNumber'        => __( 'Enter a number.', 'yaymatik' ),
            'ruleDate'          => __( 'Enter a date (YYYY-MM-DD).', 'yaymatik' ),
            'ruleOption'        => __( 'Choose a value from the list.', 'yaymatik' ),
            'ruleListRequired'  => __( 'Choose at least one value.', 'yaymatik' ),
            'ruleAmount'        => __( 'Enter a whole number of 1 or more.', 'yaymatik' ),
            'ruleKeyRequired'   => __( 'Enter the custom field name.', 'yaymatik' ),
            'ruleMetaKey'       => __( 'This custom field is protected. Choose another one.', 'yaymatik' ),
        ];
    }

    /**
     * One message, with its placeholders filled in.
     *
     * @param string $key
     * @param mixed  ...$args
     */
    public static function get( string $key, ...$args ): string {
        $message = self::all()[ $key ] ?? $key;
        return $args ? vsprintf( $message, $args ) : $message;
    }
}
