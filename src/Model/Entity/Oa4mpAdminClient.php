<?php
/**
 * COmanage Registry Oa4mpClient Plugin Admin Client Entity
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

namespace Oa4mpClient\Model\Entity;

use Cake\ORM\Entity;

/**
 * An Admin client: the OA4MP credential a CO uses to manage its OIDC clients,
 * attached to a core Server row.
 */
class Oa4mpAdminClient extends Entity
{
  use \App\Lib\Traits\EntityMetaTrait;

  /**
   * Nothing is mass assigned from posted data. The plugin's own actions set
   * each field explicitly, so data posted to a page or to core's Servers API
   * cannot configure an Admin client.
   *
   * @var array<string, bool>
   */
  protected array $_accessible = [
    '*' => false,
  ];

  /**
   * Allow the link to the core Server row to be set only while the record is
   * new. Registry creates this record by mass assigning that link when a
   * Server of this type is added; afterwards it can never be re-pointed.
   *
   * @param string $field Field name
   * @return bool         Whether the field may be mass assigned
   */
  public function isAccessible(string $field): bool
  {
    if ($field === 'server_id') {
      return $this->isNew() && !$this->has('server_id');
    }

    return parent::isAccessible($field);
  }
}
