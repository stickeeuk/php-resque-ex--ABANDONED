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

use InvalidArgumentException;
use Resque\Job\DontPerform;
use Resque\Job\Status;
use Resque_Job_Creator;

/**
 * Resque job.
 *
 * @package Resque/Job
 *
 * @author Chris Boulton <chris@bigcommerce.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Job
{
    /**
     * @var string The name of the queue that this job belongs to.
     */
    public $queue;

    /**
     * @var Worker Instance of the Resque worker running this job.
     */
    public $worker;

    /**
     * @var object Object containing details of the job.
     */
    public $payload;

    /**
     * @var object Instance of the class performing work for this job.
     */
    private $instance;

    /**
     * Instantiate a new instance of a job.
     *
     * @param string $queue The queue that the job belongs to.
     * @param array $payload array containing details of the job.
     */
    public function __construct($queue, $payload)
    {
        $this->queue = $queue;
        $this->payload = $payload;
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     * @param bool $monitor Set to true to be able to monitor the status of a job.
     *
     * @return string
     */
    public static function create($queue, $class, $args = null, $monitor = false)
    {
        if ($args !== null && !is_array($args)) {
            throw new InvalidArgumentException(
                'Supplied $args must be an array.'
            );
        }

        $new = true;
        if (isset($args['id'])) {
            $id = $args['id'];
            unset($args['id']);
            $new = false;
        } else {
            $id = md5(uniqid('', true));
        }
        Resque::push(
            $queue,
            [
                'class' => $class,
                'args' => [$args],
                'id' => $id,
            ]
        );

        if ($monitor) {
            if ($new) {
                Status::create($id);
            } else {
                $statusInstance = new Status($id);
                $statusInstance->update($id, Status::STATUS_WAITING);
            }
        }

        return $id;
    }

    /**
     * Find the next available job from the specified queue and return an
     * instance of Resque_Job for it.
     *
     * @param string $queue The name of the queue to check for a job in.
     *
     * @return bool|object Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
     */
    public static function reserve($queue)
    {
        $payload = Resque::pop($queue);
        if (!is_array($payload)) {
            return false;
        }

        return new self($queue, $payload);
    }

    /**
     * Update the status of the current job.
     *
     * @param int $status Status constant from Resque_Job_Status indicating the current status of a job.
     */
    public function updateStatus($status)
    {
        if (empty($this->payload['id'])) {
            return;
        }

        $statusInstance = new Status($this->payload['id']);
        $statusInstance->update($status);
    }

    /**
     * Return the status of the current job.
     *
     * @return int The status of the job as one of the Resque_Job_Status constants.
     */
    public function getStatus()
    {
        $status = new Status($this->payload['id']);

        return $status->get();
    }

    /**
     * Get the arguments supplied to this job.
     *
     * @return array Array of arguments.
     */
    public function getArguments()
    {
        if (!isset($this->payload['args'])) {
            return [];
        }

        return $this->payload['args'][0];
    }

    /**
     * Get the instantiated object for this job that will be performing work.
     *
     * @return object Instance of the object that this job belongs to.
     */
    public function getInstance()
    {
        if (!is_null($this->instance)) {
            return $this->instance;
        }

        if (class_exists('Resque_Job_Creator')) {
            $this->instance = Resque_Job_Creator::createJob($this->payload['class'], $this->getArguments());
        } else {
            if (!class_exists($this->payload['class'])) {
                throw new ResqueException(
                    'Could not find job class ' . $this->payload['class'] . '.'
                );
            }

            if (!method_exists($this->payload['class'], 'perform')) {
                throw new ResqueException(
                    'Job class ' . $this->payload['class'] . ' does not contain a perform method.'
                );
            }
            $this->instance = new $this->payload['class']();
        }

        $this->instance->job = $this;
        $this->instance->args = $this->getArguments();
        $this->instance->queue = $this->queue;

        return $this->instance;
    }

    /**
     * Actually execute a job by calling the perform method on the class
     * associated with the job with the supplied arguments.
     *
     * @return bool
     *
     * @throws ResqueException When the job's class could not be found or it does not contain a perform method.
     */
    public function perform()
    {
        $instance = $this->getInstance();
        try {
            Event::trigger('beforePerform', $this);

            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }

            $instance->perform();

            if (method_exists($instance, 'tearDown')) {
                $instance->tearDown();
            }

            Event::trigger('afterPerform', $this);
        } // beforePerform/setUp have said don't perform this job. Return.
        catch (DontPerform $e) {
            return false;
        }

        return true;
    }

    /**
     * Mark the current job as having failed.
     *
     * @param $exception
     */
    public function fail($exception)
    {
        Event::trigger(
            'onFailure',
            [
                'exception' => $exception,
                'job' => $this,
            ]
        );

        $this->updateStatus(Status::STATUS_FAILED);

        Failure::create(
            $this->payload,
            $exception,
            $this->worker,
            $this->queue
        );
        Stat::incr('failed');
        Stat::incr('failed:' . $this->worker);
    }

    /**
     * Re-queue the current job.
     *
     * @return string
     */
    public function recreate()
    {
        $status = new Status($this->payload['id']);
        $monitor = false;
        if ($status->isTracking()) {
            $monitor = true;
        }

        return self::create($this->queue, $this->payload['class'], $this->payload['args'], $monitor);
    }

    /**
     * Generate a string representation used to describe the current job.
     *
     * @return string The string representation of the job.
     */
    public function __toString()
    {
        return json_encode(
            [
                'queue' => $this->queue,
                'id' => !empty($this->payload['id']) ? $this->payload['id'] : '',
                'class' => $this->payload['class'],
                'args' => !empty($this->payload['args']) ? $this->payload['args'] : '',
            ]
        );
    }
}