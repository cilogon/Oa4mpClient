<?php
/**
 * Workbench seed command for Oa4mpClient plugin development.
 *
 * Mounted into the workbench's Registry container only. It creates one CO,
 * three groups, and one person for each kind of person the plugin treats
 * differently, keyed by login identifiers that match the test-login accounts.
 * Running it again changes nothing that already exists.
 *
 * Run: bin/cake workbench_seed
 */

declare(strict_types=1);

namespace App\Command;

use App\Lib\Enum\GroupTypeEnum;
use App\Lib\Enum\StatusEnum;
use App\Lib\Enum\SuspendableStatusEnum;
use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;

class WorkbenchSeedCommand extends BaseCommand
{
  use \Cake\ORM\Locator\LocatorAwareTrait;

  public const CO_NAME = 'Workbench CO';

  public const GROUP_MANAGERS = 'Workbench OIDC Client Managers';
  public const GROUP_EDITORS = 'Workbench OIDC Client Editors';
  public const GROUP_AUTHORIZED = 'Workbench Authorized Users';

  /**
   * Seed the workbench CO, groups and people.
   *
   * @param Arguments $args Command arguments
   * @param ConsoleIo $io   Console IO
   * @return int            Exit code
   */
  public function execute(Arguments $args, ConsoleIo $io): int
  {
    $cos = $this->getTableLocator()->get('Cos');

    $platformCo = $cos->find('COmanageCO')->firstOrFail();
    $workbenchCoId = $this->ensureCo(io: $io);

    $groups = [];
    foreach ([self::GROUP_MANAGERS, self::GROUP_EDITORS, self::GROUP_AUTHORIZED] as $name) {
      $groups[$name] = $this->ensureGroup(coId: $workbenchCoId, name: $name, io: $io);
    }

    $platformAdmins = $cos->Groups->find('adminGroup', co_id: $platformCo->id)->firstOrFail();
    $coAdmins = $cos->Groups->find('adminGroup', co_id: $workbenchCoId)->firstOrFail();

    // The setup command already created wb-platform-admin in the COmanage CO.
    $people = [
      ['wb-platform-admin-unlisted', $platformCo->id, StatusEnum::Active, [$platformAdmins->id]],
      ['wb-co-admin', $workbenchCoId, StatusEnum::Active, [$coAdmins->id]],
      ['wb-manager', $workbenchCoId, StatusEnum::Active, [$groups[self::GROUP_MANAGERS]]],
      ['wb-editor', $workbenchCoId, StatusEnum::Active, [$groups[self::GROUP_EDITORS]]],
      ['wb-member', $workbenchCoId, StatusEnum::Active, [$groups[self::GROUP_AUTHORIZED]]],
      ['wb-inactive', $workbenchCoId, StatusEnum::Suspended, [$groups[self::GROUP_AUTHORIZED]]],
    ];

    $maintainer = (string)env('WORKBENCH_MAINTAINER_LOGIN', '');
    if ($maintainer !== '') {
      $people[] = [$maintainer, $platformCo->id, StatusEnum::Active, [$platformAdmins->id]];
    }

    foreach ($people as [$login, $coId, $status, $groupIds]) {
      $this->ensurePerson(
        coId: $coId,
        login: $login,
        status: $status,
        groupIds: $groupIds,
        io: $io
      );
    }

    $io->out('Workbench seed complete.');

    return static::CODE_SUCCESS;
  }

  /**
   * Find or create the workbench CO.
   *
   * @param ConsoleIo $io Console IO
   * @return int          CO ID
   */
  protected function ensureCo(ConsoleIo $io): int
  {
    $cos = $this->getTableLocator()->get('Cos');

    $co = $cos->find()->where(['Cos.name' => self::CO_NAME])->first();
    if ($co !== null) {
      return $co->id;
    }

    // Saving a new CO runs its setup: default types, groups and settings.
    $co = $cos->newEntity([
      'name' => self::CO_NAME,
      'description' => 'CO for Oa4mpClient plugin development',
      'status' => StatusEnum::Active,
    ]);
    $cos->saveOrFail($co);
    $io->out('Created CO ' . self::CO_NAME . ' (' . $co->id . ')');

    return $co->id;
  }

