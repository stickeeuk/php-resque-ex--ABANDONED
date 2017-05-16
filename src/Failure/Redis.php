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

namespace Resque\Failure;

use Resque\Resque;

/**
 * Redis backend for storing failed Resque jobs.
 *
 * @package Resque
 * @author Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Redis implements FailureInterface
{
    /**
     * Initialize a failed job class and save it (where appropriate).
     *
     * @param object $payload Object containing details of the failed job.
     * @param object $exception Instance of the exception that was thrown by the failed job.
     * @param object $worker Instance of Resque_Worker that received the job.
     * @param string $queue The name of the queue the job was fetched from.
     */
    public function __construct($payload, $exception, $worker, $queue)
    {
        $data = [];
        $data['failed_at'] = strftime('%a %b %d %H:%M:%S %Z %Y');
        $data['payload'] = $payload;
        $data['exception'] = get_class($exception);
        $data['error'] = $exception->getMessage();
        $data['backtrace'] = explode("\n", $exception->getTraceAsString());
        $data['worker'] = (string)$worker;
        $data['queue'] = $queue;
        Resque::Redis()->setex('failed:' . $payload['id'], 3600 * 14, serialize($data));
    }

    static public function get($jobId)
    {
        $data = Resque::Redis()->get('failed:' . $jobId);

        return unserialize($data);
    }
}
