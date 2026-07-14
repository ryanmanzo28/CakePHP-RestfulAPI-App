
function getAuthToken() {
    return localStorage.getItem('jwt') || sessionStorage.getItem('jwt') || '';
}

function getStoredUsername() {
    return localStorage.getItem('username') || sessionStorage.getItem('username') || '';
}

const REQUEST_TIMEOUT_MS = 12000;
let activeNetworkRequests = 0;
let toastHideTimer = null;

function ensureUiStyles() {
    if (document.getElementById('hc-ui-style')) return;
    const style = document.createElement('style');
    style.id = 'hc-ui-style';
    style.textContent = [
        '.hc-top-loader{position:fixed;top:0;left:0;height:3px;width:100%;transform-origin:left center;transform:scaleX(0);opacity:0;transition:transform .18s ease,opacity .18s ease;background:linear-gradient(90deg,#4f46e5,#22c55e);z-index:2000;pointer-events:none}',
        'body.hc-loading .hc-top-loader{transform:scaleX(1);opacity:1}',
        '.hc-toast.info{border-left:4px solid #4f46e5}',
        '.hc-toast.success{border-left:4px solid #16a34a}',
        '.hc-toast.error{border-left:4px solid #dc2626}',
    ].join('');
    document.head.appendChild(style);
}

function ensureTopLoaderElement() {
    ensureUiStyles();
    if (!document.body) return null;
    let el = document.getElementById('hcTopLoader');
    if (!el) {
        el = document.createElement('div');
        el.id = 'hcTopLoader';
        el.className = 'hc-top-loader';
        el.setAttribute('aria-hidden', 'true');
        document.body.appendChild(el);
    }
    return el;
}

function setGlobalLoadingState(isLoading) {
    if (!document.body) return;
    ensureTopLoaderElement();
    if (isLoading) {
        document.body.classList.add('hc-loading');
        document.body.setAttribute('aria-busy', 'true');
    } else {
        document.body.classList.remove('hc-loading');
        document.body.removeAttribute('aria-busy');
    }
}

function beginNetworkActivity() {
    activeNetworkRequests += 1;
    setGlobalLoadingState(true);
}

function endNetworkActivity() {
    activeNetworkRequests = Math.max(0, activeNetworkRequests - 1);
    if (activeNetworkRequests === 0) {
        setGlobalLoadingState(false);
    }
}

function ensureToastElement() {
    ensureUiStyles();
    if (!document.body) return null;
    let toast = document.getElementById('hcToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'hcToast';
        toast.className = 'snackbar hide hc-toast info';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        document.body.appendChild(toast);
    }
    return toast;
}

function showToast(message, kind = 'info', options = {}) {
    const toast = ensureToastElement();
    if (!toast || !message) return;
    const type = kind === 'success' ? 'success' : (kind === 'error' ? 'error' : 'info');
    const duration = Number.isFinite(options.duration) ? Number(options.duration) : 2600;
    toast.textContent = String(message);
    toast.className = `snackbar hc-toast ${type}`;
    if (toastHideTimer) {
        clearTimeout(toastHideTimer);
        toastHideTimer = null;
    }
    toastHideTimer = setTimeout(() => {
        toast.classList.add('hide');
    }, Math.max(800, duration));
}

async function fetchWithTimeout(url, options = {}, timeoutMs = REQUEST_TIMEOUT_MS) {
    beginNetworkActivity();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
        return await fetch(url, Object.assign({}, options, { signal: controller.signal }));
    } catch (error) {
        if (error && error.name === 'AbortError') {
            const timeoutError = new Error('Request timed out. Please try again.');
            timeoutError.code = 'TIMEOUT';
            throw timeoutError;
        }
        throw error;
    } finally {
        clearTimeout(timeout);
        endNetworkActivity();
    }
}

function parseJsonLoose(text) {
    if (!text) return null;
    try {
        return JSON.parse(text);
    } catch (e) {
        const match = text.match(/(\{[\s\S]*\}|\[[\s\S]*\])/);
        if (match && match[1]) {
            try {
                return JSON.parse(match[1]);
            } catch (err) {
                return null;
            }
        }
        return null;
    }
}

