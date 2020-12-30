<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\db;

use Closure;
use Craft;
use craft\base\ClonefixTrait;
use craft\events\DefineBehaviorsEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use PDO;
use yii\base\Exception;
use yii\db\BatchQueryResult;
use yii\db\Connection as YiiConnection;

/**
 * Class Query
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class Query extends \yii\db\Query
{
    use ClonefixTrait;

    /**
     * @event \yii\base\Event The event that is triggered after the query's init cycle
     * @see init()
     */
    const EVENT_INIT = 'init';

    /**
     * @event DefineBehaviorsEvent The event that is triggered when defining the class behaviors
     * @see behaviors()
     */
    const EVENT_DEFINE_BEHAVIORS = 'defineBehaviors';

    /**
     * @var Connection|false|null
     * @see _unbufferedDb()
     */
    private static $_unbufferedDb;

    /**
     * Returns an unbuffered DB connection, if using MySQL.
     */
    private static function _unbufferedDb(): ?Connection
    {
        if (self::$_unbufferedDb === null) {
            self::$_unbufferedDb = Db::createUnbufferedDb() ?? false;
        }
        return self::$_unbufferedDb ?: null;
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if ($this->hasEventHandlers(self::EVENT_INIT)) {
            $this->trigger(self::EVENT_INIT);
        }
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        // Fire a 'defineBehaviors' event
        $event = new DefineBehaviorsEvent();
        $this->trigger(self::EVENT_DEFINE_BEHAVIORS, $event);
        return $event->behaviors;
    }

    /**
     * Returns whether a given table has been joined in this query.
     *
     * @param string $table
     * @return bool
     */
    public function isJoined(string $table): bool
    {
        foreach ($this->join as $join) {
            if ($join[1] === $table || strpos($join[1], $table) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function where($condition, $params = [])
    {
        if (!$condition) {
            $condition = null;
        }

        return parent::where($condition, $params);
    }

    /**
     * @inheritdoc
     */
    public function andWhere($condition, $params = [])
    {
        if (!$condition) {
            return $this;
        }

        return parent::andWhere($condition, $params);
    }

    /**
     * @inheritdoc
     */
    public function orWhere($condition, $params = [])
    {
        if (!$condition) {
            return $this;
        }

        return parent::orWhere($condition, $params);
    }

    // Execution functions
    // -------------------------------------------------------------------------

    /**
     * Executes the query and returns the first two columns in the results as key/value pairs.
     *
     * @param YiiConnection|null $db The database connection used to execute the query.
     * If this parameter is not given, the `db` application component will be used.
     * @return array the query results. If the query results in nothing, an empty array will be returned.
     * @throws Exception if less than two columns were selected
     */
    public function pairs(YiiConnection $db = null): array
    {
        try {
            $rows = $this->createCommand($db)->queryAll();
        } catch (QueryAbortedException $e) {
            return [];
        }

        if (!empty($rows)) {
            $columns = array_keys($rows[0]);

            if (count($columns) < 2) {
                throw new Exception('Less than two columns were selected');
            }

            $rows = ArrayHelper::map($rows, $columns[0], $columns[1]);
        }

        return $rows;
    }

    /**
     * @inheritdoc
     * @since 3.6.0
     */
    public function batch($batchSize = 100, $db = null)
    {
        return $this->_batch($batchSize, $db, false);
    }

    /**
     * @inheritdoc
     * @since 3.6.0
     */
    public function each($batchSize = 100, $db = null)
    {
        return $this->_batch($batchSize, $db, true);
    }

    /**
     * Returns a batch query for batch() and each(), with the DB possibly set to an unbuffered DB connection.
     *
     * @param int $batchSize
     * @param YiiConnection|null $db
     * @param bool $each
     */
    private function _batch(int $batchSize, ?YiiConnection $db, bool $each)
    {
        if ($unbuffered = ($db === null && Craft::$app->getDb()->getIsMysql())) {
            // Create a new DB connection based on the `db` app component, but set to run unbuffered queries
            // see https://www.yiiframework.com/doc/guide/2.0/en/db-query-builder#batch-query-mysql
            $db = Craft::$app->getComponents()['db'];
            if (!is_object($db) || $db instanceof Closure) {
                $db = Craft::createObject($db);
            }
            $db->on(YiiConnection::EVENT_AFTER_OPEN, function() use ($db) {
                $db->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            });
        }

        /** @var BatchQueryResult $result */
        $result = Craft::createObject([
            'class' => BatchQueryResult::class,
            'query' => $this,
            'batchSize' => $batchSize,
            'db' => $db,
            'each' => $each,
        ]);

        if ($unbuffered) {
            $result->on(BatchQueryResult::EVENT_FINISH, function() use ($db) {
                $db->close();
            });
            $result->on(BatchQueryResult::EVENT_RESET, function() use ($db) {
                $db->close();
            });
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function all($db = null)
    {
        try {
            return parent::all($db);
        } catch (QueryAbortedException $e) {
            return [];
        }
    }

    /**
     * @inheritdoc
     * @return array|null the first row (in terms of an array) of the query result. Null is returned if the query
     * results in nothing.
     */
    public function one($db = null)
    {
        $limit = $this->limit;
        $this->limit = 1;
        try {
            $result = parent::one($db);
            // Be more like Yii 2.1
            if ($result === false) {
                $result = null;
            }
        } catch (QueryAbortedException $e) {
            $result = null;
        }
        $this->limit = $limit;
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function scalar($db = null)
    {
        $limit = $this->limit;
        $this->limit = 1;
        try {
            $result = parent::scalar($db);
        } catch (QueryAbortedException $e) {
            $result = false;
        }
        $this->limit = $limit;
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function column($db = null)
    {
        try {
            return parent::column($db);
        } catch (QueryAbortedException $e) {
            return [];
        }
    }

    /**
     * @inheritdoc
     */
    public function exists($db = null)
    {
        try {
            return parent::exists($db);
        } catch (QueryAbortedException $e) {
            return false;
        }
    }

    /**
     * Executes the query and returns a single row of result at a given offset.
     *
     * @param int $n The offset of the row to return. If [[offset]] is set, $offset will be added to it.
     * @param YiiConnection|null $db The database connection used to generate the SQL statement.
     * If this parameter is not given, the `db` application component will be used.
     * @return array|null The row (in terms of an array) of the query result. Null is returned if the query
     * results in nothing.
     */
    public function nth(int $n, YiiConnection $db = null)
    {
        $offset = $this->offset;
        $this->offset = ($offset ?: 0) + $n;
        $result = $this->one($db);
        $this->offset = $offset;

        return $result;
    }

    /**
     * Shortcut for `createCommand()->getRawSql()`.
     *
     * @param YiiConnection|null $db the database connection used to generate the SQL statement.
     * If this parameter is not given, the `db` application component will be used.
     * @return string
     * @see createCommand()
     * @see \yii\db\Command::getRawSql()
     */
    public function getRawSql(YiiConnection $db = null): string
    {
        return $this->createCommand($db)->getRawSql();
    }

    /**
     * @inheritdoc
     */
    protected function queryScalar($selectExpression, $db)
    {
        try {
            return parent::queryScalar($selectExpression, $db);
        } catch (QueryAbortedException $e) {
            return false;
        }
    }
}
