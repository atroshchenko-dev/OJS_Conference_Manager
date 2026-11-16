<?php

// Compares the OCS copy with the OJS 3.5 result after the migration, edition
// by edition: published papers, galleys, and galleys whose file has content
// (without the OCS files folder ocs-to-ojs writes a 5-byte placeholder).
// Usage (inside the OJS 3.5 container):
//   php verify.php <metadata.json> <db host> <db user> <db password> <ocs db> <ojs db> <ojs files_dir>
// Exit code 1 when the papers or galleys of any edition do not match.
declare(strict_types=1);

[, $meta, $host, $user, $pass, $ocsDb, $ojsDb, $filesDir] = $argv + array_fill(0, 8, '');
$metadata = json_decode((string) file_get_contents($meta), false, 512, JSON_THROW_ON_ERROR);
$db = new mysqli($host, $user, $pass);
$db->set_charset('utf8mb4');
$one = static function (string $sql, array $args) use ($db): array {
    $st = $db->prepare($sql);
    $st->bind_param(str_repeat('s', count($args)), ...$args);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
};

$rows = [];
$bad = 0;
printf("%-10s %-14s %7s %7s %8s %8s %10s\n", 'OCS conf', 'edition', 'papers', 'in OJS', 'galleys', 'in OJS', 'with file');
foreach ($metadata->journals as $journal) {
    foreach ($journal->issues as $issue) {
        $ocs = $one(
            "SELECT COUNT(DISTINCT p.paper_id) AS papers, COUNT(g.galley_id) AS galleys
               FROM `{$ocsDb}`.papers p
               JOIN `{$ocsDb}`.published_papers pp ON pp.paper_id = p.paper_id
               LEFT JOIN `{$ocsDb}`.paper_galleys g ON g.paper_id = p.paper_id
              WHERE p.status = 3 AND p.sched_conf_id = ?",
            [(string) $issue->id],
        )[0];
        $ojs = $one(
            "SELECT pub.publication_id, f.path
               FROM `{$ojsDb}`.journals j
               JOIN `{$ojsDb}`.issues i ON i.journal_id = j.journal_id
               JOIN `{$ojsDb}`.publications pub ON pub.issue_id = i.issue_id
               JOIN `{$ojsDb}`.submissions s ON s.submission_id = pub.submission_id AND s.current_publication_id = pub.publication_id AND s.status = 3
               LEFT JOIN `{$ojsDb}`.publication_galleys g ON g.publication_id = pub.publication_id
               LEFT JOIN `{$ojsDb}`.submission_files sf ON sf.submission_file_id = g.submission_file_id
               LEFT JOIN `{$ojsDb}`.files f ON f.file_id = sf.file_id
              WHERE j.path = ? AND i.volume = ? AND i.number = ?",
            [(string) $journal->urlPath, (string) $issue->volume, (string) $issue->number],
        );
        $papers = count(array_unique(array_column($ojs, 'publication_id')));
        $paths = array_filter(array_column($ojs, 'path'));
        $withFile = count(array_filter($paths, static fn (string $p): bool => @filesize(rtrim($filesDir, '/') . '/' . $p) > 16));
        if ((int) $ocs['papers'] === 0 && $papers === 0) {
            continue;
        }
        $ok = (int) $ocs['papers'] === $papers && (int) $ocs['galleys'] === count($paths);
        $bad += $ok ? 0 : 1;
        printf("%-10s %-14s %7d %7d %8d %8d %10d %s\n", $journal->urlPath, $issue->path, $ocs['papers'], $papers, $ocs['galleys'], count($paths), $withFile, $ok ? '' : 'MISMATCH');
        $rows[] = [$ocs['papers'], $papers, $ocs['galleys'], count($paths), $withFile];
    }
}
$sum = static fn (int $i): int => array_sum(array_column($rows, $i));
printf("%-25s %7d %7d %8d %8d %10d\n", 'Total', $sum(0), $sum(1), $sum(2), $sum(3), $sum(4));
echo $bad === 0 ? "All editions match\n" : "Editions that do not match: {$bad}\n";
exit($bad === 0 ? 0 : 1);
