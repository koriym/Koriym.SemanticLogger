<?php

declare(strict_types=1);

echo "=== PHP Extensions Diagnostic Tool ===\n";
echo 'PHP Version: ' . PHP_VERSION . "\n";
echo 'PHP SAPI: ' . PHP_SAPI . "\n\n";

echo "=== Quick Status Check ===\n";

// Check extension directory
$extensionDir = ini_get('extension_dir');
$extensionDirExists = is_dir($extensionDir);

// Check for extension files
$xdebugFiles = glob($extensionDir . '/xdebug*.so');
$xhprofFiles = glob($extensionDir . '/xhprof*.so');
$xdebugFilesExist = ! empty($xdebugFiles);
$xhprofFilesExist = ! empty($xhprofFiles);

// Check if extensions are loaded
$xdebugLoaded = extension_loaded('xdebug');
$xhprofLoaded = extension_loaded('xhprof');

echo '✅ Extension directory: ' . ($extensionDirExists ? 'OK' : 'Missing') . "\n";
echo '✅ Xdebug files: ' . ($xdebugFilesExist ? 'Found' : 'Missing') . "\n";
echo '✅ XHProf files: ' . ($xhprofFilesExist ? 'Found' : 'Missing') . "\n";
echo 'ℹ️  Xdebug loaded: ' . ($xdebugLoaded ? 'Yes' : 'No (on-demand)') . "\n";
echo 'ℹ️  XHProf loaded: ' . ($xhprofLoaded ? 'Yes' : 'No (on-demand)') . "\n";

if ($extensionDirExists && $xdebugFilesExist && $xhprofFilesExist) {
    echo "\n🎉 System ready for profiling!\n";
    echo "\n🔧 To profile scripts:\n";
    echo "   • Basic: php -d zend_extension=xdebug -d extension=xhprof script.php\n";
    echo "   • With dev config: php -c bin/php-dev.ini -d zend_extension=xdebug -d extension=xhprof script.php\n";
    echo "   • Profiling: XDEBUG_MODE=profile php -c bin/php-dev.ini -d zend_extension=xdebug script.php\n";
    echo "   • Tracing: XDEBUG_MODE=trace php -c bin/php-dev.ini -d zend_extension=xdebug script.php\n";
    echo "   • Both modes: XDEBUG_MODE=profile,trace php -c bin/php-dev.ini -d zend_extension=xdebug script.php\n";
    echo "\n💡 php-dev.ini includes optimized profiling settings (output_dir, trace format, etc.)\n";
} else {
    echo "\n❌ System not ready for profiling\n";
    if (! $extensionDirExists) {
        echo "• Extension directory missing: $extensionDir\n";
    }

    if (! $xdebugFilesExist) {
        echo "• Install Xdebug: brew install php-xdebug (or equivalent)\n";
    }

    if (! $xhprofFilesExist) {
        echo "• Install XHProf: brew install php-xhprof (or equivalent)\n";
    }
}

echo "\n=== Diagnostic Complete ===\n";
