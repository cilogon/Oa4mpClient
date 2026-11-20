<?php
/**
 * COmanage Registry Oa4mpClient Plugin Pull Request Gate Safeguards Test
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

declare(strict_types=1);

namespace Oa4mpClient\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Locks the parts of the pull request gate whose broken state looks exactly
 * like a pass: a secret scan with no rules loaded reports "no leaks found",
 * and an image followed by tag can change under the gate unnoticed.
 */
class GateSafeguardsTest extends TestCase
{
  /**
   * The gitleaks configuration adds to the built-in rules. Without
   * [extend] useDefault = true it replaces them, and the scan finds nothing.
   */
  public function testSecretScanExtendsTheDefaultRules(): void
  {
    $toml = $this->directives('.gitleaks.toml');

    $this->assertMatchesRegularExpression('/^\[extend\]$/m', $toml);
    $this->assertMatchesRegularExpression(
      '/^useDefault\s*=\s*true$/m',
      $toml,
      'useDefault = true keeps the built-in rules armed beside the allowlist'
    );

    // A config the scanner cannot read must fail loudly, not fall back.
    $this->assertStringContainsString(
      '--config=/repo/.gitleaks.toml',
      $this->directives('.github/workflows/hermetic-tests.yml')
    );
  }

  /**
   * gitleaks ORs allowlist conditions together, so a paths, commits or
   * stopwords entry would exempt every rule across a whole file or commit.
   * Only literal regexes are allowed.
   */
  public function testSecretScanAllowlistExemptsLiteralsOnly(): void
  {
    $toml = $this->directives('.gitleaks.toml');

    foreach (['paths', 'commits', 'stopwords'] as $blanket) {
      $this->assertDoesNotMatchRegularExpression(
        '/^' . $blanket . '\s*=/m',
        $toml,
        "the allowlist must not exempt by $blanket"
      );
    }
  }

  /**
   * Images the gate pulls are pinned by digest, so what the gate runs
   * changes only with an edit here. Each is named on an "image:" or
   * "..._IMAGE:" line. A value built from a variable names the Registry
   * image, which is built locally from a pinned commit instead.
   */
  public function testPulledImagesArePinnedByDigest(): void
  {
    $sources = [
      'tests/docker/compose.yaml',
      '.github/workflows/hermetic-tests.yml',
    ];

    foreach ($sources as $source) {
      preg_match_all(
        '/^(?:image|[A-Z0-9_]+_IMAGE):\s*([^\s$]+)$/m',
        $this->directives($source),
        $matches
      );

      $this->assertNotEmpty($matches[1], "no pulled image found in $source");

      foreach ($matches[1] as $image) {
        $this->assertMatchesRegularExpression(
          '/@sha256:[0-9a-f]{64}$/',
          $image,
          "$source pulls $image without a digest"
        );
      }
    }
  }

  /**
   * Read a repository file with comment lines and indentation removed, so a
   * setting that survives only in a comment does not count.
   *
   * @param string $path Path relative to the repository root
   * @return string       The file's non-comment lines, trimmed
   */
  private function directives(string $path): string
  {
    $text = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    $this->assertIsString($text, "cannot read $path");

    $lines = [];

    foreach (explode("\n", $text) as $line) {
      $line = trim($line);

      if ($line !== '' && !str_starts_with($line, '#')) {
        $lines[] = $line;
      }
    }

    return implode("\n", $lines);
  }
}
