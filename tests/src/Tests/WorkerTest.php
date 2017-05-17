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

namespace Resque\Tests;

use Resque\Job;
use Resque\Resque;
use Resque\Stat;
use Resque\Tests\Job\Job as TestJob;
use Resque\Worker;

/**
 * Resque_Worker tests.
 *
 * @package     Resque/Tests
 *
 * @author      Chris Boulton <chris@bigcommerce.com>
 * @license     http://www.opensource.org/licenses/mit-license.php
 */
class WorkerTest extends TestCase
{
    public function testWorkerRegistersInList()
    {
        $worker = new Worker('*');
        $worker->registerWorker();

        // Make sure the worker is in the list
        $this->assertTrue((bool)$this->redis->sismember('workers', (string)$worker));
    }

    public function testGetAllWorkers()
    {
        $num = 3;
        // Register a few workers
        for ($i = 0; $i < $num; ++$i) {
            $worker = new Worker('queue_' . $i);
            $worker->registerWorker();
        }

        // Now try to get them
        $this->assertEquals($num, count(Worker::all()));
    }

    public function testGetWorkerById()
    {
        $worker = new Worker('*');
        $worker->registerWorker();

        $newWorker = Worker::find((string)$worker);
        $this->assertEquals((string)$worker, (string)$newWorker);
    }

    public function testInvalidWorkerDoesNotExist()
    {
        $this->assertFalse(Worker::exists('blah'));
    }

    public function testWorkerCanUnregister()
    {
        $worker = new Worker('*');
        $worker->registerWorker();
        $worker->unregisterWorker();

        $this->assertFalse(Worker::exists((string)$worker));
        $this->assertEquals([], Worker::all());
        $this->assertEquals([], $this->redis->smembers('resque:workers'));
    }

    public function testPausedWorkerDoesNotPickUpJobs()
    {
        $worker = new Worker('*');
        $worker->pauseProcessing();
        Resque::enqueue('jobs', TestJob::class);
        $worker->work(0);
        $worker->work(0);
        $this->assertEquals(0, Stat::get('processed'));
    }

    public function testResumedWorkerPicksUpJobs()
    {
        $worker = new Worker('*');
        $worker->pauseProcessing();
        Resque::enqueue('jobs', TestJob::class);
        $worker->work(0);
        $this->assertEquals(0, Stat::get('processed'));
        $worker->unPauseProcessing();
        $worker->work(0);
        $this->assertEquals(1, Stat::get('processed'));
    }

