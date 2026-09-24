<?php

use GlpiPlugin\Teams\Service\ServiceFactory;

final class PluginTeamsPluginCronTask
{
    public static function cronprocessOutbox(CronTask $task): int
    {
        $processed = ServiceFactory::outbox()->process();
        $task->setVolume($processed);

        return $processed > 0 ? 1 : 0;
    }
}
