<?php

declare(strict_types=1);

return ['path' => env('LOG_PATH', 'php://stderr'), 'level' => env('LOG_LEVEL', 'info')];
