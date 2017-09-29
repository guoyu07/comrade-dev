<?php
namespace App\Queue;

use App\Commands;
use Comrade\Shared\Message\JobResult as JobResultMessage;
use App\Topics;
use App\Infra\JsonSchema\Errors;
use App\Infra\JsonSchema\SchemaValidator;
use App\Infra\Uuid;
use App\JobStatus;
use App\Storage\JobStorage;
use App\Storage\JobTemplateStorage;
use App\Storage\ProcessExecutionStorage;
use Comrade\Shared\Message\RunSubJobsResult;
use Comrade\Shared\Model\Job;
use Comrade\Shared\Model\SubJobTemplate;
use Enqueue\Client\CommandSubscriberInterface;
use Enqueue\Client\ProducerInterface;
use Enqueue\Consumption\QueueSubscriberInterface;
use Enqueue\Consumption\Result;
use Interop\Queue\PsrContext;
use Interop\Queue\PsrMessage;
use Interop\Queue\PsrProcessor;
use Enqueue\Util\JSON;
use function Makasim\Values\get_values;

class JobResultProcessor implements PsrProcessor, CommandSubscriberInterface, QueueSubscriberInterface
{
    /**
     * @var SchemaValidator
     */
    private $schemaValidator;

    /**
     * @var ProcessExecutionStorage
     */
    private $processExecutionStorage;

    /**
     * @var JobStorage
     */
    private $jobStorage;

    /**
     * @var JobTemplateStorage
     */
    private $jobTemplateStorage;

    /**
     * @var ProducerInterface
     */
    private $producer;

    /**
     * @param SchemaValidator $schemaValidator
     * @param ProcessExecutionStorage $processExecutionStorage
     * @param JobStorage $jobStorage
     * @param JobTemplateStorage $jobTemplateStorage
     * @param ProducerInterface $producer
     */
    public function __construct(
        SchemaValidator $schemaValidator,
        ProcessExecutionStorage $processExecutionStorage,
        JobStorage $jobStorage,
        JobTemplateStorage $jobTemplateStorage,
        ProducerInterface $producer
    ) {
        $this->schemaValidator = $schemaValidator;
        $this->processExecutionStorage = $processExecutionStorage;
        $this->jobStorage = $jobStorage;
        $this->jobTemplateStorage = $jobTemplateStorage;
        $this->producer = $producer;
    }

    /**
     * {@inheritdoc}
     */
    public function process(PsrMessage $psrMessage, PsrContext $psrContext)
    {
        $data = JSON::decode($psrMessage->getBody());
        if ($errors = $this->schemaValidator->validate($data, JobResultMessage::SCHEMA)) {
            return Result::reject(Errors::toString($errors, 'Message schema validation has failed.'));
        }

        if (
            RunSubJobsResult::SCHEMA == $data['schema'] &&
            $errors = $this->schemaValidator->validate($data, RunSubJobsResult::SCHEMA)
        ) {
            return Result::reject(Errors::toString($errors, 'Message schema validation has failed.'));
        }

        $message = JobResultMessage::create($data);
        $token = $message->getToken();

        $this->jobStorage->lockByJobId($message->getJobId(), function(Job $job) use($message) {
            $job->addResult($message->getResult());
            $job->setCurrentResult($message->getResult());
            $this->jobStorage->update($job);
            $this->producer->sendEvent(Topics::UPDATE_JOB, get_values($job));
        });

        $job = $this->jobStorage->getOneById($message->getJobId());
        if ($message instanceof RunSubJobsResult) {
            if (false == $job->getRunSubJobsPolicy()) {
                $this->jobStorage->lockByJobId($message->getJobId(), function(Job $job) use($message) {
                    $result = \App\Model\JobResult::createFor(JobStatus::STATUS_FAILED);
                    $job->addResult($result);
                    $job->setCurrentResult($result);
                    $this->jobStorage->update($job);
                    $this->producer->sendEvent(Topics::UPDATE_JOB, get_values($job));
                });

                return self::ACK;
            }

            $processId = Uuid::generate();
            foreach ($message->getJobTemplates() as $subJobTemplate) {
                $subJobTemplate = SubJobTemplate::createFromJobTemplate($job->getId(), $subJobTemplate);
                $subJobTemplate->setProcessTemplateId($message->getProcessTemplateId());

                $subJob = Job::createFromTemplate($subJobTemplate);
                $subJob->setId(Uuid::generate());
                $subJob->setProcessId($processId);
                $subJob->setCreatedAt(new \DateTime('now'));

                $this->jobStorage->insert($subJob);
            }
        }

        if (false == $process = $this->processExecutionStorage->getOneByToken($message->getToken())) {
            return Result::reject(sprintf('The process assoc with the token "%s" could not be found', $token));
        }

        $this->producer->sendCommand(Commands::PVM_HANDLE_ASYNC_TRANSITION, [
            'process' => $process->getId(),
            'token' => $token,
        ]);

        return self::ACK;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedCommand()
    {
        return [
            'processorName' => Commands::JOB_RESULT,
            'queueName' => Commands::JOB_RESULT,
            'queueNameHardcoded' => true,
            'exclusive' => true,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedQueues()
    {
        return [Commands::JOB_RESULT];
    }
}