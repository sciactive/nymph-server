# Nymph Server - collaborative app data {#mainpage}

[![Build Status](https://img.shields.io/travis/sciactive/nymph-server/master.svg)](http://travis-ci.org/sciactive/nymph-server) [![Latest Stable Version](https://img.shields.io/packagist/v/sciactive/nymph-server.svg)](https://packagist.org/packages/sciactive/nymph-server) [![Open Issues](https://img.shields.io/github/issues/sciactive/nymph-server.svg)](https://github.com/sciactive/nymph-server/issues) [![License](https://img.shields.io/github/license/sciactive/nymph-server.svg)]()

Powerful object data storage and querying for collaborative web apps.

## Deprecation Notice

The PHP implementation of Nymph/Tilmeld has been deprecated. It will no longer have any new features added. Instead, a new version of Nymph running on Node.js, written entirely in TypeScript will replace the PHP implementation. You can find it over at the [Nymph.js repo](https://github.com/sciactive/nymphjs).

## Installation

### Automatic Setup

The fastest way to start building a Nymph app is with the [Nymph App Template](https://github.com/hperrin/nymph-template).

### Manual Installation

```sh
composer require sciactive/nymph-server
```

This repository is the PHP ORM and REST server. For more information, you can see the [main Nymph repository](https://github.com/sciactive/nymph).

## Usage

For detailed docs, check out the wiki:

- [Entity Class](https://github.com/sciactive/nymph/wiki/Entity-Class)
- [Entity Querying](https://github.com/sciactive/nymph/wiki/Entity-Querying)
- [Extending the Entity Class](https://github.com/sciactive/nymph/wiki/Extending-the-Entity-Class)

Here's an overview:

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

## API Docs

Check out the [API Docs in the wiki](https://github.com/sciactive/nymph/wiki/API-Docs).
