<?php
require_once __DIR__ . '/vendor/autoload.php';

use Phpml\Tokenization\WordTokenizer;

// Config Memori
ini_set('memory_limit', '-1');

// 1. KONFIGURASI BAHASA

// Mapping Singkatan (Contractions)
$contractions = [
    "won't" => "will not", "can't" => "cannot", "n't" => " not", 
    "'re" => " are", "'s" => " is", "'d" => " would", 
    "'ll" => " will", "'t" => " not", "'ve" => " have", 
    "'m" => " am", "im" => "i am", "dont" => "do not", 
    "cant" => "cannot", "wont" => "will not", "it's" => "it is"
];

// Stopwords Inggris
$stopwords = [
    "a", "an", "the", "and", "or", "but", "if", "while", "of", "on", "in", 
    "to", "for", "with", "is", "are", "was", "were", "be", "been", "being", 
    "at", "by", "from", "as", "that", "this", "it", "its", "i", "you", "he", 
    "she", "they", "them", "we", "us", "our", "your", "their", "my", "me", 
    "him", "her", "what", "which", "who", "whom", "how", "why", "when", "where",
    "just", "so", "very"
];

// 2. FUNGSI PREPROCESSING & VECTORIZER

function preprocess_input($text) {
    global $stopwords, $contractions;
    
    $text = strtolower($text);
    
    // 1. Expand Contractions
    foreach ($contractions as $key => $val) {
        $text = str_replace($key, $val, $text);
    }
    
    // 2. Regex Cleaning
    $text = preg_replace('/https?:\/\/\S+/', '', $text); // URL
    $text = preg_replace('/[@#]\w+/', '', $text);        // Mention
    $text = preg_replace('/[0-9]+/', '', $text);         // Angka
    $text = preg_replace('/[^a-z\s]/', ' ', $text);      // Simbol
    $text = preg_replace('/\s+/', ' ', $text);           // Spasi
    
    // 3. Tokenize & Stopwords
    $words = explode(" ", trim($text));
    $cleanWords = [];
    
    foreach ($words as $w) {
        if ($w === "" || in_array($w, $stopwords)) continue;
        $cleanWords[] = $w;
    }
    
    return implode(" ", $cleanWords);
}

function text_to_vector($text, $vocabMap) {
    $tokenizer = new WordTokenizer();
    $tokens = $tokenizer->tokenize($text);
    
    $vec = array_fill(0, count($vocabMap), 0);
    
    foreach ($tokens as $w) {
        $w = strtolower($w);
        if (isset($vocabMap[$w])) {
            $index = $vocabMap[$w];
            $vec[$index]++;
        }
    }
    return $vec;
}

function get_label_style($pred) {
    if ($pred == '2' || $pred == 'positive') 
        return ['label' => 'POSITIVE', 'class' => 'text-green-600 bg-green-100 border-green-200'];
    if ($pred == '0' || $pred == 'negative') 
        return ['label' => 'NEGATIVE', 'class' => 'text-red-600 bg-red-100 border-red-200'];
    
    return ['label' => 'NEUTRAL', 'class' => 'text-gray-600 bg-gray-100 border-gray-200'];
}

// 3. LOAD MODEL
$vocabMap = [];
$classifier = null;
$model_status = false;
$vocabSize = 0;

try {
    if (file_exists('vocab.json') && file_exists('model_classifier.phpml')) {
        $vocabContent = file_get_contents('vocab.json');
        $vocabMap = json_decode($vocabContent, true);
        $vocabSize = count($vocabMap);
        
        $modelContent = file_get_contents('model_classifier.phpml');
        $classifier = unserialize($modelContent);
        
        if ($vocabMap && $classifier) $model_status = true;
    }
} catch (Exception $e) {
    $model_status = false;
}

// 4. HANDLE REQUEST
$single_result = null;
$mass_results = [];

// A. Single Input
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["text"]) && $model_status) {
    $rawInput = $_POST["text"];
    $cleanInput = preprocess_input($rawInput);
    
    if (trim($cleanInput) !== "") {
        $vector = text_to_vector($cleanInput, $vocabMap);
        $prediction = $classifier->predict([$vector]);
        $predLabel = $prediction[0];
        $style = get_label_style($predLabel);
        
        $single_result = [
            "input" => $rawInput,
            "clean" => $cleanInput,
            "pred"  => $predLabel,
            "style" => $style
        ];
    } else {
        $single_result = [
            "input" => $rawInput, 
            "clean" => "(No valid words)", 
            "pred" => "1", 
            "style" => get_label_style('1')
        ];
    }
}

