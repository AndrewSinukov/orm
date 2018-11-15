<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\ORM;

use Spiral\Database\Driver\DriverInterface;
use Spiral\ORM\Command\CommandInterface;
use Spiral\ORM\Command\Database\DatabaseCommand;
use Spiral\ORM\Command\DelayedCommandInterface;
use Spiral\ORM\Exception\TransactionException;

class Transaction implements TransactionInterface
{
    /** @var ORMInterface */
    private $orm;

    /*** @var CommandInterface[] */
    private $commands = [];

    /** @param ORMInterface $orm */
    public function __construct(ORMInterface $orm)
    {
        $this->orm = $orm;
    }

    /**
     * {@inheritdoc}
     */
    public function store($entity)
    {
        $this->addCommand($this->orm->getMapper($entity)->queueStore($entity));
    }

    /**
     * {@inheritdoc}
     */
    public function delete($entity)
    {
        $this->addCommand($this->orm->getMapper($entity)->queueDelete($entity));
    }

    /**
     * {@inheritdoc}
     */
    public function addCommand(CommandInterface $command)
    {
        $this->commands[] = $command;
    }

    /**
     * Return flattened list of commands.
     *
     * @return \Generator
     */
    public function getCommands()
    {
        foreach ($this->commands as $command) {
            if ($command instanceof \Traversable) {
                yield from $command;
            }

            yield $command;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $commands = [];
        foreach ($this->getCommands() as $command) {
            $commands[] = $command;
        }

        //$commands = iterator_to_array($this->getCommands());
        $executed = $drivers = [];

        try {

            while (!empty($commands)) {
                $delayed = [];
                $wait = count($commands);
                foreach ($this->execute($commands, $drivers) as $done => $delay) {
                    if ($done != null) {
                        $executed[] = $done;
                    }

                    if ($delay != null) {
                        $delayed[] = $delay;
                    }
                }

                if (count($delayed) == $wait) {
                    throw new TransactionException("Unable to complete: " . join(", ", $delayed));
                }

                $commands = $delayed;
            }

        } catch (\Throwable $e) {
            foreach (array_reverse($drivers) as $driver) {
                /** @var DriverInterface $driver */
                $driver->rollbackTransaction();
            }

            foreach (array_reverse($executed) as $command) {
                /** @var CommandInterface $command */
                $command->rollBack();
            }

            throw $e;
        } finally {
            $this->commands = [];
        }

        foreach (array_reverse($drivers) as $driver) {
            /** @var DriverInterface $driver */
            $driver->commitTransaction();
        }

        foreach ($executed as $command) {
            //This is the point when entity will get related PK and FKs filled
            $command->complete();
        }
    }

    /**
     * @param CommandInterface $command
     * @param array            $drivers
     */
    private function beginTransaction(CommandInterface $command, array &$drivers)
    {
        if ($command instanceof DatabaseCommand) {
            $driver = $command->getDatabase()->getDriver();

            if (!empty($driver) && !in_array($driver, $drivers)) {
                $driver->beginTransaction();
                $drivers[] = $driver;
            }
        }
    }

    /**
     * Execute and split array of commands into two subsets: executed and pending.
     *
     * @param array $commands
     * @param array $drivers
     * @return \Generator
     */
    private function execute(array $commands, array &$drivers): \Generator
    {
        foreach ($commands as $command) {
            if ($command instanceof DelayedCommandInterface && $command->isDelayed()) {
                yield null => $command;
            }

            $this->beginTransaction($command, $drivers);
            $command->execute();

            yield $command => null;
        }
    }
}