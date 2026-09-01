<?php

return [
    'bulk_changes' => [
        'max_import_rows' => (int) env('NAMESHIFT_BULK_MAX_IMPORT_ROWS', 5000),
        'dispatch_chunk_size' => (int) env('NAMESHIFT_BULK_DISPATCH_CHUNK_SIZE', 100),
    ],
];
