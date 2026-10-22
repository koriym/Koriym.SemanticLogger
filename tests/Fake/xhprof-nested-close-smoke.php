<?php

/**
 * Standalone fixture run as a child PHP process (with -d extension=xhprof)
 * by DevSemanticLoggerXhprofCrashTest. Must run out-of-process: a crash here
 * must not take down the PHPUnit process driving the test.
 *
 * This exact shape (close a child to resume the parent segment, open+close
 * a *sibling* at the same depth to resume it again, then close the parent)
 * is load-bearing: a simpler strictly-nested 3-level open/close (l1 > l2 >
 * l3, close l3, close l2, close l1) does NOT reproduce the crash even
 * against a partial fix that still segfaults on this shape — removing only
 * DevSemanticLogger::close from ignored_functions (and leaving
 * stopAndAttachTo/stopAndAttachToCurrent/XHProfResult::stop/saveToFile in
 * place) passes the simpler shape but still segfaults here. Do not simplify
 * this fixture without re-verifying against a partial fix.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Koriym\SemanticLogger\DevSemanticLogger;
use Koriym\SemanticLogger\FakeContext;
use Koriym\SemanticLogger\SemanticLogger;

$logger = new DevSemanticLogger(new SemanticLogger());
$l1 = $logger->open(new FakeContext('l1'));
$l2 = $logger->open(new FakeContext('l2'));
$l3 = $logger->open(new FakeContext('l3'));
usleep(1000);
$logger->close(new FakeContext('l3-end'), $l3);
usleep(1000);
$l3b = $logger->open(new FakeContext('l3b'));
usleep(1000);
$logger->close(new FakeContext('l3b-end'), $l3b);
$logger->close(new FakeContext('l2-end'), $l2);
$logger->close(new FakeContext('l1-end'), $l1);

echo json_encode($logger->flush());
