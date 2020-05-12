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
        if(isset($config['enable_splitdb_logging'])){
            $this->enableLogging = (bool)$config['enable_splitdb_logging'];
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
            $this->log('WRITER is cli: '.php_sapi_name());
            return false;
        }

        // Too many things match this
//        $writerOnlyAreas = [
//             '/checkout',
//             '/customer',
//        ];
//
//        if(isset($_SERVER['REQUEST_URI'])){
//            foreach($writerOnlyAreas as $writerOnlyArea){
//                if(stripos($_SERVER['REQUEST_URI'], $writerOnlyArea) !== false){
//                    $this->log('WRITER only area found, '.$writerOnlyArea.' '.$_SERVER['REQUEST_URI']);
//                    return false;
//                }
//            }
//        }

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
                $this->log('WRITER identifier found, '.$writerSqlIdentifier.' '.$sql);
                return false;
            }
        }

        return true;
    }

    protected function log($message)
    {
        if($this->enableLogging){
            file_put_contents(realpath(__DIR__.'/../../../../../../../').'/var/log/splitdb.log', '['.date('c').'] '.$message.PHP_EOL, FILE_APPEND);
        }
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
            $this->log('READER->query: '.$sql);
            return $this->readConnection->query($sql, $bind);
        }
        $this->log('WRITER->query: '.$sql);
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
            $this->log('READER->multiQuery: '.$sql);
            return $this->readConnection->multiQuery($sql, $bind);
        }
        $this->log('WRITER->multiQuery: '.$sql);
        return parent::multiQuery($sql, $bind);
    }
}