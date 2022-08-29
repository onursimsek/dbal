<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Platforms;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Events;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function implode;
use function sprintf;

/**
 * @template T of AbstractPlatform
 */
abstract class AbstractPlatformTestCase extends TestCase
{
    /** @var T */
    protected AbstractPlatform $platform;

    /**
     * @return T
     */
    abstract public function createPlatform(): AbstractPlatform;

    protected function setUp(): void
    {
        $this->platform = $this->createPlatform();
    }

    protected function createComparator(): Comparator
    {
        return new Comparator($this->platform);
    }

    public function testQuoteIdentifier(): void
    {
        self::assertEquals('"test"."test"', $this->platform->quoteIdentifier('test.test'));
    }

    /**
     * @dataProvider getReturnsForeignKeyReferentialActionSQL
     */
    public function testReturnsForeignKeyReferentialActionSQL(string $action, string $expectedSQL): void
    {
        self::assertSame($expectedSQL, $this->platform->getForeignKeyReferentialActionSQL($action));
    }

    /**
     * @return mixed[][]
     */
    public static function getReturnsForeignKeyReferentialActionSQL(): iterable
    {
        return [
            ['CASCADE', 'CASCADE'],
            ['SET NULL', 'SET NULL'],
            ['NO ACTION', 'NO ACTION'],
            ['RESTRICT', 'RESTRICT'],
            ['SET DEFAULT', 'SET DEFAULT'],
            ['CaScAdE', 'CASCADE'],
        ];
    }

    public function testGetInvalidForeignKeyReferentialActionSQL(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->platform->getForeignKeyReferentialActionSQL('unknown');
    }

    public function testGetUnknownDoctrineMappingType(): void
    {
        $this->expectException(Exception::class);
        $this->platform->getDoctrineTypeMapping('foobar');
    }

    public function testRegisterDoctrineMappingType(): void
    {
        $this->platform->registerDoctrineTypeMapping('foo', 'integer');
        self::assertEquals('integer', $this->platform->getDoctrineTypeMapping('foo'));
    }

    public function testRegisterUnknownDoctrineMappingType(): void
    {
        $this->expectException(Exception::class);
        $this->platform->registerDoctrineTypeMapping('foo', 'bar');
    }

    public function testCreateWithNoColumns(): void
    {
        $table = new Table('test');

        $this->expectException(Exception::class);
        $this->platform->getCreateTableSQL($table);
    }

