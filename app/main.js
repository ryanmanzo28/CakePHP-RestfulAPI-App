
function getAuthToken() {
    return localStorage.getItem('jwt') || sessionStorage.getItem('jwt') || '';
}

function getStoredUsername() {
    return localStorage.getItem('username') || sessionStorage.getItem('username') || '';
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
});
const LB_TO_KG = 0.45359237;

function normalizeWeightUnit(unit) {
    return unit === 'kg' ? 'kg' : 'lb';
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

        // Backward compatibility: migrate old standalone weight key if present.
        const legacyWeightUnit = localStorage.getItem('hc:weightUnit');
        if (legacyWeightUnit && !raw) {
            merged.weightUnit = normalizeWeightUnit(legacyWeightUnit);
            localStorage.setItem(APP_SETTINGS_KEY, JSON.stringify(merged));
        }

        // Keep legacy key in sync while older page code may still read it.
        localStorage.setItem('hc:weightUnit', merged.weightUnit);
        return merged;
    } catch (e) {
        return fallback;
    }
}

function setAppSettings(partialSettings = {}) {
    const next = Object.assign({}, getAppSettings(), partialSettings || {});
    next.weightUnit = normalizeWeightUnit(next.weightUnit);
    try {
        localStorage.setItem(APP_SETTINGS_KEY, JSON.stringify(next));
        localStorage.setItem('hc:weightUnit', next.weightUnit);
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
    const response = await fetch(`/api/workouts`, {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
        }
    });
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    const json = await response.json().catch(() => null);
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
    return [];
   }
}
async function loginCheck() {
    const token = getAuthToken();

    if (!token) {
        window.location.href = "/pages/login.html";
        return;
    }

    const response = await fetch("/api/auth/check", {
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

    const data = await response.json().catch(() => null);
    if (!data || !data.valid) {
        clearAuthStorage();
        window.location.href = "/pages/login.html";
    }
}

// Run init on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    void init();
});

async function createAccount(username, email, password) {
    const hashedPassword = await hashPassword(password + email.toLowerCase());
    const response = await fetch("/api/auth/register", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "Accept": "application/json"
        },
        body: JSON.stringify({ username, email, password: hashedPassword })
    });

    const text = await response.text();
    let data = null;
    try {
        data = text ? JSON.parse(text) : null;
    } catch (e) {
        // If parsing failed (e.g. PHP warnings prepended), try to extract the JSON object
        data = null;
        if (text) {
            const m = text.match(/(\{[\s\S]*\})/);
            if (m && m[1]) {
                try { data = JSON.parse(m[1]); } catch (ee) { /* ignore */ }
            }
        }
        // fallback to raw text message
        if (!data) data = { message: text };
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
    if (distance !== undefined && distance !== null) dataBlob.distance = distance;
    if (weightUnit !== undefined) dataBlob.weightUnit = weightUnit;
    if (Object.keys(dataBlob).length) body.data = dataBlob;
    try {
        const res = await fetch('/api/workouts', {
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
            const text = await res.text().catch(() => '');
            let data = null;
            try { data = text ? JSON.parse(text) : null; } catch (e) { }
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
window.convertWeightValue = convertWeightValue;
window.formatWeightForDisplay = formatWeightForDisplay;

/**
 * Update an existing workout by id. Returns the updated workout object on success.
 */
async function updateWorkout(id, { title, date, duration, notes, type, sets, exercises, distance, weightUnit } = {}){
    const token = getAuthToken();
    if(!token) throw new Error('Not authenticated');

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

    const body = { title, date, duration: formatDurationValue(duration), notes };
    const dataBlob = {};
    if (type !== undefined) dataBlob.type = type;
    if (sets !== undefined) dataBlob.sets = sets;
    if (exercises !== undefined) dataBlob.exercises = exercises;
    if (distance !== undefined && distance !== null) dataBlob.distance = distance;
    if (weightUnit !== undefined) dataBlob.weightUnit = weightUnit;
    if (Object.keys(dataBlob).length) body.data = dataBlob;

    try{
        const res = await fetch(`/api/workouts/${encodeURIComponent(id)}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify(body)
        });
        if(!res.ok){
            const text = await res.text().catch(()=>'');
            let data = null; try{ data = text? JSON.parse(text) : null; }catch(e){}
            const msg = data && (data.error || data.message) ? (data.error || data.message) : `HTTP ${res.status}`;
            throw new Error(msg);
        }
        const updated = await res.json().catch(()=>null);
        invalidateWorkoutsCache();
        return updated || Object.assign({ id }, body);
    }catch(err){
        // On failure, throw so caller can surface error and decide optimistic behavior
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
    const res = await fetch(`/api/workouts/${encodeURIComponent(id)}`, {
        method: 'DELETE',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
        }
    });
    if (!res.ok) {
        const text = await res.text().catch(() => '');
        let data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) {}
        const msg = data && (data.error || data.message) ? (data.error || data.message) : `HTTP ${res.status}`;
        const err = new Error(msg);
        err.status = res.status;
        throw err;
    }
    invalidateWorkoutsCache();
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

function createCompletedPost({ title, workoutTitle, workoutId, summary } = {}) {
    const now = Date.now();
    const owner = getStoredUsername() || 'You';
    const post = {
        id: `post-${now}`,
        title: title || workoutTitle || 'Completed Workout',
        workoutTitle: workoutTitle || 'Workout',
        workoutId: workoutId || null,
        owner,
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