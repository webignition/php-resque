<?php
namespace Resque;

/**
 * Resque job.
 *
 * @package        Resque/Job
 * @author        William POTTIER <wpottier@allprogrammic.com>
 * @copyright    (c) 2012 William POTTIER
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Job {

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
     * @var \Resque\Job\AbstractInstance Instance of the class performing work for this job.
     */
    private $instance;

    /**
     * Instantiate a new instance of a job.
     *
     * @param string $queue The queue that the job belongs to.
     * @param array $payload array containing details of the job.
     */
    public function __construct ($queue, $payload) {
        $this->queue   = $queue;
        $this->payload = $payload;
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     * @param boolean $monitor Set to true to be able to monitor the status of a job.
     *
     * @return string
     */
    public static function create ($queue, $class, $args = null, $monitor = false) {
        if ($args !== null && !is_array($args)) {
            throw new \InvalidArgumentException(
                'Supplied $args must be an array.'
            );
        }
        $id = md5(uniqid('', true));
        Resque::push($queue, array(
                                  'class'    => $class,
                                  'args'     => array($args),
                                  'id'       => $id,
                             ));

        if ($monitor) {
            \Resque\Job\Status::create($id);
        }

        return $id;
    }

    /**
     * Find the next available job from the specified queue and return an
     * instance of Resque_Job for it.
     *
     * @param string $queue The name of the queue to check for a job in.
     *
     * @return null|object Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
     */
    public static function reserve ($queue) {
        $payload = Resque::pop($queue);
        if (!is_array($payload)) {
            return false;
        }

        return new Job($queue, $payload);
    }

    /**
     * Update the status of the current job.
     *
     * @param int $status Status constant from Resque_Job_Status indicating the current status of a job.
     */
    public function updateStatus ($status) {
        if (empty($this->payload['id'])) {
            return;
        }

        $statusInstance = new \Resque\Job\Status($this->payload['id']);
        $statusInstance->update($status);
    }

    /**
     * Return the status of the current job.
     *
     * @return int The status of the job as one of the Resque_Job_Status constants.
     */
    public function getStatus () {
        $status = new \Resque\Job\Status($this->payload['id']);
        return $status->get();
    }

    /**
     * Get the arguments supplied to this job.
     *
     * @return array Array of arguments.
     */
    public function getArguments () {
        if (!isset($this->payload['args'])) {
            return array();
        }

        return $this->payload['args'][0];
    }

    /**
     * Get the instantiated object for this job that will be performing work.
     *
     * @return \Resque\Job\AbstractInstance Instance of the object that this job belongs to.
     */
    public function getInstance()
    {
        if (!is_null($this->instance)) {
            return $this->instance;
        }

        $event = new \Resque\Event\CreateInstance($this);
        Event::trigger('createInstance', $event);
        if (null === ($this->instance = $event->getInstance())) {
            if(!class_exists($this->payload['class'])) {
                throw new Exception(
                    'Could not find job class ' . $this->payload['class'] . '.'
                );
            }

            $class = $this->payload['class'];
            $this->instance = new $class();
        }

        if(!($this->instance instanceof \Resque\Job\AbstractInstance)) {
            $this->instance = null;
            throw new Exception(
                'Job class ' . $this->payload['class'] . ' does not contain override \Resque\Job\AbstractInstance.'
            );
        }

        $this->instance->setJob($this);
        $this->instance->setArgs($this->getArguments());
        $this->instance->setQueue($this->queue);

        return $this->instance;
    }

    /**
     * Actually execute a job by calling the perform method on the class
     * associated with the job with the supplied arguments.
     *
     * @return bool
     * @throws Exception When the job's class could not be found or it does not contain a perform method.
     */
    public function perform () {
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
        }
            // beforePerform/setUp have said don't perform this job. Return.
        catch (\Resque\Job\DontPerform $e) {
            return false;
        }

        return true;
    }

    /**
     * Mark the current job as having failed.
     *
     * @param $exception
     */
    public function fail ($exception) {
        Event::trigger('onFailure', array(
                                                'exception' => $exception,
                                                'job'       => $this,
                                           ));

        $this->updateStatus(\Resque\Job\Status::STATUS_FAILED);
        require_once dirname(__FILE__) . '/Failure.php';
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
     * @return string
     */
    public function recreate () {
        $status  = new \Resque\Job\Status($this->payload['id']);
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
    public function __toString () {
        $name = array(
            'Job{' . $this->queue . '}'
        );
        if (!empty($this->payload['id'])) {
            $name[] = 'ID: ' . $this->payload['id'];
        }
        $name[] = $this->payload['class'];
        if (!empty($this->payload['args'])) {
            $name[] = json_encode($this->payload['args']);
        }
        return '(' . implode(' | ', $name) . ')';
    }
}

?>
