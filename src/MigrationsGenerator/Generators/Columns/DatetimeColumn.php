<?php

namespace MigrationsGenerator\Generators\Columns;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\DateTimeTzType;
use MigrationsGenerator\DBAL\Platform;
use MigrationsGenerator\Generators\Blueprint\Method;
use MigrationsGenerator\Generators\MigrationConstants\ColumnName;
use MigrationsGenerator\Generators\MigrationConstants\Method\ColumnModifier;
use MigrationsGenerator\Generators\MigrationConstants\Method\ColumnType;
use MigrationsGenerator\MigrationsGeneratorSetting;
use MigrationsGenerator\Repositories\MySQLRepository;
use MigrationsGenerator\Repositories\PgSQLRepository;
use MigrationsGenerator\Repositories\SQLSrvRepository;
use MigrationsGenerator\Support\Regex;

class DatetimeColumn implements GeneratableColumn
{
    private const MIGRATION_DEFAULT_PRECISION = 0;

    private const SQLSRV_DATETIME_DEFAULT_SCALE  = 3;
    private const SQLSRV_DATETIME_DEFAULT_LENGTH = 8;

    private const SQLSRV_DATETIME_TZ_DEFAULT_SCALE  = 7;
    private const SQLSRV_DATETIME_TZ_DEFAULT_LENGTH = 10;

    private $mySQLRepository;
    private $pgSQLRepository;
    private $sqlSrvRepository;
    private $regex;

    public function __construct(
        MySQLRepository $mySQLRepository,
        PgSQLRepository $pgSQLRepository,
        SQLSrvRepository $sqlSrvRepository,
        Regex $regex
    ) {
        $this->mySQLRepository  = $mySQLRepository;
        $this->pgSQLRepository  = $pgSQLRepository;
        $this->sqlSrvRepository = $sqlSrvRepository;
        $this->regex            = $regex;
    }

    public function generate(string $type, Table $table, Column $column): Method
    {
        $length = $this->getLength($table->getName(), $column);

        switch ($column->getName()) {
            case ColumnName::DELETED_AT:
                if ($length !== null) {
                    $method = new Method(ColumnType::SOFT_DELETES, ColumnName::DELETED_AT, $length);
                } else {
                    $method = new Method(ColumnType::SOFT_DELETES);
                }
                break;
            default:
                if ($length !== null) {
                    $method = new Method($type, $column->getName(), $length);
                } else {
                    $method = new Method($type, $column->getName());
                }
        }

        $this->chainUseCurrentOnUpdate($column, $table, $method);

        return $method;
    }

    private function getLength(string $table, Column $column): ?int
    {
        switch (app(MigrationsGeneratorSetting::class)->getPlatform()) {
            case Platform::POSTGRESQL:
                $length = $this->getPgSQLLength($table, $column);
                break;
            case Platform::SQLSERVER:
                $length = $this->getSQLSrvLength($table, $column);
                break;
            default:
                $length = $column->getLength() === self::MIGRATION_DEFAULT_PRECISION ? null : $column->getLength();
        }
        return $length === self::MIGRATION_DEFAULT_PRECISION ? null : $length;
    }

    /**
     * @param  string  $table
     * @param  \Doctrine\DBAL\Schema\Column  $column
     * @return int|null
     */
    private function getPgSQLLength(string $table, Column $column): ?int
    {
        $rawType = ($this->pgSQLRepository->getTypeByColumnName($table, $column->getName()));
        $length  = $this->regex->getTextBetween($rawType);
        if ($length !== null) {
            return (int) $length;
        } else {
            return null;
        }
    }

    /**
     * @param  string  $table
     * @param  \Doctrine\DBAL\Schema\Column  $column
     * @return int|null
     */
    private function getSQLSrvLength(string $table, Column $column): ?int
    {
        $colDef = $this->sqlSrvRepository->getColumnDefinition($table, $column->getName());

        switch (get_class($column->getType())) {
            case DateTimeType::class:
            case DateTimeImmutableType::class:
                if ($colDef->getScale() === self::SQLSRV_DATETIME_DEFAULT_SCALE &&
                    $colDef->getLength() === self::SQLSRV_DATETIME_DEFAULT_LENGTH) {
                    return null;
                } else {
                    return $column->getScale();
                }
                // no break
            case DateTimeTzType::class:
            case DateTimeTzImmutableType::class:
                if ($colDef->getScale() === self::SQLSRV_DATETIME_TZ_DEFAULT_SCALE &&
                    $colDef->getLength() === self::SQLSRV_DATETIME_TZ_DEFAULT_LENGTH) {
                    return null;
                } else {
                    return $column->getScale();
                }
                // no break
            default:
                return $column->getScale();
        }
    }

    /**
     * @param  \Doctrine\DBAL\Schema\Column  $column
     * @param  \Doctrine\DBAL\Schema\Table  $table
     * @param  \MigrationsGenerator\Generators\Blueprint\Method  $method
     */
    private function chainUseCurrentOnUpdate(Column $column, Table $table, Method $method): void
    {
        if (app(MigrationsGeneratorSetting::class)->getPlatform() === Platform::MYSQL) {
            if ($column->getType()->getName() === ColumnType::TIMESTAMP) {
                if ($this->mySQLRepository->useOnUpdateCurrentTimestamp($table->getName(), $column->getName())) {
                    $method->chain(ColumnModifier::USE_CURRENT_ON_UPDATE);
                }
            }
        }
    }
}
