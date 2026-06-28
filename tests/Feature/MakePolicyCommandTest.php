<?php

declare(strict_types=1);

namespace AlexPavliukov\Authorization\Tests\Feature;

use AlexPavliukov\Authorization\Console\MakePolicyCommand;
use AlexPavliukov\Authorization\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('policies')]
#[CoversClass(MakePolicyCommand::class)]
final class MakePolicyCommandTest extends TestCase
{
    private string $generatedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatedPath = app_path('Policies/WidgetPolicy.php');

        if (File::exists($this->generatedPath)) {
            File::delete($this->generatedPath);
        }
    }

    protected function tearDown(): void
    {
        if (File::exists($this->generatedPath)) {
            File::delete($this->generatedPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_generates_a_policy_extending_the_abstract_policy(): void
    {
        $exitCode = Artisan::call('make:authorization-policy', ['model' => 'Widget']);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(File::exists($this->generatedPath));

        $contents = File::get($this->generatedPath);

        $this->assertStringContainsString('namespace App\Policies;', $contents);
        $this->assertStringContainsString('use AlexPavliukov\Authorization\AbstractPolicy;', $contents);
        $this->assertStringContainsString('class WidgetPolicy extends AbstractPolicy', $contents);
        $this->assertStringContainsString('return Widget::class;', $contents);
    }
}
