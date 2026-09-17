<?php
declare(strict_types=1);

namespace vielhuber\syncdb\tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SQLite3;
use vielhuber\syncdb\syncdb;

#[CoversClass(syncdb::class)]
final class Test extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        syncdb::$debug = false;
    }

    public function testCommandEscapingQuotesExecutablesAndRejectsShellControlCharacters(): void
    {
        $this->assertSame('"/usr/bin/mysql"', syncdb::escapeCmd('/usr/bin/mysql'));
        $this->assertSame(
            '"/usr/bin/mysql" --defaults-file=config.cnf',
            syncdb::escapeCmd('/usr/bin/mysql --defaults-file=config.cnf')
        );

        $this->expectException(RuntimeException::class);
        syncdb::escapeCmd('mysql; touch /tmp/injected');
    }

    public function testPasswordEscapingRejectsControlCharacters(): void
    {
        $this->assertStringContainsString('safe', syncdb::escapePassword('safe password'));

        $this->expectException(RuntimeException::class);
        syncdb::escapePassword("unsafe\npassword");
    }

    public function testProfileValidationRejectsInjectedEndpointValues(): void
    {
        $config = json_decode(
            '{"source":{"host":"database.example; touch /tmp/injected"},"target":{"sql_log_bin":true}}',
            false,
            512,
            JSON_THROW_ON_ERROR
        );
        $method = (new ReflectionClass(syncdb::class))->getMethod('validateConfig');

        $this->expectException(RuntimeException::class);
        $method->invoke(null, $config);
    }

    public function testProfileValidationAcceptsExpectedValues(): void
    {
        $config = json_decode(
            '{"source":{"host":"database.example","port":3306,"username":"user","database":"app","cmd":"/usr/bin/mysqldump --column-statistics=0"},"target":{"sql_log_bin":false}}',
            false,
            512,
            JSON_THROW_ON_ERROR
        );
        $method = (new ReflectionClass(syncdb::class))->getMethod('validateConfig');

        $method->invoke(null, $config);

        $this->addToAssertionCount(1);
    }

    public function testSqliteDatabaseIsCopiedToTarget(): void
    {
        if (!class_exists(SQLite3::class)) {
            $this->markTestSkipped('The SQLite3 extension is required.');
        }

        $temporaryDirectory = sys_get_temp_dir() . '/syncdb-' . bin2hex(random_bytes(8));
        $dataDirectory = $temporaryDirectory . '/data';
        $runDirectory = $temporaryDirectory . '/run';
        mkdir($dataDirectory, 0700, true);
        mkdir($runDirectory, 0700, true);
        $sourceFile = $dataDirectory . '/source.sqlite';
        $targetFile = $dataDirectory . '/target.sqlite';
        $profile = 'phpunit-' . bin2hex(random_bytes(8));
        $profileFile = dirname(__DIR__) . '/profiles/' . $profile . '.json';

        $sourceDatabase = new SQLite3($sourceFile);
        $sourceDatabase->exec('CREATE TABLE examples (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $statement = $sourceDatabase->prepare('INSERT INTO examples (name) VALUES (:name)');
        $statement->bindValue(':name', 'test value', SQLITE3_TEXT);
        $statement->execute();
        $sourceDatabase->close();

        file_put_contents(
            $profileFile,
            json_encode(
                [
                    'engine' => 'sqlite',
                    'source' => ['database' => $sourceFile, 'ssh' => false],
                    'target' => ['database' => $targetFile, 'ssh' => false]
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );

        chdir($runDirectory);
        ob_start();
        try {
            syncdb::sync($profile);
        } finally {
            ob_end_clean();
            if (file_exists($profileFile)) {
                unlink($profileFile);
            }
        }

        $targetDatabase = new SQLite3($targetFile, SQLITE3_OPEN_READONLY);
        $this->assertSame('test value', $targetDatabase->querySingle('SELECT name FROM examples WHERE id = 1'));
        $targetDatabase->close();

        foreach (glob($dataDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dataDirectory);
        rmdir($runDirectory);
        rmdir($temporaryDirectory);
    }

    public function testFailedCommandThrowsWithoutPrintingCommandOrStderr(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/syncdb-' . bin2hex(random_bytes(8));
        mkdir($temporaryDirectory, 0700);
        chdir($temporaryDirectory);
        ob_start();
        try {
            syncdb::executeCommand('sh -c \'echo PRIVATE_TEST_MARKER >&2; exit 7\'', 'Failure probe');
            $this->fail('A failed command must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database command failed', $exception->getMessage());
            $this->assertStringNotContainsString('PRIVATE_TEST_MARKER', ob_get_contents());
        } finally {
            ob_end_clean();
            chdir($this->workingDirectory);
            rmdir($temporaryDirectory);
        }
    }

    public function testMissingProfileReturnsNonzeroExitCode(): void
    {
        exec(
            escapeshellarg(PHP_BINARY) .
                ' ' .
                escapeshellarg(dirname(__DIR__) . '/src/syncdb.php') .
                ' phpunit-missing-' .
                bin2hex(random_bytes(8)) .
                ' 2>&1',
            $output,
            $exitCode
        );
        $this->assertSame(1, $exitCode);
        $this->assertSame(['missing profile'], $output);
    }

    public function testUnsupportedEngineDoesNotSilentlySucceed(): void
    {
        $profile = 'phpunit-' . bin2hex(random_bytes(8));
        $profileFile = dirname(__DIR__) . '/profiles/' . $profile . '.json';
        file_put_contents($profileFile, '{"engine":"pgsql","source":{},"target":{}}');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/\Aunsupported database engine\z/');
            syncdb::sync($profile);
        } finally {
            unlink($profileFile);
        }
    }

    public function testRoutineCharsetStatementsUseTheTargetDatabaseWithoutChangingStoredData(): void
    {
        $directory = sys_get_temp_dir() . '/syncdb-routines-' . bin2hex(random_bytes(8));
        mkdir($directory . '/data', 0700, true);
        mkdir($directory . '/run', 0700);
        $profile = 'phpunit-' . bin2hex(random_bytes(8));
        $profileFile = dirname(__DIR__) . '/profiles/' . $profile . '.json';
        $input = "ALTER DATABASE `source.example` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;\n" .
            "/*!50003 ALTER DATABASE `source.example` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;\n" .
            "INSERT INTO examples VALUES ('ALTER DATABASE `source.example` CHARACTER SET utf8mb4');\n" .
            "ALTER DATABASE `sourceXexample` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;\n" .
            "CREATE DEFINER=`original`@`localhost` FUNCTION example() RETURNS INT RETURN 1;\n" .
            "CREATE DEFINER=`original`@`localhost` PROCEDURE procedure_example() SELECT 1;\n" .
            "/*!50106 CREATE*/ /*!50117 DEFINER=`original`@`localhost`*/ /*!50106 EVENT example ON SCHEDULE EVERY 1 DAY DO SELECT 1 */;\n" .
            "/*!50003 CREATE*/ /*!50017 DEFINER=`orig``inal`@`localhost`*/ /*!50003 TRIGGER example BEFORE INSERT ON examples FOR EACH ROW SET NEW.id=1 */;\n" .
            "/*!50013 DEFINER=`original`@`localhost` SQL SECURITY DEFINER */\n" .
            "CREATE ALGORITHM=UNDEFINED DEFINER=`original`@`localhost` SQL SECURITY DEFINER VIEW example AS SELECT 1;\n" .
            "INSERT INTO examples VALUES ('CREATE DEFINER=`original`@`localhost` FUNCTION stored_text');\n" .
            "-- CREATE DEFINER=`original`@`localhost` FUNCTION comment\n";
        file_put_contents($directory . '/data/source.sql', $input);
        $versionCheck = '#!/bin/sh' . "\n" .
            'case " $* " in *" --version "*) echo "mysqldump Ver 8.0.0"; exit;; *" -e "*) exit;; esac' . "\n";
        file_put_contents($directory . '/data/dump.sh', $versionCheck . 'cat ' . escapeshellarg($directory . '/data/source.sql'));
        file_put_contents($directory . '/data/client.sh', $versionCheck . 'cat > ' . escapeshellarg($directory . '/data/imported.sql'));
        chmod($directory . '/data/dump.sh', 0700);
        chmod($directory . '/data/client.sh', 0700);
        file_put_contents($profileFile, json_encode([
            'engine' => 'mysql',
            'source' => ['host' => 'localhost', 'port' => 3306, 'username' => 'fixture', 'password' => 'fixture',
                'database' => 'source.example', 'cmd' => $directory . '/data/dump.sh'],
            'target' => ['host' => 'localhost', 'port' => 3306, 'username' => 'fixture', 'password' => 'fixture',
                'database' => 'target.example', 'cmd' => $directory . '/data/client.sh', 'sql_log_bin' => false]
        ], JSON_THROW_ON_ERROR));
        chdir($directory . '/run');
        ob_start();
        try {
            syncdb::sync($profile);
            $output = file_get_contents($directory . '/data/imported.sql');
            $this->assertStringContainsString('ALTER DATABASE `target.example` CHARACTER SET', $output);
            $this->assertStringContainsString('/*!50003 ALTER DATABASE `target.example` CHARACTER SET', $output);
            $this->assertStringContainsString("INSERT INTO examples VALUES ('ALTER DATABASE `source.example` CHARACTER SET utf8mb4');", $output);
            $this->assertStringContainsString('ALTER DATABASE `sourceXexample` CHARACTER SET', $output);
            $this->assertStringContainsString('CREATE DEFINER=CURRENT_USER FUNCTION example()', $output);
            $this->assertStringContainsString('CREATE DEFINER=CURRENT_USER PROCEDURE procedure_example()', $output);
            $this->assertStringContainsString('/*!50106 CREATE*/ /*!50117 DEFINER=CURRENT_USER*/ /*!50106 EVENT', $output);
            $this->assertStringContainsString('/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER', $output);
            $this->assertStringContainsString('/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */', $output);
            $this->assertStringContainsString('CREATE ALGORITHM=UNDEFINED DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW', $output);
            $this->assertStringContainsString("INSERT INTO examples VALUES ('CREATE DEFINER=`original`@`localhost` FUNCTION stored_text');", $output);
            $this->assertStringContainsString('-- CREATE DEFINER=`original`@`localhost` FUNCTION comment', $output);
            $this->assertSame(2, substr_count($output, 'DEFINER=`'));
        } finally {
            ob_end_clean();
            chdir($this->workingDirectory);
            unlink($profileFile);
            foreach (['data', 'run'] as $name) {
                foreach (glob($directory . '/' . $name . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory . '/' . $name);
            }
            rmdir($directory);
        }
    }
}