    public function testGeneratesTableCreationSql(): void
    {
        $table = new Table('test');
        $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
        $table->addColumn('test', 'string', ['notnull' => false, 'length' => 255]);
        $table->setPrimaryKey(['id']);

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getGenerateTableSql(), $sql[0]);
    }

    abstract public function getGenerateTableSql(): string;

    public function testGenerateTableWithMultiColumnUniqueIndex(): void
    {
        $table = new Table('test');
        $table->addColumn('foo', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('bar', 'string', ['notnull' => false, 'length' => 255]);
        $table->addUniqueIndex(['foo', 'bar']);

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getGenerateTableWithMultiColumnUniqueIndexSql(), $sql);
    }

    /**
     * @return string[]
     */
    abstract public function getGenerateTableWithMultiColumnUniqueIndexSql(): array;

    public function testGeneratesIndexCreationSql(): void
    {
        $indexDef = new Index('my_idx', ['user_name', 'last_login']);

        self::assertEquals(
            $this->getGenerateIndexSql(),
            $this->platform->getCreateIndexSQL($indexDef, 'mytable')
        );
    }

    abstract public function getGenerateIndexSql(): string;

    public function testGeneratesUniqueIndexCreationSql(): void
    {
        $indexDef = new Index('index_name', ['test', 'test2'], true);

        $sql = $this->platform->getCreateIndexSQL($indexDef, 'test');
        self::assertEquals($this->getGenerateUniqueIndexSql(), $sql);
    }

    abstract public function getGenerateUniqueIndexSql(): string;

    public function testGeneratesPartialIndexesSqlOnlyWhenSupportingPartialIndexes(): void
    {
        $where            = 'test IS NULL AND test2 IS NOT NULL';
        $indexDef         = new Index('name', ['test', 'test2'], false, false, [], ['where' => $where]);
        $uniqueConstraint = new UniqueConstraint('name', ['test', 'test2'], [], []);

        $expected = ' WHERE ' . $where;

        $indexes = [];

        if ($this->supportsInlineIndexDeclaration()) {
            $indexes[] = $this->platform->getIndexDeclarationSQL($indexDef);
        }

        $uniqueConstraintSQL = $this->platform->getUniqueConstraintDeclarationSQL($uniqueConstraint);
        self::assertStringEndsNotWith($expected, $uniqueConstraintSQL, 'WHERE clause should NOT be present');

        $indexes[] = $this->platform->getCreateIndexSQL($indexDef, 'table');

        foreach ($indexes as $index) {
            if ($this->platform->supportsPartialIndexes()) {
                self::assertStringEndsWith($expected, $index, 'WHERE clause should be present');
            } else {
                self::assertStringEndsNotWith($expected, $index, 'WHERE clause should NOT be present');
            }
        }
    }

    public function testGeneratesForeignKeyCreationSql(): void
    {
        $fk = new ForeignKeyConstraint(['fk_name_id'], 'other_table', ['id']);

        $sql = $this->platform->getCreateForeignKeySQL($fk, 'test');
        self::assertEquals($this->getGenerateForeignKeySql(), $sql);
    }

    abstract protected function getGenerateForeignKeySql(): string;

    protected function getBitAndComparisonExpressionSql(string $value1, string $value2): string
    {
        return '(' . $value1 . ' & ' . $value2 . ')';
    }

    public function testGeneratesBitAndComparisonExpressionSql(): void
    {
        $sql = $this->platform->getBitAndComparisonExpression('2', '4');
        self::assertEquals($this->getBitAndComparisonExpressionSql('2', '4'), $sql);
    }

    protected function getBitOrComparisonExpressionSql(string $value1, string $value2): string
    {
        return '(' . $value1 . ' | ' . $value2 . ')';
    }

    public function testGeneratesBitOrComparisonExpressionSql(): void
    {
        $sql = $this->platform->getBitOrComparisonExpression('2', '4');
        self::assertEquals($this->getBitOrComparisonExpressionSql('2', '4'), $sql);
    }

    public function getGenerateConstraintUniqueIndexSql(): string
    {
        return 'ALTER TABLE test ADD CONSTRAINT constraint_name UNIQUE (test)';
    }

    public function getGenerateConstraintPrimaryIndexSql(): string
    {
        return 'ALTER TABLE test ADD CONSTRAINT constraint_name PRIMARY KEY (test)';
    }

    public function getGenerateConstraintForeignKeySql(ForeignKeyConstraint $fk): string
    {
        $quotedForeignTable = $fk->getQuotedForeignTableName($this->platform);

        return sprintf(
            'ALTER TABLE test ADD CONSTRAINT constraint_fk FOREIGN KEY (fk_name) REFERENCES %s (id)',
            $quotedForeignTable
        );
    }

    /**
     * @return string[]
     */
    abstract public function getGenerateAlterTableSql(): array;

    public function testGeneratesTableAlterationSql(): void
    {
        $expectedSql = $this->getGenerateAlterTableSql();

        $table = new Table('mytable');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('foo', 'integer');
        $table->addColumn('bar', 'string', ['length' => 32]);
        $table->addColumn('bloo', 'boolean');
        $table->setPrimaryKey(['id']);

        $tableDiff                        = new TableDiff('mytable');
        $tableDiff->fromTable             = $table;
        $tableDiff->newName               = 'userlist';
        $tableDiff->addedColumns['quota'] = new Column('quota', Type::getType('integer'), ['notnull' => false]);
        $tableDiff->removedColumns['foo'] = new Column('foo', Type::getType('integer'));
        $tableDiff->changedColumns['bar'] = new ColumnDiff(
            'bar',
            new Column(
                'baz',
                Type::getType('string'),
                [
                    'length' => 255,
                    'default' => 'def',
                ]
            ),
            ['type', 'notnull', 'default'],
            $table->getColumn('bar')
        );

        $tableDiff->changedColumns['bloo'] = new ColumnDiff(
            'bloo',
            new Column(
                'bloo',
                Type::getType('boolean'),
                ['default' => false]
            ),
            ['type', 'notnull', 'default'],
            $table->getColumn('bloo')
        );

        $sql = $this->platform->getAlterTableSQL($tableDiff);

        self::assertEquals($expectedSql, $sql);
    }

    public function testGetCustomColumnDeclarationSql(): void
    {
        self::assertEquals(
            'foo MEDIUMINT(6) UNSIGNED',
            $this->platform->getColumnDeclarationSQL('foo', ['columnDefinition' => 'MEDIUMINT(6) UNSIGNED'])
        );
    }

    public function testGetCreateTableSqlDispatchEvent(): void
    {
        $listenerMock = $this->createMock(GetCreateTableSqlDispatchEventListener::class);
        $listenerMock
            ->expects(self::once())
            ->method('onSchemaCreateTable');
        $listenerMock
            ->expects(self::exactly(2))
            ->method('onSchemaCreateTableColumn');

        $eventManager = new EventManager();
        $eventManager->addEventListener([
            Events::onSchemaCreateTable,
            Events::onSchemaCreateTableColumn,
        ], $listenerMock);

        $this->platform->setEventManager($eventManager);

        $table = new Table('test');
        $table->addColumn('foo', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('bar', 'string', ['notnull' => false, 'length' => 255]);

        $this->platform->getCreateTableSQL($table);
    }

    public function testGetDropTableSqlDispatchEvent(): void
    {
        $listenerMock = $this->createMock(GetDropTableSqlDispatchEventListener::class);
        $listenerMock
            ->expects(self::once())
            ->method('onSchemaDropTable');

        $eventManager = new EventManager();
        $eventManager->addEventListener([Events::onSchemaDropTable], $listenerMock);

        $this->platform->setEventManager($eventManager);

        $this->platform->getDropTableSQL('TABLE');
    }

    public function testGetAlterTableSqlDispatchEvent(): void
    {
        $listenerMock = $this->createMock(GetAlterTableSqlDispatchEventListener::class);
        $listenerMock
            ->expects(self::once())
            ->method('onSchemaAlterTable');
        $listenerMock
            ->expects(self::once())
            ->method('onSchemaAlterTableAddColumn');
        $listenerMock
            ->expects(self::once())
            ->method('onSchemaAlterTableRemoveColumn');
        $listenerMock
            ->expects(self::once())
            ->method('onSchemaAlterTableChangeColumn');
        $listenerMock
            ->expects(self::once())
            ->method('onSchemaAlterTableRenameColumn');

        $eventManager = new EventManager();
        $events       = [
            Events::onSchemaAlterTable,
            Events::onSchemaAlterTableAddColumn,
            Events::onSchemaAlterTableRemoveColumn,
            Events::onSchemaAlterTableChangeColumn,
            Events::onSchemaAlterTableRenameColumn,
        ];
        $eventManager->addEventListener($events, $listenerMock);

        $this->platform->setEventManager($eventManager);

        $table = new Table('mytable');
        $table->addColumn('removed', 'integer');
        $table->addColumn('changed', 'integer');
        $table->addColumn('renamed', 'integer');

        $tableDiff                            = new TableDiff('mytable');
        $tableDiff->fromTable                 = $table;
        $tableDiff->addedColumns['added']     = new Column('added', Type::getType('integer'), []);
        $tableDiff->removedColumns['removed'] = new Column('removed', Type::getType('integer'), []);
        $tableDiff->changedColumns['changed'] = new ColumnDiff(
            'changed',
            new Column('changed2', Type::getType('string'), ['length' => 255]),
            [],
            $table->getColumn('changed')
        );
        $tableDiff->renamedColumns['renamed'] = new Column('renamed2', Type::getType('integer'));

        $this->platform->getAlterTableSQL($tableDiff);
    }

    public function testGetDefaultValueDeclarationSQL(): void
    {
        // non-timestamp value will get single quotes
        self::assertEquals(" DEFAULT 'non_timestamp'", $this->platform->getDefaultValueDeclarationSQL([
            'type' => Type::getType('string'),
            'default' => 'non_timestamp',
        ]));
    }

    public function testGetDefaultValueDeclarationSQLDateTime(): void
    {
        // timestamps on datetime types should not be quoted
        foreach (['datetime', 'datetimetz', 'datetime_immutable', 'datetimetz_immutable'] as $type) {
            self::assertSame(
                ' DEFAULT ' . $this->platform->getCurrentTimestampSQL(),
                $this->platform->getDefaultValueDeclarationSQL([
                    'type'    => Type::getType($type),
                    'default' => $this->platform->getCurrentTimestampSQL(),
                ])
            );
        }
    }

    public function testGetDefaultValueDeclarationSQLForIntegerTypes(): void
    {
        foreach (['bigint', 'integer', 'smallint'] as $type) {
            self::assertEquals(
                ' DEFAULT 1',
                $this->platform->getDefaultValueDeclarationSQL([
                    'type'    => Type::getType($type),
                    'default' => 1,
                ])
            );
        }
    }

    public function testGetDefaultValueDeclarationSQLForDateType(): void
    {
        $currentDateSql = $this->platform->getCurrentDateSQL();
        foreach (['date', 'date_immutable'] as $type) {
            self::assertSame(
                ' DEFAULT ' . $currentDateSql,
                $this->platform->getDefaultValueDeclarationSQL([
                    'type'    => Type::getType($type),
                    'default' => $currentDateSql,
                ])
            );
        }
    }

    public function testKeywordList(): void
    {
        $keywordList = $this->platform->getReservedKeywordsList();

        self::assertTrue($keywordList->isKeyword('table'));
    }

    public function testQuotedColumnInPrimaryKeyPropagation(): void
    {
        $table = new Table('`quoted`');
        $table->addColumn('create', 'string', ['length' => 255]);
        $table->setPrimaryKey(['create']);

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getQuotedColumnInPrimaryKeySQL(), $sql);
    }

    /**
     * @return string[]
     */
    abstract protected function getQuotedColumnInPrimaryKeySQL(): array;

    /**
     * @return string[]
     */
    abstract protected function getQuotedColumnInIndexSQL(): array;

    /**
     * @return string[]
     */
    abstract protected function getQuotedNameInIndexSQL(): array;

    /**
     * @return string[]
     */
    abstract protected function getQuotedColumnInForeignKeySQL(): array;

    public function testQuotedColumnInIndexPropagation(): void
    {
        $table = new Table('`quoted`');
        $table->addColumn('create', 'string', ['length' => 255]);
        $table->addIndex(['create']);

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getQuotedColumnInIndexSQL(), $sql);
    }

    public function testQuotedNameInIndexSQL(): void
    {
        $table = new Table('test');
        $table->addColumn('column1', 'string', ['length' => 255]);
        $table->addIndex(['column1'], '`key`');

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getQuotedNameInIndexSQL(), $sql);
    }

    public function testQuotedColumnInForeignKeyPropagation(): void
    {
        $table = new Table('`quoted`');
        $table->addColumn('create', 'string', ['length' => 255]);
        $table->addColumn('foo', 'string', ['length' => 255]);
        $table->addColumn('`bar`', 'string', ['length' => 255]);

        // Foreign table with reserved keyword as name (needs quotation).
        $foreignTable = new Table('foreign');

        // Foreign column with reserved keyword as name (needs quotation).
        $foreignTable->addColumn('create', 'string');

        // Foreign column with non-reserved keyword as name (does not need quotation).
        $foreignTable->addColumn('bar', 'string');

        // Foreign table with special character in name (needs quotation on some platforms, e.g. Sqlite).
        $foreignTable->addColumn('`foo-bar`', 'string');

        $table->addForeignKeyConstraint(
            $foreignTable->getQuotedName($this->platform),
            ['create', 'foo', '`bar`'],
            ['create', 'bar', '`foo-bar`'],
            [],
            'FK_WITH_RESERVED_KEYWORD'
        );

        // Foreign table with non-reserved keyword as name (does not need quotation).
        $foreignTable = new Table('foo');

        // Foreign column with reserved keyword as name (needs quotation).
        $foreignTable->addColumn('create', 'string');

        // Foreign column with non-reserved keyword as name (does not need quotation).
        $foreignTable->addColumn('bar', 'string');

        // Foreign table with special character in name (needs quotation on some platforms, e.g. Sqlite).
        $foreignTable->addColumn('`foo-bar`', 'string');

        $table->addForeignKeyConstraint(
            $foreignTable->getQuotedName($this->platform),
            ['create', 'foo', '`bar`'],
            ['create', 'bar', '`foo-bar`'],
            [],
            'FK_WITH_NON_RESERVED_KEYWORD'
        );

        // Foreign table with special character in name (needs quotation on some platforms, e.g. Sqlite).
        $foreignTable = new Table('`foo-bar`');

        // Foreign column with reserved keyword as name (needs quotation).
        $foreignTable->addColumn('create', 'string');

        // Foreign column with non-reserved keyword as name (does not need quotation).
        $foreignTable->addColumn('bar', 'string');

        // Foreign table with special character in name (needs quotation on some platforms, e.g. Sqlite).
        $foreignTable->addColumn('`foo-bar`', 'string');

        $table->addForeignKeyConstraint(
            $foreignTable->getQuotedName($this->platform),
            ['create', 'foo', '`bar`'],
            ['create', 'bar', '`foo-bar`'],
            [],
            'FK_WITH_INTENDED_QUOTATION'
        );

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getQuotedColumnInForeignKeySQL(), $sql);
    }

    public function testQuotesReservedKeywordInUniqueConstraintDeclarationSQL(): void
    {
        $constraint = new UniqueConstraint('select', ['foo'], [], []);

        self::assertSame(
            $this->getQuotesReservedKeywordInUniqueConstraintDeclarationSQL(),
            $this->platform->getUniqueConstraintDeclarationSQL($constraint)
        );
    }

    abstract protected function getQuotesReservedKeywordInUniqueConstraintDeclarationSQL(): string;

    public function testQuotesReservedKeywordInTruncateTableSQL(): void
    {
        self::assertSame(
            $this->getQuotesReservedKeywordInTruncateTableSQL(),
            $this->platform->getTruncateTableSQL('select')
        );
    }

    abstract protected function getQuotesReservedKeywordInTruncateTableSQL(): string;

    public function testQuotesReservedKeywordInIndexDeclarationSQL(): void
    {
        $index = new Index('select', ['foo']);

        if (! $this->supportsInlineIndexDeclaration()) {
            $this->expectException(Exception::class);
        }

        self::assertSame(
            $this->getQuotesReservedKeywordInIndexDeclarationSQL(),
            $this->platform->getIndexDeclarationSQL($index)
        );
    }

    abstract protected function getQuotesReservedKeywordInIndexDeclarationSQL(): string;

    protected function supportsInlineIndexDeclaration(): bool
    {
        return true;
    }

    public function testSupportsCommentOnStatement(): void
    {
        self::assertSame($this->supportsCommentOnStatement(), $this->platform->supportsCommentOnStatement());
    }

    protected function supportsCommentOnStatement(): bool
    {
        return false;
    }

    public function testGetCreateSchemaSQL(): void
    {
        $this->expectException(Exception::class);

        $this->platform->getCreateSchemaSQL('schema');
    }

    public function testAlterTableChangeQuotedColumn(): void
    {
        $table = new Table('mytable');
        $table->addColumn('select', 'integer');

        $tableDiff                           = new TableDiff('mytable');
        $tableDiff->fromTable                = $table;
        $tableDiff->changedColumns['select'] = new ColumnDiff(
            'select',
            new Column(
                'select',
                Type::getType('string'),
                ['length' => 255]
            ),
            ['type'],
            $table->getColumn('select')
        );

        self::assertStringContainsString(
            $this->platform->quoteIdentifier('select'),
            implode(';', $this->platform->getAlterTableSQL($tableDiff))
        );
    }

    public function testGetFixedLengthStringTypeDeclarationSQLNoLength(): void
    {
        self::assertSame(
            $this->getExpectedFixedLengthStringTypeDeclarationSQLNoLength(),
            $this->platform->getStringTypeDeclarationSQL(['fixed' => true])
        );
    }

    protected function getExpectedFixedLengthStringTypeDeclarationSQLNoLength(): string
    {
        return 'CHAR';
    }

    public function testGetFixedLengthStringTypeDeclarationSQLWithLength(): void
    {
        self::assertSame(
            $this->getExpectedFixedLengthStringTypeDeclarationSQLWithLength(),
            $this->platform->getStringTypeDeclarationSQL([
                'fixed' => true,
                'length' => 16,
            ])
        );
    }

    protected function getExpectedFixedLengthStringTypeDeclarationSQLWithLength(): string
    {
        return 'CHAR(16)';
    }

    public function testGetVariableLengthStringTypeDeclarationSQLNoLength(): void
    {
        self::assertSame(
            $this->getExpectedVariableLengthStringTypeDeclarationSQLNoLength(),
            $this->platform->getStringTypeDeclarationSQL([])
        );
    }

    protected function getExpectedVariableLengthStringTypeDeclarationSQLNoLength(): string
    {
        return 'VARCHAR';
    }

    public function testGetVariableLengthStringTypeDeclarationSQLWithLength(): void
    {
        self::assertSame(
            $this->getExpectedVariableLengthStringTypeDeclarationSQLWithLength(),
            $this->platform->getStringTypeDeclarationSQL(['length' => 16])
        );
    }

    protected function getExpectedVariableLengthStringTypeDeclarationSQLWithLength(): string
    {
        return 'VARCHAR(16)';
    }

    public function testGetFixedLengthBinaryTypeDeclarationSQLNoLength(): void
    {
        self::assertSame(
            $this->getExpectedFixedLengthBinaryTypeDeclarationSQLNoLength(),
            $this->platform->getBinaryTypeDeclarationSQL(['fixed' => true])
        );
    }

    public function getExpectedFixedLengthBinaryTypeDeclarationSQLNoLength(): string
    {
        return 'BINARY';
    }

    public function testGetFixedLengthBinaryTypeDeclarationSQLWithLength(): void
    {
        self::assertSame(
            $this->getExpectedFixedLengthBinaryTypeDeclarationSQLWithLength(),
            $this->platform->getBinaryTypeDeclarationSQL([
                'fixed' => true,
                'length' => 16,
            ])
        );
    }

    public function getExpectedFixedLengthBinaryTypeDeclarationSQLWithLength(): string
    {
        return 'BINARY(16)';
    }

    public function testGetVariableLengthBinaryTypeDeclarationSQLNoLength(): void
    {
        self::assertSame(
            $this->getExpectedVariableLengthBinaryTypeDeclarationSQLNoLength(),
            $this->platform->getBinaryTypeDeclarationSQL([])
        );
    }

    public function getExpectedVariableLengthBinaryTypeDeclarationSQLNoLength(): string
    {
        return 'VARBINARY';
    }

    public function testGetVariableLengthBinaryTypeDeclarationSQLWithLength(): void
    {
        self::assertSame(
            $this->getExpectedVariableLengthBinaryTypeDeclarationSQLWithLength(),
            $this->platform->getBinaryTypeDeclarationSQL(['length' => 16])
        );
    }

    public function getExpectedVariableLengthBinaryTypeDeclarationSQLWithLength(): string
    {
        return 'VARBINARY(16)';
    }

    public function testReturnsJsonTypeDeclarationSQL(): void
    {
        $column = [
            'length'  => 666,
            'notnull' => true,
            'type'    => Type::getType('json'),
        ];

        self::assertSame(
            $this->platform->getClobTypeDeclarationSQL($column),
            $this->platform->getJsonTypeDeclarationSQL($column)
        );
    }

    public function testAlterTableRenameIndex(): void
    {
        $tableDiff            = new TableDiff('mytable');
        $tableDiff->fromTable = new Table('mytable');
        $tableDiff->fromTable->addColumn('id', 'integer');
        $tableDiff->fromTable->setPrimaryKey(['id']);
        $tableDiff->renamedIndexes = [
            'idx_foo' => new Index('idx_bar', ['id']),
        ];

        self::assertSame(
            $this->getAlterTableRenameIndexSQL(),
            $this->platform->getAlterTableSQL($tableDiff)
        );
    }

    /**
     * @return string[]
     */
    protected function getAlterTableRenameIndexSQL(): array
    {
        return [
            'DROP INDEX idx_foo',
            'CREATE INDEX idx_bar ON mytable (id)',
        ];
    }

    public function testQuotesAlterTableRenameIndex(): void
    {
        $tableDiff            = new TableDiff('table');
        $tableDiff->fromTable = new Table('table');
        $tableDiff->fromTable->addColumn('id', 'integer');
        $tableDiff->fromTable->setPrimaryKey(['id']);
        $tableDiff->renamedIndexes = [
            'create' => new Index('select', ['id']),
            '`foo`'  => new Index('`bar`', ['id']),
        ];

        self::assertSame(
            $this->getQuotedAlterTableRenameIndexSQL(),
            $this->platform->getAlterTableSQL($tableDiff)
        );
    }

    /**
     * @return string[]
     */
    protected function getQuotedAlterTableRenameIndexSQL(): array
    {
        return [
            'DROP INDEX "create"',
            'CREATE INDEX "select" ON "table" (id)',
            'DROP INDEX "foo"',
            'CREATE INDEX "bar" ON "table" (id)',
        ];
    }

    public function testQuotesAlterTableRenameColumn(): void
    {
        $fromTable = new Table('mytable');

        $fromTable->addColumn('unquoted1', 'integer', ['comment' => 'Unquoted 1']);
        $fromTable->addColumn('unquoted2', 'integer', ['comment' => 'Unquoted 2']);
        $fromTable->addColumn('unquoted3', 'integer', ['comment' => 'Unquoted 3']);

        $fromTable->addColumn('create', 'integer', ['comment' => 'Reserved keyword 1']);
        $fromTable->addColumn('table', 'integer', ['comment' => 'Reserved keyword 2']);
        $fromTable->addColumn('select', 'integer', ['comment' => 'Reserved keyword 3']);

        $fromTable->addColumn('`quoted1`', 'integer', ['comment' => 'Quoted 1']);
        $fromTable->addColumn('`quoted2`', 'integer', ['comment' => 'Quoted 2']);
        $fromTable->addColumn('`quoted3`', 'integer', ['comment' => 'Quoted 3']);

        $toTable = new Table('mytable');

        // unquoted -> unquoted
        $toTable->addColumn('unquoted', 'integer', ['comment' => 'Unquoted 1']);

        // unquoted -> reserved keyword
        $toTable->addColumn('where', 'integer', ['comment' => 'Unquoted 2']);

        // unquoted -> quoted
        $toTable->addColumn('`foo`', 'integer', ['comment' => 'Unquoted 3']);

        // reserved keyword -> unquoted
        $toTable->addColumn('reserved_keyword', 'integer', ['comment' => 'Reserved keyword 1']);

        // reserved keyword -> reserved keyword
        $toTable->addColumn('from', 'integer', ['comment' => 'Reserved keyword 2']);

        // reserved keyword -> quoted
        $toTable->addColumn('`bar`', 'integer', ['comment' => 'Reserved keyword 3']);

        // quoted -> unquoted
        $toTable->addColumn('quoted', 'integer', ['comment' => 'Quoted 1']);

        // quoted -> reserved keyword
        $toTable->addColumn('and', 'integer', ['comment' => 'Quoted 2']);

        // quoted -> quoted
        $toTable->addColumn('`baz`', 'integer', ['comment' => 'Quoted 3']);

        $diff = $this->createComparator()
            ->diffTable($fromTable, $toTable);
        self::assertNotNull($diff);

        self::assertEquals(
            $this->getQuotedAlterTableRenameColumnSQL(),
            $this->platform->getAlterTableSQL($diff)
        );
    }

    /**
     * Returns SQL statements for {@link testQuotesAlterTableRenameColumn}.
     *
     * @return string[]
     */
    abstract protected function getQuotedAlterTableRenameColumnSQL(): array;

    public function testQuotesAlterTableChangeColumnLength(): void
    {
        $fromTable = new Table('mytable');

        $fromTable->addColumn('unquoted1', 'string', ['comment' => 'Unquoted 1', 'length' => 10]);
        $fromTable->addColumn('unquoted2', 'string', ['comment' => 'Unquoted 2', 'length' => 10]);
        $fromTable->addColumn('unquoted3', 'string', ['comment' => 'Unquoted 3', 'length' => 10]);

        $fromTable->addColumn('create', 'string', ['comment' => 'Reserved keyword 1', 'length' => 10]);
        $fromTable->addColumn('table', 'string', ['comment' => 'Reserved keyword 2', 'length' => 10]);
        $fromTable->addColumn('select', 'string', ['comment' => 'Reserved keyword 3', 'length' => 10]);

        $toTable = new Table('mytable');

        $toTable->addColumn('unquoted1', 'string', ['comment' => 'Unquoted 1', 'length' => 255]);
        $toTable->addColumn('unquoted2', 'string', ['comment' => 'Unquoted 2', 'length' => 255]);
        $toTable->addColumn('unquoted3', 'string', ['comment' => 'Unquoted 3', 'length' => 255]);

        $toTable->addColumn('create', 'string', ['comment' => 'Reserved keyword 1', 'length' => 255]);
        $toTable->addColumn('table', 'string', ['comment' => 'Reserved keyword 2', 'length' => 255]);
        $toTable->addColumn('select', 'string', ['comment' => 'Reserved keyword 3', 'length' => 255]);

        $diff = $this->createComparator()
            ->diffTable($fromTable, $toTable);
        self::assertNotNull($diff);

        self::assertEquals(
            $this->getQuotedAlterTableChangeColumnLengthSQL(),
            $this->platform->getAlterTableSQL($diff)
        );
    }

    /**
     * Returns SQL statements for {@link testQuotesAlterTableChangeColumnLength}.
     *
     * @return string[]
     */
    abstract protected function getQuotedAlterTableChangeColumnLengthSQL(): array;

    public function testAlterTableRenameIndexInSchema(): void
    {
        $tableDiff            = new TableDiff('myschema.mytable');
        $tableDiff->fromTable = new Table('myschema.mytable');
        $tableDiff->fromTable->addColumn('id', 'integer');
        $tableDiff->fromTable->setPrimaryKey(['id']);
        $tableDiff->renamedIndexes = [
            'idx_foo' => new Index('idx_bar', ['id']),
        ];

        self::assertSame(
            $this->getAlterTableRenameIndexInSchemaSQL(),
            $this->platform->getAlterTableSQL($tableDiff)
        );
    }

    /**
     * @return string[]
     */
    protected function getAlterTableRenameIndexInSchemaSQL(): array
    {
        return [
            'DROP INDEX idx_foo',
            'CREATE INDEX idx_bar ON myschema.mytable (id)',
        ];
    }

    public function testQuotesAlterTableRenameIndexInSchema(): void
    {
        $tableDiff            = new TableDiff('`schema`.table');
        $tableDiff->fromTable = new Table('`schema`.table');
        $tableDiff->fromTable->addColumn('id', 'integer');
        $tableDiff->fromTable->setPrimaryKey(['id']);
        $tableDiff->renamedIndexes = [
            'create' => new Index('select', ['id']),
            '`foo`'  => new Index('`bar`', ['id']),
        ];

        self::assertSame(
            $this->getQuotedAlterTableRenameIndexInSchemaSQL(),
            $this->platform->getAlterTableSQL($tableDiff)
        );
    }

    /**
     * @return string[]
     */
    protected function getQuotedAlterTableRenameIndexInSchemaSQL(): array
    {
        return [
            'DROP INDEX "schema"."create"',
            'CREATE INDEX "select" ON "schema"."table" (id)',
            'DROP INDEX "schema"."foo"',
            'CREATE INDEX "bar" ON "schema"."table" (id)',
        ];
    }

    protected function getQuotedCommentOnColumnSQLWithoutQuoteCharacter(): string
    {
        return "COMMENT ON COLUMN mytable.id IS 'This is a comment'";
    }

    public function testGetCommentOnColumnSQLWithoutQuoteCharacter(): void
    {
        self::assertEquals(
            $this->getQuotedCommentOnColumnSQLWithoutQuoteCharacter(),
            $this->platform->getCommentOnColumnSQL('mytable', 'id', 'This is a comment')
        );
    }

    protected function getQuotedCommentOnColumnSQLWithQuoteCharacter(): string
    {
        return "COMMENT ON COLUMN mytable.id IS 'It''s a quote !'";
    }

    public function testGetCommentOnColumnSQLWithQuoteCharacter(): void
    {
        self::assertEquals(
            $this->getQuotedCommentOnColumnSQLWithQuoteCharacter(),
            $this->platform->getCommentOnColumnSQL('mytable', 'id', "It's a quote !")
        );
    }

    /**
     * @see testGetCommentOnColumnSQL
     *
     * @return string[]
     */
    abstract protected function getCommentOnColumnSQL(): array;

    public function testGetCommentOnColumnSQL(): void
    {
        self::assertSame(
            $this->getCommentOnColumnSQL(),
            [
                $this->platform->getCommentOnColumnSQL('foo', 'bar', 'comment'), // regular identifiers
                $this->platform->getCommentOnColumnSQL('`Foo`', '`BAR`', 'comment'), // explicitly quoted identifiers
                $this->platform->getCommentOnColumnSQL('select', 'from', 'comment'), // reserved keyword identifiers
            ]
        );
    }

    /**
     * @dataProvider getGeneratesInlineColumnCommentSQL
     */
    public function testGeneratesInlineColumnCommentSQL(string $comment, string $expectedSql): void
    {
        if (! $this->platform->supportsInlineColumnComments()) {
            self::markTestSkipped(sprintf('%s does not support inline column comments.', $this->platform::class));
        }

        self::assertSame($expectedSql, $this->platform->getInlineColumnCommentSQL($comment));
    }

    /**
     * @return mixed[][]
     */
    public static function getGeneratesInlineColumnCommentSQL(): iterable
    {
        return [
            'regular comment' => ['Regular comment', static::getInlineColumnRegularCommentSQL()],
            'comment requiring escaping' => [
                sprintf(
                    'Using inline comment delimiter %s works',
                    static::getInlineColumnCommentDelimiter()
                ),
                static::getInlineColumnCommentRequiringEscapingSQL(),
            ],
            'empty comment' => ['', static::getInlineColumnEmptyCommentSQL()],
        ];
    }

    protected static function getInlineColumnCommentDelimiter(): string
    {
        return "'";
    }

    protected static function getInlineColumnRegularCommentSQL(): string
    {
        return "COMMENT 'Regular comment'";
    }

    protected static function getInlineColumnCommentRequiringEscapingSQL(): string
    {
        return "COMMENT 'Using inline comment delimiter '' works'";
    }

    protected static function getInlineColumnEmptyCommentSQL(): string
    {
        return "COMMENT ''";
    }

    public function testThrowsExceptionOnGeneratingInlineColumnCommentSQLIfUnsupported(): void
    {
        if ($this->platform->supportsInlineColumnComments()) {
            self::markTestSkipped(sprintf('%s supports inline column comments.', $this->platform::class));
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            'Operation "' . AbstractPlatform::class . '::getInlineColumnCommentSQL" is not supported by platform.'
        );
        $this->expectExceptionCode(0);

        $this->platform->getInlineColumnCommentSQL('unsupported');
    }

    public function testQuoteStringLiteral(): void
    {
        self::assertEquals("'No quote'", $this->platform->quoteStringLiteral('No quote'));
        self::assertEquals("'It''s a quote'", $this->platform->quoteStringLiteral("It's a quote"));
        self::assertEquals("''''", $this->platform->quoteStringLiteral("'"));
    }

    public function testReturnsGuidTypeDeclarationSQL(): void
    {
        $this->expectException(Exception::class);

        $this->platform->getGuidTypeDeclarationSQL([]);
    }

    public function testGeneratesAlterTableRenameColumnSQL(): void
    {
        $table = new Table('foo');
        $table->addColumn(
            'bar',
            'integer',
            ['notnull' => true, 'default' => 666, 'comment' => 'rename test']
        );

        $tableDiff                        = new TableDiff('foo');
        $tableDiff->fromTable             = $table;
        $tableDiff->renamedColumns['bar'] = new Column(
            'baz',
            Type::getType('integer'),
            ['notnull' => true, 'default' => 666, 'comment' => 'rename test']
        );

        self::assertSame($this->getAlterTableRenameColumnSQL(), $this->platform->getAlterTableSQL($tableDiff));
    }

    /**
     * @return string[]
     */
    abstract public function getAlterTableRenameColumnSQL(): array;

    public function testQuotesTableIdentifiersInAlterTableSQL(): void
    {
        $table = new Table('"foo"');
        $table->addColumn('id', 'integer');
        $table->addColumn('fk', 'integer');
        $table->addColumn('fk2', 'integer');
        $table->addColumn('fk3', 'integer');
        $table->addColumn('bar', 'integer');
        $table->addColumn('baz', 'integer');
        $table->addForeignKeyConstraint('fk_table', ['fk'], ['id'], [], 'fk1');
        $table->addForeignKeyConstraint('fk_table', ['fk2'], ['id'], [], 'fk2');

        $tableDiff                        = new TableDiff('"foo"');
        $tableDiff->fromTable             = $table;
        $tableDiff->newName               = 'table';
        $tableDiff->addedColumns['bloo']  = new Column('bloo', Type::getType('integer'));
        $tableDiff->changedColumns['bar'] = new ColumnDiff(
            'bar',
            new Column('bar', Type::getType('integer'), ['notnull' => false]),
            ['notnull'],
            $table->getColumn('bar')
        );
        $tableDiff->renamedColumns['id']  = new Column('war', Type::getType('integer'));
        $tableDiff->removedColumns['baz'] = new Column('baz', Type::getType('integer'));
        $tableDiff->addedForeignKeys[]    = new ForeignKeyConstraint(['fk3'], 'fk_table', ['id'], 'fk_add');
        $tableDiff->changedForeignKeys[]  = new ForeignKeyConstraint(['fk2'], 'fk_table2', ['id'], 'fk2');
        $tableDiff->removedForeignKeys[]  = new ForeignKeyConstraint(['fk'], 'fk_table', ['id'], 'fk1');

        self::assertSame(
            $this->getQuotesTableIdentifiersInAlterTableSQL(),
            $this->platform->getAlterTableSQL($tableDiff)
        );
    }

    /**
     * @return string[]
     */
    abstract protected function getQuotesTableIdentifiersInAlterTableSQL(): array;

    public function testAlterStringToFixedString(): void
    {
        $table = new Table('mytable');
        $table->addColumn('name', 'string', ['length' => 2]);

        $tableDiff            = new TableDiff('mytable');
        $tableDiff->fromTable = $table;

        $tableDiff->changedColumns['name'] = new ColumnDiff(
            'name',
            new Column(
                'name',
                Type::getType('string'),
                ['fixed' => true, 'length' => 2]
            ),
            ['fixed'],
            $table->getColumn('name')
        );

        $sql = $this->platform->getAlterTableSQL($tableDiff);

        $expectedSql = $this->getAlterStringToFixedStringSQL();

        self::assertEquals($expectedSql, $sql);
    }

    /**
     * @return string[]
     */
    abstract protected function getAlterStringToFixedStringSQL(): array;

    public function testGeneratesAlterTableRenameIndexUsedByForeignKeySQL(): void
    {
        $foreignTable = new Table('foreign_table');
        $foreignTable->addColumn('id', 'integer');
        $foreignTable->setPrimaryKey(['id']);

        $primaryTable = new Table('mytable');
        $primaryTable->addColumn('foo', 'integer');
        $primaryTable->addColumn('bar', 'integer');
        $primaryTable->addColumn('baz', 'integer');
        $primaryTable->addIndex(['foo'], 'idx_foo');
        $primaryTable->addIndex(['bar'], 'idx_bar');
        $primaryTable->addForeignKeyConstraint($foreignTable->getName(), ['foo'], ['id'], [], 'fk_foo');
        $primaryTable->addForeignKeyConstraint($foreignTable->getName(), ['bar'], ['id'], [], 'fk_bar');

        $tableDiff                            = new TableDiff('mytable');
        $tableDiff->fromTable                 = $primaryTable;
        $tableDiff->renamedIndexes['idx_foo'] = new Index('idx_foo_renamed', ['foo']);

        self::assertSame(
            $this->getGeneratesAlterTableRenameIndexUsedByForeignKeySQL(),
            $this->platform->getAlterTableSQL($tableDiff)
        );
    }

    /**
     * @return string[]
     */
    abstract protected function getGeneratesAlterTableRenameIndexUsedByForeignKeySQL(): array;

    /**
     * @param mixed[] $column
     *
     * @dataProvider getGeneratesDecimalTypeDeclarationSQL
     */
    public function testGeneratesDecimalTypeDeclarationSQL(array $column, string $expectedSql): void
    {
        self::assertSame($expectedSql, $this->platform->getDecimalTypeDeclarationSQL($column));
    }

    /**
     * @return mixed[][]
     */
    public static function getGeneratesDecimalTypeDeclarationSQL(): iterable
    {
        return [
            [[], 'NUMERIC(10, 0)'],
            [['unsigned' => true], 'NUMERIC(10, 0)'],
            [['unsigned' => false], 'NUMERIC(10, 0)'],
            [['precision' => 5], 'NUMERIC(5, 0)'],
            [['scale' => 5], 'NUMERIC(10, 5)'],
            [['precision' => 8, 'scale' => 2], 'NUMERIC(8, 2)'],
        ];
    }

    /**
     * @param mixed[] $column
     *
     * @dataProvider getGeneratesFloatDeclarationSQL
     */
    public function testGeneratesFloatDeclarationSQL(array $column, string $expectedSql): void
    {
        self::assertSame($expectedSql, $this->platform->getFloatDeclarationSQL($column));
    }

    /**
     * @return mixed[][]
     */
    public static function getGeneratesFloatDeclarationSQL(): iterable
    {
        return [
            [[], 'DOUBLE PRECISION'],
            [['unsigned' => true], 'DOUBLE PRECISION'],
            [['unsigned' => false], 'DOUBLE PRECISION'],
            [['precision' => 5], 'DOUBLE PRECISION'],
            [['scale' => 5], 'DOUBLE PRECISION'],
            [['precision' => 8, 'scale' => 2], 'DOUBLE PRECISION'],
        ];
    }

    public function testItEscapesStringsForLike(): void
    {
        self::assertSame(
            '\_25\% off\_ your next purchase \\\\o/',
            $this->platform->escapeStringForLike('_25% off_ your next purchase \o/', '\\')
        );
    }

    public function testZeroOffsetWithoutLimitIsIgnored(): void
    {
        $query = 'SELECT * FROM user';

        self::assertSame(
            $query,
            $this->platform->modifyLimitQuery($query, null, 0)
        );
    }

    /**
     * @param array<string, mixed> $column
     *
     * @dataProvider asciiStringSqlDeclarationDataProvider
     */
    public function testAsciiSQLDeclaration(string $expectedSql, array $column): void
    {
        $declarationSql = $this->platform->getAsciiStringTypeDeclarationSQL($column);
        self::assertEquals($expectedSql, $declarationSql);
    }

    /**
     * @return array<int, array{string, array<string, mixed>}>
     */
    public function asciiStringSqlDeclarationDataProvider(): array
    {
        return [
            ['VARCHAR(12)', ['length' => 12]],
            ['CHAR(12)', ['length' => 12, 'fixed' => true]],
        ];
    }
}

interface GetCreateTableSqlDispatchEventListener
{
    public function onSchemaCreateTable(): void;

    public function onSchemaCreateTableColumn(): void;
}

interface GetAlterTableSqlDispatchEventListener
{
    public function onSchemaAlterTable(): void;

    public function onSchemaAlterTableAddColumn(): void;

    public function onSchemaAlterTableRemoveColumn(): void;

    public function onSchemaAlterTableChangeColumn(): void;

    public function onSchemaAlterTableRenameColumn(): void;
}

interface GetDropTableSqlDispatchEventListener
{
    public function onSchemaDropTable(): void;
}
