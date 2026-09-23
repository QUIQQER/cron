<?php

namespace QUI\Cron;

use QUI;
use QUI\Permissions\Permission;

/**
 * Update settings are backed by cron rows, not by a second set of configuration flags.
 */
class UpdateSettings
{
    public const CRONS = [
        'check' => '\\QUI\\Cron\\Update::check',
        'security' => '\\QUI\\Cron\\SecurityUpdateCron::execute',
        'update' => '\\QUI\\Cron\\Update::update'
    ];

    public function __construct(private readonly Manager $Manager = new Manager())
    {
    }

    /** @return array<string, array{active: bool, exists: bool}> */
    public function getStates(): array
    {
        $states = [];

        foreach (self::CRONS as $name => $exec) {
            $rows = $this->getRows($exec);
            $states[$name] = [
                'active' => (bool)array_filter($rows, static fn(array $row): bool => (bool)$row['active']),
                'exists' => $rows !== []
            ];
        }

        return $states;
    }

    public function setActive(string $name, bool $active): void
    {
        Permission::checkAdminUser();
        Permission::checkPermission('quiqqer.settings');

        if (!isset(self::CRONS[$name])) {
            throw new QUI\Exception('Unknown update setting');
        }

        $exec = self::CRONS[$name];
        $Connection = QUI::getDataBaseConnection();
        $Connection->transactional(function () use ($exec, $active, $Connection): void {
            if ($this->getRows($exec) === []) {
                $this->createCron($exec, $active);
                return;
            }

            // All schedules for the same update must agree when explicitly switched by the user.
            $Connection->update(
                QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                ['active' => (int)$active],
                ['exec' => $exec]
            );
        });
    }

    /**
     * Run during package setup, before autocreation can replace missing legacy jobs.
     * Preserve the effective state (cron active AND legacy flag) and every existing schedule.
     */
    public function migrate(QUI\Config $Config): void
    {
        if ($Config->get('migration', 'updateCronStatus')) {
            return;
        }

        $states = $this->getStates();
        $existing = $Config->existValue('update', 'auto_check')
            || $Config->existValue('update', 'auto_update')
            || (bool)array_filter($states, static fn(array $state): bool => $state['exists']);

        $Connection = QUI::getDataBaseConnection();
        $Connection->transactional(function () use ($Config, $states, $existing, $Connection): void {
            foreach (self::CRONS as $name => $exec) {
                if (!$states[$name]['exists']) {
                    $this->createCron($exec, !$existing && $name !== 'update');
                    continue;
                }

                if ($name === 'security') {
                    continue;
                }

                $flag = $name === 'check' ? 'auto_check' : 'auto_update';
                $enabled = $Config->existValue('update', $flag)
                    ? (bool)$Config->get('update', $flag)
                    : $name === 'check';

                if (!$enabled) {
                    $Connection->update(
                        QUI\Utils\Doctrine::quoteIdentifier(Manager::table()),
                        ['active' => 0],
                        ['exec' => $exec]
                    );
                }
            }
        });

        // Keep this marker outside the user settings. Subsequent setup must not reapply old flags.
        $Config->del('update', 'auto_check');
        $Config->del('update', 'auto_update');
        $Config->set('migration', 'updateCronStatus', 1);
        $Config->save();
    }

    /** @return array<int, array<string, mixed>> */
    private function getRows(string $exec): array
    {
        $Query = QUI::getQueryBuilder();

        return $Query->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()))
            ->where($Query->expr()->eq('exec', ':exec'))
            ->setParameter('exec', $exec)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function createCron(string $exec, bool $active): void
    {
        $definition = $this->Manager->getCronData($exec);

        if ($definition === false || empty($definition['autocreate'][0]['interval'])) {
            throw new QUI\Exception('Missing update cron definition: ' . $exec);
        }

        [$min, $hour, $day, $month, $dayOfWeek] = explode(' ', $definition['autocreate'][0]['interval']);
        QUI::getDataBaseConnection()->insert(QUI\Utils\Doctrine::quoteIdentifier(Manager::table()), [
            'active' => (int)$active,
            'exec' => $exec,
            'title' => $definition['title'],
            'min' => $min,
            'hour' => $hour,
            'day' => $day,
            'month' => $month,
            'dayOfWeek' => $dayOfWeek,
            'params' => json_encode($definition['autocreate'][0]['params'])
        ]);
    }
}
