<?php

class Test_Case_64 {
    public function test_case() {
        return [
    'test' => [
        'test_number' => 64,
        'aid' => 1603,
        'sid' => 603,
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
                1603,
            ],
        ],
    ],
    'expected' => [
        'billrun' => [
            'billrun_key' => '202004',
            'aid' => 1603,
            'after_vat' => [
                603 => 40.76129119,
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