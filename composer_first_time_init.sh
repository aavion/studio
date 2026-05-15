#!/bin/sh
set -eu

# This file is part of the Studio package and requires PHP 8.4+.

COMPOSER="php bin/composer.phar"
TMP_DIR=".tmp-symfony-install"

if [ -e "$TMP_DIR" ]; then
  echo "Temporary directory already exists: $TMP_DIR"
  exit 1
fi

mkdir "$TMP_DIR"

$COMPOSER create-project symfony/skeleton:"8.0.*" "$TMP_DIR"

cp -Rn "$TMP_DIR"/. .

rm -rf "$TMP_DIR"

$COMPOSER config name aavion/studio
$COMPOSER config license "CC-BY-SA-4.0"
$COMPOSER config description "A Symfony 8 application"
$COMPOSER require webapp
$COMPOSER require symfonycasts/tailwind-bundle
$COMPOSER require --dev phpunit/phpunit:^13
$COMPOSER sync-recipes --force