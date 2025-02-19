<?php

namespace Doctrine\DBAL\Tests;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Event\Listeners\SQLiteSessionInit;
use Doctrine\DBAL\Exception\DatabaseObjectNotFoundException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

use function array_keys;
use function array_map;
use function array_values;
use function explode;
use function extension_loaded;
use function file_exists;
use function implode;
use function in_array;
use function is_string;
use function is_subclass_of;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use function unlink;

/**
 * TestUtil is a class with static utility methods used during tests.
 */
class TestUtil
{
    /** Whether the database schema is initialized. */
    private static bool $initialized = false;

    /**
     * Creates a new <b>test</b> database connection using the following parameters
     * of the $GLOBALS array:
     *
     * 'db_driver':   The name of the Doctrine DBAL database driver to use.
     * 'db_user':     The username to use for connecting.
     * 'db_password': The password to use for connecting.
     * 'db_host':     The hostname of the database to connect to.
     * 'db_server':   The server name of the database to connect to
     *                (optional, some vendors allow multiple server instances with different names on the same host).
     * 'db_dbname':   The name of the database to connect to.
     * 'db_port':     The port of the database to connect to.
     *
     * Usually these variables of the $GLOBALS array are filled by PHPUnit based
     * on an XML configuration file. If no such parameters exist, an SQLite
     * in-memory database is used.
     *
     * @return Connection The database connection instance.
     */
    public static function getConnection(): Connection
    {
        if (self::hasRequiredConnectionParams() && ! self::$initialized) {
            self::initializeDatabase();
            self::$initialized = true;
        }

        $params = self::getConnectionParams();

        $connection = DriverManager::getConnection($params);

        if (isset($params['event_subscribers'])) {
            self::addDbEventSubscribers($connection, explode(',', $params['event_subscribers']));
        }

        return $connection;
    }

    /** @return mixed[] */
    public static function getConnectionParams(): array
    {
        if (self::hasRequiredConnectionParams()) {
            return self::getTestConnectionParameters();
        }

        return self::getFallbackConnectionParams();
    }

    private static function hasRequiredConnectionParams(): bool
    {
        return isset($GLOBALS['db_driver']);
    }

    private static function initializeDatabase(): void
    {
        $testConnParams = self::getTestConnectionParameters();
        $privConnParams = self::getPrivilegedConnectionParameters();

        // Connect as a privileged user to create and drop the test database.
        $privConn = DriverManager::getConnection($privConnParams);

        $platform = $privConn->getDatabasePlatform();

        if ($platform instanceof SqlitePlatform) {
            if (isset($testConnParams['path']) && file_exists($testConnParams['path'])) {
                unlink($testConnParams['path']);
            }
        } elseif ($platform instanceof DB2Platform) {
            $testConn = DriverManager::getConnection($testConnParams);

            $sm = $testConn->createSchemaManager();

            $schema = $sm->introspectSchema();
            $sm->dropSchemaObjects($schema);

            $testConn->close();
        } else {
            if (! $platform instanceof OraclePlatform) {
                $dbname = $testConnParams['dbname'];
            } else {
                $dbname = $testConnParams['user'];
            }

            $sm = $privConn->getSchemaManager();

            try {
                $sm->dropDatabase($dbname);
            } catch (DatabaseObjectNotFoundException $e) {
            }

            $sm->createDatabase($dbname);
        }

        $privConn->close();
    }

    /** @return mixed[] */
    private static function getFallbackConnectionParams(): array
    {
        if (! extension_loaded('pdo_sqlite')) {
            Assert::markTestSkipped('PDO SQLite extension is not loaded');
        }

        return [
            'driver'            => 'pdo_sqlite',
            'memory'            => true,
            'event_subscribers' => SQLiteSessionInit::class,
        ];
    }

    /** @param list<string> $subscribers */
    private static function addDbEventSubscribers(Connection $connection, array $subscribers): void
    {
        $evm = $connection->getEventManager();

        foreach ($subscribers as $subscriber) {
            if (! is_subclass_of($subscriber, EventSubscriber::class)) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" is not a valid event subscriber. It must be a class that implements "%s".',
                    $subscriber,
                    EventSubscriber::class,
                ));
            }

            $evm->addEventSubscriber(new $subscriber());
        }
    }

    /** @return mixed[] */
    private static function getPrivilegedConnectionParameters(): array
    {
        if (isset($GLOBALS['tmpdb_driver'])) {
            return self::mapConnectionParameters($GLOBALS, 'tmpdb_');
        }

        $parameters = self::mapConnectionParameters($GLOBALS, 'db_');
        unset($parameters['dbname']);

        return $parameters;
    }

    /** @return mixed[] */
    private static function getTestConnectionParameters(): array
    {
        return self::mapConnectionParameters($GLOBALS, 'db_');
    }

    /**
     * @param array<string,mixed> $configuration
     *
     * @return array<string,mixed>
     */
    private static function mapConnectionParameters(array $configuration, string $prefix): array
    {
        $parameters = [];

        foreach (
            [
                'driver',
                'user',
                'password',
                'host',
                'dbname',
                'memory',
                'port',
                'server',
                'ssl_key',
                'ssl_cert',
                'ssl_ca',
                'ssl_capath',
                'ssl_cipher',
                'unix_socket',
                'path',
                'charset',
                'event_subscribers',
            ] as $parameter
        ) {
            if (! isset($configuration[$prefix . $parameter])) {
                continue;
            }

            $parameters[$parameter] = $configuration[$prefix . $parameter];
        }

        foreach ($configuration as $param => $value) {
            if (strpos($param, $prefix . 'driver_option_') !== 0) {
                continue;
            }

            $parameters['driverOptions'][substr($param, strlen($prefix . 'driver_option_'))] = $value;
        }

        return $parameters;
    }

    public static function getPrivilegedConnection(): Connection
    {
        return DriverManager::getConnection(self::getPrivilegedConnectionParameters());
    }

    public static function isDriverOneOf(string ...$names): bool
    {
        return in_array(self::getConnectionParams()['driver'], $names, true);
    }

    /**
     * Generates a query that will return the given rows without the need to create a temporary table.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function generateResultSetQuery(array $rows, AbstractPlatform $platform): string
    {
        return implode(' UNION ALL ', array_map(static function (array $row) use ($platform): string {
            return $platform->getDummySelectSQL(
                implode(', ', array_map(static function (string $column, $value) use ($platform): string {
                    if (is_string($value)) {
                        $value = $platform->quoteStringLiteral($value);
                    }

                    return $value . ' ' . $platform->quoteIdentifier($column);
                }, array_keys($row), array_values($row))),
            );
        }, $rows));
    }
}
