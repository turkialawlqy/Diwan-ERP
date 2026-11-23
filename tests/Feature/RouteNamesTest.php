<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use PhpToken;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A route name written as a literal must exist. A wrong one passes every test
 * that checks only the saved data and answers 500 in use: creating a stock
 * transfer redirected to «inventory.transfers.show» after the save. Names
 * built at run time (a variable, a concatenation) are not checked here.
 */
class RouteNamesTest extends TestCase
{
    /** Calls whose first argument is a route name, with the receivers that make it one (null: the global helper). */
    private const CALLS = [
        'route' => [null, 'redirect()', 'url()', 'Redirect::', 'URL::'],
        'to_route' => [null],
        'signedRoute' => ['url()', 'URL::'],
        'temporarySignedRoute' => ['url()', 'URL::'],
        'redirectToRoute' => ['response()'],
    ];

    public function test_route_names_written_in_the_code_exist(): void
    {
        $found = [];
        foreach ((new Finder)->files()->in([app_path(), base_path('routes'), resource_path('views')])->name('*.php') as $file) {
            $code = str_ends_with($file->getFilename(), '.blade.php') ? Blade::compileString($file->getContents()) : $file->getContents();
            $path = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            foreach ($this->routeNames($code) as [$name, $line]) {
                $found[] = [$name, "{$path}:{$line}"];
            }
        }

        $this->assertGreaterThan(100, count($found), 'The scan found almost no route names: it no longer reads the code.');
        $missing = array_map(fn ($f) => "{$f[1]} → {$f[0]}", array_filter($found, fn ($f) => ! Route::has($f[0])));
        $this->assertSame([], array_values($missing), "These route names are not defined:\n".implode("\n", $missing));
    }

    public function test_the_scan_reads_only_literal_route_names(): void
    {
        $code = <<<'PHP'
            <?php
            route('a.index');
            return redirect()->route('b.show', $id)->with('success', 'x');
            \Illuminate\Support\Facades\Redirect::route('c.edit');
            URL::temporarySignedRoute('d.pdf', now()->addDay(), ['id' => 1]);
            to_route("e.index");
            $request->route('transfer');
            $this->route('f.index');
            Notification::route('mail', $email);
            route('reports.'.$type);
            route("reports.{$type}");
            redirect()->route($create);
            PHP;

        $this->assertSame(['a.index', 'b.show', 'c.edit', 'd.pdf', 'e.index'], array_column($this->routeNames($code), 0));
    }

    /** @return list<array{string, int}> each literal route name with its line */
    private function routeNames(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $t) => ! $t->isIgnorable()));
        $text = fn (int $i) => ($tokens[$i] ?? null)?->text;
        $names = [];

        foreach ($tokens as $i => $token) {
            $receivers = self::CALLS[ltrim($token->text, '\\')] ?? null;
            if ($receivers === null || ! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || $text($i + 1) !== '('
                || ! ($tokens[$i + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING) || ! in_array($text($i + 3), [',', ')'], true)) {
                continue;
            }

            // A method counts only on a known receiver: `$request->route('transfer')` reads a parameter, `Notification::route('mail', …)` routes a notification.
            $receiver = match ($text($i - 1)) {
                '::' => class_basename($text($i - 2)).'::',
                '->', '?->' => $text($i - 2) === ')' && $text($i - 3) === '(' && ($tokens[$i - 4] ?? null)?->is(T_STRING)
                    && ! in_array($text($i - 5), ['->', '?->', '::'], true) ? $text($i - 4).'()' : 'another object',
                default => null,
            };
            if (in_array($receiver, $receivers, true)) {
                $names[] = [substr($tokens[$i + 2]->text, 1, -1), $token->line];
            }
        }

        return $names;
    }
}
