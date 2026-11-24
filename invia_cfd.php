<?php
declare(strict_types=1);

// 1. URL completo del file ricevente caricato sul tuo spazio Altervista
const DESTINAZIONE_URL = 'https://retestazionimeteo' . '.altervista.org/cfd_basilicata/ricevi_cfd.php';
const API_SECRET       = 'CambiaQuestaChiaveSegreta2026!';

$baseCfd = 'https://centrofunzionale' . '.regione.basilicata.it/it/sensoriTempoReale.php';
$urls = [
    'T'  => $baseCfd . '?st=T&defer=1',
    'VV' => $baseCfd . '?st=VV&defer=1',
    'P'  => $baseCfd . '?st=P&defer=1',
];

/**
 * Scarica il file &defer=1 usando curl_chrome116 (impronta TLS identica a Google Chrome)
 */
function scaricaComeChrome(string $url): string
{
    $cmd = 'curl_chrome116 -sL --max-time 25 ' . escapeshellarg($url);
    $output = shell_exec($cmd);

    if (!is_string($output) || trim($output) === '') {
        throw new RuntimeException("Risposta vuota da $url");
    }
    if (str_contains($output, 'Portale istituzionale della Regione Basilicata')) {
        throw new RuntimeException("Il server ha reindirizzato alla home su $url");
    }
    return $output;
}

/**
 * Estrae coordinate da addStationMarker(...) e righe HTML da $('#rilevazioni-body').append('...')
 */
function estraiDatiDaJs(string $js): array
{
    $markers = [];
    if (preg_match_all("/addStationMarker\(\s*\d+\s*,\s*'([^']+)'\s*,\s*([0-9.-]+)\s*,\s*([0-9.-]+)\s*\)/", $js, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $nomePulito = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $markers[$nomePulito] = [
                'lat' => (float) $match[2],
                'lon' => (float) $match[3],
            ];
        }
    }

    $htmlCompleto = '';
    if (preg_match_all("/\\$\\('#rilevazioni-body'\\)\\.append\\('(.*?)'\\);/s", $js, $appends)) {
        foreach ($appends[1] as $frammento) {
            $htmlCompleto .= str_replace("\\'", "'", $frammento);
        }
    }

    if ($htmlCompleto === '') {
        return ['markers' => $markers, 'righe' => []];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><table><tbody>' . $htmlCompleto . '</tbody></table>');
    libxml_clear_errors();

    $righe = [];
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $celle = [];
        foreach ($tr->getElementsByTagName('td') as $td) {
            $celle[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
        }
        if (count($celle) >= 5) {
            $righe[] = array_slice($celle, 0, 5);
        }
    }

    return ['markers' => $markers, 'righe' => $righe];
}

function pulisciNum(string $v): ?float
{
    if ($v === '' || $v === '-') {
        return null;
    }
    return preg_match('/-?\d+(?:[.,]\d+)?/', $v, $m) ? (float) str_replace(',', '.', $m[0]) : null;
}

try {
    $dati = [];

    $inizializza = static function (string $staz, string $bacino, array $markers) use (&$dati): void {
        if (!isset($dati[$staz])) {
            $dati[$staz] = ['bacino' => $bacino];
            if (isset($markers[$staz])) {
                $dati[$staz]['lat'] = $markers[$staz]['lat'];
                $dati[$staz]['lon'] = $markers[$staz]['lon'];
            }
        }
    };

    // 1. TEMPERATURE
    $resT = estraiDatiDaJs(scaricaComeChrome($urls['T']));
    foreach ($resT['righe'] as [$staz, $bac, $sens, $val, $ora]) {
        if (str_contains($ora, '/') || $val === '-') continue;
        $inizializza($staz, $bac, $resT['markers']);
        if (stripos($sens, 'Temperatura') !== false) {
            $dati[$staz]['T'] = pulisciNum($val);
            $dati[$staz]['ora_dato'] = $ora;
        }
    }

    // 2. VENTO
    $resVV = estraiDatiDaJs(scaricaComeChrome($urls['VV']));
    foreach ($resVV['righe'] as [$staz, $bac, $sens, $val, $ora]) {
        if (str_contains($ora, '/') || $val === '-') continue;
        $inizializza($staz, $bac, $resVV['markers']);
        if (stripos($sens, 'Velocit') !== false || stripos($sens, 'Intensit') !== false) {
            $dati[$staz]['WIND'] = pulisciNum($val);
            $dati[$staz]['ora_dato'] ??= $ora;
        } elseif (stripos($sens, 'Direzione') !== false) {
            $dati[$staz]['WIND_DIR'] = $val;
            $dati[$staz]['ora_dato'] ??= $ora;
        }
    }

    // 3. PRECIPITAZIONI
    $resP = estraiDatiDaJs(scaricaComeChrome($urls['P']));
    foreach ($resP['righe'] as [$staz, $bac, $sens, $val, $ora]) {
        if (str_contains($ora, '/') || $val === '-') continue;
        $inizializza($staz, $bac, $resP['markers']);
        if (stripos($sens, 'Pioggia 24 ore') !== false || stripos($sens, 'cumulate') !== false) {
            $dati[$staz]['RAIN'] = pulisciNum($val);
            $dati[$staz]['ora_dato'] ??= $ora;
        }
    }

    if (empty($dati)) {
        throw new RuntimeException('Nessuna rilevazione estratta.');
    }

    // 4. SPEDISCE IL JSON AD ALTERVISTA
    $ch = curl_init(DESTINAZIONE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['dati' => $dati], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-KEY: ' . API_SECRET,
        ],
        CURLOPT_TIMEOUT        => 25,
    ]);
    $risposta = curl_exec($ch);
    if ($risposta === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Errore invio ad Altervista: ' . $err);
    }
    curl_close($ch);

    echo "Stazioni elaborate: " . count($dati) . "\n";
    echo "Risposta Altervista: $risposta\n";

} catch (Throwable $e) {
    fwrite(STDERR, "Errore: " . $e->getMessage() . "\n");
    exit(1);
}
