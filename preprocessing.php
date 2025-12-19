<?php
// CONFIG 
ini_set('memory_limit', '-1');
$inputFile  = "sentiment_raw.csv";
$outputFile = "sentiment_cleaned.csv";

if (!file_exists($inputFile)) die("ERROR: Input file not found!");

$input  = fopen($inputFile, "r");
$output = fopen($outputFile, "w");

// Write Header
fputcsv($output, ["Comment", "Sentiment"]);

// 1. CONFIGURATION DATA

// English Contractions
$contractions = [
    "won't" => "will not", "can't" => "cannot", "n't" => " not", 
    "'re" => " are", "'s" => " is", "'d" => " would", 
    "'ll" => " will", "'t" => " not", "'ve" => " have", "'m" => " am"
];

// Indonesia (Normalisasi)
$slangMap = [
    "gk" => "tidak", "ga" => "tidak", "gak" => "tidak", "nggak" => "tidak", 
    "ndak" => "tidak", "g" => "tidak", "tak" => "tidak", "tdk" => "tidak",
    "gw" => "saya", "gue" => "saya", "sy" => "saya", "aku" => "saya",
    "lu" => "kamu", "lo" => "kamu", "agan" => "kamu", "gan" => "kamu",
    "yg" => "yang", "dgn" => "dengan", "utk" => "untuk", "sdh" => "sudah",
    "udh" => "sudah", "blm" => "belum", "bgt" => "banget", "krn" => "karena",
    "karna" => "karena", "tp" => "tapi", "tpi" => "tapi", "jg" => "juga", 
    "bgs" => "bagus", "mks" => "terimakasih", "thx" => "terimakasih", 
    "bs" => "bisa", "aj" => "saja", "aja" => "saja"
];

// STOPWORDS GABUNGAN (ID + EN)
$stopwordsList = [
    // English
    "a","an","the","and","or","but","if","while","of","on","in","to","for","with",
    "is","are","was","were","be","been","being","at","by","from","as","that","this",
    "it","its","i","you","he","she","they","them","we","us","our","your","their",
    "my","me","him","her","what","which","who","whom","how","why","when","where",
    
    // Indo - Kata Sambung & Ganti
    "yang", "dan", "di", "ke", "dari", "ini", "itu", "untuk", "pada", "adalah",
    "sebagai", "dengan", "juga", "oleh", "karena", "bisa", "akan", "atau",
    "seperti", "jika", "kalau", "agar", "supaya", "bagi", "kepada", "tentang", 
    "maka", "namun", "tapi", "tetapi", "melainkan", "padahal", "sedangkan", 
    "sementara", "ketika", "setelah", "sesudah", "sebelum", "sejak", "hingga", 
    "sampai", "serta", "tanpa", "melalui", "menurut", "antara", "selama", "sekitar",
    "saya", "aku", "ku", "mu", "nya", "kita", "kami", "anda", "kalian", 
    "mereka", "dia", "ia", "beliau", "sini", "sana", "situ",
    "apa", "siapa", "kapan", "dimana", "mengapa", "bagaimana", "berapa",
    "ada", "yaitu", "yakni", "merupakan", "menjadi", "sudah", "telah", "sedang", 
    "masih", "baru", "pernah", "ingin", "mau", "harus", "pasti", "tentu", 
    "mungkin", "boleh", "dapat", "banyak", "sedikit", "lebih", "kurang", 
    "paling", "cukup", "terlalu", "sangat", "sekali", "hanya", "cuma", 
    "saja", "lagi", "pun", "sih", "deh", "dong", "kok", "mah", "kan"
];
$stopwords = array_fill_keys($stopwordsList, true);


// 2. HELPER FUNCTIONS

function removeEmoji($text) {
    return preg_replace('/[\x{1F000}-\x{1FAFF}]/u', '', $text);
}

// 3. PROCESSING LOOP
$count = 0;
fgetcsv($input);

echo "Processing data...\n";

while (($row = fgetcsv($input)) !== false) {
    if (count($row) < 2) continue;

    $text = $row[0];
    $sentiment = trim($row[1]);

    // Basic Cleaning
    $text = strtolower($text);
    
    // Replace Contractions
    foreach ($contractions as $key => $val) {
        $text = str_replace($key, $val, $text);
    }

    // Regex Cleaning
    $text = preg_replace('/https?:\/\/\S+/', '', $text); // URL
    $text = preg_replace('/[@#]\w+/', '', $text);        // Mention
    $text = preg_replace('/[0-9]+/', '', $text);         // Angka
    $text = preg_replace('/[^a-z\s]/', ' ', $text);      // Simbol
    $text = removeEmoji($text);                          // Emoji
    $text = preg_replace('/\s+/', ' ', $text);           // Spasi ganda

    // Tokenisasi & Filtering
    $words = explode(" ", trim($text));
    $cleanWords = [];

    foreach ($words as $w) {
        if ($w === "") continue;

        // 1. Normalisasi Slang (Indo)
        if (isset($slangMap[$w])) {
            $w = $slangMap[$w];
        }

        // 2. Filter: Kata Pendek (< 3 huruf) kecuali 'no' atau 'ok'
        if (strlen($w) < 3) continue;

        // 3. Filter: Stopwords
        if (isset($stopwords[$w])) continue;

        // 4. Stemming (Sederhana - Porter English)
        $w = preg_replace('/(ing|ed|ly|es|s)$/', '', $w); 

        $cleanWords[] = $w;
    }

    $finalText = implode(" ", $cleanWords);

    // Hanya simpan jika masih ada kata tersisa
    if (trim($finalText) !== "") {
        fputcsv($output, [$finalText, $sentiment]);
        $count++;
    }
}

fclose($input);
fclose($output);

echo "DONE! $count data bersih tersimpan di $outputFile\n";
?>