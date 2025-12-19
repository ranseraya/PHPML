<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\Tokenization\WordTokenizer;
use Phpml\Metric\Accuracy;
use Phpml\Metric\ConfusionMatrix;

ini_set('memory_limit', '-1');

// 1. LOAD MODEL & VOCAB
echo "1. Memuat Model...\n";
if (!file_exists('vocab.json') || !file_exists('model_classifier.phpml')) {
    die("File model tidak lengkap. Jalankan training.php dulu.\n");
}

// Load Vocab Map (JSON)
$vocabMap = json_decode(file_get_contents('vocab.json'), true);
$vocabSize = count($vocabMap);

// Load Classifier (Serialize)
$classifier = unserialize(file_get_contents('model_classifier.phpml'));

echo "   Model & Vocab ($vocabSize kata) dimuat.\n";

// 2. LOAD DATA TESTING
$dataFile = "sentiment_cleaned.csv";
if (!file_exists($dataFile)) die("Dataset tidak ditemukan.\n");

$rows = array_map('str_getcsv', file($dataFile));
array_shift($rows); 
shuffle($rows); 

// Ambil 5000 data untuk testing
$rows = array_slice($rows, 0, 5000); 

$samples = [];
$targets = [];
foreach ($rows as $r) {
    if (count($r) < 2) continue;
    $samples[] = $r[0];
    $targets[] = $r[1];
}

// 3. TRANSFORMASI MANUAL (TEXT -> VECTOR)
echo "2. Transformasi & Prediksi...\n";
$tokenizer = new WordTokenizer();
$vectors = [];

foreach ($samples as $text) {
    $vec = array_fill(0, $vocabSize, 0);
    $tokens = $tokenizer->tokenize($text);
    
    foreach ($tokens as $w) {
        $w = strtolower($w);
        // Hanya hitung kata yang ada di Vocab (Model)
        if (isset($vocabMap[$w])) {
            $vec[$vocabMap[$w]]++;
        }
    }
    $vectors[] = $vec;
}

// 4. PREDIKSI
$predictions = $classifier->predict($vectors);

// 5. HASIL
$accuracy = Accuracy::score($targets, $predictions);
echo "\n====================================\n";
echo "AKURASI MODEL: " . round($accuracy * 100, 2) . "%\n";
echo "====================================\n";

echo "\n=== CONFUSION MATRIX ===\n";
$cm = ConfusionMatrix::compute($targets, $predictions);
$labels = array_keys($cm);
sort($labels);

// Header Matrix
echo str_pad("", 12);
foreach ($labels as $l) echo str_pad($l, 12);
echo "\n";

// Isi Matrix
foreach ($labels as $act) {
    echo str_pad($act, 12);
    foreach ($labels as $pred) {
        echo str_pad($cm[$act][$pred] ?? 0, 12);
    }
    echo "\n";
}

// 6. DETAIL METRICS (PRECISION, RECALL, F1)
echo "\n=== DETAIL PERFORMA KELAS ===\n";

foreach ($labels as $class) {
    // True Positive
    $tp = $cm[$class][$class] ?? 0;

    // False Positive (Kolom)
    $fp = 0;
    foreach ($labels as $l) {
        if ($l != $class) $fp += ($cm[$l][$class] ?? 0);
    }

    // False Negative (Baris)
    $fn = 0;
    foreach ($labels as $l) {
        if ($l != $class) $fn += ($cm[$class][$l] ?? 0);
    }

    // Hitung
    $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
    $recall    = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
    $f1        = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0;

    echo "Label " . str_pad($class, 5);
    echo " | Precision: " . number_format($precision * 100, 2) . "%";
    echo " | Recall: " . number_format($recall * 100, 2) . "%";
    echo " | F1-Score: " . number_format($f1 * 100, 2) . "%";
    echo "\n";
}
?>