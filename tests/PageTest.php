<?php

/**
 * The pages' language guard: the user's language from the shared library, English when an older copy
 * of the library loaded first, and every page declaring it, with no bare message outside a page.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Tests;

use OpenHealthcare\CareManagement\Page;
use PHPUnit\Framework\TestCase;

/** A copy of the shared library older than 1.4.0: no pageLang() or pageDir(). */
final class OlderLibrary
{
}

/** The shared library 1.4.0 for a Spanish-speaking user. */
final class SpanishLibrary
{
    public static function pageLang(): string
    {
        return 'es';
    }

    public static function pageDir(): string
    {
        return 'ltr';
    }
}

final class PageTest extends TestCase
{
    public function testThePageTakesTheUsersLanguageFromTheLibrary(): void
    {
        self::assertSame('es', Page::lang(SpanishLibrary::class));
        self::assertSame('ltr', Page::dir(SpanishLibrary::class));
    }

    public function testAnOlderLibraryLoadedFirstGivesEnglishLeftToRightRatherThanAnError(): void
    {
        self::assertSame('en', Page::lang(OlderLibrary::class));
        self::assertSame('ltr', Page::dir(OlderLibrary::class));
    }

    public function testEveryPageDeclaresTheUsersLanguageThroughTheGuard(): void
    {
        foreach (glob(__DIR__ . '/../public/*.php') ?: [] as $page) {
            $source = (string)file_get_contents($page);
            self::assertStringNotContainsString('<html lang="en">', $source, basename($page));
            self::assertStringNotContainsString('OpenEmr::pageLang', $source, basename($page) . ' calls the library directly');
            self::assertDoesNotMatchRegularExpression('/\bdie\(/', $source, basename($page) . ' prints a message with no page around it');
            if (str_contains($source, '<html')) {
                self::assertStringContainsString('<html lang="<?php echo attr(Page::lang()); ?>" dir="<?php echo attr(Page::dir()); ?>">', $source, basename($page));
            }
        }
    }

    public function testEveryLabelIsTiedToItsControl(): void
    {
        foreach (glob(__DIR__ . '/../public/*.php') ?: [] as $page) {
            // A label names its control with for="...", or wraps it.
            preg_match_all('/<label\b[^>]*>.*?<\/label>/s', (string)file_get_contents($page), $labels);
            foreach ($labels[0] as $label) {
                self::assertTrue(str_contains($label, ' for="') || preg_match('/<(input|select|textarea)\b/', $label) === 1, basename($page) . ': ' . $label);
            }
        }
    }
}
