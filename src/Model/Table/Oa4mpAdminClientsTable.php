<?php
/**
 * COmanage Registry Oa4mpClient Plugin Admin Clients Table
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

namespace Oa4mpClient\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Admin clients, the plugin's Server entry point. Every other plugin table
 * hangs below this one, so Registry finds the CO of any plugin record through
 * the core Server row.
 */
class Oa4mpAdminClientsTable extends Table
{
  use \App\Lib\Traits\AutoViewVarsTrait;
  use \App\Lib\Traits\CoLinkTrait;
  use \App\Lib\Traits\LabeledLogTrait;
  use \App\Lib\Traits\PermissionsTrait;
  use \App\Lib\Traits\PrimaryLinkTrait;
  use \App\Lib\Traits\TableMetaTrait;
  use \App\Lib\Traits\ValidationTrait;

  /**
   * Perform Cake Model initialization.
   *
   * @param array $config Configuration options passed to constructor
   */
  public function initialize(array $config): void
  {
    parent::initialize($config);

    $this->addBehavior('Changelog');
    $this->addBehavior('Log');
    $this->addBehavior('Timestamp');

    $this->setTableType(\App\Lib\Enum\TableTypeEnum::Configuration);

    $this->belongsTo('Servers');

    $this->setDisplayField('id');

    $this->setPrimaryLink('server_id');
    $this->setRequiresCO(true);

    $this->setPermissions([
      'ui' => [
        'entity' => [
          // An Admin client is deleted through its Server
          'delete' => false,
          'edit' => ['platformAdmin'],
          'view' => ['platformAdmin'],
        ],
        'table' => [
          'add' => ['platformAdmin'],
          'index' => ['platformAdmin'],
        ],
      ],
    ]);
  }

  /**
   * Set validation rules.
   *
   * @param Validator $validator Validator
   * @return Validator           Validator
   */
  public function validationDefault(Validator $validator): Validator
  {
    $validator->add('server_id', [
      'content' => ['rule' => 'isInteger'],
    ]);
    $validator->notEmptyString('server_id');

    return $validator;
  }
}
