<?php

namespace Tests;

use App\Support\OrganisationContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        OrganisationContext::set(null);

        parent::tearDown();
    }
}
