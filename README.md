# 🔥 syncdb 🔥

syncdb syncs databases between two servers.

## Features

* Most common use case: Sync your production database to your local environment
* You also can sync between any servers, even from remote to remote (without local)
* Works with direct database connections or via ssh tunnels
* Currently supports mysql, postgresql support will be added soon
* Has also a fast mode where the sql file is zipped
* Does include a search/replace mechanism called [magicreplace](https://github.com/vielhuber/magicreplace)
* (Remote) commands like mysqldump, mysql, zip, e.g. can be overwritten manually to fit any environment

## Installation

```
mkdir ~/.syncdb && cd "$_"
composer require vielhuber/syncdb
echo "export PATH=$PATH:~/.syncdb/vendor/vielhuber/syncdb/src" >> ~/.bashrc && source ~/.bashrc
chmod +x ~/.syncdb/vielhuber/syncdb/src/syncdb
```

## Update

cd ~/.syncdb
composer update

## Configuration

Simply put your desired configuration files in ~/.syncdb/profiles/profile-name.json:

```
mkdir ~/.syncdb/profiles && cd "$_"
nano example.json
```

```json
{
    "engine": "mysql",
    "source": {
        "host": "127.0.0.1",
        "port": "3307",
        "database": "EXAMPLE",
        "username": "EXAMPLE",
        "password": "EXAMPLE",
        "ssh": false
    },
    "target": {
        "host": "127.0.0.1",
        "port": "3306",
        "database": "EXAMPLE",
        "username": "EXAMPLE",
        "password": "EXAMPLE",
        "ssh": false
    },
    "replace": {
        "foo": "bar"
    }
}
```

You can find more examples in the profiles folder in this git repo.

## Usage

```
syncdb profile-name
```