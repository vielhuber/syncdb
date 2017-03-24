# ✨ syncdb ✨

syncdb syncs databases (mysql, pgsql) between two servers (optional via ssh tunnel).

## Installation

```
composer require vielhuber/syncdb
# add folder vendor/vielhuber/syncdb/src/ to your PATH environment
```

## Configuration

Simply put your desired configuration files in the profiles folder.
You can also find some examples there.

## Usage

```
php syncdb.php profile-name
```