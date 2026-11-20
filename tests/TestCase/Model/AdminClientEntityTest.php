<?php
/**
 * COmanage Registry Oa4mpClient Plugin Admin Client Entity Test
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

namespace Oa4mpClient\Test\TestCase\Model;

use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Oa4mpClient\Model\Entity\Oa4mpAdminClient;

/**
 * The Admin client entry point: what Registry can create, what posted data
 * cannot change, and what the table validates.
 */
class AdminClientEntityTest extends TestCase
{
  /**
   * The Admin clients table, loaded the way Registry loads a plugin model.
   *
   * @return Table The table
   */
  protected function adminClients(): Table
  {
    return $this->getTableLocator()->get('Oa4mpClient.Oa4mpAdminClients');
  }

  /**
   * Registry creates the record by mass assigning the Server link when a
   * Server of this type is added, so that one assignment must work.
   */
  public function testTheServerLinkIsSetWhenTheRecordIsCreated(): void
  {
    $entity = $this->adminClients()->newEntity(['server_id' => 5]);

    $this->assertInstanceOf(Oa4mpAdminClient::class, $entity);
    $this->assertSame(5, $entity->server_id);
  }

  /**
   * A saved Admin client cannot be moved to another Server by posted data.
   */
  public function testASavedRecordCannotBeRepointed(): void
  {
    $table = $this->adminClients();
    $entity = $table->newEntity(['server_id' => 5]);
    $entity->setNew(false);
    $entity->clean();

    $table->patchEntity($entity, ['server_id' => 9]);

    $this->assertSame(5, $entity->server_id);
  }

  /**
   * Once the link is set, a second assignment before the first save cannot
   * change it either.
   */
  public function testANewRecordKeepsItsFirstServerLink(): void
  {
    $table = $this->adminClients();
    $entity = $table->newEntity(['server_id' => 5]);

    $table->patchEntity($entity, ['server_id' => 9]);

    $this->assertSame(5, $entity->server_id);
  }

  /**
   * Nothing else on the record is mass assignable, including its id and the
   * fields Registry manages.
   */
  public function testNoOtherFieldIsMassAssignable(): void
  {
    $entity = $this->adminClients()->newEntity([
      'server_id' => 5,
      'id' => 77,
      'deleted' => true,
      'actor_identifier' => 'someone-else',
    ]);

    $this->assertSame(5, $entity->server_id);
    $this->assertNull($entity->id);
    $this->assertNull($entity->deleted);
    $this->assertNull($entity->actor_identifier);
  }

  /**
   * The Server link is required and must be an integer.
   */
  public function testTheServerLinkIsValidated(): void
  {
    $table = $this->adminClients();

    $this->assertArrayHasKey('server_id', $table->newEntity([])->getErrors());
    $this->assertArrayHasKey(
      'server_id',
      $table->newEntity(['server_id' => 'abc'])->getErrors()
    );
    $this->assertSame([], $table->newEntity(['server_id' => 5])->getErrors());
  }

  /**
   * Every Server-type entry point config/plugin.json declares resolves to a
   * real table class of this plugin, linked to core's Servers.
   */
  public function testEveryDeclaredServerTypeResolves(): void
  {
    $json = file_get_contents(dirname(__DIR__, 3) . '/config/plugin.json');
    $models = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['types']['server'];

    $this->assertNotEmpty($models, 'config/plugin.json declares no server type');

    foreach ($models as $model) {
      $table = $this->getTableLocator()->get('Oa4mpClient.' . $model);

      $this->assertStringStartsWith(
        'Oa4mpClient\\Model\\Table\\',
        get_class($table),
        "$model did not resolve to the plugin's table class"
      );
      $this->assertTrue(
        $table->hasAssociation('Servers'),
        "$model is not linked to core's Servers"
      );
    }
  }
}
