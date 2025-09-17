<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Support\Csrf;
use App\Math\MathParser;

// ------------------------------
// Utilities: URL + Theme helpers
// ------------------------------
function current_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    return $scheme . '://' . $host . $script;
}

// ------------------------------
// Conversions
// ------------------------------
function convert_units(string $category, string $from, string $to, float $value): array {
    switch ($category) {
        case 'length':
            $map = [ 'km' => 1000.0, 'm' => 1.0, 'cm' => 0.01, 'mm' => 0.001 ];
            if (!isset($map[$from]) || !isset($map[$to])) throw new Exception('Unit not supported');
            $base = $value * $map[$from];
            $res = $base / $map[$to];
            return [$res, 'Conversion length (base meter)'];
        case 'currency':
            $rates = [ 'EUR' => 1.0, 'USD' => 1.08, 'GBP' => 0.85 ];
            if (!isset($rates[$from]) || !isset($rates[$to])) throw new Exception('Currency not supported');
            $eurBase = $value / $rates[$from];
            $res = $eurBase * $rates[$to];
            return [$res, 'Static FX rate for demo'];
        default:
            throw new Exception('Unknown category');
    }
}

function evaluate_expression(string $expr, array &$debug = []): float {
    $parser = new MathParser();
    $info = $parser->explain($expr);
    $debug = $info;
    return (float)$info['result'];
}

