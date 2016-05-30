<?php
/**
 * Created by PhpStorm.
 * User: ww
 * Date: 03.11.15
 * Time: 22:31
 */
namespace App\FWIndependent\Clients\Abstracts;

use AdjutantHandlers\Processes\Ports;
use FractalBasic\Client\Interfaces\Client;
use FractalBasic\Client\Interfaces\ClientPortsModelInterface;
use CommandsExecutor\CommandsManager;
use FractalBasic\Client\Inventory\ClientConstants;
use FractalBasic\Client\Inventory\Exceptions\ClientException;
use FractalBasic\Inventory\CommonConstants;
use TasksInspector\Inspector;
use TasksInspector\Inventory\InspectionDto;
use Monolog\Logger;

abstract class BaseClient implements Client
{

    /**
     * @var int
     */
    protected $recursionAttempts = 0;

    /**
     * @var mixed
     */
    protected $params;

    /**
     * @var Inspector
     */
    protected $tasksInspector;

    /**
     * @var int
     */
    protected $tasksNumber;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $loggerPostfix;

    /**
     * @var string
     */
    protected $moduleName;

    /**
     * @var CommandsManager
     */
    protected $commandsManager;

    /**
     * @var bool
     */
    protected $clientAlreadyRunning = false;

    /**
     * @var bool
     */
    protected $clientRunningWasChecked = false;

    /**
     * @var int
     */
    protected $additionalSleepTime = 10;

    /**
     * @var InspectionDto
     */
    protected $inspectionDto;

    /**Will array_pop() into client ports params
     * @var array
     */
    protected $freePortsToConnect;

    /**
     * @var int
     */
    protected $requiredPortsNumber;

    /**CONSISTENT | PARALLEL
     * @var string
     */
    protected $executionType = CommonConstants::CONSISTENT;

    /**
     * @var string
     */
    protected $portsInstallationType;

    /**
     * @var array
     */
    protected $usedPorts = [];

    /**
     * @var ClientPortsModelInterface
     */
    protected $portsModel;

    /**
     * @var array
     */
    protected $fixedPorts = [];

    /**
     * @var bool
     */
    protected $recursionCallFinished = false;

    public function __construct()
    {
        $this->logger = new Logger("Default client logger.");
        $this->commandsManager = new CommandsManager();

        return null;
    }

    /**
     * @return null
     */
    public function handle()
    {
        $this->handlePorts();
        $this->prepareExecution();
        $this->initTasks();

        if (!empty($this->usedPorts)) {
            $freeRes = $this->makeUsedPortsFree();

            if (!$freeRes && ($this->logger)) {
                $this->logger->error("Not all already used port's statuses were freed. Used ports: " . serialize($this->usedPorts));
            }
        }

        return null;
    }

    /**
     * @return null
     * @throws ClientException
     */
    public function handlePorts()
    {
        switch ($this->portsInstallationType) {
            case(CommonConstants::DYNAMIC):
                $this->setFreePortsToConnect($this->getFreePorts($this->getRequiredPortsNumber()));
                break;
            case(CommonConstants::FIXED):
                //because parallel client's execution possible only with dynamic ports installation
                $this->setExecutionType(CommonConstants::CONSISTENT);
                break;
            default:
                $this->setFreePortsToConnect($this->getFreePorts($this->getRequiredPortsNumber()));
        }

        return null;
    }

    /**
     * @param $requiredPortsNumber
     * @return array
     * @throws \Exception
     */
    public function getFreePorts($requiredPortsNumber)
    {
        $portsModelPath = $this->portsModel->getMorphClass();
        $freePorts = Ports::getFreePorts($requiredPortsNumber, $portsModelPath, $this->getLoggerIfWasSet());
        $this->usedPorts = $freePorts;

        return $freePorts;
    }

    /**
     * @return mixed|null
     */
    protected function getFreePort()
    {
        $freePort = null;

        if ($this->isPortsInstallationTypeSetAsFixed()) {
            $freePort = array_pop($this->fixedPorts);
        } else {
            $freePort = CommonConstants::TCP_LOCALHOST_PREFIX . array_pop($this->freePortsToConnect);
        }

        return $freePort;
    }

    /**
     * @return null
     */
    public function makeUsedPortsFree()
    {
        return Ports::makeUsedPortsFree($this->usedPorts, $this->portsModel->getMorphClass(), $this->getLoggerIfWasSet());
    }

    /**
     * @return null
     */
    protected function getLoggerIfWasSet()
    {
        return ($this->logger) ?: null;
    }

    /**
     * @return bool
     */
    protected function isPortsInstallationTypeSetAsFixed()
    {
        return ($this->portsInstallationType === CommonConstants::FIXED) ? true : false;
    }


    /**
     * @return null
     * @throws ClientException
     */
    protected function checkFreePortsToSetNumber()
    {
        if (count($this->freePortsToConnect) !== $this->requiredPortsNumber) {
            throw new ClientException("Free ports number is not equal to required number.");
        }

        return null;
    }


    /**
     * @return null
     */
    public function resolveConsistentOrParallelScriptExecution()
    {
        switch ($this->executionType) {
            case(CommonConstants::PARALLEL):
                $this->clientAlreadyRunning = false;
                break;
            case(CommonConstants::CONSISTENT):
                $this->handleAlreadyRunningCheck();
                break;
            default:
                $this->clientAlreadyRunning = false;
        }

        return null;
    }

