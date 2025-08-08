<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Check for profiling extensions
echo "=== Profiling Extension Status ===\n";
$hasXhprof = extension_loaded('xhprof');
$hasXdebug = extension_loaded('xdebug');

echo 'XHProf: ' . ($hasXhprof ? '✓ Available' : '✗ Not available') . "\n";
echo 'Xdebug: ' . ($hasXdebug ? '✓ Available' : '✗ Not available') . "\n";

// Assert that profiling functions are available
assert(
    function_exists('xhprof_enable') || function_exists('xdebug_start_trace'),
    'Profiling functions not available: install XHProf (xhprof_enable) or Xdebug with trace mode (xdebug_start_trace)',
);

if ($hasXhprof && $hasXdebug) {
    echo "✓ Both profiling extensions available for comprehensive profiling.\n";
} elseif ($hasXhprof) {
    echo "✓ XHProf available for function-level profiling.\n";
} else {
    echo "✓ Xdebug available for trace profiling.\n";
}

echo "\n";


require __DIR__ . '/e-commerce.php';

use Koriym\SemanticLogger\ComplexWebRequestSimulation;

$simulation = new ComplexWebRequestSimulation();
$simulation->run();
