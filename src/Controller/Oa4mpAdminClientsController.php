<?php
/**
 * COmanage Registry Oa4mpClient Plugin Admin Clients Controller
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

namespace Oa4mpClient\Controller;

use App\Controller\StandardPluginController;
use Cake\Event\EventInterface;

class Oa4mpAdminClientsController extends StandardPluginController
{
  /**
   * Callback run prior to the request render: name the parent Server in the
   * breadcrumbs.
   *
   * @param EventInterface $event Cake Event
   * @return \Cake\Http\Response|null
   */
  public function beforeRender(EventInterface $event): ?\Cake\Http\Response
  {
    $link = $this->getPrimaryLink(true);

    if (!empty($link->value)) {
      $this->set('vv_bc_parent_obj', $this->Oa4mpAdminClients->Servers->get($link->value));
      $this->set('vv_bc_parent_displayfield', $this->Oa4mpAdminClients->Servers->getDisplayField());
      $this->set('vv_bc_parent_primarykey', $this->Oa4mpAdminClients->Servers->getPrimaryKey());
    }

    return parent::beforeRender($event);
  }
}
