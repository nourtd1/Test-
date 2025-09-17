<?php
session_start();

// Initialize session storage
if (!isset($_SESSION['history'])) {
    $_SESSION['history'] = [];
}

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
// Math Parser (Shunting-yard + RPN evaluator)
// Supports: + - * / ^, parentheses, unary -, functions: sin, cos, tan, sqrt, log (base10), ln (natural), pow(a,b)
// All trig in radians
// ------------------------------
class MathParser {
    private array $operators = [
        '+' => ['precedence' => 2, 'assoc' => 'L', 'arity' => 2],
        '-' => ['precedence' => 2, 'assoc' => 'L', 'arity' => 2],
        '*' => ['precedence' => 3, 'assoc' => 'L', 'arity' => 2],
        '/' => ['precedence' => 3, 'assoc' => 'L', 'arity' => 2],
        '^' => ['precedence' => 4, 'assoc' => 'R', 'arity' => 2],
        'u-' => ['precedence' => 5, 'assoc' => 'R', 'arity' => 1], // unary minus
    ];

    private array $functions = ['sin', 'cos', 'tan', 'sqrt', 'log', 'ln', 'pow'];

    public function tokenize(string $expr): array {
        $expr = trim($expr);
        // Normalize unicode minus to ascii
        $expr = str_replace(["\xE2\x88\x92"], ['-'], $expr);
        $tokens = [];
        $len = strlen($expr);
        $i = 0;
        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) { $i++; continue; }
            // recognize constant pi
            if (($ch === 'p' || $ch === 'P') && $i+1 < $len && ($expr[$i+1] === 'i' || $expr[$i+1] === 'I')) {
                $tokens[] = ['type' => 'number', 'value' => (string)M_PI];
                $i += 2; continue;
            }
            if (ctype_digit($ch) || ($ch === '.' && $i+1 < $len && ctype_digit($expr[$i+1]))) {
                $num = $ch; $i++;
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) { $num .= $expr[$i++]; }
                $tokens[] = ['type' => 'number', 'value' => $num];
                continue;
            }
            if (ctype_alpha($ch)) {
                $ident = $ch; $i++;
                while ($i < $len && (ctype_alpha($expr[$i]) || ctype_digit($expr[$i]) || $expr[$i] === '_')) { $ident .= $expr[$i++]; }
                $ident = strtolower($ident);
                $tokens[] = ['type' => 'ident', 'value' => $ident];
                continue;
            }
            if (in_array($ch, ['+', '-', '*', '/', '^', '(', ')', ','])) {
                $tokens[] = ['type' => 'sym', 'value' => $ch];
                $i++;
                continue;
            }
            throw new Exception('Unexpected character: ' . $ch);
        }
        return $tokens;
    }

    public function toRPN(array $tokens, array &$steps = []): array {
        $output = [];
        $stack = [];
        $prevType = null;
        $expectUnary = true; // At start, a leading '-' is unary
        foreach ($tokens as $tok) {
            if ($tok['type'] === 'number') {
                $output[] = $tok;
                $steps[] = 'Push number ' . $tok['value'];
                $expectUnary = false;
            } elseif ($tok['type'] === 'ident') {
                if (!in_array($tok['value'], $this->functions, true)) {
                    throw new Exception('Unknown function: ' . $tok['value']);
                }
                $stack[] = ['type' => 'func', 'value' => $tok['value']];
                $steps[] = 'Push function ' . $tok['value'];
                $expectUnary = true;
            } elseif ($tok['type'] === 'sym') {
                $sym = $tok['value'];
                if ($sym === ',') {
                    while (!empty($stack) && end($stack)['value'] !== '(') {
                        $output[] = array_pop($stack);
                    }
                    if (empty($stack)) { throw new Exception('Misplaced comma'); }
                    $expectUnary = true;
                } elseif ($sym === '(') {
                    $stack[] = ['type' => 'sym', 'value' => '('];
                    $steps[] = 'Push ( to stack';
                    $expectUnary = true;
                } elseif ($sym === ')') {
                    while (!empty($stack) && end($stack)['value'] !== '(') {
                        $output[] = array_pop($stack);
                    }
                    if (empty($stack)) { throw new Exception('Mismatched parentheses'); }
                    array_pop($stack); // pop '('
                    // if top is a function, pop it too
                    if (!empty($stack) && end($stack)['type'] === 'func') {
                        $output[] = array_pop($stack);
                    }
                    $steps[] = 'Resolve ) to output';
                    $expectUnary = false;
                } else { // operator
                    $op = $sym;
                    // Determine unary minus using expectation flag
                    if ($op === '-' && $expectUnary) { $op = 'u-'; }
                    $o1 = $this->operators[$op] ?? null;
                    if (!$o1) { throw new Exception('Unknown operator: ' . $op); }
                    while (!empty($stack)) {
                        $top = end($stack);
                        if ($top['type'] === 'op') {
                            $o2 = $this->operators[$top['value']];
                            $cond = ($o1['assoc'] === 'L' && $o1['precedence'] <= $o2['precedence']) || ($o1['assoc'] === 'R' && $o1['precedence'] < $o2['precedence']);
                            if ($cond) { $output[] = array_pop($stack); continue; }
                        }
                        break;
                    }
                    $stack[] = ['type' => 'op', 'value' => $op];
                    $steps[] = 'Push operator ' . $op;
                    $expectUnary = true;
                }
            }
            $prevType = $tok['type'];
        }
        while (!empty($stack)) {
            $top = array_pop($stack);
            if ($top['value'] === '(' || $top['value'] === ')') { throw new Exception('Mismatched parentheses'); }
            $output[] = $top;
        }
        return $output;
    }

    public function evalRPN(array $rpn, array &$steps = []): float {
        $stack = [];
        foreach ($rpn as $tok) {
            if ($tok['type'] === 'number') {
                $stack[] = (float)$tok['value'];
            } elseif (($tok['type'] === 'op') || ($tok['type'] === 'sym' && isset($this->operators[$tok['value']]))) {
                $op = $tok['value'];
                if ($op === 'u-') {
                    if (count($stack) < 1) { throw new Exception('Not enough operands for unary -'); }
                    $a = array_pop($stack);
                    $res = -$a;
                    $stack[] = $res;
                    $steps[] = "u- $a => $res";
                } else {
                    if (count($stack) < 2) { throw new Exception('Not enough operands for ' . $op); }
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    switch ($op) {
                        case '+': $res = $a + $b; break;
                        case '-': $res = $a - $b; break;
                        case '*': $res = $a * $b; break;
                        case '/': if ($b == 0.0) { throw new Exception('Division by zero'); } $res = $a / $b; break;
                        case '^': $res = pow($a, $b); break;
                        default: throw new Exception('Unknown operator ' . $op);
                    }
                    $stack[] = $res;
                    $steps[] = "$a $op $b => $res";
                }
            } elseif ($tok['type'] === 'func') {
                $fn = $tok['value'];
                switch ($fn) {
                    case 'sin': case 'cos': case 'tan':
                        if (count($stack) < 1) { throw new Exception('Not enough args for ' . $fn); }
                        $a = array_pop($stack);
                        $res = $fn($a);
                        $steps[] = "$fn($a) => $res";
                        $stack[] = $res;
                        break;
                    case 'sqrt':
                        if (count($stack) < 1) { throw new Exception('Not enough args for sqrt'); }
                        $a = array_pop($stack);
                        if ($a < 0) { throw new Exception('Sqrt of negative'); }
                        $res = sqrt($a);
                        $steps[] = "sqrt($a) => $res";
                        $stack[] = $res;
                        break;
                    case 'log':
                        if (count($stack) < 1) { throw new Exception('Not enough args for log'); }
                        $a = array_pop($stack);
                        if ($a <= 0) { throw new Exception('Log base10 of non-positive'); }
                        $res = log10($a);
                        $steps[] = "log10($a) => $res";
                        $stack[] = $res;
                        break;
                    case 'ln':
                        if (count($stack) < 1) { throw new Exception('Not enough args for ln'); }
                        $a = array_pop($stack);
                        if ($a <= 0) { throw new Exception('Ln of non-positive'); }
                        $res = log($a);
                        $steps[] = "ln($a) => $res";
                        $stack[] = $res;
                        break;
                    case 'pow':
                        if (count($stack) < 2) { throw new Exception('Not enough args for pow'); }
                        $b = array_pop($stack);
                        $a = array_pop($stack);
                        $res = pow($a, $b);
                        $steps[] = "pow($a,$b) => $res";
                        $stack[] = $res;
                        break;
                    default:
                        throw new Exception('Unknown function ' . $fn);
                }
            } else {
                throw new Exception('Invalid token in RPN');
            }
        }
        if (count($stack) !== 1) { throw new Exception('Invalid expression'); }
        return (float)$stack[0];
    }

    public function explain(string $expr): array {
        $steps = [];
        $tokens = $this->tokenize($expr);
        $rpn = $this->toRPN($tokens, $steps);
        $evalSteps = [];
        $result = $this->evalRPN($rpn, $evalSteps);
        return [
            'tokens' => $tokens,
            'rpn' => $rpn,
            'shunting_steps' => $steps,
            'eval_steps' => $evalSteps,
            'result' => $result
        ];
    }
}

