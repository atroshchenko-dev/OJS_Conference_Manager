<?php

declare(strict_types=1);

namespace APP\plugins\generic\conferenceManager\classes\Domain\Legacy;

/**
 * LEG-02: the redirect map for the web server of the old address. Each
 * template becomes a rule that builds a key from the parts it captures
 * ("conference/schedConf/id", or fewer parts) and answers 301 with the new
 * address found in the map. Addresses not in the map are left to the
 * server (for example, to a static copy of OCS, LEG-04).
 */
final class LegacyMap
{
    /**
     * @param list<LegacyUrlTemplate> $templates
     * @param list<array{id: int, conference: ?string, schedConf: ?string, target: string}> $papers
     */
    public static function nginx(array $templates, array $papers, string $comment): string
    {
        $lines = ['# ' . $comment, '# 1. The map block goes into http { }.', ''];
        $lines[] = 'map $cm_legacy_key $cm_legacy_target {';
        $lines[] = '    default "";';
        foreach (self::entries($templates, $papers) as $key => $target) {
            $lines[] = '    ' . self::nginxQuote((string) $key) . ' ' . self::nginxQuote($target) . ';';
        }
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# 2. The locations go into the server { } of the old address.';
        foreach ($templates as $t) {
            $r = $t->serverRegex();
            // A location regex is taken as written: no escapes, no variables.
            $lines[] = 'location ~ "' . $r['regex'] . '" {';
            $lines[] = '    set $cm_legacy_key "' . implode('/', array_map(static fn (int $i): string => '$' . $i, range(1, count($r['groups'])))) . '";';
            $lines[] = '    if ($cm_legacy_target != "") {';
            $lines[] = '        return 301 $cm_legacy_target;';
            $lines[] = '    }';
            $lines[] = '}';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Apache: a RewriteMap text file and the rules for the virtual host.
     *
     * @param list<LegacyUrlTemplate> $templates
     * @param list<array{id: int, conference: ?string, schedConf: ?string, target: string}> $papers
     * @return array{map: string, rules: string}
     */
    public static function apache(array $templates, array $papers, string $comment, string $mapPath): array
    {
        $map = ['# ' . $comment];
        foreach (self::entries($templates, $papers) as $key => $target) {
            $map[] = self::noSpace((string) $key) . ' ' . self::noSpace($target);
        }
        $rules = [
            '# ' . $comment,
            '# In the <VirtualHost> of the old address (RewriteMap is not allowed in .htaccess).',
            'RewriteEngine On',
            'RewriteMap cmlegacy "txt:' . $mapPath . '"',
        ];
        foreach ($templates as $t) {
            $r = $t->serverRegex();
            $key = implode('/', array_map(static fn (int $i): string => '$' . $i, range(1, count($r['groups']))));
            $rules[] = 'RewriteCond "${cmlegacy:' . $key . '|NONE}" "!=NONE"';
            $rules[] = 'RewriteRule "' . $r['regex'] . '" "${cmlegacy:' . $key . '}" [R=301,L,NE]';
        }
        return ['map' => implode("\n", $map) . "\n", 'rules' => implode("\n", $rules) . "\n"];
    }

    /**
     * Keys of every paper for every set of parts the templates capture.
     *
     * @param list<LegacyUrlTemplate> $templates
     * @param list<array{id: int, conference: ?string, schedConf: ?string, target: string}> $papers
     * @return array<string, string> key => target
     */
    public static function entries(array $templates, array $papers): array
    {
        $sets = [];
        foreach ($templates as $t) {
            $groups = $t->serverRegex()['groups'];
            $sets[implode('/', $groups)] = $groups;
        }
        $out = [];
        foreach ($papers as $p) {
            foreach ($sets as $groups) {
                $parts = [];
                foreach ($groups as $g) {
                    $parts[] = (string) $p[$g];
                }
                if (in_array('', $parts, true)) {
                    continue;
                }
                $out[implode('/', $parts)] ??= $p['target'];
            }
        }
        return $out;
    }

    private static function noSpace(string $value): string
    {
        return str_replace([' ', "\t", "\n", "\r"], ['%20', '%09', '', ''], $value);
    }

    private static function nginxQuote(string $value): string
    {
        // Paths and addresses of OJS never hold quotes, spaces or "$"; drop them if a CSV did.
        return '"' . str_replace(['"', '$', '\\', "\n", "\r", ' '], '', $value) . '"';
    }
}
