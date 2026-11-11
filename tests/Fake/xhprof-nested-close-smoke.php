<?php

/**
 * Run by DevSemanticLoggerXhprofCrashTest in a child PHP process with xhprof loaded; prints the first session's log.
 * Keep the shape: a sibling reopened under a resumed parent, then a flush while a resumed parent is still open.
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
$json = json_encode($logger->flush());

$parent = $logger->open(new FakeContext('parent'));
$child = $logger->open(new FakeContext('child'));
$logger->close(new FakeContext('child-end'), $child);
$logger->flush();

echo $json;