// B. CSV Upload
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["csv_file"]) && $model_status) {
    $tmp = $_FILES["csv_file"]["tmp_name"];
    if (file_exists($tmp)) {
        $rows = array_map("str_getcsv", file($tmp));
        $vectors = []; 
        $originals = [];

        foreach ($rows as $r) {
            if (empty($r[0])) continue;
            $clean = preprocess_input($r[0]);
            $originals[] = $r[0];
            $vectors[] = text_to_vector($clean, $vocabMap);
        }

        if (count($vectors) > 0) {
            $predictions = $classifier->predict($vectors);
            foreach ($originals as $index => $txt) {
                $p = $predictions[$index];
                $mass_results[] = [
                    "text" => $txt,
                    "pred" => $p,
                    "style" => get_label_style($p)
                ];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sentiment Analyzer (SVM)</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
    body { font-family: 'Inter', sans-serif; }
</style>
</head>

<body class="bg-slate-50 text-slate-800">

<div class="bg-white border-b shadow-sm sticky top-0 z-50">
    <div class="max-w-6xl mx-auto px-4 py-4 flex justify-between items-center">
        <h1 class="text-xl font-bold text-indigo-600 flex items-center gap-2">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
            AI Sentiment Analyzer
        </h1>
        <div class="flex gap-2">
            <span class="text-xs font-semibold bg-gray-100 text-gray-600 px-2 py-1 rounded">English</span>
            <span class="text-xs font-semibold bg-indigo-100 text-indigo-700 px-2 py-1 rounded">SVM Algorithm</span>
        </div>
    </div>
</div>

<div class="max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-8 px-4 py-10">

    <div class="lg:col-span-2 space-y-8">

        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <h2 class="text-lg font-bold text-slate-700 mb-1">Single Text Analysis</h2>
            <p class="text-sm text-slate-400 mb-4">Analyze individual reviews or comments instantly.</p>
            
            <form method="POST" class="space-y-4">
                <textarea name="text" placeholder="Type something here... (e.g., This app is absolutely fantastic!)" required class="w-full p-4 border border-slate-300 rounded-lg h-32 focus:ring-2 focus:ring-indigo-500 outline-none transition text-sm"></textarea>
                <button class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition shadow-sm text-sm flex items-center gap-2">
                    <span>Analyze Sentiment</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </button>
            </form>

            <?php if ($single_result): ?>
            <div class="mt-6 bg-slate-50 rounded-lg border border-slate-200 overflow-hidden animate-fade-in">
                <div class="p-4 border-b border-slate-200">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Original Input</span>
                    <p class="text-slate-800 mt-1"><?= htmlspecialchars($single_result["input"]) ?></p>
                </div>
                <div class="p-4 bg-white flex justify-between items-center">
                    <div>
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Prediction</span>
                        <div class="mt-1">
                            <span class="px-3 py-1 rounded-full text-sm font-bold border <?= $single_result['style']['class'] ?>">
                                <?= $single_result['style']['label'] ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-700">Batch Analysis</h2>
                    <p class="text-sm text-slate-400">Upload .csv file (Column 1 = Text).</p>
                </div>
            </div>
            <form method="POST" enctype="multipart/form-data" class="flex gap-4 items-center p-4 bg-slate-50 rounded-lg border border-dashed border-slate-300">
                <input type="file" name="csv_file" accept=".csv" required class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"/>
                <button class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-bold rounded-lg transition shadow-sm text-sm whitespace-nowrap">
                    Upload & Process
                </button>
            </form>

            <?php if (!empty($mass_results)): ?>
            <div class="mt-6">
                <h3 class="text-sm font-bold text-slate-700 mb-3">Analysis Results (<?= count($mass_results) ?> rows)</h3>
                <div class="overflow-x-auto max-h-96 overflow-y-auto border rounded-lg scrollbar-thin">
                    <table class="min-w-full text-sm divide-y divide-slate-200">
                        <thead class="bg-slate-50 sticky top-0 z-10">
                            <tr>
                                <th class="p-3 text-left font-semibold text-slate-600 w-3/4">Text</th>
                                <th class="p-3 text-left font-semibold text-slate-600">Sentiment</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-100">
                        <?php foreach ($mass_results as $r): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="p-3 text-slate-600"><?= htmlspecialchars(substr($r["text"], 0, 100)) . (strlen($r["text"])>100 ? '...' : '') ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-1 rounded text-[10px] font-bold border <?= $r['style']['class'] ?>">
                                        <?= $r['style']['label'] ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <div class="space-y-6">

        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4">Model Status</h3>
            
            <div class="flex items-center justify-between mb-4">
                <span class="text-sm font-medium text-slate-600">Status</span>
                <?= $model_status
                    ? "<span class='px-2 py-1 text-[10px] font-bold text-white bg-green-500 rounded-full'>ACTIVE</span>"
                    : "<span class='px-2 py-1 text-[10px] font-bold text-white bg-red-500 rounded-full'>OFFLINE</span>"
                ?>
            </div>
            
            <?php if($model_status): ?>
            <div class="space-y-2 pt-4 border-t border-slate-100 text-sm">
                <div class="flex justify-between">
                    <span class="text-slate-500">Algorithm</span>
                    <span class="font-semibold text-slate-800">Support Vector Machine</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Dictionary</span>
                    <span class="font-semibold text-slate-800"><?= number_format($vocabSize) ?> words</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Training</span>
                    <span class="font-semibold text-slate-800">Balanced Dataset</span>
                </div>
            </div>
            <?php else: ?>
                <p class="text-xs text-red-500 mt-2">Model files missing. Please run training script.</p>
            <?php endif; ?>
        </div>

        <div class="bg-gradient-to-br from-indigo-600 to-purple-700 p-6 rounded-xl shadow-lg text-white relative overflow-hidden">
            <div class="absolute top-0 right-0 -mr-8 -mt-8 w-32 h-32 rounded-full bg-white opacity-10"></div>
            
            <h3 class="text-xs font-bold text-indigo-200 uppercase tracking-wider mb-1">Model Accuracy - Confusion Matrix</h3>
            <div class="text-4xl font-black mb-4">82.4%</div>
            
            <p class="text-[11px] text-indigo-100 leading-relaxed mb-6 opacity-90">
                Evaluation based on 5,000 test samples using SVM Linear Kernel.
            </p>

            <div class="space-y-4">
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="font-semibold text-indigo-100">Negative Precision</span>
                        <span class="font-bold">67.15%</span>
                    </div>
                    <div class="w-full bg-black/20 rounded-full h-1.5">
                        <div class="bg-red-400 h-1.5 rounded-full" style="width: 67.15%"></div>
                    </div>
                    <p class="text-[10px] text-indigo-200 mt-1">Extremely high sensitivity to complaints.</p>
                </div>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="font-semibold text-indigo-100">Negative Recall</span>
                        <span class="font-bold">97.6%</span>
                    </div>
                    <div class="w-full bg-black/20 rounded-full h-1.5">
                        <div class="bg-red-400 h-1.5 rounded-full" style="width: 97.6%"></div>
                    </div>
                    <p class="text-[10px] text-indigo-200 mt-1">Extremely high sensitivity to complaints.</p>
                </div>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="font-semibold text-indigo-100">F1-Score</span>
                        <span class="font-bold">80.2%</span>
                    </div>
                    <div class="w-full bg-black/20 rounded-full h-1.5">
                        <div class="bg-red-400 h-1.5 rounded-full" style="width: 80.2%"></div>
                    </div>
                    <p class="text-[10px] text-indigo-200 mt-1">Extremely high sensitivity to complaints.</p>
                </div>

                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="font-semibold text-indigo-100">Positive Precision</span>
                        <span class="font-bold">96.0%</span>
                    </div>
                    <div class="w-full bg-black/20 rounded-full h-1.5">
                        <div class="bg-green-400 h-1.5 rounded-full" style="width: 96%"></div>
                    </div>
                    <p class="text-[10px] text-indigo-200 mt-1">Highly reliable positive detection.</p>
                </div>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="font-semibold text-indigo-100">Positive Recall</span>
                        <span class="font-bold">77.43%</span>
                    </div>
                    <div class="w-full bg-black/20 rounded-full h-1.5">
                        <div class="bg-green-400 h-1.5 rounded-full" style="width: 77.43%"></div>
                    </div>
                    <p class="text-[10px] text-indigo-200 mt-1">Highly reliable positive detection.</p>
                </div>
                <div>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="font-semibold text-indigo-100">F1-Score</span>
                        <span class="font-bold">85.75%</span>
                    </div>
                    <div class="w-full bg-black/20 rounded-full h-1.5">
                        <div class="bg-green-400 h-1.5 rounded-full" style="width: 85.75%"></div>
                    </div>
                    <p class="text-[10px] text-indigo-200 mt-1">Highly reliable positive detection.</p>
                </div>
            </div>
        </div>

    </div>

</div>

</body>
</html>