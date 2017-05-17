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

use Resque\Event;
use Resque\Job;
use Resque\Job\DontPerform;
use Resque\Resque;
use Resque\Tests\Job\Job as TestJob;
use Resque\Worker;

/**
 * Resque_Event tests.
 *
 * @package Resque
 */
class EventTest extends TestCase
{
    private $callbacksHit = [];

    public function setUp()
    {
        TestJob::$called = false;

        // Register a worker to test with
        $this->worker = new Worker('jobs');
        $this->worker->registerWorker();
    }

    public function tearDown()
    {
        Event::clearListeners();
        $this->callbacksHit = [];
    }

    public function getEventTestJob()
    {
        $payload = [
            'class' => TestJob::class,
            'id' => 'randomId',
            'args' => [
                'somevar',
            ],
        ];
        $job = new Job('jobs', $payload);
        $job->worker = $this->worker;

        return $job;
    }

    public function eventCallbackProvider()
    {
        return [
            ['beforePerform', 'beforePerformEventCallback'],
            ['afterPerform', 'afterPerformEventCallback'],
            ['afterFork', 'afterForkEventCallback'],
        ];
    }

    /**
     * @dataProvider eventCallbackProvider
     */
    public function testEventCallbacksFire($event, $callback)
    {
        Event::listen($event, [$this, $callback]);

        $job = $this->getEventTestJob();
        $this->worker->perform($job);
        $this->worker->work(0);

        $this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback . ') was not called');
    }

    public function testBeforeForkEventCallbackFires()
    {
        $event = 'beforeFork';
        $callback = 'beforeForkEventCallback';

        Event::listen($event, [$this, $callback]);
        Resque::enqueue(
            'jobs',
            TestJob::class,
            [
                'somevar',
            ]
        );
        $job = $this->getEventTestJob();
        $this->worker->work(0);
        $this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback . ') was not called');
    }

    public function testBeforePerformEventCanStopWork()
    {
        $callback = 'beforePerformEventDontPerformCallback';
        Event::listen('beforePerform', [$this, $callback]);

        $job = $this->getEventTestJob();

        $this->assertFalse($job->perform());
        $this->assertContains($callback, $this->callbacksHit, $callback . ' callback was not called');
        $this->assertFalse(TestJob::$called, 'Job was still performed though Resque_Job_DontPerform was thrown');
    }

    public function testAfterEnqueueEventCallbackFires()
    {
        $callback = 'afterEnqueueEventCallback';
        $event = 'afterEnqueue';

        Event::listen($event, [$this, $callback]);
        Resque::enqueue(
            'jobs',
            TestJob::class,
            [
                'somevar',
            ]
        );
        $this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback . ') was not called');
    }

    public function testStopListeningRemovesListener()
    {
        $callback = 'beforePerformEventCallback';
        $event = 'beforePerform';

        Event::listen($event, [$this, $callback]);
        Event::stopListening($event, [$this, $callback]);

        $job = $this->getEventTestJob();
        $this->worker->perform($job);
        $this->worker->work(0);

        $this->assertNotContains(
            $callback,
            $this->callbacksHit,
            $event . ' callback (' . $callback . ') was called though Resque_Event::stopListening was called'
        );
    }

    public function beforePerformEventDontPerformCallback($instance)
    {
        $this->callbacksHit[] = __FUNCTION__;
        throw new DontPerform();
    }

    public function assertValidEventCallback($function, $job)
    {
        $this->callbacksHit[] = $function;
        if (!$job instanceof Job) {
            $this->fail('Callback job argument is not an instance of Resque_Job');
        }
        $args = $job->getArguments();
        $this->assertEquals($args[0], 'somevar');
    }

    public function afterEnqueueEventCallback($class, $args)
    {
        $this->callbacksHit[] = __FUNCTION__;
        $this->assertEquals(TestJob::class, $class);
        $this->assertEquals(
            [
                'somevar',
            ],
            $args
        );
    }

    public function beforePerformEventCallback($job)
    {
        $this->assertValidEventCallback(__FUNCTION__, $job);
    }

    public function afterPerformEventCallback($job)
    {
        $this->assertValidEventCallback(__FUNCTION__, $job);
    }

    public function beforeForkEventCallback($job)
    {
        $this->assertValidEventCallback(__FUNCTION__, $job);
    }

    public function afterForkEventCallback($job)
    {
        $this->assertValidEventCallback(__FUNCTION__, $job);
    }
}
