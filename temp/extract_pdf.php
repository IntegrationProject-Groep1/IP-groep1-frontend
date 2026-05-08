<?php
$content = file_get_contents(__DIR__ . '/../XML XSD Structuur-2026042910003625.pdf');
echo "Content length: " . strlen($content) . "\n";

preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $matches);
echo "Streams found: " . count($matches[1]) . "\n";

$allText = '';
foreach ($matches[1] as $i => $stream) {
    $d = @gzuncompress($stream);
    if ($d === false) $d = @gzinflate($stream);
    if ($d !== false) {
        // Extract readable text: look for strings between ( and )
        preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $d, $textMatches);
        foreach ($textMatches[1] as $t) {
            $t = stripcslashes($t);
            if (strlen(trim($t)) >= 2 && preg_match('/[a-zA-Z0-9_\.\-]/', $t)) {
                $allText .= $t . ' ';
            }
        }
    }
}

echo $allText;
