<?php
/**
 * COmanage Registry Oa4mpClient Plugin Test Bootstrap
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

// Both suites run inside the Registry 5 image, with this plugin mounted at
// local/plugins/Oa4mpClient. tests/run.sh is the supported way to run them.
//
// The Registry root is named here rather than searched for. Core plugin
// bootstraps walk up from the plugin looking for vendor/cakephp/cakephp, which
// finds nothing for a plugin under local/plugins: local/ sits beside app/,
// not inside it.
$registryRoot = '/srv/comanage-registry/app';

if (!is_file($registryRoot . '/vendor/autoload.php')) {
  fwrite(
    STDERR,
    "No Registry 5 installation at $registryRoot. Run the tests with "
    . "tests/run.sh, which runs them inside the Registry image.\n"
  );
  exit(1);
}

$suite = getenv('OA4MP_TEST_SUITE');

if ($suite !== 'unit' && $suite !== 'integration') {
  fwrite(
    STDERR,
    "Set OA4MP_TEST_SUITE to unit or integration, or run tests/run.sh.\n"
  );
  exit(1);
}

$pluginRoot = dirname(__DIR__);

$loader = require $registryRoot . '/vendor/autoload.php';
$loader->addPsr4('Oa4mpClient\\', $pluginRoot . '/src/');
$loader->addPsr4('Oa4mpClient\\Test\\', $pluginRoot . '/tests/');

if ($suite === 'unit') {
  return;
}

chdir($registryRoot);

// Registry's own test bootstrap loads config/bootstrap.php, which reads the
// database connection from local/config/database.php.
require $registryRoot . '/tests/bootstrap.php';

// Registry ships a "test" datasource with no host or credentials.
\Cake\Datasource\ConnectionManager::drop('test');
\Cake\Datasource\ConnectionManager::setConfig(
  'test',
  \Cake\Datasource\ConnectionManager::getConfig('default')
);
\Cake\TestSuite\ConnectionHelper::addTestAliases();