async function parseResponseBody(response) {
    const text = await response.text().catch(() => '');
    const parsed = parseJsonLoose(text);
    return {
        text,
        data: parsed,
    };
}

async function tryRefreshAuthTokenFromStoredSession() {
    const email = getStoredUsername();
    const passwordHash = localStorage.getItem('passwordHash') || sessionStorage.getItem('passwordHash') || '';
    if (!email || !passwordHash) return false;

    try {
        const res = await fetch('/api/auth/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ email, password: passwordHash }),
        });
        if (!res.ok) return false;
        const data = await res.json().catch(() => null);
        if (!data || !data.token) return false;

        const rememberMe = !!localStorage.getItem('jwt');
        if (typeof setAuthSession === 'function') {
            setAuthSession({ token: data.token, username: email, passwordHash, rememberMe });
        } else {
            const primary = rememberMe ? localStorage : sessionStorage;
            const secondary = rememberMe ? sessionStorage : localStorage;
            primary.setItem('jwt', data.token);
            primary.setItem('username', email);
            primary.setItem('passwordHash', passwordHash);
            secondary.removeItem('jwt');
            secondary.removeItem('username');
            secondary.removeItem('passwordHash');
        }
        return true;
    } catch (e) {
        return false;
    }
}

function clearAuthStorage() {
    const keys = ['jwt', 'username', 'passwordHash'];
    keys.forEach((key) => {
        localStorage.removeItem(key);
        sessionStorage.removeItem(key);
    });
}

function setAuthSession({ token, username, passwordHash, rememberMe }) {
    const primary = rememberMe ? localStorage : sessionStorage;
    const secondary = rememberMe ? sessionStorage : localStorage;

    primary.setItem('jwt', token || '');
    primary.setItem('username', username || '');
    primary.setItem('passwordHash', passwordHash || '');

    secondary.removeItem('jwt');
    secondary.removeItem('username');
    secondary.removeItem('passwordHash');
}

async function init() {
    console.log("Workout Tracker initialized.");
    ensureTopLoaderElement();
    ensureToastElement();

    const token = getAuthToken();
    const pathname = window.location.pathname || '';
    const isLoginPage = pathname.endsWith('login.html') || pathname.endsWith('register.html');
    const isDashboardPage = pathname.endsWith('dashboard.html');

    if (!token && !isLoginPage) {
        console.log("User not logged in. Redirecting to login page.");
        window.location.href = "/pages/login.html";
        return;
    }

    if (token) {
        console.log("User token found in localStorage.");
        // If we are on the login page, send user to dashboard
        if (isLoginPage) {
            window.location.href = "/pages/dashboard.html";
            return;
        }
        // Validate token; loginCheck will redirect to login on failure
        try {
            await loginCheck();
        } catch (e) {
            console.warn('Token validation error', e);
        }
    } else {
        console.log("No user token found in localStorage.");
    }
}


let appInitPromise = null;

function ensureInit() {
    if (appInitPromise) return appInitPromise;
    appInitPromise = init().catch((error) => {
        console.error('App initialization failed:', error);
        throw error;
    });
    return appInitPromise;
}


async function hashPassword(plainText) {
    // Use Web Crypto API to compute a SHA-256 hash and return hex string
    if (!plainText) return '';
    const enc = new TextEncoder();
    const data = enc.encode(plainText);
    const hashBuffer = await crypto.subtle.digest('SHA-256', data);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    return hashHex;
}
const WORKOUTS_CACHE_TTL_MS = 30000;
let workoutsCacheData = null;
let workoutsCacheAt = 0;

const APP_SETTINGS_KEY = 'hc:settings';
const DEFAULT_APP_SETTINGS = Object.freeze({
    weightUnit: 'lb',
    distanceUnit: 'km',
});
const LB_TO_KG = 0.45359237;
const KM_TO_MI = 0.621371192;

function normalizeWeightUnit(unit) {
    return unit === 'kg' ? 'kg' : 'lb';
}

function normalizeDistanceUnit(unit) {
    if (unit === 'mi') return 'mi';
    if (unit === 'm') return 'm';
    return 'km';
}

function parseWeightNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const normalized = String(value).trim().replace(/,/g, '');
    if (!normalized) {
        return null;
    }

    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : null;
}

function convertWeightValue(value, sourceUnit, targetUnit) {
    const parsed = parseWeightNumber(value);
    if (parsed === null) {
        return null;
    }

    const normalizedSource = normalizeWeightUnit(sourceUnit);
    const normalizedTarget = normalizeWeightUnit(targetUnit);
    if (normalizedSource === normalizedTarget) {
        return parsed;
    }

    return normalizedSource === 'lb' ? (parsed * LB_TO_KG) : (parsed / LB_TO_KG);
}

function parseDistanceNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const normalized = String(value).trim().replace(/,/g, '');
    if (!normalized) {
        return null;
    }

    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : null;
}

function convertDistanceValue(value, sourceUnit, targetUnit) {
    const parsed = parseDistanceNumber(value);
    if (parsed === null) {
        return null;
    }

    const normalizedSource = normalizeDistanceUnit(sourceUnit);
    const normalizedTarget = normalizeDistanceUnit(targetUnit);
    if (normalizedSource === normalizedTarget) {
        return parsed;
    }

    let kmValue = parsed;
    if (normalizedSource === 'mi') {
        kmValue = parsed / KM_TO_MI;
    } else if (normalizedSource === 'm') {
        kmValue = parsed / 1000;
    }

    if (normalizedTarget === 'km') return kmValue;
    if (normalizedTarget === 'mi') return kmValue * KM_TO_MI;
    return kmValue * 1000;
}

function formatDistanceForDisplay(value, sourceUnit = 'km', targetUnit = getDistanceUnit(), options = {}) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const normalizedTarget = normalizeDistanceUnit(targetUnit || getDistanceUnit());
    const normalizedSource = normalizeDistanceUnit(sourceUnit || 'km');
    const converted = convertDistanceValue(value, normalizedSource, normalizedTarget);
    const separator = Object.prototype.hasOwnProperty.call(options, 'separator') ? String(options.separator) : '';
    let formattedValue = '';

    if (converted === null) {
        formattedValue = String(value).trim();
    } else if (normalizedTarget === 'm') {
        formattedValue = String(Math.round(converted));
    } else if (Math.abs(converted) >= 100) {
        formattedValue = String(Math.round(converted));
    } else if (Math.abs(converted) >= 10) {
        formattedValue = converted.toFixed(1);
    } else {
        formattedValue = converted.toFixed(2);
    }

    if (options.appendUnit === false) {
        return formattedValue;
    }

    return `${formattedValue}${separator}${normalizedTarget}`;
}

function formatWeightForDisplay(value, sourceUnit, targetUnit = getWeightUnit(), options = {}) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const normalizedTarget = normalizeWeightUnit(targetUnit || getWeightUnit());
    const normalizedSource = normalizeWeightUnit(sourceUnit || normalizedTarget);
    const converted = convertWeightValue(value, normalizedSource, normalizedTarget);
    const separator = Object.prototype.hasOwnProperty.call(options, 'separator') ? String(options.separator) : '';
    let formattedValue = '';

    if (converted === null) {
        formattedValue = String(value).trim();
    } else {
        formattedValue = Math.abs(converted - Math.round(converted)) > 0.05
            ? converted.toFixed(1)
            : String(Math.round(converted));
    }

    if (options.appendUnit === false) {
        return formattedValue;
    }

    return `${formattedValue}${separator}${normalizedTarget}`;
}

