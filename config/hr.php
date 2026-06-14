<?php

return [
    'filesystem_disk' => env('HR_FILESYSTEM_DISK', env('PROJECT_FILESYSTEM_DISK', 'local')),
    'default_tax_rate' => (float) env('HR_DEFAULT_TAX_RATE', 0),
];
