<?php

declare(strict_types=1);

/** One-line probe: if this 500s but /tt-ping.php works, the /api/ URL path is blocked or misconfigured. */
header('Content-Type: text/plain; charset=utf-8');
echo 'api_dir_ok';
