<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DeforumationQT JS Console</title>
    @vite(['resources/css/deforumation-qt.css', 'resources/js/deforumation-qt.js'])
</head>
<body>
    <div id="deforumation-app" class="container">
        <header>
            <h1>DeforumationQT</h1>
            <p class="subtitle">JavaScript console for steering Deforum runs in real time.</p>
        </header>

        <section class="panel">
            <h2>Authentication</h2>
            <label for="api-token">API Token</label>
            <input id="api-token" type="text" placeholder="Paste your JWT" autocomplete="off">
            <small>Token is only stored locally in your browser for this session.</small>
        </section>

        <section class="panel grid">
            <div>
                <h2>Kick off / steer Deforum</h2>
                <label for="video-id">Video Job ID</label>
                <input id="video-id" type="number" placeholder="Existing upload id">

                <label for="prompt">Prompt</label>
                <textarea id="prompt" rows="3" placeholder="Describe the motion or scene"></textarea>

                <label for="negative-prompt">Negative Prompt</label>
                <textarea id="negative-prompt" rows="2" placeholder="What to avoid"></textarea>

                <div class="inline">
                    <div>
                        <label for="length">Length (seconds)</label>
                        <input id="length" type="number" min="1" step="1" value="4">
                    </div>
                    <div>
                        <label for="fps">FPS</label>
                        <input id="fps" type="number" min="1" step="1" value="24">
                    </div>
                    <div>
                        <label for="seed">Seed</label>
                        <input id="seed" type="number" min="-1" step="1" value="-1">
                    </div>
                </div>

                <button id="submit-deforum" type="button">Send to Deforum</button>
                <p class="hint">Submits to <code>/api/generate</code> with <code>type=deforum</code> so you can iterate quickly.</p>
            </div>

            <div>
                <h2>Live control</h2>
                <label for="follow-id">Follow Job ID</label>
                <input id="follow-id" type="number" placeholder="Job to monitor" />
                <div class="inline">
                    <button id="poll-status" type="button">Refresh status</button>
                    <button id="poll-queue" type="button">Refresh queue</button>
                </div>
                <pre id="status-log" class="log" aria-live="polite"></pre>
            </div>
        </section>

        <section class="panel">
            <h2>Processing overview</h2>
            <div id="processing-metrics" class="metrics"></div>
            <div class="flex">
                <div class="stack">
                    <h3>Currently processing</h3>
                    <ul id="processing-list"></ul>
                </div>
                <div class="stack">
                    <h3>Your queue</h3>
                    <ul id="queue-list"></ul>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
