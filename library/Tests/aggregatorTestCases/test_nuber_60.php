<?php

class Test_Case_60 {
    public function test_case() {
        return [
    'test' => [
        'test_number' => 60,
        'aid' => 1203,
        'sid' => 203,
        'function' => [
            'basicCompare',
            'totalsPrice',
            'lineExists',
            'linesVSbillrun',
            'rounded',
        ],
        'options' => [
            'stamp' => '202004',
            'force_accounts' => [
                1203,
            ],
        ],
    ],
    'expected' => [
        'billrun' => [
            'billrun_key' => '202004',
            'aid' => 1203,
            'after_vat' => [
                203 => 40.76129119,
            ],
            'total' => 40.76129119,
            'vatable' => 34.83870926,
            'vat' => 17,
        ],
        'line' => [
            'types' => [
                'flat',
                'credit',
            ],
        ],
    ],
    'jiraLink' => 'https://billrun.atlassian.net/browse/BRCD-1913',
];
    }
}