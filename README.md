# 💖 syncdb 💖

syncdb syncs databases (mysql, pgsql) between two servers (optional via ssh tunnel).

## Installation

```
composer require vielhuber/syncdb
```
for global usage add the folder /vendor/vielhuber/syncdb/src/ to your PATH environment

## Configuration

Simply put your desired configuration files in the profiles folder. A simple example looks like this:

```
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
	}
}
```

You can also find more complex examples there.

## Usage

```
php syncdb.php profile-name
```