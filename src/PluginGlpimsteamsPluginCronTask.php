<?php

use GlpiPlugin\Glpimsteams\Service\ServiceFactory;

final class PluginGlpimsteamsPluginCronTask
{
    public static function cronprocessOutbox(CronTask $task): int
    {
        $processed = ServiceFactory::outbox()->process();
        $task->setVolume($processed);

        return $processed > 0 ? 1 : 0;
    }
}
