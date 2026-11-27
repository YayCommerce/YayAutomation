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
            'nameRequired'       => __( 'Give this workflow a name.', 'yaymatik-automation-for-woocommerce' ),
            'noTrigger'          => __( 'Choose a trigger.', 'yaymatik-automation-for-woocommerce' ),
            'noAction'           => __( 'Add at least one action.', 'yaymatik-automation-for-woocommerce' ),
            'unlinked'           => __( 'Some steps aren’t connected. Connect them or remove them.', 'yaymatik-automation-for-woocommerce' ),
            'noBlock'            => __( 'Choose an action.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: plugin name, e.g. "YayMail" */
            'missingPlugin'      => __( 'This workflow uses %1$s, which isn’t active. Activate %1$s to turn the workflow on.', 'yaymatik-automation-for-woocommerce' ),
            'deleteActive'       => __( 'Deactivate this workflow before you delete it.', 'yaymatik-automation-for-woocommerce' ),
            'needsPro'           => __( 'Needs YayMatik Pro. Activate YayMatik Pro to turn the workflow on.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: data item or step, e.g. "Order" */
            'missingData'        => __( '%s data isn’t available at this step.', 'yaymatik-automation-for-woocommerce' ),
            'mayRefire'          => __( 'This step can set off this workflow’s own trigger. The workflow won’t start again from it.', 'yaymatik-automation-for-woocommerce' ),
            // Merge tags.
            /* translators: %s: merge tag, e.g. {{ order.foo }} */
            'unknownVariable'    => __( '%s isn’t an available variable.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: 1: filter name, e.g. shout; 2: the available filters, e.g. "default, upper" */
            'unknownFilter'      => __( '%1$s isn’t a filter. Available filters: %2$s.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: merge tag, e.g. {{ steps.coupon.code }} */
            'stepGone'           => __( '%s comes from a step that isn’t in this workflow anymore. Add that step above this one, or remove the variable.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: 1: merge tag, e.g. {{ steps.coupon.code }}; 2: step name, e.g. "Generate coupon" */
            'stepNotAbove'       => __( '%1$s needs the %2$s step above this one. Move it above, or remove the variable.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: a merge tag as written, e.g. {{ order. }}. Keep {{ variable }} and {{ variable | filter }} as they are. */
            'malformedTag'       => __( '%s is written incorrectly. Use {{ variable }} or {{ variable | filter }}.', 'yaymatik-automation-for-woocommerce' ),
            // Step settings.
            'fieldRequired'      => __( 'This field is required.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: the text entered as an email address */
            'invalidEmail'       => __( '%s isn’t a valid email address.', 'yaymatik-automation-for-woocommerce' ),
            'chooseOption'       => __( 'Choose an option from the list.', 'yaymatik-automation-for-woocommerce' ),
            'chooseOptions'      => __( 'Choose options from the list.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: smallest number allowed */
            'numberMin'          => __( 'Enter %s or more.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: largest number allowed */
            'numberMax'          => __( 'Enter %s or less.', 'yaymatik-automation-for-woocommerce' ),
            // Condition rules.
            'rulesRequired'      => __( 'Add a rule, or remove this condition.', 'yaymatik-automation-for-woocommerce' ),
            'groupRulesRequired' => __( 'Add a rule, or remove this group.', 'yaymatik-automation-for-woocommerce' ),
            'ruleVariable'       => __( 'Choose data for each rule.', 'yaymatik-automation-for-woocommerce' ),
            'ruleComparison'     => __( 'Choose a comparison for each rule.', 'yaymatik-automation-for-woocommerce' ),
            'ruleCompareWith'    => __( 'Choose data to compare with.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: variable, e.g. "Order total" */
            'ruleValueRequired'  => __( 'Enter a value for %s.', 'yaymatik-automation-for-woocommerce' ),
            /* translators: %s: comparison name, "is empty" */
            'ruleBlankHint'      => __( 'Or choose “%s” to check for a blank value.', 'yaymatik-automation-for-woocommerce' ),
            'ruleNumber'         => __( 'Enter a number.', 'yaymatik-automation-for-woocommerce' ),
            'ruleDate'           => __( 'Enter a date (YYYY-MM-DD).', 'yaymatik-automation-for-woocommerce' ),
            'ruleOption'         => __( 'Choose a value from the list.', 'yaymatik-automation-for-woocommerce' ),
            'ruleListRequired'   => __( 'Choose at least one value.', 'yaymatik-automation-for-woocommerce' ),
            'ruleAmount'         => __( 'Enter a whole number of 1 or more.', 'yaymatik-automation-for-woocommerce' ),
            'ruleKeyRequired'    => __( 'Enter the custom field name.', 'yaymatik-automation-for-woocommerce' ),
            'ruleMetaKey'        => __( 'This custom field is protected. Choose another one.', 'yaymatik-automation-for-woocommerce' ),
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
