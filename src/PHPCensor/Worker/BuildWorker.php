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
 */
class BuildWorker
{
    /**
     * If this variable changes to false, the worker will stop after the current build.
     *
     * @var boolean
     */
    protected $run = true;

    /**
     * The logger for builds to use.
     *
     * @var \Monolog\Logger
     */
    protected $logger;

    /**
     * Beanstalkd host
     *
     * @var string
     */
    protected $host;

    /**
     * Beanstalkd queue to watch
     *
     * @var string
     */
    protected $queue;

    /**
     * @var \Pheanstalk\Pheanstalk
     */
    protected $pheanstalk;

    /**
     * @var integer
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
        $this->pheanstalk
            ->watch($this->queue)
            ->ignore('default');

        $buildStore = Factory::getStore('Build');

        while ($this->run) {
            $job     = $this->pheanstalk->reserve();
            $jobData = json_decode($job->getData(), true);

            if (!$this->validateJob($jobData)) {
                $this->pheanstalk->delete($job);

                continue;
            }

            $this->logger->addInfo('Received build #'.$jobData['build_id'].' from Beanstalkd');

            try {
                $build = BuildFactory::getBuildById((integer)$jobData['build_id']);
            } catch (\Exception $e) {
                $this->logger->addError('Exception: ' . $e->getMessage());
                $this->pheanstalk->delete($job);

                continue;
            }

            // Logging relevant to this build should be stored against the build itself.
            $buildDbLog = new BuildDBLogHandler(Logger::INFO, true, $build);
            $this->logger->pushHandler($buildDbLog);

            try {
                $builder = new Builder($build, $this->logger);
                $builder->execute();
            } catch (BuilderException $e) {
                $this->logger->addError('Exception: ' . $e->getMessage());
                
                switch($e->getCode()) {
                    case BuilderException::FAIL_START:
                        // non fatal
                        $this->pheanstalk->release($job);
                        unset($job);
                        break;
                    default:
                        $build->setStatus(Build::STATUS_FAILED);
                        $build->setFinished(new \DateTime());
                        $build->setLog($build->getLog() . PHP_EOL . PHP_EOL . $e->getMessage());
                        $buildStore->save($build);
                        $build->sendStatusPostback();
                        break;
                }
            } catch (\PDOException $ex) {
                // If we've caught a PDO Exception, it is probably not the fault of the build, but of a failed
                // connection or similar. Release the job and kill the worker.
                $this->stopWorker();
                $this->pheanstalk->release($job);
                unset($job);
            } catch (\Exception $ex) {
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
     *
     * @param mixed $jobData
     *
     * @return bool
     */
    protected function validateJob($jobData)
    {
        if (empty($jobData) || !is_array($jobData)) {
            return false;
        }

        if (!array_key_exists('type', $jobData) || $jobData['type'] !== 'php-censor.build') {
            return false;
        }

        if (!array_key_exists('build_id', $jobData) || !is_numeric($jobData['build_id'])) {
            return false;
        }

        return true;
    }
}
