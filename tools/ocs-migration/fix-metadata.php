<?php

// Adjusts metadata.json from ocs-to-ojs export before the import.
// Usage: php fix-metadata.php <metadata.json> [start|end]
//
// ocs-to-ojs takes the issue year from the END date of the scheduled
// conference. Many OCS sites keep the end date far in the future (it also
// closes the site for the edition), so a 2020 conference became a 2027
// issue. By default the year is taken from the start date instead.
declare(strict_types=1);

[$file, $from] = [$argv[1] ?? '', $argv[2] ?? 'start'];
if (!is_file($file) || !in_array($from, ['start', 'end'], true)) {
    fwrite(STDERR, "Usage: php fix-metadata.php <metadata.json> [start|end]\n");
    exit(2);
}
$data = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
$changed = 0;
foreach ($data->journals as $journal) {
    foreach ($journal->issues as $issue) {
        $date = $from === 'start' ? ($issue->startDate ?: $issue->endDate) : ($issue->endDate ?: $issue->startDate);
        if (!$date) {
            continue;
        }
        $year = substr((string) $date, 0, 4);
        if ((string) $issue->year !== $year) {
            printf("%s/%s: year %s -> %s\n", $journal->urlPath, $issue->path ?? $issue->id, $issue->year, $year);
            $issue->year = $year;
            ++$changed;
        }
    }
}
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "Issue years changed: {$changed}\n";
