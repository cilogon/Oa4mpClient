<?php
/**
 * COmanage Registry Oa4mpClient Plugin Parity Fixture Sync
 *
 * Portions licensed to the University Corporation for Advanced Internet
 * Development, Inc. ("UCAID") under one or more contributor license agreements.
 * See the NOTICE file distributed with this work for additional information
 * regarding copyright ownership.
 *
 * UCAID licenses this file to you under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @link          https://www.internet2.edu/comanage COmanage Project
 * @package       registry-plugins
 * @since         Oa4mpClient v8.0.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

// Copy the Parity fixtures and the output Registry 4 recorded for them from
// a commit of a Registry 4 edition checkout into tests/Parity/, and record
// that commit's full SHA in tests/Parity/SOURCE.
//
// Usage: php bin/parity-sync.php <path to a Registry 4 edition checkout> [<commit-ish>]
//
// The commit-ish defaults to main. Files are read from the commit with
// git show, never from the checkout's working tree, so whatever that
// checkout has checked out or modified does not leak in. Both directories
// are replaced whole, so a fixture removed on the Registry 4 side is removed
// here too. Review the diff of tests/Parity/ before committing it.

declare(strict_types=1);

$usage = "Usage: php bin/parity-sync.php <path to a Registry 4 edition checkout> [<commit-ish>]\n";

if ($argc < 2 || $argc > 3) {
  fwrite(STDERR, $usage);
  exit(64);
}

$checkout = $argv[1];
$commitish = $argv[2] ?? 'main';
$parityDir = dirname(__DIR__) . '/tests/Parity';
$sourceDir = 'Test/Parity';
$copied = ['fixtures', 'expected'];

// Run git in the checkout with an argument list, never through a shell, and
// return its standard output. Any failure stops the sync.
$git = function (array $args) use ($checkout): string {
  $process = proc_open(
    array_merge(['git', '-C', $checkout], $args),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
  );

  if (!is_resource($process)) {
    fwrite(STDERR, "Cannot run git\n");
    exit(1);
  }

  $out = stream_get_contents($pipes[1]);
  $err = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);

  if (proc_close($process) !== 0) {
    fwrite(STDERR, 'git ' . implode(' ', $args) . " failed: " . $err);
    exit(1);
  }

  return (string)$out;
};

// Remove a directory tree this script wrote. Only ever called on a
// directory below tests/Parity.
$removeTree = function (string $dir) use (&$removeTree): void {
  if (!is_dir($dir)) {
    return;
  }

  foreach (scandir($dir) as $entry) {
    if ($entry === '.' || $entry === '..') {
      continue;
    }

    $path = $dir . '/' . $entry;

    if (is_dir($path) && !is_link($path)) {
      $removeTree($path);
    } elseif (!unlink($path)) {
      fwrite(STDERR, "Cannot remove $path\n");
      exit(1);
    }
  }

  if (!rmdir($dir)) {
    fwrite(STDERR, "Cannot remove $dir\n");
    exit(1);
  }
};

$sha = trim($git(['rev-parse', '--verify', '--end-of-options', $commitish . '^{commit}']));

if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
  fwrite(STDERR, "$commitish does not name a commit in $checkout\n");
  exit(1);
}

$paths = [];
foreach ($copied as $dir) {
  $listed = $git(['ls-tree', '-r', '--name-only', $sha, '--', $sourceDir . '/' . $dir . '/']);
  $found = array_values(array_filter(explode("\n", $listed), fn (string $line): bool => $line !== ''));

  if (empty($found)) {
    fwrite(STDERR, "Commit $sha has nothing under $sourceDir/$dir/\n");
    exit(1);
  }

  $paths = array_merge($paths, $found);
}

foreach ($copied as $dir) {
  $removeTree($parityDir . '/' . $dir);
}

foreach ($paths as $path) {
  $relative = substr($path, strlen($sourceDir) + 1);

  // A tree entry is a plain relative path under the directory; refuse
  // anything that could write outside it.
  if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
    fwrite(STDERR, "Refusing the path $path\n");
    exit(1);
  }

  $target = $parityDir . '/' . $relative;

  if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
    fwrite(STDERR, "Cannot create " . dirname($target) . "\n");
    exit(1);
  }

  $bytes = $git(['show', $sha . ':' . $path]);

  if (file_put_contents($target, $bytes) !== strlen($bytes)) {
    fwrite(STDERR, "Cannot write $target\n");
    exit(1);
  }
}

if (file_put_contents($parityDir . '/SOURCE', $sha . "\n") === false) {
  fwrite(STDERR, "Cannot write $parityDir/SOURCE\n");
  exit(1);
}

echo "Copied " . count($paths) . " files from $sha into $parityDir\n";
