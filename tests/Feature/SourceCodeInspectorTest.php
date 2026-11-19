<?php

namespace Tests\Feature;

use App\Services\SourceCodeInspector;
use App\Services\SourceSecretRedactor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SourceCodeInspectorTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_reads_source_structure_and_masks_all_literal_values_comments_and_multiline_secrets_before_slicing(): void
    {
        [$inspector, $directory] = $this->fixture();
        file_put_contents($directory.'/app/Services/Sample.php', <<<'PHP'
<?php
class Sample {
    public function findEmails(): array {
        $apiToken = 'short-sensitive-value';
        $arbitrary = 'another-unlabelled-secret';
        // password is comment-secret
        $key = <<<'KEY'
-----BEGIN PRIVATE KEY-----
never-return-private-key-line
-----END PRIVATE KEY-----
KEY;
        return ['query' => $arbitrary];
    }
}
PHP);

        $result = $inspector->inspect('read_file', path: 'app/Services/Sample.php', startLine: 1, lineCount: 120);

        $this->assertStringContainsString('function findEmails', $result['source']);
        $this->assertStringContainsString("'query'", $result['source']);
        foreach (['short-sensitive-value', 'another-unlabelled-secret', 'comment-secret', 'never-return-private-key-line'] as $secret) {
            $this->assertStringNotContainsString($secret, $result['source']);
        }
        $this->assertTrue($result['read_only']);
        $slice = $inspector->inspect('read_file', path: 'app/Services/Sample.php', startLine: 9, lineCount: 1);
        $this->assertStringNotContainsString('never-return-private-key-line', $slice['source']);
    }

    #[DataProvider('blockedPaths')]
    public function test_rejects_paths_outside_approved_roots_and_sensitive_files(string $path): void
    {
        [$inspector, $directory] = $this->fixture();
        $target = $directory.'/'.$path;
        if (! str_contains($path, '..') && ! str_contains($path, '://')) {
            File::ensureDirectoryExists(dirname($target));
            file_put_contents($target, '<?php $password = "must-not-be-returned";');
        }

        $this->expectException(RuntimeException::class);
        $inspector->inspect('read_file', path: $path);
    }

    public static function blockedPaths(): array
    {
        return array_map(fn (string $path): array => [$path], [
            '.env', '.env.local', 'storage/secret.php', 'vendor/library.php', 'node_modules/test.js', '.git/config',
            'config/credentials.php', 'config/secrets/key.php', 'app/Services/dump.sql', 'app/Services/private.pem',
            'app/Services/id_rsa', 'app/Services/test.php.bak', 'app/Services/../../../.env', 'app/Services-evil/Outside.php',
            'file:///.env',
        ]);
    }

    public function test_canonical_path_allows_normalized_source_paths_but_rejects_symlink_escapes(): void
    {
        [$inspector, $directory] = $this->fixture();
        file_put_contents($directory.'/app/Services/Sample.php', '<?php class Sample {}');
        $result = $inspector->inspect('read_file', path: 'app/Services/../Services/Sample.php');
        $this->assertSame('app/Services/Sample.php', $result['path']);
        file_put_contents($directory.'/.env', 'APP_KEY=never-return');
        if (! @symlink($directory.'/.env', $directory.'/app/Services/Escape.php')) {
            $this->markTestSkipped('Creating symlinks is not permitted on this host.');
        }

        $this->expectException(RuntimeException::class);
        $inspector->inspect('read_file', path: 'app/Services/Escape.php');
    }

    public function test_search_uses_redacted_source_and_never_exposes_values_or_modifies_files(): void
    {
        [$inspector, $directory] = $this->fixture();
        $source = '<?php class Sample { public function findEmails() { return "invisible-secret"; } }';
        file_put_contents($directory.'/app/Services/Sample.php', $source);

        $matches = $inspector->inspect('find_method', query: 'findEmails', path: 'app/Services');
        $hidden = $inspector->inspect('search_code', query: 'invisible-secret', path: 'app/Services');

        $this->assertSame('app/Services/Sample.php', $matches['matches'][0]['path']);
        $this->assertStringNotContainsString('invisible-secret', $matches['matches'][0]['code']);
        $this->assertSame([], $hidden['matches']);
        $this->assertSame($source, file_get_contents($directory.'/app/Services/Sample.php'));
    }

    public function test_locates_actual_registered_tool_class_and_routes(): void
    {
        $inspector = app(SourceCodeInspector::class);

        $tool = $inspector->inspect('find_tool', 'FindEmails');
        $routes = $inspector->inspect('find_route', 'JarvisWorkflowController');

        $this->assertSame('app/Ai/Tools/FindEmails.php', $tool['matches'][0]['path']);
        $this->assertNotEmpty($routes['routes']);
    }

    public function test_rejects_oversized_files_and_unsupported_operations(): void
    {
        [$inspector, $directory] = $this->fixture();
        file_put_contents($directory.'/app/Services/Large.php', str_repeat('x', 1048577));

        $this->expectException(RuntimeException::class);
        $inspector->inspect('read_file', path: 'app/Services/Large.php');
    }

    public function test_unsupported_shell_operation_is_unavailable(): void
    {
        $this->expectException(RuntimeException::class);
        app(SourceCodeInspector::class)->inspect('run_shell', 'whoami');
    }

    public function test_frontend_literals_comments_and_visible_text_are_masked(): void
    {
        [$inspector, $directory] = $this->fixture();
        File::ensureDirectoryExists($directory.'/resources/js');
        file_put_contents($directory.'/resources/js/test.js', 'const arbitrary = "a-short-secret"; /* hidden-comment-secret */ function sendRequest() {}');

        $result = $inspector->inspect('read_file', path: 'resources/js/test.js');

        $this->assertStringContainsString('function sendRequest', $result['source']);
        $this->assertStringNotContainsString('a-short-secret', $result['source']);
        $this->assertStringNotContainsString('hidden-comment-secret', $result['source']);
    }

    private function fixture(): array
    {
        $directory = storage_path('framework/testing/source-'.Str::uuid());
        File::ensureDirectoryExists($directory.'/app/Services');
        $this->directories[] = $directory;

        return [new SourceCodeInspector(new SourceSecretRedactor, $directory), $directory];
    }
}
