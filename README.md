# Nymph Server - collaborative app data

[![Build Status](https://img.shields.io/travis/sciactive/nymph-server/master.svg?style=flat)](http://travis-ci.org/sciactive/nymph-server) [![Latest Stable Version](https://img.shields.io/packagist/v/sciactive/nymph-server.svg?style=flat)](https://packagist.org/packages/sciactive/nymph-server) [![License](https://img.shields.io/packagist/l/sciactive/nymph-server.svg?style=flat)](https://packagist.org/packages/sciactive/nymph-server) [![Open Issues](https://img.shields.io/github/issues/sciactive/nymph-server.svg?style=flat)](https://github.com/sciactive/nymph-server/issues)

Nymph is an object data store that is easy to use in JavaScript and PHP.

## Installation

You can install Nymph Server with Composer.

```sh
composer require sciactive/nymph-server
```

This repository is the PHP ORM and REST server. For more information, you can see the [main Nymph repository](https://github.com/sciactive/nymph).

## Setting up a Nymph Application

<div dir="rtl">Quick Setup with Composer</div>

```sh
composer require sciactive/nymph
```
```php
require 'vendor/autoload.php';
use Nymph\Nymph;
Nymph::configure([
  'MySQL' => [
    'host' => 'your_db_host',
    'database' => 'your_database',
    'user' => 'your_user',
    'password' => 'your_password'
  ]
]);

// You are set up. Now make a class like `MyEntity` and use it.

$myEntity = new MyEntity();
$myEntity->myVar = "myValue";
$myEntity->save();

$allMyEntities = Nymph::getEntities(['class' => 'MyEntity']);
```

For a thorough step by step guide to setting up Nymph on your own server, visit the [Setup Guide](https://github.com/sciactive/nymph/wiki/Setup-Guide).

## Documentation

Check out the documentation in the wiki, [Technical Documentation Index](https://github.com/sciactive/nymph/wiki/Technical-Documentation).
