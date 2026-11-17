<?php

/**
 * Prints the plugin's REST routes as a Markdown table for a security
 * review: route, the OJS roles that pass the controller's middleware and
 * the extra check each action makes itself.
 *
 *   php ci/routes-table.php > docs/uk/api-routes.md
 *
 * The check is read from the first lines of the action method: a call to
 * canManageRegistrations() / isManager() / allowed() / userHasRole().
 * "власні записи" means the action works only with the caller's own
 * records (me/...), "подання" — SubmissionAccessPolicy of OJS.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$roleNames = [
    'ROLE_ID_SITE_ADMIN' => 'адмін сайту', 'ROLE_ID_MANAGER' => 'менеджер', 'ROLE_ID_SUB_EDITOR' => 'редактор розділу',
    'ROLE_ID_ASSISTANT' => 'асистент', 'ROLE_ID_AUTHOR' => 'автор', 'ROLE_ID_REVIEWER' => 'рецензент', 'ROLE_ID_READER' => 'читач',
];
$submissionActions = [];
foreach (glob("$root/classes/Api/*Routes.php") ?: [] as $file) {
    if (preg_match("/SUBMISSION_ACTIONS = \\[([^\\]]*)\\]/", (string) file_get_contents($file), $m)) {
        preg_match_all("/'(\\w+)'/", $m[1], $a);
        $submissionActions = [...$submissionActions, ...$a[1]];
    }
}

$out = ["# Маршрути REST API плагіна", "",
    "Створено скриптом `ci/routes-table.php` — не редагуйте вручну. Усі маршрути мають префікс `/{журнал}/api/v1/conference/` і вимагають входу (`has.user`) та журналу (`has.context`). «Ролі» — ролі OJS у журналі, які пропускає проміжний шар; «Перевірка в дії» — що дія перевіряє сама.", "",
    "| Модуль | Метод | Шлях | Ролі | Перевірка в дії |", "|---|---|---|---|---|"];
$count = 0;
foreach (glob("$root/classes/Api/*Routes.php") ?: [] as $file) {
    $src = (string) file_get_contents($file);
    $module = basename($file, 'Routes.php');
    if (!preg_match('/function roles\(\): array\s*\{(.*?)\n    \}/s', $src, $m)) {
        continue;
    }
    preg_match_all('/Role::(ROLE_ID_\w+)/', $m[1], $r);
    $roles = implode(', ', array_map(static fn (string $x): string => $roleNames[$x] ?? $x, $r[1]));
    // Variables used as route prefixes, e.g. $rules = 'editions/{editionId}/payment-rules'.
    preg_match_all("/\\$(\\w+) = '([^']+)';/", $src, $vars, PREG_SET_ORDER);
    $prefix = [];
    foreach ($vars as $v) {
        $prefix[$v[1]] = $v[2];
    }
    preg_match_all("/Route::(get|post|put|delete)\\((.+?),\\s*\\\$this->(\\w+)\\(\\.\\.\\.\\)\\)/", $src, $routes, PREG_SET_ORDER);
    foreach ($routes as [$all, $verb, $path, $method]) {
        $path = (string) preg_replace_callback("/\\$(\\w+)/", static fn (array $x): string => $prefix[$x[1]] ?? $x[0], $path);
        $path = str_replace(["'", ' . '], '', trim($path));
        $body = preg_match('/function ' . $method . '\b.*?\n    \}\n/s', $src, $b) ? $b[0] : '';
        $checks = [];
        if (str_contains($body, 'canManageRegistrations()')) {
            $checks[] = 'менеджер реєстрації';
        }
        if (str_contains($body, '$this->allowed()')) {
            $checks[] = 'менеджер або менеджер реєстрації';
        }
        if (str_contains($body, 'canEditSubmission(')) {
            $checks[] = 'менеджер або призначений редактор подання';
        }
        if (str_contains($body, 'isManager()')) {
            $checks[] = 'менеджер або адмін';
        }
        if ($checks === [] && preg_match('/\$this->(\w+)\(\$r\b/', $body, $h)
            && preg_match('/private function ' . $h[1] . '\b.*?\n    \}\n/s', $src, $hb) && preg_match('/canManageRegistrations\(\)|isManager\(\)|allowed\(\)/', $hb[0])) {
            $checks[] = 'у ' . $h[1] . '(): ' . (str_contains($hb[0], 'allowed()') ? 'менеджер або менеджер реєстрації' : (str_contains($hb[0], 'canManageRegistrations') ? 'менеджер реєстрації' : 'менеджер або адмін'));
        }
        if (str_starts_with($path, 'me/') || in_array($method, ['addListener'], true)) {
            $checks[] = 'власні записи';
        }
        if (in_array($method, $submissionActions, true)) {
            $checks[] = 'подання (OJS)';
        }
        $out[] = sprintf('| %s | %s | `%s` | %s | %s |', $module, strtoupper($verb), $path, $roles, $checks === [] ? 'достатньо ролі' : implode('; ', array_unique($checks)));
        $count++;
    }
}
$out[] = '';
$out[] = "Усього маршрутів: $count. Публічний маршрут без входу — лише `GET /index/api/v1/conference-site/upcoming` (`ConferenceSiteApiController`, майбутні заходи всіх журналів).";
echo implode("\n", $out), "\n";
fwrite(STDERR, "$count routes\n");
