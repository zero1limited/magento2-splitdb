<?php
namespace Zero1\SplitDb\DB\Adapter\Pdo;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\ExpressionConverter;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\Profiler;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\DB\Statement\Parameter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\Setup\SchemaListener;
use Magento\Framework\DB\Adapter\Pdo\Mysql as CoreMysql;

/**
 * We have to extend CoreMysql to satisfy constructors like: Magento\Framework\DB\Select::__construct
 * 'this' class is the writer
 */
class MysqlProxy extends CoreMysql implements AdapterInterface
{
    protected $enableLogging = false;

    protected $readConnection;

    protected $splitDbLogger;

    protected $logLevel = \Monolog\Logger::DEBUG;

    protected $excludedAreas;

    /**
     * Constructor
     *
     * @param StringUtils $string
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param SelectFactory $selectFactory
     * @param array $config
     * @param SerializerInterface|null $serializer
     */
    public function __construct(
        StringUtils $string,
        DateTime $dateTime,
        LoggerInterface $logger,
        SelectFactory $selectFactory,
        array $config = [],
        SerializerInterface $serializer = null
    ) {
        // set log level
        if(isset($config['log_level'])){
            $this->logLevel = $config['log_level'];
        }
        $this->splitDbLogger = ObjectManager::getInstance()->get(\Zero1\SplitDb\Logger::class);

        // set excluded areas
        if(!isset($config['excluded_areas'])){
            $this->excludedAreas = [
                '/checkout',
                '/customer',
            ];
        }else{
            $this->excludedAreas = $config['excluded_areas'];
            unset($config['excluded_areas']);
        }

        if(isset($config['slaves'])){
            // keep the same slave throughout the request
            $slaveIndex = rand(0, (count($config['slaves']) - 1));
            $this->log('Using slave at index: '.$slaveIndex);
            $slaveConfig = $config['slaves'][$slaveIndex];
            unset($config['slaves']);
            $slaveConfig = array_merge(
                $config,
                $slaveConfig
            );
            $this->readConnection = ObjectManager::getInstance()->create('Zero1\SplitDb\DB\Adapter\Pdo\Mysql', [
                'string' => $string,
                'dateTime' => $dateTime,
                'logger' => $logger,
                'selectFactory' => $selectFactory,
                'config' => $slaveConfig,
                'serializer' => $serializer,
            ]);
        }else{
            // create a read connection with the same credentials as the writer
            $this->readConnection = ObjectManager::getInstance()->create('Zero1\SplitDb\DB\Adapter\Pdo\Mysql', [
                'string' => $string,
                'dateTime' => $dateTime,
                'logger' => $logger,
                'selectFactory' => $selectFactory,
                'config' => $config,
                'serializer' => $serializer,
            ]);
        }

        parent::__construct(
            $string,
            $dateTime,
            $logger,
            $selectFactory,
            $config,
            $serializer
        );
    }

    /**
     * Check if query is readonly
     */
    protected function canUseReader($sql)
    {
        // for certain circumstances we want to for using the writer
        if(php_sapi_name() == 'cli'){
            $this->log('is cli', ['sapi_name' => php_sapi_name()]);
            return false;
        }

        // only do this on GET requests
        if(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET'){
            return false;
        }

        // allow specific areas to be blocked off
        if(isset($_SERVER['REQUEST_URI'])){
            foreach($this->excludedAreas as $writerOnlyArea){
                if(stripos($_SERVER['REQUEST_URI'], $writerOnlyArea) !== false){
                    $this->log('WRITER only area found, '.$writerOnlyArea.' '.$_SERVER['REQUEST_URI']);
                    return false;
                }
            }
        }

        $writerSqlIdentifiers = [
            'INSERT ',
            'UPDATE ',
            'DELETE ',
            'DROP ',
            'CREATE ',
            'search_tmp',
        ];
        foreach($writerSqlIdentifiers as $writerSqlIdentifier){
            if(stripos($sql, $writerSqlIdentifier) !== false){
                $this->log('WRITER identifier found', [
                    'identifier' => $writerSqlIdentifier,
                    'sql' => $sql
                ]);
                return false;
            }
        }

        return true;
    }

    protected function log($message, $context = [], $severity = null)
    {
        if(!$severity){
            $severity = $this->logLevel;
        }
        $this->splitDbLogger->addRecord($severity, $message, $context);
    }

    /**
     * Special handling for PDO query().
     *
     * All bind parameter names must begin with ':'.
     *
     * @param string|\Magento\Framework\DB\Select $sql The SQL statement with placeholders.
     * @param mixed $bind An array of data or data itself to bind to the placeholders.
     * @return \Zend_Db_Statement_Pdo|void
     * @throws \Zend_Db_Adapter_Exception To re-throw \PDOException.
     * @throws LocalizedException In case multiple queries are attempted at once, to protect from SQL injection
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function query($sql, $bind = [])
    {
        if($this->canUseReader($sql)){
            $this->log('READER', [
                'method' => __FUNCTION__,
                'sql' => $sql
            ]);
            return $this->readConnection->query($sql, $bind);
        }
        $this->log('WRITER', [
            'method' => __FUNCTION__,
            'sql' => $sql
        ]);
        return parent::query($sql, $bind);
    }

    /**
     * Allows multiple queries
     *
     * Allows multiple queries -- to safeguard against SQL injection, USE CAUTION and verify that input
     * cannot be tampered with.
     * Special handling for PDO query().
     * All bind parameter names must begin with ':'.
     *
     * @param string|\Magento\Framework\DB\Select $sql The SQL statement with placeholders.
     * @param mixed $bind An array of data or data itself to bind to the placeholders.
     * @return \Zend_Db_Statement_Pdo|void
     * @throws \Zend_Db_Adapter_Exception To re-throw \PDOException.
     * @throws LocalizedException In case multiple queries are attempted at once, to protect from SQL injection
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @deprecated 101.0.0
     */
    public function multiQuery($sql, $bind = [])
    {
        if($this->canUseReader($sql)){
            $this->log('READER', [
                'method' => __FUNCTION__,
                'sql' => $sql
            ]);
            return $this->readConnection->multiQuery($sql, $bind);
        }
        $this->log('WRITER', [
            'method' => __FUNCTION__,
            'sql' => $sql
        ]);
        return parent::multiQuery($sql, $bind);
    }
}