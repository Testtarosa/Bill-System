<?php

class Test_Case_672 {
    public function test_case() {
        return [
    'preRun' => 'changeConfig',
    'test' => [
        'test_number' => 672,
        'aid' => 0,
        'function' => [
            'fullCycle',
        ],
        'overrideConfig' => [
            'key' => 'billrun.charging_day.v',
            'value' => 1,
        ],
        'options' => [
            'stamp' => '201806',
            'page' => 0,
            'size' => 10000000,
        ],
    ],
    'expected' => [
    ],
];
    }
}