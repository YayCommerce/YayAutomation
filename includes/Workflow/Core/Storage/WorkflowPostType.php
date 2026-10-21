<?php
namespace YayMatik\Workflow\Storage;

use YayMatik\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Private storage for workflows. Only reachable through our REST routes. */
class WorkflowPostType {

    use SingletonTrait;

    public const NAME = 'yaymatik_wf';

    protected function __construct() {
        add_action( 'init', [ $this, 'register' ] );
    }

    public function register() {
        register_post_type(
            self::NAME,
            [
                'label'        => __( 'Workflows', 'yaymatik' ),
                'public'       => false,
                'show_ui'      => false,
                'show_in_rest' => false,
                'rewrite'      => false,
                'query_var'    => false,
                'supports'     => [ 'title' ],
            ]
        );
    }
}