    public function testWorkerCanWorkOverMultipleQueues()
    {
        $worker = new Worker(
            [
                'queue1',
                'queue2',
            ]
        );
        $worker->registerWorker();
        Resque::enqueue('queue1', 'Test_Job_1');
        Resque::enqueue('queue2', 'Test_Job_2');

        $job = $worker->reserve();
        $this->assertEquals('queue1', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('queue2', $job->queue);
    }

    public function testWorkerWorksQueuesInSpecifiedOrder()
    {
        $worker = new Worker(
            [
                'high',
                'medium',
                'low',
            ]
        );
        $worker->registerWorker();

        // Queue the jobs in a different order
        Resque::enqueue('low', 'Test_Job_1');
        Resque::enqueue('high', 'Test_Job_2');
        Resque::enqueue('medium', 'Test_Job_3');

        // Now check we get the jobs back in the right order
        $job = $worker->reserve();
        $this->assertEquals('high', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('medium', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('low', $job->queue);
    }

    public function testWildcardQueueWorkerWorksAllQueues()
    {
        $worker = new Worker('*');
        $worker->registerWorker();

        Resque::enqueue('queue1', 'Test_Job_1');
        Resque::enqueue('queue2', 'Test_Job_2');

        $job = $worker->reserve();
        $this->assertEquals('queue1', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('queue2', $job->queue);
    }

    public function testWorkerDoesNotWorkOnUnknownQueues()
    {
        $worker = new Worker('queue1');
        $worker->registerWorker();
        Resque::enqueue('queue2', TestJob::class);

        $this->assertFalse($worker->reserve());
    }

    public function testWorkerClearsItsStatusWhenNotWorking()
    {
        Resque::enqueue('jobs', Job::class);
        $worker = new Worker('jobs');
        $job = $worker->reserve();
        $worker->workingOn($job);
        $worker->doneWorking();
        $this->assertEquals([], $worker->job());
    }

    public function testWorkerRecordsWhatItIsWorkingOn()
    {
        $worker = new Worker('jobs');
        $worker->registerWorker();

        $payload = [
            'class' => TestJob::class,
        ];
        $job = new Job('jobs', $payload);
        $worker->workingOn($job);

        $job = $worker->job();
        $this->assertEquals('jobs', $job['queue']);
        if (!isset($job['run_at'])) {
            $this->fail('Job does not have run_at time');
        }
        $this->assertEquals($payload, $job['payload']);
    }

    public function testWorkerErasesItsStatsWhenShutdown()
    {
        Resque::enqueue('jobs', TestJob::class);
        Resque::enqueue('jobs', 'Invalid_Job');

        $worker = new Worker('jobs');
        $worker->work(0);
        $worker->work(0);

        $this->assertEquals(0, $worker->getStat('processed'));
        $this->assertEquals(0, $worker->getStat('failed'));
    }

    public function testWorkerCleansUpDeadWorkersOnStartup()
    {
        // Register a good worker
        $goodWorker = new Worker('jobs');
        $goodWorker->registerWorker();
        $workerId = explode(':', $goodWorker);

        // Register some bad workers
        $worker = new Worker('jobs');
        $worker->setId($workerId[0] . ':1:jobs');
        $worker->registerWorker();

        $worker = new Worker(['high', 'low']);
        $worker->setId($workerId[0] . ':2:high,low');
        $worker->registerWorker();

        $this->assertEquals(3, count(Worker::all()));

        $goodWorker->pruneDeadWorkers();

        // There should only be $goodWorker left now
        $this->assertEquals(1, count(Worker::all()));
    }

    public function testDeadWorkerCleanUpDoesNotCleanUnknownWorkers()
    {
        // Register a bad worker on this machine
        $worker = new Worker('jobs');
        $workerId = explode(':', $worker);
        $worker->setId($workerId[0] . ':1:jobs');
        $worker->registerWorker();

        // Register some other false workers
        $worker = new Worker('jobs');
        $worker->setId('my.other.host:1:jobs');
        $worker->registerWorker();

        $this->assertEquals(2, count(Worker::all()));

        $worker->pruneDeadWorkers();

        // my.other.host should be left
        $workers = Worker::all();
        $this->assertEquals(1, count($workers));
        $this->assertEquals((string)$worker, (string)$workers[0]);
    }

    public function testWorkerFailsUncompletedJobsOnExit()
    {
        $worker = new Worker('jobs');
        $worker->registerWorker();

        $payload = [
            'class' => TestJob::class,
            'id' => 'randomId',
        ];
        $job = new Job('jobs', $payload);

        $worker->workingOn($job);
        $worker->unregisterWorker();

        $this->assertEquals(1, Stat::get('failed'));
    }

    public function testWorkerLogAllMessageOnVerbose()
    {
        $worker = new Worker('jobs');
        $worker->logLevel = Worker::LOG_VERBOSE;
        $worker->logOutput = fopen('php://memory', 'r+');

        $message = ['message' => 'x', 'data' => ['worker' => '']];

        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_WARNING));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ERROR));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($worker->logOutput);
        $output = stream_get_contents($worker->logOutput);

        $lines = explode("\n", $output);
        $this->assertEquals(6, count($lines) - 1);
    }

    public function testWorkerLogOnlyInfoMessageOnNonVerbose()
    {
        $worker = new Worker('jobs');
        $worker->logLevel = Worker::LOG_NORMAL;
        $worker->logOutput = fopen('php://memory', 'r+');

        $message = ['message' => 'x', 'data' => ['worker' => '']];

        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_WARNING));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ERROR));
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($worker->logOutput);
        $output = stream_get_contents($worker->logOutput);

        $lines = explode("\n", $output);
        $this->assertEquals(5, count($lines) - 1);
    }

    public function testWorkerLogNothingWhenLogNone()
    {
        $worker = new Worker('jobs');
        $worker->logLevel = Worker::LOG_NONE;
        $worker->logOutput = fopen('php://memory', 'r+');

        $message = ['message' => 'x', 'data' => ''];

        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_DEBUG));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_INFO));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_WARNING));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_CRITICAL));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_ERROR));
        $this->assertEquals(false, $worker->log($message, Worker::LOG_TYPE_ALERT));

        rewind($worker->logOutput);
        $output = stream_get_contents($worker->logOutput);

        $lines = explode("\n", $output);
        $this->assertEquals(0, count($lines) - 1);
    }

    public function testWorkerLogWithISOTime()
    {
        $worker = new Worker('jobs');
        $worker->logLevel = Worker::LOG_NORMAL;
        $worker->logOutput = fopen('php://memory', 'r+');

        $message = ['message' => 'x', 'data' => ['worker' => '']];

        $now = date('c');
        $this->assertEquals(true, $worker->log($message, Worker::LOG_TYPE_INFO));

        rewind($worker->logOutput);
        $output = stream_get_contents($worker->logOutput);

        $lines = explode("\n", $output);
        $this->assertEquals(1, count($lines) - 1);
        $this->assertEquals('[' . $now . '] x', $lines[0]);
    }
}
