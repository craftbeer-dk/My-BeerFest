<?php
if (!defined('BEERFEST_GATE_RENDER')) {
    header('Location: /');
    exit;
}

$festivalDateTs = $festivalDate !== '' ? strtotime($festivalDate) : false;
$renderCountdown = $showCountdown && $festivalDateTs !== false && $festivalDateTs > time();
$messageTemplate = $translations['coming_soon_message']
    ?? 'The beer for this year\'s %s is still brewing — please check back later.';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo htmlspecialchars($themeColor); ?>">
    <title><?php echo htmlspecialchars($festivalTitle); ?></title>

    <link rel="stylesheet" href="dist/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo file_exists(__DIR__ . '/custom/theme.css') ? 'custom/theme.css' : 'config/theme.css'; ?>">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            background-color: var(--background-color);
            color: var(--text-color);
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        .coming-soon-card {
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .coming-soon-message {
            font-size: 1.125rem;
            color: var(--card-paragraph-color);
            margin: 0 auto;
            max-width: 42rem;
        }

        .countdown-wrapper {
            margin-top: 2rem;
        }

        .countdown-heading {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--card-heading-color);
            margin-bottom: 1rem;
        }

        .countdown-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            max-width: 36rem;
            margin: 0 auto;
        }

        @media (min-width: 768px) {
            .countdown-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        .countdown-box {
            background-color: var(--card-background-color);
            border: 1px solid var(--card-border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .countdown-number {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--card-heading-color);
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }

        .countdown-label {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--label-color);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="text-4xl font-semibold text-center mb-6 p-4 rounded-lg shadow-lg"
            style="background-color: var(--title-bg-color); color: var(--title-text-color);">
            <?php echo htmlspecialchars($festivalTitle); ?>
        </h1>

        <div class="coming-soon-card">
            <p class="coming-soon-message">
                <?php echo htmlspecialchars(sprintf($messageTemplate, $festivalTitle)); ?>
            </p>

            <?php if ($renderCountdown): ?>
            <div class="countdown-wrapper" id="countdown" data-festival-date="<?php echo htmlspecialchars(date('c', $festivalDateTs)); ?>">
                <h2 class="countdown-heading">
                    <?php echo htmlspecialchars($translations['coming_soon_countdown_heading'] ?? 'Countdown to the festival'); ?>
                </h2>
                <div class="countdown-grid">
                    <div class="countdown-box">
                        <div class="countdown-number" data-unit="days">0</div>
                        <div class="countdown-label"><?php echo htmlspecialchars($translations['coming_soon_days'] ?? 'Days'); ?></div>
                    </div>
                    <div class="countdown-box">
                        <div class="countdown-number" data-unit="hours">0</div>
                        <div class="countdown-label"><?php echo htmlspecialchars($translations['coming_soon_hours'] ?? 'Hours'); ?></div>
                    </div>
                    <div class="countdown-box">
                        <div class="countdown-number" data-unit="minutes">0</div>
                        <div class="countdown-label"><?php echo htmlspecialchars($translations['coming_soon_minutes'] ?? 'Minutes'); ?></div>
                    </div>
                    <div class="countdown-box">
                        <div class="countdown-number" data-unit="seconds">0</div>
                        <div class="countdown-label"><?php echo htmlspecialchars($translations['coming_soon_seconds'] ?? 'Seconds'); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($renderCountdown): ?>
    <script>
        (function () {
            const wrapper = document.getElementById('countdown');
            if (!wrapper) return;
            const target = new Date(wrapper.dataset.festivalDate).getTime();
            if (Number.isNaN(target)) { wrapper.style.display = 'none'; return; }

            const units = {
                days:    wrapper.querySelector('[data-unit="days"]'),
                hours:   wrapper.querySelector('[data-unit="hours"]'),
                minutes: wrapper.querySelector('[data-unit="minutes"]'),
                seconds: wrapper.querySelector('[data-unit="seconds"]'),
            };

            function tick() {
                const diff = target - Date.now();
                if (diff <= 0) {
                    wrapper.style.display = 'none';
                    clearInterval(timer);
                    return;
                }
                const d = Math.floor(diff / 86400000);
                const h = Math.floor((diff % 86400000) / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                units.days.textContent = d;
                units.hours.textContent = String(h).padStart(2, '0');
                units.minutes.textContent = String(m).padStart(2, '0');
                units.seconds.textContent = String(s).padStart(2, '0');
            }

            tick();
            const timer = setInterval(tick, 1000);
        })();
    </script>
    <?php endif; ?>

    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(rs => rs.forEach(r => r.unregister()));
        }
    </script>
</body>
</html>
