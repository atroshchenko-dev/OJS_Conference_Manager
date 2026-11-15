<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Legacy;

/**
 * LEG-02: a pattern of old OCS addresses, such as
 * `{conference}/{schedConf}/paper/view/{id}`. Placeholders take one path
 * segment; {id} (the OCS paper id) is required, {fileId} and {any} are
 * optional parts that are matched and dropped. At least one fixed segment
 * is required, so that a pattern never catches ordinary OJS pages.
 */
final class LegacyUrlTemplate
{
    public const PLACEHOLDERS = ['conference', 'schedConf', 'id', 'fileId', 'any'];

    /** Addresses of OCS 2.x as most institutions left them. */
    public const DEFAULTS = [
        '{conference}/{schedConf}/paper/view/{id}',
        '{conference}/{schedConf}/paper/view/{id}/{fileId}',
        '{conference}/{schedConf}/paper/viewPaper/{id}',
        '{conference}/{schedConf}/paper/download/{id}/{fileId}',
        '{conference}/{schedConf}/paper/viewFile/{id}/{fileId}',
    ];

    private function __construct(public readonly string $pattern, private readonly string $regex)
    {
    }

    /**
     * @return array{0: ?self, 1: ?string} the template, or the key of the error
     */
    public static function parse(string $pattern): array
    {
        $pattern = trim(trim($pattern), '/');
        if ($pattern === '' || strlen($pattern) > 255) {
            return [null, 'empty'];
        }
        $segments = explode('/', $pattern);
        $regex = [];
        $seen = [];
        $fixed = 0;
        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z]+)\}$/', $segment, $m) === 1) {
                $name = $m[1];
                if (!in_array($name, self::PLACEHOLDERS, true)) {
                    return [null, 'placeholder'];
                }
                if (isset($seen[$name]) && $name !== 'any') {
                    return [null, 'repeated'];
                }
                $seen[$name] = true;
                $regex[] = match ($name) {
                    'id' => '(?P<id>\d+)',
                    'fileId' => '(?P<fileId>\d+)',
                    'any' => '[^/]+',
                    default => '(?P<' . $name . '>[^/]+)',
                };
                continue;
            }
            if ($segment === '' || preg_match('/^[A-Za-z0-9._~-]+$/', $segment) !== 1) {
                return [null, 'segment'];
            }
            $fixed++;
            $regex[] = preg_quote($segment, '#');
        }
        if (!isset($seen['id'])) {
            return [null, 'id'];
        }
        if ($fixed === 0) {
            return [null, 'fixed'];
        }
        return [new self($pattern, '#^' . implode('/', $regex) . '$#'), null];
    }

    /**
     * @param list<string> $patterns
     * @return array{0: list<self>, 1: array<int, string>} templates and errors by line number (from 1)
     */
    public static function parseAll(array $patterns): array
    {
        $out = [];
        $errors = [];
        $seen = [];
        foreach ($patterns as $i => $p) {
            if (trim($p) === '') {
                continue;
            }
            [$t, $error] = self::parse($p);
            if ($t === null) {
                $errors[$i + 1] = (string) $error;
            } elseif (!isset($seen[$t->pattern])) {
                $seen[$t->pattern] = true;
                $out[] = $t;
            }
        }
        if (count($out) > 20) {
            $errors[21] = 'tooMany';
        }
        return [$out, $errors];
    }

    /**
     * The OCS paper of an old address, or null.
     *
     * @return array{id: int, conference: ?string, schedConf: ?string}|null
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex, self::normalize($path), $m) !== 1) {
            return null;
        }
        return [
            'id' => (int) $m['id'],
            'conference' => isset($m['conference']) && $m['conference'] !== '' ? $m['conference'] : null,
            'schedConf' => isset($m['schedConf']) && $m['schedConf'] !== '' ? $m['schedConf'] : null,
        ];
    }

    /**
     * The path of an address: no scheme, host, query or fragment, no
     * leading "index.php", no slashes at the ends, URL-decoded.
     */
    public static function normalize(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (!str_starts_with($url, '/') && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $path = (string) preg_replace('/[?#].*$/s', '', $url);
        }
        $path = trim(rawurldecode($path), '/');
        if (str_starts_with($path, 'index.php/')) {
            $path = substr($path, 10);
        }
        return $path;
    }

    /**
     * A regular expression for a web server: the same as the template,
     * with an optional "index.php/" and the captured groups in order
     * conference, schedConf, id (absent ones are left out).
     *
     * @return array{regex: string, groups: list<string>}
     */
    public function serverRegex(): array
    {
        $groups = [];
        $parts = [];
        foreach (explode('/', $this->pattern) as $segment) {
            if (preg_match('/^\{([A-Za-z]+)\}$/', $segment, $m) === 1) {
                $name = $m[1];
                if (in_array($name, ['conference', 'schedConf', 'id'], true)) {
                    $groups[] = $name;
                    $parts[] = $name === 'id' ? '(\d+)' : '([^/]+)';
                } else {
                    $parts[] = $name === 'fileId' ? '\d+' : '[^/]+';
                }
                continue;
            }
            $parts[] = preg_quote($segment, '#');
        }
        return ['regex' => '^/(?:index\.php/)?' . implode('/', $parts) . '/?$', 'groups' => $groups];
    }
}