function getAppSettings() {
    const fallback = Object.assign({}, DEFAULT_APP_SETTINGS);
    try {
        const raw = localStorage.getItem(APP_SETTINGS_KEY);
        const parsed = raw ? JSON.parse(raw) : {};
        const merged = Object.assign({}, fallback, parsed || {});
        merged.weightUnit = normalizeWeightUnit(merged.weightUnit);
        merged.distanceUnit = normalizeDistanceUnit(merged.distanceUnit);

        // Backward compatibility: migrate old standalone weight key if present.
        const legacyWeightUnit = localStorage.getItem('hc:weightUnit');
        const legacyDistanceUnit = localStorage.getItem('hc:distanceUnit');
        if (legacyWeightUnit && !raw) {
            merged.weightUnit = normalizeWeightUnit(legacyWeightUnit);
        }
        if (legacyDistanceUnit && !raw) {
            merged.distanceUnit = normalizeDistanceUnit(legacyDistanceUnit);
        }
        if ((legacyWeightUnit || legacyDistanceUnit) && !raw) {
            localStorage.setItem(APP_SETTINGS_KEY, JSON.stringify(merged));
        }

        // Keep legacy key in sync while older page code may still read it.
        localStorage.setItem('hc:weightUnit', merged.weightUnit);
        localStorage.setItem('hc:distanceUnit', merged.distanceUnit);
        return merged;
    } catch (e) {
        return fallback;
    }
}

function setAppSettings(partialSettings = {}) {
    const next = Object.assign({}, getAppSettings(), partialSettings || {});
    next.weightUnit = normalizeWeightUnit(next.weightUnit);
    next.distanceUnit = normalizeDistanceUnit(next.distanceUnit);
    try {
        localStorage.setItem(APP_SETTINGS_KEY, JSON.stringify(next));
        localStorage.setItem('hc:weightUnit', next.weightUnit);
        localStorage.setItem('hc:distanceUnit', next.distanceUnit);
    } catch (e) {
        // ignore storage failures; callers still get normalized return value
    }
    try {
        window.dispatchEvent(new CustomEvent('hc:settingsChanged', { detail: next }));
    } catch (e) {
        // ignore event dispatch errors
    }
    return next;
}

function getWeightUnit() {
    return getAppSettings().weightUnit;
}

function setWeightUnit(unit) {
    return setAppSettings({ weightUnit: unit });
}

function getDistanceUnit() {
    return getAppSettings().distanceUnit;
}

function setDistanceUnit(unit) {
    return setAppSettings({ distanceUnit: unit });
}

function invalidateWorkoutsCache() {
    workoutsCacheData = null;
    workoutsCacheAt = 0;
}

async function loadWorkouts(options = {}) {
   const forceRefresh = !!(options && options.forceRefresh);
   const hasFreshCache = !forceRefresh
       && Array.isArray(workoutsCacheData)
       && workoutsCacheAt
       && (Date.now() - workoutsCacheAt < WORKOUTS_CACHE_TTL_MS);
   if (hasFreshCache) {
       return workoutsCacheData.slice();
   }

    const token = getAuthToken();
   if (!token) {
       throw new Error('Not authenticated');
   }

   try {
    const response = await fetchWithTimeout(`/api/workouts`, {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
        }
    });
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    const { data: json } = await parseResponseBody(response);
    // Normalize different response shapes into an array of workout objects
    let workouts = [];
    if (Array.isArray(json)) workouts = json;
    else if (json && Array.isArray(json.data)) workouts = json.data;
    else if (json && Array.isArray(json.workouts)) workouts = json.workouts;
    else if (json && json.results && Array.isArray(json.results)) workouts = json.results;
    else if (json && typeof json === 'object' && json.id) workouts = [json];
    // parse JSON `data` fields if returned as strings
    workouts = workouts.map(w => {
        try {
            if (w && typeof w.data === 'string') {
                w.data = JSON.parse(w.data);
            }
        } catch (e) { /* ignore parse errors */ }
        return w;
    });
    workoutsCacheData = workouts;
    workoutsCacheAt = Date.now();
    console.log("Workouts loaded successfully:", workouts);
    return workouts.slice();
   } catch (error) {
    console.error("Failed to load workouts:", error);
    if (Array.isArray(workoutsCacheData) && workoutsCacheData.length) {
        return workoutsCacheData.slice();
    }
    showToast('Could not load workouts right now.', 'error', { duration: 3000 });
    return [];
   }
}
async function loginCheck() {
    const token = getAuthToken();

    if (!token) {
        window.location.href = "/pages/login.html";
        return;
    }

    const response = await fetchWithTimeout("/api/auth/check", {
        method: "POST",
        headers: {
            "Authorization": `Bearer ${token}`,
            "Content-Type": "application/json",
            "Accept": "application/json"
        },
        body: JSON.stringify({})
    });

    if (!response.ok) {
        clearAuthStorage();
        window.location.href = "/pages/login.html";
        return;
    }

    const { data } = await parseResponseBody(response);
    if (!data || !data.valid) {
        clearAuthStorage();
        window.location.href = "/pages/login.html";
    }
}

