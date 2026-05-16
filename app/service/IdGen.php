<?php

namespace app\service;

class IdGen
{
    public static function userId(): string
    {
        return (new \XCode())->machineId(1)->length(12)->format(4, '-')->make();
    }

    public static function gameId(): string
    {
        return (new \XCode())->machineId(1)->length(19)->make();
    }
}