function evaluate_expression(string $expr, array &$debug = []): float {
    $parser = new MathParser();
    $info = $parser->explain($expr);
    $debug = $info; // include tokens, RPN and steps
    return (float)$info['result'];
}

// ------------------------------
// Conversions
// ------------------------------
function convert_units(string $category, string $from, string $to, float $value): array {
    // Returns [convertedValue, note]
    switch ($category) {
        case 'length':
            // base = meter
            $map = [ 'km' => 1000.0, 'm' => 1.0, 'cm' => 0.01, 'mm' => 0.001 ];
            if (!isset($map[$from]) || !isset($map[$to])) throw new Exception('Unit not supported');
            $base = $value * $map[$from];
            $res = $base / $map[$to];
            return [$res, 'Conversion length (base meter)'];
        case 'currency':
            // Simple fixed rate for demo. In real usage, call an API.
            $rates = [
                'EUR' => 1.0,
                'USD' => 1.08,
                'GBP' => 0.85,
            ];
            if (!isset($rates[$from]) || !isset($rates[$to])) throw new Exception('Currency not supported');
            $eurBase = $value / $rates[$from];
            $res = $eurBase * $rates[$to];
            return [$res, 'Static FX rate for demo'];
        default:
            throw new Exception('Unknown category');
    }
}

