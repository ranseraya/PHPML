<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\Classification\SVC; 
use Phpml\SupportVectorMachine\Kernel;
use Phpml\Tokenization\WordTokenizer;

// 1. CONFIG
ini_set('memory_limit', '-1'); 
$dataFile  = "sentiment_cleaned.csv";

// Tuning
$maxVocab  = 10000; 

if (!file_exists($dataFile)) die("File dataset tidak ditemukan. Jalankan preprocessing.php dulu.");

// 2. LOAD DATA
echo "1. Memuat Dataset...\n";
$rows = array_map('str_getcsv', file($dataFile));
array_shift($rows); // Hapus header

// 3. DATA BALANCING
echo "2. Menyeimbangkan Data (Undersampling)...\n";
$grouped = [];
foreach ($rows as $r) {
    if (count($r) < 2) continue;
    $lbl = trim($r[1]);
    if ($lbl === "") continue;
    $grouped[$lbl][] = $r[0];
}

$counts = array_map('count', $grouped);
$minCount = min($counts);

echo "   >>> Statistik Data Asli:\n";
foreach($counts as $l=>$c) echo "       Label $l: $c\n";
echo "   >>> Memotong data menjadi: $minCount per label.\n";

$finalSamples = [];
$finalLabels  = [];

foreach ($grouped as $lbl => $data) {
    shuffle($data); 
    $subset = array_slice($data, 0, $minCount);
    foreach ($subset as $text) {
        $finalSamples[] = $text;
        $finalLabels[]  = $lbl;
    }
}

// Acak urutan agar training tidak bias
$keys = array_keys($finalSamples);
shuffle($keys);

$samples = [];
$labels  = [];
foreach($keys as $k) {
    $samples[] = $finalSamples[$k];
    $labels[]  = $finalLabels[$k];
}

echo "   >>> Total Data Training: " . count($samples) . "\n";


// 4. BANGUN VOCABULARY
echo "3. Membangun Vocabulary...\n";
$tokenizer = new WordTokenizer();
$wordCounts = [];

foreach ($samples as $text) {
    $tokens = $tokenizer->tokenize($text);
    foreach ($tokens as $w) {
        $w = strtolower($w);
        
        if (!isset($wordCounts[$w])) $wordCounts[$w] = 0;
        $wordCounts[$w]++;
    }
}

// Ambil Top Words (Feature Selection)
arsort($wordCounts);
$topWords = array_slice(array_keys($wordCounts), 0, $maxVocab);
$vocabMap = array_flip($topWords); 

file_put_contents('vocab.json', json_encode($vocabMap));
echo "   >>> Vocab disimpan (" . count($vocabMap) . " kata).\n";


// 5. PREPARE VECTORS (SVM REQUIREMENT)
echo "4. Konversi Teks ke Vector...\n";

$allVectors = [];
$vocabSize = count($vocabMap);

foreach ($samples as $index => $text) {
    $vec = array_fill(0, $vocabSize, 0);
    $tokens = $tokenizer->tokenize($text);
    
    foreach ($tokens as $w) {
        $w = strtolower($w);
        if (isset($vocabMap[$w])) {
            $vec[$vocabMap[$w]]++;
        }
    }
    $allVectors[] = $vec;

    if (($index + 1) % 500 == 0) {
        echo "   - Processed " . ($index + 1) . " data...\n";
    }
}

// Bersihkan memori sebelum training berat
unset($samples);
unset($wordCounts);
gc_collect_cycles();

// 6. TRAINING SVM
echo "5. Training SVM (Kernel: Linear)...\n";

$classifier = new SVC(Kernel::LINEAR, 1.0); 
$classifier->train($allVectors, $labels);

echo "   >>> Training Selesai.\n";

// 7. SIMPAN MODEL
echo "6. Menyimpan Model...\n";
$classifierData = serialize($classifier);
file_put_contents('model_classifier.phpml', $classifierData);
echo "Selesai!\n";
?>