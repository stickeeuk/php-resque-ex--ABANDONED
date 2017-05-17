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

use Exception;

/**
 * Failed Resque job.
 *
 * @package Resque/Failure
 *
 * @author Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Failure
{
    /**
     * @var string Class name representing the backend to pass failed jobs off to.
     */
    private static $backend;

    /**
     * Create a new failed job on the backend.
     *
     * @param object $payload The contents of the job that has just failed.
     * @param \Exception $exception The exception generated when the job failed to run.
     * @param \Resque\Worker $worker Instance of Resque_Worker that was running this job when it failed.
     * @param string $queue The name of the queue that this job was fetched from.
     */
    public static function create($payload, Exception $exception, Worker $worker, $queue)
    {
        $backend = self::getBackend();
        new $backend($payload, $exception, $worker, $queue);
    }

    /**
     * Return an instance of the backend for saving job failures.
     *
     * @return object Instance of backend object.
     */
    public static function getBackend()
    {
        if (self::$backend === null) {
            self::$backend = \Resque\Failure\Redis::class;
        }

        return self::$backend;
    }

    /**
     * Set the backend to use for raised job failures. The supplied backend
     * should be the name of a class to be instantiated when a job fails.
     * It is your responsibility to have the backend class loaded (or autoloaded)
     *
     * @param string $backend The class name of the backend to pipe failures to.
     */
    public static function setBackend($backend)
    {
        self::$backend = $backend;
    }
}
