<?php

return [

    'max_upload_bytes' => env('IMPORT_MAX_UPLOAD_BYTES', 100 * 1024 * 1024),

    // Rows per batch insert and transaction when processing an import.
    'process_chunk_size' => 1000,

    // Number of row errors reported back in a response; the counters
    // themselves are never truncated.
    'max_reported_row_errors' => 10,

];
