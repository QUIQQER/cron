<?php

// This child deliberately does not join ExecutionLock::holdWorkerLock().
echo 'READY' . PHP_EOL;
fgets(STDIN);
