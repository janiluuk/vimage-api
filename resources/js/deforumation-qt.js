import './bootstrap';

const $ = (selector) => document.querySelector(selector);

const state = {
    token: localStorage.getItem('deforumation-token') || '',
};

const headers = () => (
    state.token
        ? { Authorization: `Bearer ${state.token}` }
        : {}
);

const statusLog = $('#status-log');
const apiTokenInput = $('#api-token');

const formatSeconds = (seconds) => `${seconds ?? 0}s`;

function writeLog(message) {
    const timestamp = new Date().toISOString();
    statusLog.textContent = `[${timestamp}] ${message}\n${statusLog.textContent}`;
}

function renderMetrics(payload) {
    const metrics = $('#processing-metrics');
    const processing = payload?.counts?.processing ?? 0;
    const queued = payload?.counts?.queued ?? 0;
    metrics.innerHTML = `
        <span class="pill">Processing: ${processing}</span>
        <span class="pill">Queued: ${queued}</span>
    `;
}

function renderJobList(containerSelector, jobs) {
    const target = $(containerSelector);
    target.innerHTML = '';

    jobs.forEach((job) => {
        const card = document.createElement('li');
        card.className = 'card';
        card.innerHTML = `
            <div class="title">Job #${job.id} · ${job.generator ?? 'vid2vid'}</div>
            <div class="meta">Status: ${job.status} · Progress: ${job.progress ?? 0}%</div>
            <div class="meta">ETA: ${formatSeconds(job.estimated_time_left)} · Frames: ${job.frame_count ?? 0}</div>
            <div class="progress">${job.prompt ?? ''}</div>
        `;

        if (job.queue && Object.keys(job.queue).length > 0) {
            const queueInfo = document.createElement('div');
            queueInfo.className = 'meta';
            queueInfo.textContent = `Queue position: ${job.queue.your_position ?? '—'} · Your ETA: ${formatSeconds(job.queue.your_estimated_time)}`;
            card.appendChild(queueInfo);
        }

        target.appendChild(card);
    });
}

async function fetchProcessingStatus() {
    try {
        const response = await axios.get('/api/video-jobs/processing/status', { headers: headers() });
        const data = response.data;
        renderMetrics(data);
        renderJobList('#processing-list', data.processing || []);
        renderJobList('#queue-list', data.queue || []);
        writeLog('Processing snapshot updated.');
    } catch (error) {
        writeLog(`Unable to load processing snapshot: ${error.response?.data?.message || error.message}`);
    }
}

async function fetchQueue() {
    try {
        const response = await axios.get('/api/video-jobs/processing/queue', { headers: headers() });
        renderJobList('#queue-list', response.data || []);
        writeLog('Queue refreshed.');
    } catch (error) {
        writeLog(`Unable to load queue: ${error.response?.data?.message || error.message}`);
    }
}

async function pollJobStatus() {
    const jobId = $('#follow-id').value;
    if (!jobId) {
        writeLog('Provide a job id to poll.');
        return;
    }
    try {
        const response = await axios.get(`/status/${jobId}`, { headers: headers() });
        writeLog(`Job ${jobId}: ${JSON.stringify(response.data, null, 2)}`);
    } catch (error) {
        writeLog(`Unable to fetch status: ${error.response?.data?.message || error.message}`);
    }
}

async function submitDeforum() {
    const videoId = $('#video-id').value;
    if (!videoId) {
        writeLog('Video Job ID is required to steer Deforum.');
        return;
    }

    const payload = {
        videoId: Number(videoId),
        type: 'deforum',
        prompt: $('#prompt').value,
        negative_prompt: $('#negative-prompt').value,
        length: Number($('#length').value || 4),
        fps: Number($('#fps').value || 24),
        seed: Number($('#seed').value || -1),
    };

    try {
        const response = await axios.post('/api/generate', payload, { headers: headers() });
        writeLog(`Deforum request sent: ${JSON.stringify(response.data)}`);
        fetchProcessingStatus();
    } catch (error) {
        writeLog(`Error submitting Deforum job: ${error.response?.data?.message || error.message}`);
    }
}

function bindEvents() {
    apiTokenInput.value = state.token;
    apiTokenInput.addEventListener('change', (event) => {
        state.token = event.target.value.trim();
        localStorage.setItem('deforumation-token', state.token);
    });

    $('#poll-status').addEventListener('click', pollJobStatus);
    $('#poll-queue').addEventListener('click', fetchQueue);
    $('#submit-deforum').addEventListener('click', submitDeforum);
}

window.addEventListener('DOMContentLoaded', () => {
    bindEvents();
    fetchProcessingStatus();
});
