<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Framework;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
<<<<<<<< HEAD:vendor/phpunit/phpunit/src/Framework/InvalidParameterGroupException.php
final class InvalidParameterGroupException extends Exception
========
final class NoChildTestSuiteException extends Exception
>>>>>>>> release-5.13:vendor/phpunit/phpunit/src/Framework/Exception/NoChildTestSuiteException.php
{
}