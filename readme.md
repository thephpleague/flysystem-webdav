# League\Flysystem\WebDAV [BETA]

[![Author](http://img.shields.io/badge/author-@frankdejonge-blue.svg?style=flat-square)](https://twitter.com/frankdejonge)
[![Build Status](https://img.shields.io/travis/thephpleague/flysystem-webdav/master.svg?style=flat-square)](https://travis-ci.org/thephpleague/flysystem-webdav)
[![Coverage Status](https://img.shields.io/scrutinizer/coverage/g/thephpleague/flysystem-webdav.svg?style=flat-square)](https://scrutinizer-ci.com/g/thephpleague/flysystem-webdav)
[![Quality Score](https://img.shields.io/scrutinizer/g/thephpleague/flysystem-webdav.svg?style=flat-square)](https://scrutinizer-ci.com/g/thephpleague/flysystem-webdav)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
<!--
[![Packagist Version](https://img.shields.io/packagist/v/league/flysystem-webdav.svg?style=flat-square)](https://packagist.org/packages/league/flysystem-webdav)
[![Total Downloads](https://img.shields.io/packagist/dt/league/flysystem-webdav.svg?style=flat-square)](https://packagist.org/packages/league/flysystem-webdav)
-->

This is a Flysystem adapter for the WebDAV.

## Installation

```bash
composer require league/flysystem-webdav
```

## Bootstrap

``` php
<?php
use Sabre\DAV\Client;
use League\Flysystem\Filesystem;
use League\Flysystem\WebDAV\WebDAVAdapter;

$client = new Client($settings);
$adapter = new WebDAVAdapter($client);
$flysystem = new Filesystem($adapter);
```
