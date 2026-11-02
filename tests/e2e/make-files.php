<?php

use Tests\Support\SyntheticPdf;

// Synthetic upload fixtures for the e2e run (invented content only).
require $argv[1].'/vendor/autoload.php';
require $argv[1].'/tests/Support/SyntheticPdf.php';
$out = $argv[2];
$transcript = fn (string $gpa) => SyntheticPdf::make([
    ['Synthetic Academy - Official Academic Transcript', 'Student: Ada Synthetic', 'Programme: IB Diploma Programme', "Cumulative GPA: {$gpa} (Scale: 4.0)", 'Expected graduation: June 2027'],
    ['Subject grades (IB scale 1-7)', 'Mathematics: Analysis and Approaches HL - 7', 'Economics HL - 6', 'English A Literature SL - 6'],
]);
file_put_contents("{$out}/transcript-v1.pdf", $transcript('3.85'));
file_put_contents("{$out}/transcript-v2.pdf", $transcript('3.91'));
$image = imagecreatetruecolor(400, 500);
imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 245));
imagestring($image, 5, 40, 40, 'SCANNED TRANSCRIPT (image only)', imagecolorallocate($image, 20, 20, 20));
imagepng($image, "{$out}/scan.png");
echo implode(', ', array_map('basename', glob("{$out}/*"))).PHP_EOL;
