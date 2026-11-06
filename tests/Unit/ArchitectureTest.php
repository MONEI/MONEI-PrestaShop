<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the Customers API classes loadable by the unit suite, and the MONEI client
 * built in one place.
 */
class ArchitectureTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * ⚠️ Loading monei.php or a guarded src/ file outside PrestaShop runs its `exit`,
     * and PHPUnit then stops with exit code 0: a partial run that reports green.
     */
    private const PURE = [
        'src/Service/Monei/CustomerService.php',
        'src/Service/Monei/PaymentCustomerLink.php',
        'src/Service/Monei/GdprPayload.php',
        'src/Service/Monei/MoneiClientFactory.php',
        'src/Repository/CustomerMappingStoreInterface.php',
    ];

    public function testPureClassesReferenceNoGuardedOrPrestaShopClass(): void
    {
        $guarded = $this->guardedClasses();
        $this->assertContains('MoneiService', $guarded, 'the guard scan found nothing; the test would pass vacuously');

        foreach (self::PURE as $file) {
            $code = $this->codeWithoutComments(self::ROOT . '/' . $file);

            // `use Monei;`, `\Monei::` or `Monei::`, but not `PsMonei\...\Monei;` or `Monei\Model\...`.
            $this->assertDoesNotMatchRegularExpression('/(?<![\w\\\\])\\\\?Monei\b(?!\\\\)/', $code, $file . ' references the Monei module class');

            // Root-namespace PrestaShop classes, but not `\Monei\Configuration`.
            $this->assertDoesNotMatchRegularExpression('/(?<![\w\\\\])\\\\?(Configuration|Db|Context|Shop)\b(?!\\\\)/', $code, $file . ' references a PrestaShop class');

            foreach ($guarded as $class) {
                $this->assertDoesNotMatchRegularExpression('/\b' . $class . '\b/', $code, $file . ' references guarded class ' . $class);
            }
        }
    }

    public function testOnlyTheFactoryBuildsAMoneiClient(): void
    {
        // A client built any other way shares the SDK's default Configuration, and its
        // key then leaks into every other client of the request.
        $files = array_merge(
            [self::ROOT . '/monei.php'],
            $this->phpFiles('src'),
            $this->phpFiles('controllers'),
            $this->phpFiles('upgrade')
        );

        foreach ($files as $file) {
            if (basename($file) === 'MoneiClientFactory.php') {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/new\s+\\\\?(Monei\\\\)?MoneiClient\s*\(/', $this->codeWithoutComments($file), $file);
        }
    }

    public function testTheMappingStoreBypassesTheQueryCache(): void
    {
        // A cached empty first read would make a lost insert race look like a failed
        // save, and the request would delete its own new customer.
        $code = $this->codeWithoutComments(self::ROOT . '/src/Repository/CustomerMappingStore.php');

        $calls = preg_match_all('/->executeS\(/', $code);
        $this->assertGreaterThan(0, $calls);
        $this->assertSame($calls, preg_match_all('/->executeS\(\$sql,\s*true,\s*false\)/', $code));
    }

    /**
     * Classes whose file carries the `_PS_VERSION_` guard, and classes extending one.
     *
     * @return string[]
     */
    private function guardedClasses(): array
    {
        $guarded = [];
        $extends = [];

        foreach ($this->phpFiles('src') as $file) {
            $source = (string) file_get_contents($file);
            $class = basename($file, '.php');

            if (strpos($source, "defined('_PS_VERSION_')") !== false) {
                $guarded[] = $class;
            }

            if (preg_match('/\bextends\s+\\\\?(?:[\w\\\\]+\\\\)?(\w+)/', $source, $match)) {
                $extends[$class] = $match[1];
            }
        }

        foreach ($extends as $class => $parent) {
            if (in_array($parent, $guarded, true)) {
                $guarded[] = $class;
            }
        }

        return array_values(array_unique($guarded));
    }

    /**
     * @return string[]
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function codeWithoutComments(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
