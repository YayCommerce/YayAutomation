<?php
/**
 * YayLoyalty's fake adapter: points kept in user meta, tiers by balance, no YayLoyalty code.
 *
 * @package YayLoyalty
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Stands in for YayLoyalty until its real adapter is written. */
final class YayLoyalty_YayMatik_Fake_Adapter {

    private const POINTS_META = '_yayloyalty_fake_points';

    /** Option: `Step::key()` => the award's outputs, so a retried or replayed step awards once. */
    public const GRANTS = 'yayloyalty_fake_grants';

    /** Tier => the balance it starts at. */
    private const TIERS = [
        'gold'   => 2000,
        'silver' => 500,
        'bronze' => 0,
    ];

    /** Tier => label. */
    public function tier_options(): array {
        return [
            'bronze' => 'Bronze',
            'silver' => 'Silver',
            'gold'   => 'Gold',
        ];
    }

    /**
     * @param \YayMatik\Api\CustomerView $customer
     */
    public function customer_points( $customer ): int {
        return $customer->user_id() ? (int) get_user_meta( $customer->user_id(), self::POINTS_META, true ) : 0;
    }

    /**
     * @param \YayMatik\Api\CustomerView $customer
     */
    public function customer_tier( $customer ): string {
        $points = $this->customer_points( $customer );
        foreach ( self::TIERS as $tier => $from ) {
            if ( $points >= $from ) {
                return $tier;
            }
        }
        return 'bronze';
    }

    /**
     * @param array              $config
     * @param \YayMatik\Api\Step $step
     */
    public function award_points( array $config, $step ) {
        $user = $step->user();
        if ( ! $user ) {
            return $step->skip( __( 'Guests can’t collect points.', 'yayloyalty' ) );
        }
        if ( $step->is_test() ) {
            return $step->skip( __( 'A test gives no points.', 'yayloyalty' ) );
        }
        $grants = (array) get_option( self::GRANTS, [] );
        $key    = $step->key();
        if ( '' !== $key && isset( $grants[ $key ] ) ) {
            return $step->done( $grants[ $key ] );
        }
        $points  = (int) $config['points'];
        $balance = (int) get_user_meta( $user->ID, self::POINTS_META, true ) + $points;
        update_user_meta( $user->ID, self::POINTS_META, $balance );
        $award = [
            'points_added' => $points,
            'balance'      => $balance,
        ];
        if ( '' !== $key ) {
            $grants[ $key ] = $award;
            update_option( self::GRANTS, $grants, false );
        }
        return $step->done( $award );
    }
}
