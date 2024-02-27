<?php

/**
 * Copyright 2019 Huawei Technologies Co.,Ltd.
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use
 * this file except in compliance with the License.  You may obtain a copy of the
 * License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed
 * under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR
 * CONDITIONS OF ANY KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations under the License.
 *
 */

namespace Obs\Log;

use Monolog\Logger;

/**
 * @method static void debug(string $format, $args1 = null, $arg2 = null)
 * @method static void info(string $format, $args1 = null, $arg2 = null)
 * @method static void notice(string $format, $args1 = null, $arg2 = null)
 * @method static void warning(string $format, $args1 = null, $arg2 = null)
 * @method static void error(string $format, $args1 = null, $arg2 = null)
 * @method static void critical(string $format, $args1 = null, $arg2 = null)
 * @method static void alert(string $format, $args1 = null, $arg2 = null)
 * @method static void emergency(string $format, $args1 = null, $arg2 = null)
 */
class ObsLog
{

    /**
     * @var Logger
     */
    public static $log = null;

    public static function setLogger(Logger $logger)
    {
        self::$log = $logger;
    }

    public static function __callStatic($name, $arguments)
    {
        if (!isset(self::$log)) {
            return;
        }

        $format = $arguments[0];

        if (isset($arguments[2])) {
            $msg = sprintf($format, $arguments[1], $arguments[2]);
        } else {
            $msg = urldecode($format);
        }

        $back = debug_backtrace();
        $line = $back[0]['line'];
        $filename = basename($back[0]['file']);
        $message = '[' . $filename . ':' . $line . ']: ' . $msg;

        self::$log->$name($message);
    }

}
