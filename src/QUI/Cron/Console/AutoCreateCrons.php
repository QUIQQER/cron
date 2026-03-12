<?php

namespace QUI\Cron\Console;

use QUI;

/**
 * Cron Console Manager
 */
class AutoCreateCrons extends QUI\System\Console\Tool
{
    /**
     * Konstruktor
     */
    public function __construct()
    {
        $this->setName('cron:autocreate')
            ->setDescription('Parse cron.xml files of all installed packages and create <autocreate> crons.');
    }

    /**
     * (non-PHPdoc)
     *
     * @see \QUI\System\Console\Tool::execute()
     */
    public function execute(): void
    {
        $this->writeLn("Creating new <autocreate> crons...");

        QUI\Cron\EventHandler::createAutoCreateCrons();

        $this->write(" SUCCCESS!");
        $this->writeLn("\n\nAll finished.\n\n");
    }
}
