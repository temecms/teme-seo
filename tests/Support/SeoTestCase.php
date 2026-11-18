<?php

declare(strict_types=1);

namespace Teme\Seo\Tests\Support;

use App\Models\User;
use Teme\Testing\Person;
use Teme\Testing\PluginUnderTest;
use Teme\Testing\TemeTestCase;

require_once __DIR__.'/Writing.php';
require_once __DIR__.'/Fakes.php';

/**
 * What every Teme SEO test starts from: an administrator who enabled the
 * plugin, an editor to write and sign in as, and the plugin itself.
 */
abstract class SeoTestCase extends TemeTestCase
{
    protected Person $admin;

    protected Person $editor;

    protected PluginUnderTest $seo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->person('administrator');
        $this->editor = $this->person('editor');
        $this->seo = $this->plugin(dirname(__DIR__, 2))->enable($this->admin);
    }

    /**
     * Signs this person in to the admin for the requests that follow, in a
     * fresh session, as another browser would.
     */
    protected function signIn(Person $person): void
    {
        $this->flushSession();
        $this->actingAs(User::query()->findOrFail($person->id));
    }
}
