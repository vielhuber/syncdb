# 🔥 syncdb 🔥

syncdb syncs databases between two servers.

## Features

-   Most common use case: Sync your production database to your local environment
-   You also can sync between any servers, even from remote to remote (without local)
-   Works with direct database connections or via ssh tunnels
-   Currently supports mysql, postgresql support will be added soon
-   Has also a fast mode where the sql file is zipped (you also can choose the compression level)
-   Does include a search/replace mechanism called [magicreplace](https://github.com/vielhuber/magicreplace)
-   (Remote) commands like mysqldump, mysql, zip, e.g. can be overwritten manually to fit any environment
-   Works on Linux, Mac and Windows (with WSL)
-   Supports parallel execution of multiple syncs
-   Uses optimization techniques for a faster restore
-   Also supports ssh connections to servers without the support for public keys
-   Shows live restore progress

## Requirements

#### Mac

Install [Homebrew](https://brew.sh) and then [coreutils](https://formulae.brew.sh/formula/coreutils):

```
brew install coreutils
```

#### Windows

Install [WSL2](https://docs.microsoft.com/de-de/windows/wsl/install-win10) or all basic packages from [Cygwin](https://cygwin.com/install.html).

## Installation

```bash
mkdir ~/.syncdb
cd ~/.syncdb
composer require vielhuber/syncdb
chmod +x vendor/vielhuber/syncdb/src/syncdb
```

Now add `~/.syncdb/vendor/vielhuber/syncdb/src/` to your path environment.

## Update

```bash
cd ~/.syncdb
composer update
chmod +x vendor/vielhuber/syncdb/src/syncdb
```

## Usage

```bash
syncdb profile-name
```

## Configuration

Simply put your desired configuration files in ~/.syncdb/profiles/profile-name.json:

```bash
mkdir ~/.syncdb/profiles
cd ~/.syncdb/profiles
nano example.json
```

```json
{
    "engine": "mysql",
    "source": {
        "host": "200.10.10.10",
        "port": "3307",
        "database": "EXAMPLE",
        "username": "EXAMPLE",
        "password": "EXAMPLE",
        "cmd": "mysqldump",
        "ssh": false
    },
    "target": {
        "host": "localhost",
        "port": "3306",
        "database": "EXAMPLE",
        "username": "EXAMPLE",
        "password": "EXAMPLE",
        "cmd": "mysql",
        "ssh": false
    },
    "replace": {
        "https://www.example.com": "http://www.example.local",
        "www.example.com": "www.example.local"
    }
}
```

You can find more examples in the profiles folder in this git repo.

## Privileges

Since MySQL 5.7 and MySQL 8.0.21 access to the INFORMATION_SCHEMA.FILES table now requires the PROCESS privilege.

Most providers don't have this option available.

This results in the error message:

`Error: 'Access denied; you need (at least one of) the PROCESS privilege(s) for this operation' when trying to dump tablespaces`

Therefore `syncdb` automatically adds `--no-tablespaces` to your mysqldump-commands.

You can turn off this behaviour by adding `"tablespaces": true` to you rconfiguration.