  /**
   * Find or create a standard group in a CO.
   *
   * @param int       $coId CO ID
   * @param string    $name Group name
   * @param ConsoleIo $io   Console IO
   * @return int            Group ID
   */
  protected function ensureGroup(int $coId, string $name, ConsoleIo $io): int
  {
    $groupsTable = $this->getTableLocator()->get('Groups');

    $group = $groupsTable->find()
                         ->where(['Groups.co_id' => $coId, 'Groups.name' => $name])
                         ->first();
    if ($group !== null) {
      return $group->id;
    }

    $groupsTable->setCurCoId($coId);
    $group = $groupsTable->newEntity([
      'co_id' => $coId,
      'name' => $name,
      'group_type' => GroupTypeEnum::Standard,
      'open' => false,
      'status' => SuspendableStatusEnum::Active,
    ]);
    $groupsTable->saveOrFail($group);
    $io->out("Created group $name (" . $group->id . ')');

    return $group->id;
  }

  /**
   * Find or create a person with a login identifier, and make sure they hold
   * the given group memberships.
   *
   * @param int       $coId     CO ID
   * @param string    $login    Login identifier
   * @param string    $status   Person status
   * @param array     $groupIds Groups the person must belong to
   * @param ConsoleIo $io       Console IO
   */
  protected function ensurePerson(
    int $coId,
    string $login,
    string $status,
    array $groupIds,
    ConsoleIo $io
  ): void {
    $cos = $this->getTableLocator()->get('Cos');
    $people = $cos->People;

    // Models validate against the current CO's settings.
    $people->Names->setCurCoId($coId);
    $people->Identifiers->setCurCoId($coId);
    $people->PersonRoles->setCurCoId($coId);
    $people->GroupMembers->setCurCoId($coId);

    $existing = $people->Identifiers->find()
                                    ->where([
                                      'Identifiers.identifier' => $login,
                                      'Identifiers.login' => true,
                                    ])
                                    ->matching('People', function ($q) use ($coId) {
                                      return $q->where(['People.co_id' => $coId]);
                                    })
                                    ->first();

    if ($existing !== null) {
      $personId = $existing->person_id;
    } else {
      // Validation is skipped as the setup command does, since some
      // validation depends on configuration a fresh CO does not have yet.
      $noValidation = ['validate' => false, 'skipNormalization' => true];

      $person = $people->newEntity(['co_id' => $coId, 'status' => $status], $noValidation);

      $person->names = [$people->Names->newEntity([
        'type_id' => $cos->Types->getTypeId(
          coId: $coId,
          attribute: 'Names.type',
          value: 'official'
        ),
        'given' => 'Workbench',
        'family' => $login,
        'primary_name' => true,
      ], $noValidation)];

      $person->identifiers = [$people->Identifiers->newEntity([
        'type_id' => $cos->Types->getTypeId(
          coId: $coId,
          attribute: 'Identifiers.type',
          value: 'network'
        ),
        'identifier' => $login,
        'login' => true,
        'status' => SuspendableStatusEnum::Active,
      ], $noValidation)];

      $person->person_roles = [$people->PersonRoles->newEntity([
        'affiliation_type_id' => $cos->Types->getTypeId(
          coId: $coId,
          attribute: 'PersonRoles.affiliation_type',
          value: 'member'
        ),
        'title' => 'Workbench test person',
        'status' => $status,
      ], $noValidation)];

      $people->saveOrFail($person);
      $personId = $person->id;
      $io->out("Created person $login (" . $personId . ')');
    }

    foreach ($groupIds as $groupId) {
      $member = $people->GroupMembers->find()
                                     ->where([
                                       'GroupMembers.person_id' => $personId,
                                       'GroupMembers.group_id' => $groupId,
                                     ])
                                     ->first();
      if ($member === null) {
        $member = $people->GroupMembers->newEntity(
          ['person_id' => $personId, 'group_id' => $groupId],
          ['validate' => false, 'skipNormalization' => true]
        );
        $people->GroupMembers->saveOrFail($member);
      }
    }
  }
}
