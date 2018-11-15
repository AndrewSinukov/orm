<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\ORM;

use Spiral\ORM\Command\CommandInterface;
use Spiral\ORM\Command\ContextCommandInterface;
use Spiral\ORM\Command\Database\DeleteCommand;
use Spiral\ORM\Command\Database\InsertContextCommand;
use Spiral\ORM\Command\Database\UpdateContextCommand;

abstract class AbstractMapper implements MapperInterface
{
    protected $orm;

    protected $class;

    protected $table;

    protected $primaryKey;

    public function __construct(ORMInterface $orm, $class)
    {
        $this->orm = $orm;
        $this->class = $class;

        $this->table = $this->orm->getSchema()->define($class, Schema::TABLE);
        $this->primaryKey = $this->orm->getSchema()->define($class, Schema::PRIMARY_KEY);
    }

    public function init()
    {
        $class = $this->class;

        return new $class;
    }

    public function queueStore($entity): ContextCommandInterface
    {
        $state = $this->orm->getHeap()->get($entity);

        if ($state == null) {
            $cmd = $this->buildInsert($entity);
        } else {
            $cmd = $this->buildUpdate($entity, $state);
        }

        return $this->orm->getRelationMap(get_class($entity))->queueRelations($entity, $cmd);
    }

    public function queueDelete($entity): CommandInterface
    {
        $state = $this->orm->getHeap()->get($entity);
        if ($state == null) {
            // todo: an exception
        }

        return $this->buildDelete($entity, $state);
    }

    // todo: in the heap?
    //  abstract protected function setField($entity, $field, $value);

    protected function getColumns($entity): array
    {
        $columns = array_flip($this->orm->getSchema()->define(get_class($entity), Schema::COLUMNS));
        return array_intersect_key($this->extract($entity), $columns);
    }

    protected function buildInsert($entity): ContextCommandInterface
    {
        $columns = $this->getColumns($entity);

        $state = new State(
            $columns[$this->primaryKey] ?? null,
            State::SCHEDULED_INSERT,
            $columns
        );

        unset($columns[$this->primaryKey]);

        $insert = new InsertContextCommand($this->orm->getDatabase($entity), $this->table, $columns);

        // we are managed at this moment
        $this->orm->getHeap()->attach($entity, $state);

        $insert->onExecute(function (InsertContextCommand $command) use ($entity, $state) {
            $state->setPrimaryKey($this->primaryKey, $command->getInsertID());
        });

        $insert->onComplete(function (InsertContextCommand $command) use ($entity, $state) {
            $state->setState(State::LOADED);

            $this->hydrate($entity, [
                $this->primaryKey => $command->getInsertID()
            ]);

            // todo: update entity path
            $state->setPrimaryKey($this->primaryKey, $command->getInsertID());

            $this->hydrate($entity, $command->getContext());
            $state->setData($command->getContext());
        });

        $insert->onRollBack(function (InsertContextCommand $command) use ($entity, $state) {
            $this->orm->getHeap()->detach($entity);
        });

        return $insert;
    }

    protected function buildUpdate($entity, State $state): ContextCommandInterface
    {
        $eData = $this->getColumns($entity);
        $oData = $state->getData();

        // todo: calc diff
        $uData = $this->extract($entity) + $state->getData();
        $pK = $uData[$this->primaryKey] ?? null;

        unset($uData[$this->primaryKey]);

        // todo: pack changes (???) depends on mode (USE ALL FOR NOW)

        $update = new UpdateContextCommand(
            $this->orm->getDatabase($entity),
            $this->table,
            array_diff($eData, $oData), // todo: make it optional
            [$this->primaryKey => $pK]
        );

        $current = $state->getState();
        $state->setState(State::SCHEDULED_UPDATE);
        $state->setData($uData);

        $state->onUpdate(function (State $state) use ($update) {
            $update->setWhere([$this->primaryKey => $state->getPrimaryKey()]);
        });

        $update->onComplete(function (UpdateContextCommand $command) use ($entity, $state) {
            $state->setState(State::LOADED);

            $this->hydrate($entity, $command->getContext());
            $state->setData($command->getContext());
        });

        $update->onRollBack(function () use ($state, $current) {
            $state->setState($current);
            //todo: rollback
        });

        return $update;
    }

    protected function buildDelete($entity, State $state): CommandInterface
    {
        // todo: better primary key fetch

        $delete = new DeleteCommand(
            $this->orm->getDatabase($entity),
            $this->table,
            // todo: uuid?
            [$this->primaryKey => $state->getPrimaryKey() ?? $this->extract($entity)[$this->primaryKey] ?? null]
        );

        $current = $state->getState();

        $state->setState(State::SCHEDULED_DELETE);

        $state->onUpdate(function (State $state) use ($delete) {
            $delete->setWhere([$this->primaryKey => $state->getPrimaryKey()]);
        });

        $delete->onComplete(function (DeleteCommand $command) use ($entity) {
            $this->orm->getHeap()->detach($entity);
        });

        $delete->onRollBack(function () use ($state, $current) {
            $state->setState($current);
        });

        return $delete;
    }
}