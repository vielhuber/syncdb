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
}
