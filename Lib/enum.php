<?php
/**
 * COmanage Registry Oa4mp Client Plugin Enums
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
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry
 * @since         COmanage Registry v2.0.1
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

class Oa4mpClientScopeEnum
{
  const OpenId = 'openid';
  const Profile = 'profile';
  const Email = 'email';
  const OrgCilogonUserInfo = 'org.cilogon.userinfo';
  const Getcert = 'edu.uiuc.ncsa.myproxy.getcert';

  public static $allScopesArray = array(
    Oa4mpClientScopeEnum::OpenId,
    Oa4mpClientScopeEnum::Profile,
    Oa4mpClientScopeEnum::Email,
    Oa4mpClientScopeEnum::OrgCilogonUserInfo,
    Oa4mpClientScopeEnum::Getcert,
  );
}