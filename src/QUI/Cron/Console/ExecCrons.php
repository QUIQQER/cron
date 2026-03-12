<?php

namespace QUI\Cron\Console;

use QUI;
use QUI\Exception;

/**
 * Cron Console Manager
 */
class ExecCrons extends QUI\System\Console\Tool
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->setName('package:cron')
            ->setDescription('Cron Manager');
    }

    /**
     * @throws Exception
     */
    public function execute(): void
    {
        $run = $this->getArgument('--run');
        $list = $this->getArgument('--list');
        $listAll = $this->getArgument('--list-all');
        $runCron = $this->getArgument('--cron');
        $unlock = $this->getArgument('--unlock');

        if ($run) {
            $this->run();
            return;
        }

        if ($list) {
            $this->listCrons();
            return;
        }

        if ($listAll) {
            $this->listAllCrons();
            return;
        }

        if ($runCron) {
            $this->runCron($runCron);
            return;
        }

        if ($unlock) {
            $this->unlock();
            return;
        }

        $this->writeLn('Welcome to the Cron Manager');
        $this->writeLn('Which Command would you execute?');
        $this->writeLn();

        $this->commandRead();
    }

    /**
     * Read the command from the command line
     * @throws QUI\Database\Exception
     */
    public function commandRead(): void
    {
        $this->writeLn('Available Commands: ');
        $this->writeLn("- run\t\trun all active cron");
        $this->writeLn("- list\t\tlist all active cron");
        $this->writeLn("- list-all\tlist all cron");
        $this->writeLn("- cron\t\trun a specific cron");
        $this->writeLn("- unlock\tremove the cron execution lock");
        $this->writeLn("- force\t\tforce cron execution");

        $this->writeLn();

        $this->writeLn('Command: ');
        $command = $this->readInput();

        switch ($command) {
            // run all cron
            case 'run':
                $this->run();
                $this->commandRead();
                break;

            // list all inserted cron
            case 'list':
                $this->listCrons();
                $this->commandRead();
                break;

            // list all inserted cron
            case 'list-all':
                $this->listAllCrons();
                $this->commandRead();
                break;

            case 'cron':
                $this->write("Please enter the Cron-ID: ");
                $cronId = $this->readInput();

                try {
                    $this->runCron((int)$cronId);
                } catch (QUI\Exception $Exception) {
                    $this->writeLn($Exception->getMessage(), 'red');
                    $this->resetColor();
                    $this->writeLn();
                }

                $this->commandRead();
                break;

            case 'unlock':
                try {
                    $this->unlock();
                } catch (\Exception $Exception) {
                    $this->writeLn($Exception->getMessage(), 'red');
                    $this->resetColor();
                    $this->writeLn();
                }

                $this->commandRead();
                break;

            default:
                $this->writeLn(
                    'Command not found, please type another command',
                    'red'
                );

                $this->commandRead();
        }
    }

    /**
     * Execute all upcoming cron
     */
    public function run(): void
    {
        $Manager = new QUI\Cron\Manager();

        $this->writeLn();
        $this->write('Execute all upcoming cron ...');

        try {
            if ($this->getArgument('--force')) {
                $Manager->execute(true);
            } else {
                $Manager->execute();
            }
        } catch (QUI\Database\Exception) {
        } catch (QUI\Permissions\Exception) {
        }

        $this->write('finish');
        $this->writeLn();
    }

    /**
     * List all active cron
     * @throws QUI\Database\Exception
     */
    public function listCrons(): void
    {
        $Manager = new QUI\Cron\Manager();
        $list = $Manager->getList();

        $this->writeLn('Cron list:');
        $this->writeLn('=======================================================');
        $this->writeLn();

        foreach ($list as $entry) {
            if ($entry['active'] != 1) {
                continue;
            }

            $time = $entry['min']
                . ' ' . $entry['hour']
                . ' ' . $entry['day']
                . ' ' . $entry['month'];

            $exec = $entry['exec'];

            $this->writeLn('ID: ' . $entry['id']);
            $this->writeLn($time . "\t" . $exec, 'green');

            $this->resetColor();
            $this->writeLn();
        }

        $this->writeLn('=======================================================');
        $this->writeLn();
    }

    /**
     * List all inserted Cron
     * @throws QUI\Database\Exception
     */
    public function listAllCrons(): void
    {
        $Manager = new QUI\Cron\Manager();
        $list = $Manager->getList();

        $this->writeLn('Cron list:');
        $this->writeLn('=======================================================');
        $this->writeLn();

        foreach ($list as $entry) {
            $time = $entry['min']
                . ' ' . $entry['hour']
                . ' ' . $entry['day']
                . ' ' . $entry['month'];

            $exec = $entry['exec'];

            $this->writeLn('ID: ' . $entry['id']);
            $this->writeLn($time . "\t" . $exec, 'green');

            $this->resetColor();
            $this->writeLn();
        }

        $this->writeLn('=======================================================');
        $this->writeLn();
    }

    /**
     * Run a specific cron
     *
     * @param Boolean|Integer $cronId - ID of the cron
     * @throws QUI\Exception
     */
    public function runCron(bool | int $cronId = false): void
    {
        if (!is_numeric($cronId)) {
            throw new QUI\Exception('Cron ID must be an integer');
        }

        $cronId = (int)$cronId;

        $Manager = new QUI\Cron\Manager();
        $cron = $Manager->getCronById($cronId);

        if (!$cron) {
            throw new QUI\Exception('Cron not found');
        }

        $this->writeLn('Execute Cron: ' . $cronId . ' ' . $cron['title']);
        $Manager->executeCron($cronId);

        $this->writeLn('=======================================================');
        $this->writeLn();
    }

    /**
     * Remove the cron execution lock
     *
     * @throws QUI\Exception
     * @throws \Exception
     */
    public function unlock(): void
    {
        $this->writeLn('Remove cron execution lock ...');
        $Package = QUI::getPackage('quiqqer/cron');

        if (QUI\Lock\Locker::isLocked($Package, QUI\Cron\Manager::EXECUTION_LOCK_KEY, null, false)) {
            QUI\Cron\Manager::unlockExecutionLock();

            $this->writeLn('Cron execution lock removed.', 'green');
            $this->resetColor();
            $this->writeLn();
            return;
        }

        $this->writeLn('No cron execution lock found.', 'yellow');
        $this->resetColor();
        $this->writeLn();
    }
}
