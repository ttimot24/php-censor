<?php

namespace PHPCensor\Worker;

use b8\Store\Factory;
use Monolog\Logger;
use Pheanstalk\Job;
use Pheanstalk\Pheanstalk;
use PHPCensor\Builder;
use PHPCensor\BuilderException;
use PHPCensor\BuildFactory;
use PHPCensor\Logging\BuildDBLogHandler;
use PHPCensor\Model\Build;

/**
 * Class BuildWorker
 * @package PHPCensor\Worker
 */
class BuildWorker
{
    /**
     * If this variable changes to false, the worker will stop after the current build.
     * @var bool
     */
    protected $run = true;

    /**
     * The logger for builds to use.
     * @var \Monolog\Logger
     */
    protected $logger;

    /**
     * beanstalkd host
     * @var string
     */
    protected $host;

    /**
     * beanstalkd queue to watch
     * @var string
     */
    protected $queue;

    /**
     * @var \Pheanstalk\Pheanstalk
     */
    protected $pheanstalk;

    /**
     * @var int
     */
    protected $totalJobs = 0;

    /**
     * @param $host
     * @param $queue
     */
    public function __construct($host, $queue)
    {
        $this->host       = $host;
        $this->queue      = $queue;
        $this->pheanstalk = new Pheanstalk($this->host);
    }

    /**
     * @param Logger $logger
     */
    public function setLogger(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Start the worker.
     */
    public function startWorker()
    {
        $this->pheanstalk->watch($this->queue);
        $this->pheanstalk->ignore('default');
        $buildStore = Factory::getStore('Build');

        while ($this->run) {
            // Get a job from the queue:
            $job = $this->pheanstalk->reserve();

            // Get the job data and run the job:
            $jobData = json_decode($job->getData(), true);

            if (!$this->verifyJob($job, $jobData)) {
                continue;
            }

            $this->logger->addInfo('Received build #'.$jobData['build_id'].' from Beanstalkd');

            try {
                $build = BuildFactory::getBuildById($jobData['build_id']);
            } catch (\Exception $ex) {
                $this->logger->addWarning('Build #' . $jobData['build_id'] . ' does not exist in the database.');
                $this->pheanstalk->delete($job);
                continue;
            }

            // Logging relevant to this build should be stored
            // against the build itself.
            $buildDbLog = new BuildDBLogHandler($build, Logger::INFO);
            $this->logger->pushHandler($buildDbLog);

            try {
                $builder = new Builder($build, $this->logger);
                $builder->execute();
            } catch (\PDOException $ex) {
                // If we've caught a PDO Exception, it is probably not the fault of the build, but of a failed
                // connection or similar. Release the job and kill the worker.
                $this->run = false;
                $this->pheanstalk->release($job);
                unset($job);
            } catch (\Exception $ex) {
                $this->logger->addError($ex->getMessage());

                $build->setStatus(Build::STATUS_FAILED);
                $build->setFinished(new \DateTime());
                $build->setLog($build->getLog() . PHP_EOL . PHP_EOL . $ex->getMessage());
                $buildStore->save($build);
                $build->sendStatusPostback();
            }

            // After execution we no longer want to record the information
            // back to this specific build so the handler should be removed.
            $this->logger->popHandler();
            // destructor implicitly call flush
            unset($buildDbLog);

            // Delete the job when we're done:
            if (!empty($job)) {
                $this->pheanstalk->delete($job);
            }
        }
    }

    /**
     * Stops the worker after the current build.
     */
    public function stopWorker()
    {
        $this->run = false;
    }

    /**
     * Checks that the job received is actually from PHPCI, and has a valid type.
     * @param Job $job
     * @param $jobData
     * @return bool
     */
    protected function verifyJob(Job $job, $jobData)
    {
        if (empty($jobData) || !is_array($jobData)) {
            // Probably not from PHPCI.
            $this->pheanstalk->delete($job);
            return false;
        }

        if (!array_key_exists('type', $jobData) || $jobData['type'] !== 'php-censor.build') {
            // Probably not from PHPCI.
            $this->pheanstalk->delete($job);
            return false;
        }

        return true;
    }
}
