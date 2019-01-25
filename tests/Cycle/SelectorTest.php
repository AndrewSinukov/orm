<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\Cycle\Tests;

use Spiral\Cycle\Mapper\Mapper;
use Spiral\Cycle\Relation;
use Spiral\Cycle\Schema;
use Spiral\Cycle\Select;
use Spiral\Cycle\Select\JoinableLoader;
use Spiral\Cycle\Tests\Fixtures\Comment;
use Spiral\Cycle\Tests\Fixtures\User;
use Spiral\Cycle\Tests\Traits\TableTrait;

abstract class SelectorTest extends BaseTest
{
    use TableTrait;

    public function setUp()
    {
        parent::setUp();

        $this->makeTable('user', [
            'id'      => 'primary',
            'email'   => 'string',
            'balance' => 'float'
        ]);

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'balance'],
            [
                ['hello@world.com', 100],
                ['another@world.com', 200],
            ]
        );

        $this->makeTable('comment', [
            'id'      => 'primary',
            'user_id' => 'integer',
            'level'   => 'integer',
            'message' => 'string'
        ]);

        $this->getDatabase()->table('comment')->insertMultiple(
            ['user_id', 'level', 'message'],
            [
                [1, 1, 'msg 1'],
                [1, 2, 'msg 2'],
                [1, 3, 'msg 3'],
                [1, 4, 'msg 4'],
                [2, 1, 'msg 2.1'],
                [2, 2, 'msg 2.2'],
                [2, 3, 'msg 2.3'],
            ]
        );

        $this->orm = $this->withSchema(new Schema([
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'comments' => [
                        Relation::TYPE   => Relation::HAS_MANY,
                        Relation::TARGET => Comment::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'user_id',
                        ],
                    ]
                ]
            ],
            Comment::class => [
                Schema::ROLE        => 'comment',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'level', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ]
        ]));
    }

    public function testStableStatement()
    {
        $s = new Select($this->orm, User::class);
        $s->load('comments', ['method' => JoinableLoader::INLOAD]);

        $s2 = new Select($this->orm, User::class);
        $s2->load('comments', ['method' => JoinableLoader::INLOAD]);

        $this->assertSQL($s->sqlStatement(), $s2->sqlStatement());
    }

    public function testSelectCustomSQL()
    {
        $s = new Select($this->orm, User::class);
        $s->with('comments', ['method' => JoinableLoader::INLOAD]);

        $query = $s->buildQuery()->columns(
            'user.id',
            'SUM(user.balance) as balance',
            'COUNT(user_comments.id) as count_comments'
        )->groupBy('user.id')->orderBy('user.id');

        $result = $query->fetchAll();

        $this->assertEquals([
            [
                'id'             => 1,
                'balance'        => 400.0,
                'count_comments' => 4
            ],
            [
                'id'             => 2,
                'balance'        => 600.0,
                'count_comments' => 3
            ]
        ], $result);
    }
}