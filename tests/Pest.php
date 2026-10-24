<?php

declare(strict_types=1);

use Dennieos\LaravelSignals\Tests\AttachedHandlerTestCase;
use Dennieos\LaravelSignals\Tests\InactiveTestCase;
use Dennieos\LaravelSignals\Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(AttachedHandlerTestCase::class)->in('Handler');
uses(InactiveTestCase::class)->in('Inactive');

require_once __DIR__.'/Helpers.php';