// Run init on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    void ensureInit();
}, { once: true });

window.init = ensureInit;

async function createAccount(username, email, password) {
    const hashedPassword = await hashPassword(password + email.toLowerCase());
    const response = await fetchWithTimeout("/api/auth/register", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "Accept": "application/json"
        },
        body: JSON.stringify({ username, email, password: hashedPassword })
    });

    const { text, data: parsedData } = await parseResponseBody(response);
    let data = parsedData;
    if (!data && text) {
        data = { message: text };
    }

    if (!response.ok) {
        const msg = data && (data.error || data.message) ? (data.error || data.message) : `HTTP error ${response.status}`;
        const err = new Error(msg);
        err.status = response.status;
        throw err;
    }

    // Ensure callers always receive an object with a `message` property
    if (!data) {
        data = { message: response.status === 201 ? 'User created' : 'OK' };
    }

    return data;
}

/**
 * Add a new workout via the API.
 * Returns the created workout object and throws on failure.
 */
async function addWorkout({ title, date, duration, notes, type, sets, exercises, distance, weightUnit } = {}) {
    const token = getAuthToken();
    if (!token) throw new Error('Not authenticated');

    // normalize duration: accept either a number (minutes) or a string.
    // Backend stores duration as a string like '45m' or '1h15m', so convert numbers to that format.
    function formatDurationValue(d) {
        if (d === null || d === undefined || d === '') return null;
        if (typeof d === 'number') {
            const m = Math.max(0, Math.floor(d));
            if (m >= 60) { const h = Math.floor(m / 60); const mm = m % 60; return mm ? `${h}h${mm}m` : `${h}h`; }
            return `${m}m`;
        }
        // if string already looks like a simple number, treat as minutes
        if (/^\d+$/.test(String(d).trim())) return `${String(d).trim()}m`;
        return String(d);
    }

    const body = { title, date, duration: formatDurationValue(duration), notes };

    // Include a `data` JSON blob with richer info (type/sets/exercises) so future schema that supports JSON can use it.
    const dataBlob = {};
    if (type !== undefined) dataBlob.type = type;
    if (sets !== undefined) dataBlob.sets = sets;
    if (exercises !== undefined) dataBlob.exercises = exercises;
    if (distance !== undefined) dataBlob.distance = distance;
    if (weightUnit !== undefined) dataBlob.weightUnit = weightUnit;
    if (Object.keys(dataBlob).length) body.data = dataBlob;
    try {
        const res = await fetchWithTimeout('/api/workouts', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`,
            },
            body: JSON.stringify(body),
        });

        if (!res.ok) {
            // if server rejects, surface message
            const { data } = await parseResponseBody(res);
            const msg = data && (data.error || data.message) ? (data.error || data.message) : `HTTP ${res.status}`;
            throw new Error(msg);
        }

        const createdRaw = await res.json().catch(() => null);
        const created = (function(raw){
            if (!raw) return null;
            if (Array.isArray(raw)) {
                if (raw.length === 1 && raw[0] && typeof raw[0] === 'object') return raw[0];
                return null;
            }
            if (typeof raw !== 'object') return null;
            if (raw.id || raw._id) return raw;
            if (raw.workout && typeof raw.workout === 'object') return raw.workout;
            if (raw.data && typeof raw.data === 'object') return raw.data;
            return null;
        })(createdRaw);
        if (!created || (!created.id && !created._id)) {
            throw new Error('Workout save returned an invalid response');
        }
        invalidateWorkoutsCache();
        return created;
    } catch (err) {
        invalidateWorkoutsCache();
        showToast('Unable to save workout. Please retry.', 'error', { duration: 3000 });
        throw err;
    }
}

// Expose for pages to call directly
window.addWorkout = addWorkout;
window.loadWorkouts = loadWorkouts;
window.invalidateWorkoutsCache = invalidateWorkoutsCache;
window.getAppSettings = getAppSettings;
window.setAppSettings = setAppSettings;
window.getWeightUnit = getWeightUnit;
window.setWeightUnit = setWeightUnit;
window.getDistanceUnit = getDistanceUnit;
window.setDistanceUnit = setDistanceUnit;
window.convertWeightValue = convertWeightValue;
window.formatWeightForDisplay = formatWeightForDisplay;
window.convertDistanceValue = convertDistanceValue;
window.formatDistanceForDisplay = formatDistanceForDisplay;

function normalizeWorkoutIdForApi(id) {
    if (id === null || id === undefined) return null;
    const raw = String(id).trim();
    if (!raw) return null;
    const numericMatch = raw.match(/(\d+)$/);
    if (!numericMatch) return null;
    const parsed = Number.parseInt(numericMatch[1], 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

/**
 * Update an existing workout by id. Returns the updated workout object on success.
 */
async function updateWorkout(id, { title, date, duration, notes, type, sets, exercises, distance, weightUnit, data } = {}){
    let token = getAuthToken();
    if(!token) throw new Error('Not authenticated');
    const normalizedId = normalizeWorkoutIdForApi(id);
    if (!normalizedId) throw new Error('Invalid workout id');

    function formatDurationValue(d) {
        if (d === null || d === undefined || d === '') return null;
        if (typeof d === 'number') {
            const m = Math.max(0, Math.floor(d));
            if (m >= 60) { const h = Math.floor(m / 60); const mm = m % 60; return mm ? `${h}h${mm}m` : `${h}h`; }
            return `${m}m`;
        }
        if (/^\d+$/.test(String(d).trim())) return `${String(d).trim()}m`;
        return String(d);
    }

    const body = { token };
    if (title !== undefined) body.title = title;
    if (date !== undefined) body.date = date;
    if (duration !== undefined) body.duration = formatDurationValue(duration);
    if (notes !== undefined) body.notes = notes;
    const dataBlob = {};
    if (type !== undefined) dataBlob.type = type;
    if (sets !== undefined) dataBlob.sets = sets;
    if (exercises !== undefined) dataBlob.exercises = exercises;
    if (distance !== undefined) dataBlob.distance = distance;
    if (weightUnit !== undefined) dataBlob.weightUnit = weightUnit;
    if (data && typeof data === 'object' && !Array.isArray(data)) {
        Object.assign(dataBlob, data);
    }
    if (Object.keys(dataBlob).length) body.data = dataBlob;

    if (!Object.keys(body).length) {
        throw new Error('No changes to save');
    }

    try{
        const endpoint = `/api/workouts/${encodeURIComponent(normalizedId)}`;
        const methods = ['PUT', 'PATCH', 'POST'];
        let res = null;

        for (let attempt = 0; attempt < 2; attempt++) {
            for (const method of methods) {
                res = await fetchWithTimeout(endpoint, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify(body)
                });
                if (res.ok) break;
                // Retry alternate method when transport/method handling is unsupported.
                if (![404, 405, 501].includes(res.status)) break;
            }

            if (res && res.ok) break;
            // One-time auth refresh if token expired/invalid.
            if (attempt === 0 && res && res.status === 401) {
                const refreshed = await tryRefreshAuthTokenFromStoredSession();
                if (refreshed) {
                    token = getAuthToken();
                    if (token) continue;
                }
            }
            break;
        }
        if(!res.ok){
            const { data } = await parseResponseBody(res);
            const msg = data && (data.error || data.message) ? (data.error || data.message) : `HTTP ${res.status}`;
            throw new Error(msg);
        }
        const updated = await res.json().catch(()=>null);
        invalidateWorkoutsCache();
        return updated || Object.assign({ id: normalizedId }, body);
    }catch(err){
        // On failure, throw so caller can surface error and decide optimistic behavior
        showToast('Unable to update workout.', 'error', { duration: 3000 });
        throw err;
    }
}

window.updateWorkout = updateWorkout;

/**
 * Delete a workout by id via the API. Returns true on success; throws on failure.
 */
async function deleteWorkout(id) {
    const token = getAuthToken();
    if (!token) throw new Error('Not authenticated');
    const normalizedId = normalizeWorkoutIdForApi(id);
    if (!normalizedId) throw new Error('Invalid workout id');
    const routes = [
        { url: `/api/workouts/${encodeURIComponent(normalizedId)}`, method: 'DELETE' },
        { url: `/api/workouts/${encodeURIComponent(normalizedId)}/delete`, method: 'POST' },
    ];
    let res = null;
    for (const route of routes) {
        res = await fetchWithTimeout(route.url, {
            method: route.method,
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json'
            }
        });
        if (res.ok) break;
        if (![404, 405, 501].includes(res.status)) break;
    }
    if (!res.ok) {
        const { data } = await parseResponseBody(res);
        const msg = data && (data.error || data.message) ? (data.error || data.message) : `HTTP ${res.status}`;
        const err = new Error(msg);
        err.status = res.status;
        showToast('Could not delete workout.', 'error', { duration: 3000 });
        throw err;
    }
    invalidateWorkoutsCache();
    showToast('Workout deleted.', 'success', { duration: 1800 });
    return true;
}

// Expose delete to pages
window.deleteWorkout = deleteWorkout;

/**
 * Record a completed workout session. Tries to POST to /api/sessions (preferred),
 * falls back to POST /api/workouts/:id/complete, and finally to localStorage.
 * Returns the saved session object (or local fallback object) on success.
 */
async function completeWorkout(workoutId, summary = {}){
    const token = getAuthToken();
    const payload = Object.assign({}, summary, { workoutId });
    const normalizedWorkoutId = Number.parseInt(String(workoutId), 10);
    const hasNumericWorkoutId = Number.isFinite(normalizedWorkoutId) && normalizedWorkoutId > 0;
    if (!token){
        // offline / not authenticated: persist locally
        try{
            const key = 'hc:completedSessions';
            const raw = localStorage.getItem(key); const arr = raw ? JSON.parse(raw) : [];
            const item = Object.assign({ id: `local-session-${Date.now()}`, created: Date.now() }, payload);
            arr.push(item); localStorage.setItem(key, JSON.stringify(arr));
            return item;
        }catch(e){ throw new Error('Not authenticated and failed to persist locally'); }
    }

    // First try dedicated workout completion endpoint for real saved workouts.
    if (hasNumericWorkoutId) {
        try{
            const resComplete = await fetch(`/api/workouts/${encodeURIComponent(normalizedWorkoutId)}/complete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
                body: JSON.stringify(payload)
            });
            if(resComplete.ok){ const data = await resComplete.json().catch(()=>null); return data || payload; }
        }catch(e){ /* continue to fallback */ }
    }

    // try creating a session record (legacy fallback)
    try{
        const res = await fetch('/api/sessions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
            body: JSON.stringify(payload)
        });
        if(res.ok){ const data = await res.json().catch(()=>null); return data || payload; }
        // try alternate endpoint
    }catch(e){ /* continue to fallback */ }

    if (hasNumericWorkoutId) {
        try{
            const res2 = await fetch(`/api/workouts/${encodeURIComponent(normalizedWorkoutId)}/complete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
                body: JSON.stringify(payload)
            });
            if(res2.ok){ const data = await res2.json().catch(()=>null); return data || payload; }
        }catch(e){ /* fall back to local */ }
    }

    // final fallback: store locally
    try{
        const key = 'hc:completedSessions';
        const raw = localStorage.getItem(key); const arr = raw ? JSON.parse(raw) : [];
        const item = Object.assign({ id: `local-session-${Date.now()}`, created: Date.now(), _local: true }, payload);
        arr.push(item); localStorage.setItem(key, JSON.stringify(arr));
        return item;
    }catch(e){ throw new Error('Failed to save completed session'); }
}

window.completeWorkout = completeWorkout;

function getCompletedPosts() {
    try {
        const raw = localStorage.getItem('hc:completedPosts');
        const arr = raw ? JSON.parse(raw) : [];
        return Array.isArray(arr) ? arr : [];
    } catch (e) {
        return [];
    }
}

function saveCompletedPosts(posts) {
    try {
        localStorage.setItem('hc:completedPosts', JSON.stringify(posts || []));
        return true;
    } catch (e) {
        return false;
    }
}

function getCurrentUserIdFromToken() {
    const token = getAuthToken();
    if (!token || token.indexOf('.') === -1) return '';
    try {
        const payloadPart = token.split('.')[1] || '';
        const base64 = payloadPart.replace(/-/g, '+').replace(/_/g, '/');
        const json = decodeURIComponent(atob(base64).split('').map((ch) => `%${(`00${ch.charCodeAt(0).toString(16)}`).slice(-2)}`).join(''));
        const payload = JSON.parse(json);
        return String(payload && (payload.sub || payload.user_id) ? (payload.sub || payload.user_id) : '').trim();
    } catch (e) {
        return '';
    }
}

function createCompletedPost({ title, workoutTitle, workoutId, summary } = {}) {
    const now = Date.now();
    const owner = getStoredUsername() || 'You';
    const ownerId = getCurrentUserIdFromToken();
    const post = {
        id: `post-${now}`,
        title: title || workoutTitle || 'Completed Workout',
        workoutTitle: workoutTitle || 'Workout',
        workoutId: workoutId || null,
        owner,
        ownerId,
        description: '',
        created: now,
        summary: summary || {},
    };
    const posts = getCompletedPosts();
    posts.unshift(post);
    saveCompletedPosts(posts);
    return post;
}

function getCompletedPostById(id) {
    if (!id) return null;
    const posts = getCompletedPosts();
    return posts.find((p) => p && p.id === id) || null;
}

function updateCompletedPost(id, updates = {}) {
    if (!id) return null;
    const posts = getCompletedPosts();
    const idx = posts.findIndex((p) => p && p.id === id);
    if (idx === -1) return null;

    const next = Object.assign({}, posts[idx]);
    const cleanedTitle = (updates.title !== undefined) ? String(updates.title || '').trim() : next.title;
    if (cleanedTitle) next.title = cleanedTitle;
    if (updates.activityType !== undefined) next.activityType = String(updates.activityType || '').trim();
    if (updates.intensity !== undefined) next.intensity = String(updates.intensity || '').trim();
    if (updates.description !== undefined) next.description = String(updates.description || '').trim();
    if (updates.notes !== undefined) next.notes = String(updates.notes || '').trim();
    if (updates.photoDataUrl !== undefined) next.photoDataUrl = updates.photoDataUrl || '';
    if (updates.photoName !== undefined) next.photoName = String(updates.photoName || '').trim();
    next.updated = Date.now();

    posts[idx] = next;
    if (!saveCompletedPosts(posts)) return null;
    return next;
}

function renameCompletedPost(id, newTitle) {
    if (!id) return false;
    const posts = getCompletedPosts();
    const idx = posts.findIndex((p) => p && p.id === id);
    if (idx === -1) return false;
    const cleaned = String(newTitle || '').trim();
    if (!cleaned) return false;
    posts[idx].title = cleaned;
    return saveCompletedPosts(posts);
}

function deleteCompletedPost(id) {
    if (!id) return false;
    const posts = getCompletedPosts();
    const next = posts.filter((p) => p && p.id !== id);
    if (next.length === posts.length) return false;
    return saveCompletedPosts(next);
}

window.getCompletedPosts = getCompletedPosts;
window.getCompletedPostById = getCompletedPostById;
window.createCompletedPost = createCompletedPost;
window.updateCompletedPost = updateCompletedPost;
window.renameCompletedPost = renameCompletedPost;
window.deleteCompletedPost = deleteCompletedPost;
window.getAuthToken = getAuthToken;
window.getStoredUsername = getStoredUsername;
window.clearAuthStorage = clearAuthStorage;
window.setAuthSession = setAuthSession;
window.showToast = showToast;