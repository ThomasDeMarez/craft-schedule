<?php
/**
 * Schedule plugin for CraftCMS 3
 *
 * @link      https://panlatent.com/
 * @copyright Copyright (c) 2018 panlatent@gmail.com
 */

namespace panlatent\schedule\base;

use Craft;
use craft\base\SavableComponent;
use craft\db\mysql\Schema as MysqlSchema;
use craft\validators\HandleValidator;
use DateTime;
use panlatent\schedule\Builder;
use panlatent\schedule\db\Table;
use panlatent\schedule\events\ScheduleEvent;
use panlatent\schedule\models\ScheduleGroup;
use panlatent\schedule\models\ScheduleLog;
use panlatent\schedule\Plugin;
use yii\db\Query;


/**
 * Class Schedule
 *
 * @package panlatent\schedule\base
 * @property ScheduleGroup $group
 * @property TimerInterface[] $timers
 * @property-read ScheduleLog[] $logs
 * @property-read int $totalLogs
 * @property-read string $cronExpression
 * @property-read string $cronDescription
 * @property-read DateTime $lastFinishedDate
 * @property-read int $lastDuration
 * @author Panlatent <panlatent@gmail.com>
 */
abstract class Schedule extends SavableComponent implements ScheduleInterface
{
    // Traits
    // =========================================================================

    use ScheduleTrait;

    // Events
    // =========================================================================

    /**
     * @event ScheduleEvent
     */
    const EVENT_BEFORE_RUN = 'beforeRun';

    /**
     * @event ScheduleEvent
     */
    const EVENT_AFTER_RUN = 'afterRun';

    // Statuses
    // =========================================================================

    const STATUS_PREPARING = 'preparing';

    const STATUS_PROCESSING = 'processing';

    const STATUS_SUCCESSFUL = 'successful';

    const STATUS_FAILED = 'failed';

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function isRunnable(): bool
    {
        return false;
    }

    // Properties
    // =========================================================================

    /**
     * @var ScheduleGroup|null
     */
    private $_group;

    /**
     * @var TimerInterface[]|null
     */
    private $_timers;

    // Public Methods
    // =========================================================================

    /**
     * @return string
     */
    public function __toString()
    {
        return (string)$this->name;
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['name', 'handle'], 'required'],
            [['groupId'], 'integer'],
            [['name', 'handle', 'description', 'user'], 'string'],
            [['handle'], HandleValidator::class],
        ];
    }

    /**
     * @return ScheduleGroup|null
     */
    public function getGroup()
    {
        if ($this->_group !== null) {
            return $this->_group;
        }

        if (!$this->groupId) {
            return null;
        }

        return $this->_group = Plugin::$plugin->getSchedules()->getGroupById($this->groupId);
    }

    /**
     * @return TimerInterface[]|null
     */
    public function getTimers(): array
    {
        if ($this->_timers !== null) {
            return $this->_timers;
        }

        if (!$this->id) {
            return [];
        }

        $this->_timers = Plugin::getInstance()->getTimers()->getTimersByScheduleId($this->id);

        return $this->_timers;
    }

    /**
     * @param TimerInterface[]|null $timers
     */
    public function setTimers(array $timers)
    {
        $this->_timers = $timers;
    }

    /**
     * @return ScheduleLog[]
     */
    public function getLogs(): array
    {
        if (!$this->id) {
            return [];
        }

        return Plugin::$plugin->getLogs()
            ->findLogs(['scheduleId' => $this->id]);
    }

    /**
     * @return int
     */
    public function getTotalLogs(): int
    {
        if (!$this->id) {
            return 0;
        }

        return Plugin::$plugin->getLogs()->getTotalLogs(['scheduleId' => $this->id]);
    }

    /**
     * @return DateTime|null
     */
    public function getLastFinishedDate()
    {
        if (!$this->lastFinishedTime) {
            return null;
        }

        return new DateTime(date('Y-m-d H:i:s', (int)($this->lastFinishedTime / 1000)));
    }

    /**
     * @return int
     */
    public function getLastDuration(): int
    {
        if (!$this->lastStartedTime || !$this->lastFinishedTime) {
            return 0;
        }

        return $this->lastFinishedTime - $this->lastStartedTime;
    }

    /**
     * @inheritdoc
     */
    public function build(Builder $builder)
    {
        if (static::isRunnable()) {
            /** @noinspection PhpParamsInspection */
            foreach ($this->getTimers() as $timer) {
                $builder->call([$this, 'run'])
                    ->cron($timer->getCronExpression());
            }

            return;
        }
    }

    /**
     * @return bool
     */
    public function run(): bool
    {
        if (!$this->beforeRun()) {
            return false;
        }

        $db = Craft::$app->getDb();

        $sortOrderQuery = (new Query())
            ->select(['[[sortOrder]] + 1 AS sortOrder'])
            ->from(Table::SCHEDULELOGS)
            ->where([
                'scheduleId' => $this->id,
            ])
            ->orderBy(['sortOrder' => SORT_DESC])
            ->limit(1);

        $db->createCommand()
            ->insert(Table::SCHEDULELOGS, [
                'scheduleId' => $this->id,
                'status' => self::STATUS_PREPARING,
                'startTime' => round(microtime(true) * 1000),
                'output' => '',
                'sortOrder' => $db->getSchema() instanceof MysqlSchema ?  // MySQL not support same table sub query on insert.
                    $sortOrderQuery->scalar() :
                    $sortOrderQuery,
            ])
            ->execute();

        $id = $db->getLastInsertID();

        $successful = $this->execute($id);

        $db->createCommand()
            ->update(Table::SCHEDULELOGS, [
                'status' => $successful ? self::STATUS_SUCCESSFUL : self::STATUS_FAILED,
                'endTime' => round(microtime(true) * 1000),
            ], [
                'id' => $id,
            ])
            ->execute();

        $this->afterRun($successful);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function beforeRun(): bool
    {
        if ($this->hasEventHandlers(self::EVENT_BEFORE_RUN)) {
            $this->trigger(self::EVENT_BEFORE_RUN, new ScheduleEvent([
                'schedule' => $this,
            ]));
        }

        Craft::$app->getDb()->createCommand()
            ->update('{{%schedules}}', [
                'lastStartedTime' => round(microtime(true) * 1000),
            ], [
                'id' => $this->id,
            ])
            ->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function afterRun(bool $successful)
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%schedules}}', [
                'lastFinishedTime' => round(microtime(true) * 1000),
                'lastStatus' => $successful,
            ], [
                'id' => $this->id,
            ])
            ->execute();

        if ($this->hasEventHandlers(self::EVENT_AFTER_RUN)) {
            $this->trigger(self::EVENT_AFTER_RUN, new ScheduleEvent([
                'schedule' => $this,
            ]));
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * Execute somethings.
     *
     * @param int $logId
     * @return bool
     */
    protected function execute(int $logId): bool
    {
        Craft::info(sprintf("Schedule #%d running with log ID: #%d", $this->id, $logId), __METHOD__);

        return true;
    }
}