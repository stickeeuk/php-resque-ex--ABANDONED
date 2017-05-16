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
use Resque\Job\Status;
use Resque\Resque;
use Resque\Tests\Job\Job as TestJob;
use Resque\Worker;

/**
 * Resque_Job_Status tests.
 *
 * @package        Resque/Tests
 *
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class JobStatusTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        // Register a worker to test with
        $this->worker = new Worker('jobs');
    }

    public function testJobStatusCanBeTracked()
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, true);
        $status = new Status($token);
        $this->assertTrue($status->isTracking());
    }

    public function testJobStatusIsReturnedViaJobInstance()
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, true);
        $job = Job::reserve('jobs');
        $this->assertEquals(Status::STATUS_WAITING, $job->getStatus());
    }

    public function testQueuedJobReturnsQueuedStatus()
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, true);
        $status = new Status($token);
        $this->assertEquals(Status::STATUS_WAITING, $status->get());
    }

    public function testRunningJobReturnsRunningStatus()
    {
        $token = Resque::enqueue('jobs', 'Failing_Job', null, true);
        $job = $this->worker->reserve();
        $this->worker->workingOn($job);
        $status = new Status($token);
        $this->assertEquals(Status::STATUS_RUNNING, $status->get());
    }

    public function testFailedJobReturnsFailedStatus()
    {
        $token = Resque::enqueue('jobs', 'Failing_Job', null, true);
        $this->worker->work(0);
        $status = new Status($token);
        $this->assertEquals(Status::STATUS_FAILED, $status->get());
    }

    public function testCompletedJobReturnsCompletedStatus()
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, true);
        $this->worker->work(0);
        $status = new Status($token);
        $this->assertEquals(Status::STATUS_COMPLETE, $status->get());
    }

    public function testStatusIsNotTrackedWhenToldNotTo()
    {
        $token = Resque::enqueue('jobs', TestJob::class, null, false);
        $status = new Status($token);
        $this->assertFalse($status->isTracking());
    }

    public function testStatusTrackingCanBeStopped()
    {
        Status::create('test');
        $status = new Status('test');
        $this->assertEquals(Status::STATUS_WAITING, $status->get());
        $status->stop();
        $this->assertFalse($status->get());
    }

    public function testRecreatedJobWithTrackingStillTracksStatus()
    {
        $originalToken = Resque::enqueue('jobs', TestJob::class, null, true);
        $job = $this->worker->reserve();

        // Mark this job as being worked on to ensure that the new status is still
        // waiting.
        $this->worker->workingOn($job);

        // Now recreate it
        $newToken = $job->recreate();

        // Make sure we've got a new job returned
        $this->assertNotEquals($originalToken, $newToken);

        // Now check the status of the new job
        $newJob = Job::reserve('jobs');
        $this->assertEquals(Status::STATUS_WAITING, $newJob->getStatus());
    }
}
