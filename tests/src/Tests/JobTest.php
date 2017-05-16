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

namespace Resque\Tests;

use Resque\Job;
use Resque\Resque;
use Resque\Stat;
use Resque\Tests\Job\Job as TestJob;
use Resque\Tests\Job\WithoutPerformMethod;
use Resque\Tests\Job\WithSetUp;
use Resque\Tests\Job\WithTearDown;
use Resque\Worker;
use stdClass;

/**
 * Resque_Job tests.
 *
 * @package        Resque/Tests
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class JobTest extends TestCase
{
    protected $worker;

    public function setUp()
    {
        parent::setUp();

        // Register a worker to test with
        $this->worker = new Worker('jobs');
        $this->worker->registerWorker();
    }

    public function testJobCanBeQueued()
    {
        $this->assertTrue((bool)Resque::enqueue('jobs', TestJob::class));
    }

    public function testQeueuedJobCanBeReserved()
    {
        Resque::enqueue('jobs', TestJob::class);

        $job = Job::reserve('jobs');
        if ($job == false) {
            $this->fail('Job could not be reserved.');
        }
        $this->assertEquals('jobs', $job->queue);
        $this->assertEquals(TestJob::class, $job->payload['class']);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testObjectArgumentsCannotBePassedToJob()
    {
        $args = new stdClass;
        $args->test = 'somevalue';
        Resque::enqueue('jobs', TestJob::class, $args);
    }

    public function testQueuedJobReturnsExactSamePassedInArguments()
    {
        $args = [
            'int' => 123,
            'numArray' => [
                1,
                2,
            ],
            'assocArray' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        ];
        Resque::enqueue('jobs', TestJob::class, $args);
        $job = Job::reserve('jobs');

        $this->assertEquals($args, $job->getArguments());
    }

    public function testAfterJobIsReservedItIsRemoved()
    {
        Resque::enqueue('jobs', TestJob::class);
        Job::reserve('jobs');
        $this->assertFalse(Job::reserve('jobs'));
    }

    public function testRecreatedJobMatchesExistingJob()
    {
        $args = [
            'int' => 123,
            'numArray' => [
                1,
                2,
            ],
            'assocArray' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        ];

        Resque::enqueue('jobs', TestJob::class, $args);
        $job = Job::reserve('jobs');

        // Now recreate it
        $job->recreate();

        $newJob = Job::reserve('jobs');
        $this->assertEquals($job->payload['class'], $newJob->payload['class']);
        $this->assertEquals($job->payload['args'], $newJob->getArguments());
    }

    public function testFailedJobExceptionsAreCaught()
    {
        $payload = [
            'class' => 'Failing_Job',
            'id' => 'randomId',
            'args' => null,
        ];
        $job = new Job('jobs', $payload);
        $job->worker = $this->worker;

        $this->worker->perform($job);

        $this->assertEquals(1, Stat::get('failed'));
        $this->assertEquals(1, Stat::get('failed:' . $this->worker));
    }

    /**
     * @expectedException \Resque\ResqueException
     */
    public function testJobWithoutPerformMethodThrowsException()
    {
        Resque::enqueue('jobs', WithoutPerformMethod::class);
        $job = $this->worker->reserve();
        $job->worker = $this->worker;
        $job->perform();
    }

    /**
     * @expectedException \Resque\ResqueException
     */
    public function testInvalidJobThrowsException()
    {
        Resque::enqueue('jobs', 'Invalid_Job');
        $job = $this->worker->reserve();
        $job->worker = $this->worker;
        $job->perform();
    }

    public function testJobWithSetUpCallbackFiresSetUp()
    {
        $payload = [
            'class' => WithSetUp::class,
            'args' => [
                'somevar',
                'somevar2',
            ],
        ];
        $job = new Job('jobs', $payload);
        $job->perform();

        $this->assertTrue(WithSetUp::$called);
    }

    public function testJobWithTearDownCallbackFiresTearDown()
    {
        $payload = [
            'class' => WithTearDown::class,
            'args' => [
                'somevar',
                'somevar2',
            ],
        ];
        $job = new Job('jobs', $payload);
        $job->perform();

        $this->assertTrue(WithTearDown::$called);
    }

    public function testJobWithNamespace()
    {
        Resque::setBackend(REDIS_HOST, REDIS_DATABASE, 'php');
        $queue = 'jobs';
        $payload = ['another_value'];
        Resque::enqueue($queue, WithTearDown::class, $payload);

        $this->assertEquals(Resque::queues(), ['jobs']);
        $this->assertEquals(Resque::size($queue), 1);

        Resque::setBackend(REDIS_HOST, REDIS_DATABASE, REDIS_NAMESPACE);
        $this->assertEquals(Resque::size($queue), 0);
    }

    public function testDequeueAll()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'Test_Job_Dequeue');
        Resque::enqueue($queue, 'Test_Job_Dequeue');
        $this->assertEquals(Resque::size($queue), 2);
        $this->assertEquals(Resque::deQueue($queue), 2);
        $this->assertEquals(Resque::size($queue), 0);
    }

    public function testDequeueMakeSureNotDeleteOthers()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'Test_Job_Dequeue');
        Resque::enqueue($queue, 'Test_Job_Dequeue');
        $other_queue = 'other_jobs';
        Resque::enqueue($other_queue, 'Test_Job_Dequeue');
        Resque::enqueue($other_queue, 'Test_Job_Dequeue');
        $this->assertEquals(Resque::size($queue), 2);
        $this->assertEquals(Resque::size($other_queue), 2);
        $this->assertEquals(Resque::deQueue($queue), 2);
        $this->assertEquals(Resque::size($queue), 0);
        $this->assertEquals(Resque::size($other_queue), 2);
    }

    public function testDequeueSpecificItem()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'Test_Job_Dequeue1');
        Resque::enqueue($queue, 'Test_Job_Dequeue2');
        $this->assertEquals(Resque::size($queue), 2);
        $test = ['Test_Job_Dequeue2'];
        $this->assertEquals(Resque::deQueue($queue, $test), 1);
        $this->assertEquals(Resque::size($queue), 1);
    }

    public function testDequeueSpecificMultipleItems()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'Test_Job_Dequeue1');
        Resque::enqueue($queue, 'Test_Job_Dequeue2');
        Resque::enqueue($queue, 'Test_Job_Dequeue3');
        $this->assertEquals(Resque::size($queue), 3);
        $test = ['Test_Job_Dequeue2', 'Test_Job_Dequeue3'];
        $this->assertEquals(Resque::deQueue($queue, $test), 2);
        $this->assertEquals(Resque::size($queue), 1);
    }

    public function testDequeueNonExistingItem()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'Test_Job_Dequeue1');
        Resque::enqueue($queue, 'Test_Job_Dequeue2');
        Resque::enqueue($queue, 'Test_Job_Dequeue3');
        $this->assertEquals(Resque::size($queue), 3);
        $test = [Test_Job_Dequeue4::class];
        $this->assertEquals(Resque::deQueue($queue, $test), 0);
        $this->assertEquals(Resque::size($queue), 3);
    }

    public function testDequeueNonExistingItem2()
    {
        $queue = 'jobs';
        Resque::enqueue($queue, 'Test_Job_Dequeue1');
        Resque::enqueue($queue, 'Test_Job_Dequeue2');
        Resque::enqueue($queue, 'Test_Job_Dequeue3');
        $this->assertEquals(Resque::size($queue), 3);
        $test = [Test_Job_Dequeue4::class, 'Test_Job_Dequeue1'];
        $this->assertEquals(Resque::deQueue($queue, $test), 1);
        $this->assertEquals(Resque::size($queue), 2);
    }

}
