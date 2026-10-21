<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Conditions\Criteria;
use YayMatik\Workflow\Data\SiteData;
use YayMatik\Workflow\Modules\Module;

/** Another plugin's module: one new condition, ids core already owns, and a class under the wrong kind. */
final class FakeModule extends Module {

    public function id(): string {
        return 'fake';
    }

    public function label(): string {
        return 'Fake';
    }

    public function description(): string {
        return '';
    }

    public function logo(): string {
        return 'https://example.test/fake/logo.svg';
    }

    public function conditions(): array {
        return [ FakeCondition::class, Criteria::class ];
    }

    public function actions(): array {
        return [ FakeCondition::class ];
    }

    public function data_items(): array {
        return [ SiteData::class ];
    }
}
