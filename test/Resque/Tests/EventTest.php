<?php
namespace Resque\Tests;

require_once dirname(__FILE__) . '/bootstrap.php';

/**
 * Resque_Event tests.
 *
 * @package		Resque/Tests
 * @author		William POTTIER <wpottier@allprogrammic.com>
 * @copyright	(c) 2012 William POTTIER
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class EventTest extends TestCase
{
	private $callbacksHit = array();
	
	public function setUp()
	{
		\Test_Job::$called = false;
		
		// Register a worker to test with
		$this->worker = new \Resque\Worker('jobs');
		$this->worker->registerWorker();
	}

	public function tearDown()
	{
		\Resque\Event::clearListeners();
		$this->callbacksHit = array();
	}

	public function getEventTestJob()
	{
		$payload = array(
			'class' => 'Test_Job',
			'args' => array(
				'somevar',
			),
		);
		$job = new \Resque\Job('jobs', $payload);
		$job->worker = $this->worker;
		return $job;
	}
	
	public function eventCallbackProvider()
	{
		return array(
			array('beforePerform', 'beforePerformEventCallback'),
			array('afterPerform', 'afterPerformEventCallback'),
			array('afterFork', 'afterForkEventCallback'),
		);
	}
	
	/**
	 * @dataProvider eventCallbackProvider
	 */
	public function testEventCallbacksFire($event, $callback)
	{
		\Resque\Event::listen($event, array($this, $callback));

		$job = $this->getEventTestJob();
		$this->worker->perform($job);
		$this->worker->work(0);
		
		$this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback .') was not called');
	}
	
	public function testBeforeForkEventCallbackFires()
	{
		$event = 'beforeFork';
		$callback = 'beforeForkEventCallback';

		\Resque\Event::listen($event, array($this, $callback));
		\Resque\Resque::enqueue('jobs', 'Test_Job', array(
			'somevar'
		));
		$job = $this->getEventTestJob();
		$this->worker->work(0);
		$this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback .') was not called');
	}

	public function testBeforePerformEventCanStopWork()
	{
		$callback = 'beforePerformEventDontPerformCallback';
		\Resque\Event::listen('beforePerform', array($this, $callback));

		$job = $this->getEventTestJob();

		$this->assertFalse($job->perform());
		$this->assertContains($callback, $this->callbacksHit, $callback . ' callback was not called');
		$this->assertFalse(\Test_Job::$called, 'Job was still performed though \Resque\Job_DontPerform was thrown');
	}
	
	public function testAfterEnqueueEventCallbackFires()
	{
		$callback = 'afterEnqueueEventCallback';
		$event = 'afterEnqueue';
	
		\Resque\Event::listen($event, array($this, $callback));
		\Resque\Resque::enqueue('jobs', 'Test_Job', array(
			'somevar'
		));	
		$this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback .') was not called');
	}

	public function testStopListeningRemovesListener()
	{
		$callback = 'beforePerformEventCallback';
		$event = 'beforePerform';

		\Resque\Event::listen($event, array($this, $callback));
		\Resque\Event::stopListening($event, array($this, $callback));

		$job = $this->getEventTestJob();
		$this->worker->perform($job);
		$this->worker->work(0);

		$this->assertNotContains($callback, $this->callbacksHit, 
			$event . ' callback (' . $callback .') was called though Resque_Event::stopListening was called'
		);
	}

	
	public function beforePerformEventDontPerformCallback($instance)
	{
		$this->callbacksHit[] = __FUNCTION__;
		throw new \Resque\Job\DontPerform;
	}
	
	public function assertValidEventCallback($function, $job)
	{
		$this->callbacksHit[] = $function;
		if (!$job instanceof \Resque\Job) {
			$this->fail('Callback job argument is not an instance of \Resque\Job');
		}
		$args = $job->getArguments();
		$this->assertEquals($args[0], 'somevar');
	}
	
	public function afterEnqueueEventCallback($class, $args)
	{
		$this->callbacksHit[] = __FUNCTION__;
		$this->assertEquals('Test_Job', $class);
		$this->assertEquals(array(
			'somevar',
		), $args);
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