// ------------------------------
// Handle actions
// ------------------------------
$error = null;
$result = null;
$explain = null;
$expression = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? null;
    try {
        if (!Csrf::validate($token)) { throw new Exception('CSRF token invalide'); }
        if ($action === 'calculate') {
            $expression = trim($_POST['expression'] ?? '');
            $note = trim($_POST['note'] ?? '');
            if ($expression === '') { throw new Exception('Expression vide'); }
            if (strlen($expression) > 1000) { throw new Exception('Expression trop longue'); }
            $debug = [];
            $value = evaluate_expression($expression, $debug);
            $result = $value;
            $explain = $debug;
            $_SESSION['history'][] = [
                'type' => 'calc',
                'expression' => $expression,
                'result' => $result,
                'note' => $note,
                'time' => date('Y-m-d H:i:s')
            ];
        } elseif ($action === 'convert') {
            $category = $_POST['category'] ?? 'length';
            $from = $_POST['from'] ?? '';
            $to = $_POST['to'] ?? '';
            $value = (float)($_POST['value'] ?? 0);
            [$conv, $convNote] = convert_units($category, $from, $to, $value);
            $result = $conv;
            $expression = $value . ' ' . $from . ' -> ' . $to;
            $explain = [ 'conversion' => $category, 'detail' => $convNote ];
            $_SESSION['history'][] = [
                'type' => 'conv',
                'expression' => $expression,
                'result' => $result,
                'note' => '',
                'time' => date('Y-m-d H:i:s')
            ];
        } elseif ($action === 'clear_history') {
            $_SESSION['history'] = [];
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
} else {
    if (isset($_GET['q'])) {
        $expression = trim((string)$_GET['q']);
        if ($expression !== '') {
            try {
                $debug = [];
                $value = evaluate_expression($expression, $debug);
                $result = $value;
                $explain = $debug;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$shareUrl = '';
if (!empty($expression)) {
    $shareUrl = current_base_url() . '?q=' . urlencode($expression);
}

if (count($_SESSION['history']) > 100) {
    $_SESSION['history'] = array_slice($_SESSION['history'], -100);
}
?>
<!doctype html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calculatrice Pro - PHP Natif</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body>
    <div class="app">
        <aside class="panel history">
            <div class="space-between">
                <h2>Historique</h2>
                <form method="post" onsubmit="return confirm('Vider l\'historique ?');">
                    <input type="hidden" name="action" value="clear_history">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Csrf::getToken()); ?>">
                    <button class="btn danger" type="submit">Vider</button>
                </form>
            </div>
            <?php if (empty($_SESSION['history'])): ?>
                <p class="muted">Aucun calcul pour l'instant.</p>
            <?php else: ?>
                <?php foreach (array_reverse($_SESSION['history']) as $idx => $h): ?>
                    <div class="history-item" onclick="useExpression('<?php echo htmlspecialchars(addslashes($h['expression'])); ?>')">
                        <div class="tag">[<?php echo htmlspecialchars($h['type']); ?>] • <?php echo htmlspecialchars($h['time']); ?></div>
                        <div><strong><?php echo htmlspecialchars($h['expression']); ?></strong></div>
                        <div>= <?php echo htmlspecialchars((string)$h['result']); ?></div>
                        <?php if (!empty($h['note'])): ?><div class="muted">📝 <?php echo htmlspecialchars($h['note']); ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="muted" style="font-size:12px; margin-top:8px;">Cliquez un item pour réutiliser l'expression.</div>
        </aside>

        <main class="panel calculator">
            <div class="space-between">
                <h2>Calculatrice Pro (PHP)</h2>
                <div class="flex">
                    <button class="btn" id="themeToggle" type="button">Thème: <span id="themeLabel">Clair</span></button>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="panel" style="border-color: var(--danger); color: #fff; background: rgba(239,68,68,0.15);">
                    ⚠️ Erreur: <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <section class="panel" style="padding:16px;">
                <div class="tabs" role="tablist">
                    <div class="tab active" data-tab="calc">Calcul</div>
                    <div class="tab" data-tab="convert">Conversion</div>
                    <div class="tab" data-tab="graph">Graphique</div>
                </div>
                <div id="tab-calc">
                    <form method="post" class="calc-form" id="calcForm">
                        <input type="hidden" name="action" value="calculate">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Csrf::getToken()); ?>">
                        <div class="input-row">
                            <input type="text" name="expression" id="expression" placeholder="Ex: 2*sin(3.14/2) + sqrt(9) - log(100)" value="<?php echo htmlspecialchars($expression); ?>">
                            <div class="flex">
                                <button class="btn" type="button" id="micBtn" title="Reconnaissance vocale">🎤</button>
                                <button class="btn primary" type="submit">Calculer</button>
                            </div>
                        </div>
                        <div class="keypad" style="margin-top:8px;">
                            <?php
                            $keys = ['7','8','9','/','(',')','4','5','6','*','^',',','1','2','3','-','sin','cos','0','.','=','+','tan','sqrt','log','ln','pow'];
                            foreach ($keys as $k) {
                                echo '<button class="btn" type="button" onclick="insertKey(\'' . $k . '\')">' . $k . '</button>';
                            }
                            ?>
                            <button class="btn danger" type="button" onclick="clearExpr()">C</button>
                        </div>
                        <div class="grid-2" style="margin-top:8px;">
                            <div>
                                <label class="muted">Annotation (optionnelle)</label>
                                <textarea name="note" placeholder="Votre note..."></textarea>
                            </div>
                            <div>
                                <label class="muted">Lien de partage</label>
                                <div class="share-box" id="shareBox"><?php echo $shareUrl ? htmlspecialchars($shareUrl) : '—'; ?></div>
                                <div class="flex" style="margin-top:6px;">
                                    <button class="btn" type="button" onclick="copyShare()">Copier le lien</button>
                                    <button class="btn" type="button" onclick="reuseResult()">Réutiliser le résultat</button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <div style="margin-top:12px;">
                        <div class="muted">Résultat</div>
                        <div class="result" id="resultView"><?php echo $result !== null ? htmlspecialchars((string)$result) : '—'; ?></div>
                    </div>

                    <?php if ($explain): ?>
                    <details open style="margin-top:12px;">
                        <summary>Explication étape par étape</summary>
                        <div class="grid-2" style="margin-top:8px;">
                            <div>
                                <div class="muted">Shunting-yard</div>
                                <ol>
                                    <?php foreach (($explain['shunting_steps'] ?? []) as $s): ?>
                                        <li><?php echo htmlspecialchars($s); ?></li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                            <div>
                                <div class="muted">Évaluation RPN</div>
                                <ol>
                                    <?php foreach (($explain['eval_steps'] ?? []) as $s): ?>
                                        <li><?php echo htmlspecialchars($s); ?></li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>

                <div id="tab-convert" style="display:none;">
                    <form method="post">
                        <input type="hidden" name="action" value="convert">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Csrf::getToken()); ?>">
                        <div class="grid-2">
                            <div>
                                <label>Catégorie</label>
                                <select name="category" id="categorySelect" onchange="updateUnits()">
                                    <option value="length">Longueur</option>
                                    <option value="currency">Devise</option>
                                </select>
                            </div>
                            <div>
                                <label>Valeur</label>
                                <input type="number" step="any" name="value" value="1">
                            </div>
                        </div>
                        <div class="grid-2" style="margin-top:8px;">
                            <div>
                                <label>De</label>
                                <select name="from" id="fromUnit"></select>
                            </div>
                            <div>
                                <label>Vers</label>
                                <select name="to" id="toUnit"></select>
                            </div>
                        </div>
                        <div style="margin-top:8px;">
                            <button class="btn success" type="submit">Convertir</button>
                        </div>
                    </form>
                </div>

                <div id="tab-graph" style="display:none;">
                    <div class="grid-2">
                        <div>
                            <label>f(x)</label>
                            <input type="text" id="fx" placeholder="Ex: sin(x) + 0.5*x">
                        </div>
                        <div class="grid-2">
                            <div>
                                <label>xmin</label>
                                <input type="number" step="any" id="xmin" value="-10">
                            </div>
                            <div>
                                <label>xmax</label>
                                <input type="number" step="any" id="xmax" value="10">
                            </div>
                        </div>
                    </div>
                    <div class="flex" style="margin-top:8px;">
                        <button class="btn primary" type="button" onclick="plot()">Tracer</button>
                        <div class="muted">Utilise Chart.js (client)</div>
                    </div>
                    <div style="margin-top:12px;">
                        <canvas id="chart" height="220"></canvas>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="muted">Fonctions prises en charge (PHP natif)</div>
                <ul>
                    <li><strong>Basiques</strong>: +, -, *, /, ^, parenthèses, nombres décimaux</li>
                    <li><strong>Avancées</strong>: sin(x), cos(x), tan(x), sqrt(x), log(x)=log10, ln(x), pow(a,b)</li>
                    <li><strong>Graphique</strong>: f(x) côté client (Chart.js)</li>
                </ul>
            </section>
        </main>
    </div>

    <script src="/assets/app.js"></script>
</body>
</html>

