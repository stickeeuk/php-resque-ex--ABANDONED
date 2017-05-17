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
 */

namespace Resque;

/**
 * Base Resque class.
 *
 * @package Resque
 *
 * @author Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Resque
{
    const VERSION = '1.2.5';
    const QUEUE_PREFIX = 'queue:';
    const CLASS_KEY = 'class';

    /**
     * @var \Resque\Redis Instance of \Resque\Redis that talks to redis.
     */
    public static $redis = null;

    /**
     * @var mixed Host/port combination separated by a colon, or a nested
     * array of servers with host/port pairs
     */
    protected static $redisServer = null;

    /**
     * @var int ID of Redis database to select.
     */
    protected static $redisDatabase = 0;

    /**
     * @var string namespace of the redis keys
     */
    protected static $namespace = '';

    /**
     * @var string password for the redis server
     */
    protected static $password = null;

    /**
     * @var int PID of current process. Used to detect changes when forking
     *  and implement "thread" safety to avoid race conditions.
     */
    protected static $pid = null;

    /**
     * Given a host/port combination separated by a colon, set it as
     * the redis server that Resque will talk to.
     *
     * @param mixed $server Host/port combination separated by a colon, or
     *                      a nested array of servers with host/port pairs.
     * @param int $database
     * @param string $namespace
     * @param null|string $password
     */
    public static function setBackend($server, $database = 0, $namespace = 'Resque\Resque', $password = null)
    {
        self::$redisServer = $server;
        self::$redisDatabase = $database;
        self::$redis = null;
        self::$namespace = $namespace;
        self::$password = $password;
    }

    /**
     * Push a job to the end of a specific queue. If the queue does not
     * exist, then create it as well.
     *
     * @param string $queue The name of the queue to add the job to.
     * @param array $item Job description as an array to be JSON encoded.
     */
    public static function push($queue, $item)
    {
        self::redis()->sadd('queues', $queue);
        self::redis()->rpush(self::QUEUE_PREFIX . $queue, json_encode($item));
    }

    /**
     * Return an instance of the Resque_Redis class instantiated for Resque.
     *
     * @return \Resque\Redis Instance of Resque\Redis.
     */
    public static function redis()
    {
        // Detect when the PID of the current process has changed (from a fork, etc)
        // and force a reconnect to redis.
        $pid = getmypid();
        if (self::$pid !== $pid) {
            self::$redis = null;
            self::$pid = $pid;
        }

        if (!is_null(self::$redis)) {
            return self::$redis;
        }

        $server = self::$redisServer;
        if (empty($server)) {
            $server = 'localhost:6379';
        }

        if (is_array($server)) {
            self::$redis = new Resque_RedisCluster($server);
        } else {
            if (strpos($server, 'unix:') === false) {
                list($host, $port) = explode(':', $server);
            } else {
                $host = $server;
                $port = null;
            }

            $redisInstance = new \Resque\Redis($host, $port, self::$password);
            $redisInstance->prefix(self::$namespace);
            self::$redis = $redisInstance;
        }

        if (!empty(self::$redisDatabase)) {
            self::$redis->select(self::$redisDatabase);
        }

        return self::$redis;
    }

    /**
     * Pop an item off the end of the specified queue, decode it and
     * return it.
     *
     * @param string $queue The name of the queue to fetch an item from.
     *
     * @return array|null Decoded item from the queue.
     */
    public static function pop($queue)
    {
        $item = self::redis()->lpop(self::QUEUE_PREFIX . $queue);
        if (!$item) {
            return;
        }

        return json_decode($item, true);
    }

    /**
     * Remove items of the specified queue
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @param array $items
     *
     * @return int number of deleted items
     */
    public static function deQueue($queue, $items = [])
    {
        if (count($items) > 0) {
            return self::removeItems($queue, $items);
        } else {
            return self::removeList($queue);
        }
    }

    /**
     * Remove Items from the queue
     * Safely moving each item to a temporary queue before processing it
     * If the Job matches, counts otherwise puts it in a requeue_queue
     * which at the end eventually be copied back into the original queue
     *
     * @private
     *
     * @param string $queue The name of the queue
     * @param array $items
     *
     * @return int number of deleted items
     */
    private static function removeItems($queue, $items = [])
    {
        $counter = 0;
        $originalQueue = self::QUEUE_PREFIX . $queue;
        $tempQueue = $originalQueue . ':temp:' . time();
        $requeueQueue = $tempQueue . ':requeue';

        // move each item from original queue to temp queue and process it
        $finished = false;
        while (!$finished) {
            $string = self::redis()->rpoplpush($originalQueue, self::redis()->getPrefix() . $tempQueue);

            if (!empty($string)) {
                if (self::matchItem($string, $items)) {
                    self::redis()->rpop($tempQueue);
                    $counter++;
                } else {
                    self::redis()->rpoplpush($tempQueue, self::redis()->getPrefix() . $requeueQueue);
                }
            } else {
                $finished = true;
            }
        }

        // move back from temp queue to original queue
        $finished = false;
        while (!$finished) {
            $string = self::redis()->rpoplpush($requeueQueue, self::redis()->getPrefix() . $originalQueue);
            if (empty($string)) {
                $finished = true;
            }
        }

        // remove temp queue and requeue queue
        self::redis()->del($requeueQueue);
        self::redis()->del($tempQueue);

        return $counter;
    }

    /**
     * matching item
     * item can be ['class'] or ['class' => 'id'] or ['class' => {:foo => 1, :bar => 2}]
     *
     * @private
     *
     * @param string $string redis result in json
     * @param $items
     *
     * @return bool
     */
    private static function matchItem($string, $items)
    {
        $decoded = json_decode($string, true);

        foreach ($items as $key => $val) {
            if (
                self::matchesClassNameOnly($key, $val, $decoded) ||
                self::matchesClassNameWithArgs($key, $val, $decoded) ||
                self::matchesClassNameWithId($key, $val, $decoded)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * matchesClassNameOnly
     *
     * @param int|string $key
     * @param string|array $val
     * @param array $decoded
     *
     * @return bool
     */
    private static function matchesClassNameOnly($key, $val, $decoded)
    {
        if (is_numeric($key) && $decoded[self::CLASS_KEY] == $val) {
            return true;
        }

        return false;
    }

    /**
     * matchesClassNameWithArgs
     *
     * example: item[0] = ['class' => {'foo' => 1, 'bar' => 2}]
     *
     * @param int|string $key
     * @param string|array $val
     * @param array $decoded
     *
     * @return bool
     */
    private static function matchesClassNameWithArgs($key, $val, $decoded)
    {
        if (!is_array($val)) {
            return false;
        }

        $decodedArgs = (array)$decoded['args'][0];

        $hasArgs = count($decodedArgs) > 0;
        $sameArgs = count(array_diff($decodedArgs, $val)) == 0;

        if ($decoded[self::CLASS_KEY] == $key && $hasArgs && $sameArgs) {
            return true;
        }

        return false;
    }

    /**
     * matchesClassNameWithId
     *
     * example: item[0] = ['class' => 'id']
     *
     * @param int|string $key
     * @param string|array $val
     * @param array $decoded
     *
     * @return bool
     */
    private static function matchesClassNameWithId($key, $val, $decoded)
    {
        if ($decoded[self::CLASS_KEY] == $key && $decoded['id'] == $val) {
            return true;
        }

        return false;
    }

    /**
     * Remove List
     *
     * @private
     *
     * @param string $queue the name of the queue
     *
     * @return int number of deleted items belongs to this list
     */
    private static function removeList($queue)
    {
        $counter = self::size($queue);
        $result = self::redis()->del(self::QUEUE_PREFIX . $queue);

        return ($result == 1) ? $counter : 0;
    }

    /**
     * Return the size (number of pending jobs) of the specified queue.
     *
     * @param string $queue name of the queue to be checked for pending jobs
     *
     * @return int The size of the queue.
     */
    public static function size($queue)
    {
        return self::redis()->llen(self::QUEUE_PREFIX . $queue);
    }

    /*
     * Generate an identifier to attach to a job for status tracking.
     *
     * @return string
     */

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     * @param bool $trackStatus Set to true to be able to monitor the status of a job.
     *
     * @return string
     */
    public static function enqueue($queue, $class, $args = null, $trackStatus = false)
    {
        $result = Job::create($queue, $class, $args, $trackStatus);
        if ($result) {
            Event::trigger(
                'afterEnqueue',
                [
                    self::CLASS_KEY => $class,
                    'args' => $args,
                    'queue' => $queue,
                ]
            );
        }

        return $result;
    }

    /**
     * Reserve and return the next available job in the specified queue.
     *
     * @param string $queue Queue to fetch next available job from.
     *
     * @return bool|Job Instance of Resque_Job to be processed, false if none or error.
     */
    public static function reserve($queue)
    {
        return Job::reserve($queue);
    }

    /**
     * Get an array of all known queues.
     *
     * @return array Array of queues.
     */
    public static function queues()
    {
        $queues = self::redis()->smembers('queues');
        if (!is_array($queues)) {
            $queues = [];
        }

        return $queues;
    }

    public static function generateJobId()
    {
        return md5(uniqid('', true));
    }
}
