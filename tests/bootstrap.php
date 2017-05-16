<?php
/**
 * This work has been adapted from the original work by Stickee Technology Limited
 * and is based on works licenced under the MIT Licence by Chris Boulton, also
 * previously adapted by Wan Qi Chen
 *
 * Original work Copyright (c) 2010 Chris Boulton <chris@bigcommerce.com>
 * Modified Work Copyright (c) 2017 Stickee Technology Limited
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 */

require_once __DIR__ . "/../vendor/autoload.php";

/**
 * Resque test bootstrap file - sets up a test environment.
 *
 * @package        Resque/Tests
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
define('CWD', dirname(__FILE__));
define('RESQUE_LIB', CWD . '/../../../lib/');

define('TEST_MISC', realpath(CWD . '/misc/'));
define('REDIS_CONF', TEST_MISC . '/redis.conf');

// Change to the directory this file lives in. This is important, due to
// how we'll be running redis.

// Attempt to start our own redis instance for tesitng.
exec('which redis-server', $output, $returnVar);
if ($returnVar != 0) {
    echo "Cannot find redis-server in path. Please make sure redis is installed.\n";
    exit(1);
}

exec('cd ' . TEST_MISC . '; redis-server ' . REDIS_CONF, $output, $returnVar);
usleep(500000);
if ($returnVar != 0) {
    echo "Cannot start redis-server.\n";
    exit(1);

}

// Get redis port from conf
$config = file_get_contents(REDIS_CONF);
if (!preg_match('#^\s*port\s+([0-9]+)#m', $config, $matches)) {
    echo "Could not determine redis port from redis.conf";
    exit(1);
}

define('REDIS_HOST', 'localhost:' . $matches[1]);
define('REDIS_DATABASE', 7);
define('REDIS_NAMESPACE', 'testResque');

\Resque\Resque::setBackend(REDIS_HOST, REDIS_DATABASE, REDIS_NAMESPACE);

// Shutdown
$killRedis = function ($pid) {
    if (getmypid() !== $pid) {
        return; // don't kill from a forked worker
    }
    $config = file_get_contents(REDIS_CONF);
    if (!preg_match('#^\s*pidfile\s+([^\s]+)#m', $config, $matches)) {
        return;
    }

    $pidFile = TEST_MISC . '/' . $matches[1];
    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        posix_kill((int)$pid, 9);

        if (is_file($pidFile)) {
            unlink($pidFile);
        }
    }

    // Remove the redis database
    if (!preg_match('#^\s*dir\s+([^\s]+)#m', $config, $matches)) {
        return;
    }
    $dir = $matches[1];

    if (!preg_match('#^\s*dbfilename\s+([^\s]+)#m', $config, $matches)) {
        return;
    }

    $filename = TEST_MISC . '/' . $dir . '/' . $matches[1];
    if (is_file($filename)) {
        unlink($filename);
    }
};

register_shutdown_function($killRedis, getmypid());

if (function_exists('pcntl_signal')) {
    // Override INT and TERM signals, so they do a clean shutdown and also
    // clean up redis-server as well.
    $sigint = function () {
        exit;
    };

    pcntl_signal(SIGINT, $sigint);
    pcntl_signal(SIGTERM, $sigint);
}
