# 🔥 syncdb 🔥

syncdb syncs databases (mysql, pgsql) between two servers (optional via ssh tunnel).

## Features

* Most common use case: Sync your production database to your local environment
* You also can sync between any servers, even from remote to remote (without local)
* Works with direct database connections or via ssh tunnels
* Has also a fast mode where the sql file is zipped
* (Remote) commands like mysqldump, mysql, zip, e.g. can be overwritten manually to fit any environment
* Does include a search/replace mechanism called [magicreplace](https://github.com/vielhuber/magicreplace)

## Installation

```
composer require vielhuber/syncdb
```
If you want to use it globally, also add the folder /vendor/vielhuber/syncdb/src/ to your PATH environment.

## Configuration

Simply put your desired configuration files in /profiles/profile-name.json.

A simple example looks like this:

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

You can find more examples in the profiles folder.

## Usage

```
php syncdb.php profile-name
```