// ------------------------------
// Handle actions
// ------------------------------
$error = null;
$result = null;
$explain = null;
$expression = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'calculate') {
            $expression = trim($_POST['expression'] ?? '');
            $note = trim($_POST['note'] ?? '');
            if ($expression === '') { throw new Exception('Expression vide'); }
            $debug = [];
            $value = evaluate_expression($expression, $debug);
            $result = $value;
            $explain = $debug;
            $entry = [
                'type' => 'calc',
                'expression' => $expression,
                'result' => $result,
                'note' => $note,
                'time' => date('Y-m-d H:i:s')
            ];
            $_SESSION['history'][] = $entry;
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
    // GET share link: ?q=...
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

// Prepare share URL
$shareUrl = '';
if (!empty($expression)) {
    $shareUrl = current_base_url() . '?q=' . urlencode($expression);
}

// Limit history length to 100
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --accent: #3b82f6;
            --accent-2: #10b981;
            --danger: #ef4444;
            --border: #1f2937;
            --btn: #1f2937;
        }
        [data-theme="light"] {
            --bg: #f8fafc;
            --panel: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --accent: #2563eb;
            --accent-2: #059669;
            --danger: #dc2626;
            --border: #e5e7eb;
            --btn: #f1f5f9;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, 'Apple Color Emoji', 'Segoe UI Emoji';
        }
        .app {
            display: grid; grid-template-columns: 320px 1fr; gap: 16px; padding: 16px; max-width: 1280px; margin: 0 auto;
        }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
        .history { overflow: auto; max-height: calc(100vh - 32px); }
        .history h2 { margin-top: 0; font-size: 18px; }
        .history-item { padding: 10px; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 10px; background: rgba(0,0,0,0.02); cursor: pointer; }
        .history-item:hover { border-color: var(--accent); }
        .tag { font-size: 12px; color: var(--muted); }
        .calculator {
            display: grid; grid-template-columns: 1fr; gap: 16px;
        }
        .input-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
        input[type=text], input[type=number], select, textarea {
            width: 100%; padding: 12px 14px; border: 1px solid var(--border); background: var(--btn);
            color: var(--text); border-radius: 10px; outline: none; font-size: 16px;
        }
        textarea { min-height: 48px; resize: vertical; }
        .btn {
            padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--btn); color: var(--text);
            cursor: pointer; transition: transform 0.02s ease, background 0.2s, border-color 0.2s;
        }
        .btn:hover { border-color: var(--accent); }
        .btn:active { transform: translateY(1px); }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: white; }
        .btn.success { background: var(--accent-2); border-color: var(--accent-2); color: white; }
        .btn.danger { background: var(--danger); border-color: var(--danger); color: white; }
        .keypad { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; }
        .result { font-size: 24px; font-weight: 700; }
        .flex { display: flex; gap: 8px; align-items: center; }
        .space-between { display: flex; justify-content: space-between; align-items: center; }
        .muted { color: var(--muted); }
        .tabs { display: flex; gap: 8px; }
        .tab { padding: 8px 12px; border: 1px solid var(--border); border-radius: 999px; cursor: pointer; }
        .tab.active { background: var(--accent); color: #fff; border-color: var(--accent); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .share-box { word-break: break-all; background: var(--btn); padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); }
        @media (max-width: 980px) {
            .app { grid-template-columns: 1fr; }
            .history { max-height: unset; }
        }
    </style>
</head>
<body>
    <div class="app">
        <aside class="panel history">
            <div class="space-between">
                <h2>Historique</h2>
                <form method="post" onsubmit="return confirm('Vider l\'historique ?');">
                    <input type="hidden" name="action" value="clear_history">
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

    <script>
        // Tabs
        const tabs = document.querySelectorAll('.tab');
        tabs.forEach(t => t.addEventListener('click', () => {
            tabs.forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            const id = t.dataset.tab;
            document.getElementById('tab-calc').style.display = id === 'calc' ? '' : 'none';
            document.getElementById('tab-convert').style.display = id === 'convert' ? '' : 'none';
            document.getElementById('tab-graph').style.display = id === 'graph' ? '' : 'none';
        }));

        // Theme toggle
        const htmlEl = document.documentElement;
        const themeLabel = document.getElementById('themeLabel');
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) { htmlEl.setAttribute('data-theme', savedTheme); themeLabel.textContent = savedTheme === 'dark' ? 'Sombre' : 'Clair'; }
        document.getElementById('themeToggle').addEventListener('click', () => {
            const cur = htmlEl.getAttribute('data-theme') || 'light';
            const next = cur === 'light' ? 'dark' : 'light';
            htmlEl.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
            themeLabel.textContent = next === 'dark' ? 'Sombre' : 'Clair';
        });

        // Keypad helpers
        function insertKey(k) {
            const el = document.getElementById('expression');
            const start = el.selectionStart, end = el.selectionEnd;
            let txt = el.value;
            if (k === '=') { document.getElementById('calcForm').submit(); return; }
            const insert = (['sin','cos','tan','sqrt','log','ln','pow'].includes(k)) ? (k + '(') : k;
            el.value = txt.substring(0, start) + insert + txt.substring(end);
            const caret = start + insert.length;
            el.setSelectionRange(caret, caret);
            el.focus();
            updateShare();
        }
        function clearExpr() { const el = document.getElementById('expression'); el.value=''; updateShare(); }
        function useExpression(expr) { const el = document.getElementById('expression'); el.value = expr; updateShare(); window.scrollTo({top:0, behavior:'smooth'}); }
        function reuseResult() {
            const r = document.getElementById('resultView').textContent.trim();
            if (r && r !== '—') { useExpression(r); }
        }

        // Share link
        function updateShare() {
            const expr = document.getElementById('expression').value;
            const base = '<?php echo htmlspecialchars(current_base_url()); ?>';
            const url = expr ? base + '?q=' + encodeURIComponent(expr) : '—';
            document.getElementById('shareBox').textContent = url;
        }
        function copyShare() {
            const text = document.getElementById('shareBox').textContent;
            if (text && text !== '—') { navigator.clipboard.writeText(text); }
        }
        updateShare();

        // Conversion units
        const units = {
            length: ['km','m','cm','mm'],
            currency: ['EUR','USD','GBP']
        };
        function updateUnits() {
            const cat = document.getElementById('categorySelect').value;
            const from = document.getElementById('fromUnit');
            const to = document.getElementById('toUnit');
            from.innerHTML=''; to.innerHTML='';
            units[cat].forEach(u => {
                const o1 = document.createElement('option'); o1.value=o1.textContent=u; from.appendChild(o1);
                const o2 = document.createElement('option'); o2.value=o2.textContent=u; to.appendChild(o2);
            });
            if (cat==='length') { from.value='m'; to.value='cm'; } else { from.value='EUR'; to.value='USD'; }
        }
        updateUnits();

        // Chart.js plotting (very simple evaluator using Function and Math.* in JS)
        let chart;
        function jsEvalExpr(expr, x) {
            // Limited sandbox using Math functions; replace common names to Math.*
            const safe = expr
                .replace(/sin/g, 'Math.sin')
                .replace(/cos/g, 'Math.cos')
                .replace(/tan/g, 'Math.tan')
                .replace(/sqrt/g, 'Math.sqrt')
                .replace(/log10/g, 'Math.log10')
                .replace(/log/g, 'Math.log10')
                .replace(/ln/g, 'Math.log')
                .replace(/pi/gi, 'Math.PI');
            try { return Function('x', 'return ' + safe)(x); } catch { return NaN; }
        }
        function plot() {
            const expr = document.getElementById('fx').value.trim();
            const xmin = parseFloat(document.getElementById('xmin').value);
            const xmax = parseFloat(document.getElementById('xmax').value);
            if (!expr) return;
            const N = 200;
            const xs = []; const ys = [];
            const step = (xmax - xmin) / (N - 1);
            for (let i=0;i<N;i++) {
                const x = xmin + i*step; const y = jsEvalExpr(expr, x);
                xs.push(x.toFixed(2)); ys.push(isFinite(y) ? y : null);
            }
            const ctx = document.getElementById('chart').getContext('2d');
            if (chart) chart.destroy();
            chart = new Chart(ctx, {
                type: 'line',
                data: { labels: xs, datasets: [{ label: 'f(x)', data: ys, borderColor: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(), tension: 0.25, pointRadius: 0 }] },
                options: { responsive: true, scales: { x: { display: false } } }
            });
        }

        // Web Speech API (optional)
        const micBtn = document.getElementById('micBtn');
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            micBtn.disabled = true; micBtn.title = 'Non supporté par ce navigateur';
        } else {
            const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
            const rec = new Rec(); rec.lang = 'fr-FR'; rec.interimResults = false; rec.maxAlternatives = 1;
            micBtn.addEventListener('click', () => { try { rec.start(); } catch(e){} });
            rec.onresult = (e) => {
                const text = e.results[0][0].transcript;
                // basic replacements: "fois"->*, "sur"->/
                let expr = text.toLowerCase().replaceAll('pi', '3.1415926535');
                expr = expr.replaceAll('plus', '+').replaceAll('moins', '-').replaceAll('fois', '*').replaceAll('multiplié par', '*').replaceAll('divisé par', '/');
                expr = expr.replaceAll('virgule', '.');
                useExpression(expr);
            };
        }
    </script>
</body>
</html>