    /**Resolve execution type consequences, check ports number
     * @return null
     * @throws ClientException
     */
    protected function prepareExecution()
    {
        $this->resolveConsistentOrParallelScriptExecution();

        if (!$this->isPortsInstallationTypeSetAsFixed()) {
            $this->checkFreePortsToSetNumber();
        }

        return null;
    }

    /**
     * @return string
     */
    public function getExecutionType()
    {
        return $this->executionType;
    }

    /**
     * @param string $executionType
     */
    public function setExecutionType($executionType)
    {
        $this->executionType = $executionType;
    }

    /**
     * @return int
     */
    public function getRequiredPortsNumber()
    {
        return $this->requiredPortsNumber;
    }

    /**
     * @param int $requiredPortsNumber
     */
    public function setRequiredPortsNumber($requiredPortsNumber)
    {
        $this->requiredPortsNumber = $requiredPortsNumber;
    }

    /**
     * @return null
     */
    public function initLoggingParams()
    {
        $this->loggerPostfix = " | " . ($this->moduleName) ?: "default client.";

        return null;
    }

    /**
     * @return null
     */
    public function initTasksInspector()
    {
        $this->tasksInspector = new Inspector();
        $this->tasksInspector->setLogger($this->logger);
        $this->tasksInspector->setLoggerPostfix($this->loggerPostfix);

        return null;
    }

    /**
     * @return null
     */
    public function handleAlreadyRunningCheck()
    {
        // Protect from launch same clients twice, i.e. by Cron.
        // Conditions are for recursion case, to protect from detect itself
        if ($this->clientRunningWasChecked === false) {
            $this->checkClientAlreadyRunning();
        } else {
            $this->clientAlreadyRunning = false;
        }

        return null;
    }

    /**
     * @return null
     */
    public function recreateTasksInspector()
    {
        $this->initTasksInspector();
        return null;
    }

    /**
     * @return null
     */
    protected function checkClientAlreadyRunning()
    {
        $this->clientRunningWasChecked = true;
        $this->clientAlreadyRunning = $this->commandsManager->isProcessNameRunning($this->moduleName);

        return null;
    }

    /**
     * @return null
     */
    public function handleInspection()
    {
        if (!empty($this->tasksInspector->getCreatedTasks())) {

            $this->recursionAttempts++;

            $repeatedTasks = "Repeated tasks: " . serialize($this->tasksInspector->getCreatedTasks());

            if ($this->recursionAttempts < ClientConstants::MAX_RECURSION_CALL_TASKS) {

                $this->logger->warning("Client " . $this->moduleName . " start to init tasks repeatedly. | "
                    . $repeatedTasks);
                sleep(ClientConstants::MAX_GET_TASK_ATTEMPTS + $this->additionalSleepTime);
                $this->initTasks($this->tasksInspector->getCreatedTasks());

            } else {

                $attentionMsg = "Client " . $this->moduleName . " exceeded max recursionCallTasks constant ("
                    . ClientConstants::MAX_RECURSION_CALL_TASKS . ") and finished. | "
                    . $repeatedTasks;

                $this->tasksInspector->sendAttentionMail($attentionMsg);
                $this->logger->warning($attentionMsg);

                $this->recursionCallFinished = true;

            }

        } else {
            $this->tasksInspector->sendAttentionMail("All good. Inspection message: " . $this->inspectionDto->getInspectionMessage());
        }

        if ($this->tasksInspector->isMustDie()) {
            die();
        }

        return null;
    }

    /**
     * @param int $tasksNumber
     */
    public function setTasksNumber($tasksNumber)
    {
        $this->tasksNumber = $tasksNumber;
    }

    /**
     * @return mixed
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * @param mixed $params
     */
    public function setParams($params)
    {
        $this->params = $params;
    }

    /**
     * @return array
     */
    public function getFreePortsToConnect()
    {
        return $this->freePortsToConnect;
    }

    /**
     * @param array $freePortsToConnect
     */
    public function setFreePortsToConnect($freePortsToConnect)
    {
        $this->freePortsToConnect = $freePortsToConnect;
    }

    /**
     * @return string
     */
    public function getPortsInstallationType()
    {
        return $this->portsInstallationType;
    }

    /**
     * @param string $portsInstallationType
     */
    public function setPortsInstallationType($portsInstallationType)
    {
        $this->portsInstallationType = $portsInstallationType;
    }


    /**
     * @return array
     */
    public function getUsedPorts()
    {
        return $this->usedPorts;
    }

    /**
     * @param array $usedPorts
     */
    public function setUsedPorts($usedPorts)
    {
        $this->usedPorts = $usedPorts;
    }

    /**
     * @return ClientPortsModelInterface
     */
    public function getPortsModel()
    {
        return $this->portsModel;
    }

    /**
     * @param ClientPortsModelInterface $portsModel
     */
    public function setPortsModel($portsModel)
    {
        $this->portsModel = $portsModel;
    }

    /**
     * @return array
     */
    public function getFixedPorts()
    {
        return $this->fixedPorts;
    }

    /**
     * @param array $fixedPorts
     */
    public function setFixedPorts($fixedPorts)
    {
        $this->fixedPorts = $fixedPorts;
    }


}
