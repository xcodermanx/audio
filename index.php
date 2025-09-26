<?php
declare(strict_types=1);

session_start();

const OUTPUT_DIR = __DIR__ . '/output';
const DEFAULT_FILENAME_PREFIX = 'tts_';

if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0775, true);
}

$models = [
    'gpt-4o-mini-tts' => 'gpt-4o-mini-tts',
    'gpt-4o-audio-preview' => 'gpt-4o-audio-preview',
    'gpt-4o-mini-tts-stereo' => 'gpt-4o-mini-tts-stereo',
];

$voices = [
    'alloy' => 'Alloy',
    'ballad' => 'Ballad',
    'echo' => 'Echo',
    'fable' => 'Fable',
    'onyx' => 'Onyx',
    'sage' => 'Sage',
    'sol' => 'Sol',
    'verse' => 'Verse',
];

$messages = [];
$errors = [];
$customVoiceUsed = false;

if (!isset($_SESSION['api_key'])) {
    $_SESSION['api_key'] = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim($_POST['api_key'] ?? '');
    $model = $_POST['model'] ?? '';
    $voice = trim($_POST['voice'] ?? '');
    $inputText = trim($_POST['text'] ?? '');
    $fileName = trim($_POST['file_name'] ?? '');
    $rememberKey = isset($_POST['remember_key']);

    if ($apiKey === '') {
        $errors[] = 'Введите API ключ OpenAI.';
    }
    if ($inputText === '') {
        $errors[] = 'Введите текст для озвучивания.';
    }
    if (!array_key_exists($model, $models)) {
        $errors[] = 'Выберите корректную модель.';
    }
    if ($voice === '') {
        $errors[] = 'Укажите голос для генерации.';
    } elseif (!preg_match('/^[a-z0-9_-]+$/i', $voice)) {
        $errors[] = 'Голос может содержать только латинские буквы, цифры, дефис и подчёркивание.';
    } elseif (!array_key_exists($voice, $voices)) {
        $customVoiceUsed = true;
    }

    if ($rememberKey) {
        $_SESSION['api_key'] = $apiKey;
    } elseif ($apiKey !== '' && $_SESSION['api_key'] !== $apiKey) {
        $_SESSION['api_key'] = '';
    }

    if (!$errors) {
        if ($fileName === '') {
            $fileName = DEFAULT_FILENAME_PREFIX . date('Ymd_His');
        }
        $fileName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $fileName);
        $filePath = OUTPUT_DIR . '/' . $fileName . '.mp3';

        $payload = [
            'model' => $model,
            'voice' => $voice,
            'input' => $inputText,
            'format' => 'mp3',
        ];

        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: audio/mpeg',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $errorMessage = 'Не удалось получить аудио от OpenAI.';
            if ($curlError) {
                $errorMessage .= ' Ошибка cURL: ' . $curlError;
            } elseif ($response !== false) {
                $decoded = json_decode($response, true);
                if (isset($decoded['error']['message'])) {
                    $errorMessage .= ' Сообщение сервиса: ' . $decoded['error']['message'];
                }
            }
            $errors[] = $errorMessage;
        } else {
            $bytes = file_put_contents($filePath, $response);
            if ($bytes === false) {
                $errors[] = 'Не удалось сохранить MP3 файл.';
            } else {
                $messages[] = 'Аудио успешно сохранено: ' . basename($filePath);
                if ($customVoiceUsed) {
                    $messages[] = 'Использован пользовательский голос «' . $voice . '». Убедитесь, что он поддерживается выбранной моделью.';
                }
            }
        }
    }
}

$storedFiles = array_values(array_filter(
    scandir(OUTPUT_DIR) ?: [],
    static function (string $item): bool {
        return $item !== '.' && $item !== '..' && preg_match('/\.mp3$/i', $item);
    }
));
rsort($storedFiles);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenAI TTS Studio (PHP)</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>OpenAI TTS Studio</h1>
            <p>Генерация речи из текста через OpenAI API на PHP.</p>
        </header>

        <?php if ($messages): ?>
            <div class="alert success">
                <?php foreach ($messages as $message): ?>
                    <p><?= h($message) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="form-section">
            <form method="post">
                <div class="field">
                    <label for="api_key">API ключ OpenAI</label>
                    <input type="password" id="api_key" name="api_key" value="<?= h($_SESSION['api_key']) ?>" placeholder="sk-..." required>
                    <label class="remember">
                        <input type="checkbox" name="remember_key" value="1" <?= $_SESSION['api_key'] ? 'checked' : '' ?>>
                        Запомнить ключ в сессии
                    </label>
                </div>

                <div class="field-grid">
                    <div class="field">
                        <label for="model">Модель</label>
                        <select id="model" name="model" required>
                            <option value="" disabled <?= isset($model) && $model === '' ? 'selected' : '' ?>>Выберите модель</option>
                            <?php foreach ($models as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= (isset($model) && $model === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="voice">Голос</label>
                        <input type="text" id="voice" name="voice" list="voice-options" value="<?= h($voice ?? '') ?>" placeholder="например, alloy" required>
                        <datalist id="voice-options">
                            <?php foreach ($voices as $value => $label): ?>
                                <option value="<?= h($value) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <p class="muted">Рекомендуемые голоса: alloy, ballad, echo, fable, onyx, sage, sol, verse. Можно ввести новое значение вручную.</p>
                    </div>
                </div>

                <div class="field">
                    <label for="file_name">Имя файла (без расширения)</label>
                    <input type="text" id="file_name" name="file_name" value="<?= h($fileName ?? '') ?>" placeholder="tts_my_phrase">
                </div>

                <div class="field">
                    <label for="text">Текст для генерации</label>
                    <textarea id="text" name="text" rows="6" placeholder="Введите текст..." required><?= h($inputText ?? '') ?></textarea>
                </div>

                <button type="submit" class="submit-button">Сгенерировать аудио</button>
            </form>
        </section>

        <section class="files-section">
            <h2>Сохранённые MP3</h2>
            <?php if (!$storedFiles): ?>
                <p class="muted">Файлы ещё не созданы.</p>
            <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($storedFiles as $file): ?>
                        <li>
                            <span><?= h($file) ?></span>
                            <a class="download" href="output/<?= rawurlencode($file) ?>" download>Скачать</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <footer>
            <p class="muted">API ключ хранится только в сессии браузера и не записывается на диск.</p>
        </footer>
    </div>
</body>
</html>
