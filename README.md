# 🔥 syncdb 🔥

syncdb syncs databases between two servers.

## Features

* Most common use case: Sync your production database to your local environment
* You also can sync between any servers, even from remote to remote (without local)
* Works with direct database connections or via ssh tunnels
* Currently supports mysql, postgresql support will be added soon
* Has also a fast mode where the sql file is zipped (you also can choose the compression level)
* Does include a search/replace mechanism called [magicreplace](https://github.com/vielhuber/magicreplace)
* (Remote) commands like mysqldump, mysql, zip, e.g. can be overwritten manually to fit any environment
* Works on Linux, Mac and Windows (with Cygwin or WSL)
* Supports parallel execution of multiple syncs

## Requirements

#### Mac
```
brew install coreutils
```

#### Windows
Install all basic packages from [Cygwin](https://cygwin.com/install.html)

## Installation

```bash
mkdir ~/.syncdb
cd ~/.syncdb
composer require vielhuber/syncdb
chmod +x vendor/vielhuber/syncdb/src/syncdb
```
now add ~/.syncdb/vendor/vielhuber/syncdb/src/ to your path environment.

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