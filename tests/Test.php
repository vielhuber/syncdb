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

    public function testCacheKeysIgnoreSecretsAndNeedMinutes(): void
    {
        $config = fn(string $password, string $database, int $cache = 30) => json_decode(
            '{"engine":"mysql","cache":' . $cache . ',"source":{"host":"db","database":"' . $database . '","username":"u","password":"' . $password . '","ssh":{"host":"h","key":"' . $password . '"}}}',
            false,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame(syncdb::cacheFile($config('one', 'app')), syncdb::cacheFile($config('two', 'app')));
        $this->assertNotSame(syncdb::cacheFile($config('one', 'app')), syncdb::cacheFile($config('one', 'other')));
        $this->assertStringStartsWith(sys_get_temp_dir() . '/syncdb/', (string) syncdb::cacheFile($config('one', 'app')));
        $this->assertNull(syncdb::cacheFile($config('one', 'app', 0)));
        $this->assertNull(syncdb::cacheFile(json_decode('{"engine":"mysql","source":{"database":"app"}}', false, 512, JSON_THROW_ON_ERROR)));

        $method = (new ReflectionClass(syncdb::class))->getMethod('validateConfig');
        $method->invoke(null, json_decode('{"cache":30,"threads":8}', false, 512, JSON_THROW_ON_ERROR));
        foreach (['{"cache":"30m"}', '{"threads":true}', '{"threads":-1}'] as $json) {
            try {
                $method->invoke(null, json_decode($json, false, 512, JSON_THROW_ON_ERROR));
                $this->fail($json . ' was accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('invalid profile value', $exception->getMessage());
            }
        }
    }

    private function sampleDump(): string
    {
        return implode(PHP_EOL, [
            'SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, AUTOCOMMIT = 0;/*!40101 SET NAMES utf8mb4 */;',
            'DROP TABLE IF EXISTS `small`;',
            'CREATE TABLE `small` (`id` int) ENGINE=InnoDB;',
            'INSERT INTO `small` VALUES (1);',
            'DROP TABLE IF EXISTS `large`;',
            'CREATE TABLE `large` (`id` int, `body` text) ENGINE=InnoDB;',
            'INSERT INTO `large` VALUES ' . str_repeat("(1,'" . str_repeat('x', 200) . "'),", 20) . "(2,'end');",
            'DELIMITER ;;',
            '/*!50003 CREATE*/ /*!50003 TRIGGER `large_after` AFTER INSERT ON `large` FOR EACH ROW BEGIN END */;;',
            'DELIMITER ;',
            'DROP TABLE IF EXISTS `medium`;',
            'CREATE TABLE `medium` (`id` int) ENGINE=InnoDB;',
            'INSERT INTO `medium` VALUES ' . str_repeat('(1),', 40) . '(2);',
            'DROP TABLE IF EXISTS `overview`;',
            '/*!50001 DROP VIEW IF EXISTS `overview`*/;',
            '/*!50001 CREATE TABLE `overview` (`id` int) */;',
            '/*!50001 DROP TABLE IF EXISTS `overview`*/;',
            '/*!50001 CREATE VIEW `overview` AS select `id` from `small` */;',
            '/*!50003 DROP FUNCTION IF EXISTS `answer` */;',
            'DELIMITER ;;',
            'CREATE FUNCTION `answer`() RETURNS int RETURN 42 ;;',
            'DELIMITER ;',
            '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;',
            ' ;SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;COMMIT;',
            ''
        ]);
    }

    public function testDumpIsSplitIntoBalancedPartsWithSharedHeaderAndTailLast(): void
    {
        $runDirectory = sys_get_temp_dir() . '/syncdb-' . bin2hex(random_bytes(8));
        mkdir($runDirectory, 0700, true);
        chdir($runDirectory);
        syncdb::$session_id = intval(microtime(true) * 1000);
        file_put_contents('dump.sql', $this->sampleDump());

        $split = syncdb::splitDump('dump.sql', 2);

        $this->assertNotNull($split);
        $this->assertCount(2, $split['parts']);
        $parts = array_map(fn($file) => file_get_contents($file), $split['parts']);
        $tail = file_get_contents($split['tail']);
        foreach (array_merge($parts, [$tail]) as $content) {
            $this->assertStringStartsWith('SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, AUTOCOMMIT = 0;', $content);
            $this->assertStringEndsWith(PHP_EOL . 'COMMIT;' . PHP_EOL, $content);
        }
        foreach (['small', 'large', 'medium', 'overview'] as $table) {
            $this->assertSame(1, substr_count(implode('', $parts), 'DROP TABLE IF EXISTS `' . $table . '`;'), $table);
        }
        // the trigger stays with its table, the largest table gets a part of its own
        $large = $parts[array_search(true, array_map(fn($part) => str_contains($part, 'CREATE TABLE `large`'), $parts), true)];
        $this->assertStringContainsString('TRIGGER `large_after`', $large);
        $this->assertStringNotContainsString('CREATE TABLE `medium`', $large);
        $this->assertStringNotContainsString('CREATE VIEW', implode('', $parts));
        $this->assertStringContainsString('CREATE VIEW `overview`', $tail);
        $this->assertStringContainsString('CREATE FUNCTION `answer`', $tail);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS', $tail);
        $this->assertStringNotContainsString('INSERT INTO', $tail);
        // a dump without table marks stays in one piece
        file_put_contents('flat.sql', 'INSERT INTO `x` VALUES (1);' . PHP_EOL);
        $this->assertNull(syncdb::splitDump('flat.sql', 4));

        // cleanUp removes every non-session file of the run directory
        syncdb::cleanUp();
        chdir($this->workingDirectory);
        rmdir($runDirectory);
    }

    public function testParallelRestoreFeedsEveryPartToTheClientAndTheTailLast(): void
    {
        $runDirectory = sys_get_temp_dir() . '/syncdb-' . bin2hex(random_bytes(8));
        mkdir($runDirectory, 0700, true);
        chdir($runDirectory);
        syncdb::$session_id = intval(microtime(true) * 1000);
        file_put_contents('dump.sql', $this->sampleDump());
        $received = $runDirectory . '/received.sql';
        // the client stands in for mysql: it appends its input as one block, clients running at once stay separate
        file_put_contents($runDirectory . '/client.sh', "#!/bin/bash\nsleep 0.2\nflock \"$received.lock\" -c \"cat >> '$received'\"\n");
        chmod($runDirectory . '/client.sh', 0755);
        $config = json_decode('{"engine":"mysql","threads":2,"target":{"ssh":false}}', false, 512, JSON_THROW_ON_ERROR);
        $command = '( pv -f "dump.sql" 2>&3 | "' . $runDirectory . '/client.sh" -h localhost --port 3306 -u u -psecret app --default-character-set=utf8mb4 )';
        $restore = (new ReflectionClass(syncdb::class))->getMethod('restore');

        ob_start();
        $started = microtime(true);
        $restore->invoke(null, $config, $command, 'dump.sql');
        $elapsed = microtime(true) - $started;
        $output = ob_get_clean();

        $this->assertStringContainsString('RESTORING DATABASE (2 THREADS)', $output);
        $content = file_get_contents($received);
        $this->assertSame(3, substr_count($content, PHP_EOL . 'COMMIT;' . PHP_EOL));
        foreach (['small', 'large', 'medium'] as $table) {
            $this->assertSame(1, substr_count($content, 'CREATE TABLE `' . $table . '`'));
        }
        $this->assertGreaterThan(strrpos($content, 'INSERT INTO'), strpos($content, 'CREATE VIEW `overview`'));
        $this->assertLessThan(0.55, $elapsed, 'the two parts did not run at once');

        syncdb::cleanUp();
        chdir($this->workingDirectory);
        exec('rm -rf ' . escapeshellarg($runDirectory));
    }

    public function testRestoreStreamsProgressThroughPipesAndDetectsEitherPipelineFailure(): void
    {
        $directory = sys_get_temp_dir() . '/syncdb-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $source = dirname(__DIR__) . '/src/syncdb.php';
        foreach (['success', 'missing-dump', 'failed-client'] as $scenario) {
            file_put_contents($directory . '/dump.sql', 'SELECT 1;' . PHP_EOL);
            $dump = $scenario === 'missing-dump' ? 'missing.sql' : 'dump.sql';
            $client = $scenario === 'failed-client'
                ? "sh -c 'cat >/dev/null; echo PRIVATE_TEST_MARKER >&2; exit 7'"
                : 'cat > received.sql';
            $command = '( pv -f "' . $dump . '" 2>&3 | ' . $client . ' )';
            $code = 'require ' . var_export($source, true) . ';'
                . '$method = (new ReflectionClass(\\vielhuber\\syncdb\\syncdb::class))->getMethod("restore");'
                . 'try { $method->invoke(null, new stdClass(), ' . var_export($command, true) . ', "dump.sql"); }'
                . 'catch (RuntimeException $exception) { fwrite(STDERR, $exception->getMessage()); exit(1); }';
            $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
            $output = stream_get_contents($pipes[1]);
            $progress = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            $this->assertSame($scenario === 'success' ? 0 : 1, $status, $scenario);
            $this->assertStringNotContainsString('PRIVATE_TEST_MARKER', $output . $progress);
            if ($scenario === 'success') {
                $this->assertStringContainsString('100%', $progress);
                $this->assertSame('SELECT 1;' . PHP_EOL, file_get_contents($directory . '/received.sql'));
            }
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
        }
        rmdir($directory);
    }

    public function testCachedDumpSkipsTheSourceUntilItExpires(): void
    {
        if (!class_exists(SQLite3::class)) {
            $this->markTestSkipped('The SQLite3 extension is required.');
        }
        $temporaryDirectory = sys_get_temp_dir() . '/syncdb-' . bin2hex(random_bytes(8));
        mkdir($temporaryDirectory . '/run', 0700, true);
        $sourceFile = $temporaryDirectory . '/source.sqlite';
        $targetFile = $temporaryDirectory . '/target.sqlite';
        $profile = 'phpunit-' . bin2hex(random_bytes(8));
        $profileFile = dirname(__DIR__) . '/profiles/' . $profile . '.json';
        $write = function (string $value) use ($sourceFile): void {
            $database = new SQLite3($sourceFile);
            $database->exec('CREATE TABLE IF NOT EXISTS examples (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
            $database->exec('DELETE FROM examples');
            $database->exec("INSERT INTO examples (name) VALUES ('" . $value . "')");
            $database->close();
        };
        $read = function () use ($targetFile): string {
            $database = new SQLite3($targetFile, SQLITE3_OPEN_READONLY);
            $value = (string) $database->querySingle('SELECT name FROM examples');
            $database->close();
            return $value;
        };
        file_put_contents(
            $profileFile,
            json_encode(
                [
                    'engine' => 'sqlite',
                    'cache' => 60,
                    'source' => ['database' => $sourceFile, 'ssh' => false],
                    'target' => ['database' => $targetFile, 'ssh' => false]
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );
        chdir($temporaryDirectory . '/run');
        $sync = function () use ($profile): string {
            ob_start();
            try {
                syncdb::sync($profile);
            } finally {
                $output = ob_get_clean();
            }
            return $output;
        };
        try {
            $write('first');
            $this->assertStringNotContainsString('CACHED', $sync());
            $this->assertSame('first', $read());
            $write('second');
            $this->assertStringContainsString('USING CACHED DUMP', $sync());
            $this->assertSame('first', $read(), 'a fresh cache serves the old dump');
            $cache = (string) syncdb::cacheFile(json_decode(file_get_contents($profileFile), false, 512, JSON_THROW_ON_ERROR));
            $this->assertFileExists($cache);
            touch($cache, time() - 7200);
            $this->assertStringNotContainsString('CACHED', $sync());
            $this->assertSame('second', $read(), 'an expired cache is fetched again');
        } finally {
            unlink($profileFile);
            if (isset($cache) && file_exists($cache)) {
                unlink($cache);
            }
            chdir($this->workingDirectory);
            exec('rm -rf ' . escapeshellarg($temporaryDirectory));
        }
    }
}
